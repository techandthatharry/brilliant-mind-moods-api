<?php

namespace App\Http\Controllers;

use App\Models\StravaActivity;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class StravaController extends Controller
{
    private const AUTH_URL  = 'https://www.strava.com/oauth/authorize';
    private const TOKEN_URL = 'https://www.strava.com/oauth/token';
    private const API_URL   = 'https://www.strava.com/api/v3';

    // ── Auth URL ──────────────────────────────────────────────────────────────

    /**
     * Return the Strava OAuth URL. Flutter opens this in an external browser.
     */
    public function authUrl(Request $request): JsonResponse
    {
        $user  = $request->user();
        $state = base64_encode(json_encode([
            'user_id' => $user->id,
            'nonce'   => Str::random(16),
        ]));

        $params = http_build_query([
            'client_id'       => env('STRAVA_CLIENT_ID'),
            'redirect_uri'    => env('STRAVA_REDIRECT_URI'),
            'response_type'   => 'code',
            'approval_prompt' => 'auto',   // skip consent if already approved
            'scope'           => 'activity:read_all',
            'state'           => $state,
        ]);

        return response()->json(['url' => self::AUTH_URL . '?' . $params]);
    }

    // ── OAuth callback ────────────────────────────────────────────────────────

    /**
     * Strava redirects here after the user authorises.
     * Exchanges the code for tokens, stores them, then syncs the past 12 months.
     */
    public function callback(Request $request)
    {
        // User denied access
        if ($request->has('error')) {
            return $this->html('❌ Access Denied', 'You declined access to Strava. Close this tab and try again.');
        }

        $code  = $request->query('code');
        $state = $request->query('state');

        if (! $code || ! $state) {
            return $this->html('❌ Authorization Failed', 'Missing code or state. Close this tab and try again.', 400);
        }

        $stateData = json_decode(base64_decode($state), true);
        if (! $stateData || ! isset($stateData['user_id'])) {
            return $this->html('❌ Invalid State', 'Could not decode the state parameter.', 400);
        }

        $user = User::find($stateData['user_id']);
        if (! $user) {
            return $this->html('❌ User Not Found', 'No matching account for this authorisation.', 400);
        }

        // Exchange code for tokens
        $tokenResp = Http::post(self::TOKEN_URL, [
            'client_id'     => env('STRAVA_CLIENT_ID'),
            'client_secret' => env('STRAVA_CLIENT_SECRET'),
            'code'          => $code,
            'grant_type'    => 'authorization_code',
        ]);

        if ($tokenResp->failed()) {
            return $this->html('❌ Token Exchange Failed', $tokenResp->body(), 400);
        }

        $tokens = $tokenResp->json();

        $user->update([
            'strava_access_token'    => $tokens['access_token'],
            'strava_refresh_token'   => $tokens['refresh_token'],
            'strava_token_expires_at' => Carbon::createFromTimestamp($tokens['expires_at']),
            'strava_athlete_id'      => $tokens['athlete']['id'] ?? null,
        ]);

        // ── Historical sync: past 12 months ───────────────────────────────
        $count = $this->runSync($user, historical: true);

        $user->update(['strava_last_synced_at' => now()]);

        return $this->html(
            '✅ Strava Connected!',
            "Synced <strong>{$count}</strong> activities from the past 12 months. "
            . 'Close this tab and return to the app.'
        );
    }

    // ── Status ────────────────────────────────────────────────────────────────

    public function status(Request $request): JsonResponse
    {
        $user      = $request->user();
        $connected = ! empty($user->strava_access_token);

        return response()->json([
            'connected'        => $connected,
            'athlete_id'       => $user->strava_athlete_id,
            'last_synced_at'   => $user->strava_last_synced_at,
            'activity_count'   => $connected
                ? StravaActivity::where('user_id', $user->id)->count()
                : 0,
        ]);
    }

    // ── Sync ──────────────────────────────────────────────────────────────────

    /**
     * Fetch any activities created after the most recent one already stored.
     */
    public function sync(Request $request): JsonResponse
    {
        $user = $request->user();

        if (empty($user->strava_access_token)) {
            return response()->json(['error' => 'Strava not connected'], 422);
        }

        $count = $this->runSync($user, historical: false);
        $user->update(['strava_last_synced_at' => now()]);

        return response()->json([
            'synced'  => $count,
            'message' => "Synced {$count} new activities.",
        ]);
    }

    // ── Activities ────────────────────────────────────────────────────────────

    /**
     * Return all stored activities for the authenticated user, newest first.
     */
    public function activities(Request $request): JsonResponse
    {
        $activities = StravaActivity::where('user_id', $request->user()->id)
            ->orderByDesc('start_date')
            ->get()
            ->map(fn ($a) => $a->toApiArray());

        return response()->json($activities);
    }

    // ── Disconnect ────────────────────────────────────────────────────────────

    public function disconnect(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->update([
            'strava_access_token'     => null,
            'strava_refresh_token'    => null,
            'strava_token_expires_at' => null,
            'strava_athlete_id'       => null,
            'strava_last_synced_at'   => null,
        ]);
        StravaActivity::where('user_id', $user->id)->delete();

        return response()->json(['message' => 'Strava disconnected.']);
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    /**
     * Core sync logic. Fetches from Strava API and upserts into strava_activities.
     * $historical = true  → fetch past 12 months
     * $historical = false → fetch since the most recent stored activity
     */
    private function runSync(User $user, bool $historical): int
    {
        $accessToken = $this->validToken($user);
        if (! $accessToken) return 0;

        $after = $historical
            ? now()->subMonths(12)->timestamp
            : (StravaActivity::where('user_id', $user->id)
                ->max('start_date')
                ? Carbon::parse(
                    StravaActivity::where('user_id', $user->id)->max('start_date')
                  )->timestamp
                : now()->subMonths(12)->timestamp);

        $count = 0;
        $page  = 1;

        do {
            $resp = Http::withToken($accessToken)
                ->timeout(30)
                ->get(self::API_URL . '/athlete/activities', [
                    'after'    => $after,
                    'per_page' => 200,
                    'page'     => $page,
                ]);

            if ($resp->failed()) break;

            $activities = $resp->json() ?? [];

            foreach ($activities as $a) {
                $this->upsertActivity($user->id, $a);
                $count++;
            }

            $page++;
        } while (count($activities) === 200 && $page <= 10); // max 2000 activities

        return $count;
    }

    private function upsertActivity(int $userId, array $a): void
    {
        $sportType = $a['sport_type'] ?? $a['type'] ?? 'Unknown';
        $isIndoor  = ($a['trainer'] ?? false) ||
                     in_array($sportType, ['VirtualRun', 'VirtualRide', 'IndoorRun', 'IndoorRide']);

        StravaActivity::updateOrCreate(
            ['user_id' => $userId, 'strava_id' => $a['id']],
            [
                'name'                  => $a['name'] ?? 'Activity',
                'sport_type'            => $sportType,
                'is_indoor'             => $isIndoor,
                'distance_metres'       => $a['distance']             ?? 0,
                'moving_time_seconds'   => $a['moving_time']          ?? 0,
                'elapsed_time_seconds'  => $a['elapsed_time']         ?? 0,
                'start_date'            => Carbon::parse($a['start_date']),
                'average_heartrate'     => $a['average_heartrate']    ?? null,
                'average_speed_mps'     => $a['average_speed']        ?? null,
                'total_elevation_gain'  => $a['total_elevation_gain'] ?? null,
                'suffer_score'          => $a['suffer_score']         ?? null,
            ]
        );
    }

    /**
     * Return a valid access token, refreshing if needed.
     */
    private function validToken(User $user): ?string
    {
        if (empty($user->strava_access_token)) return null;

        if ($user->strava_token_expires_at && now()->gte($user->strava_token_expires_at)) {
            $resp = Http::post(self::TOKEN_URL, [
                'client_id'     => env('STRAVA_CLIENT_ID'),
                'client_secret' => env('STRAVA_CLIENT_SECRET'),
                'grant_type'    => 'refresh_token',
                'refresh_token' => $user->strava_refresh_token,
            ]);

            if ($resp->failed()) return null;

            $tokens = $resp->json();
            $user->update([
                'strava_access_token'     => $tokens['access_token'],
                'strava_refresh_token'    => $tokens['refresh_token'],
                'strava_token_expires_at' => Carbon::createFromTimestamp($tokens['expires_at']),
            ]);
            $user->refresh();
        }

        return $user->strava_access_token;
    }

    private function html(string $heading, string $body, int $status = 200)
    {
        $html = <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
          <title>Strava — Brilliant Mind Moods</title>
          <meta name="viewport" content="width=device-width, initial-scale=1">
          <style>
            body { font-family: system-ui, sans-serif; max-width: 420px;
                   margin: 80px auto; text-align: center; color: #2C3E50; padding: 0 20px; }
            h1   { font-size: 1.4rem; }
            p    { color: #666; line-height: 1.5; }
          </style>
        </head>
        <body>
          <h1>$heading</h1>
          <p>$body</p>
        </body>
        </html>
        HTML;

        return response($html, $status)->header('Content-Type', 'text/html');
    }
}
