<?php

namespace App\Http\Controllers;

use App\Models\BodyMetric;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WithingsController extends Controller
{
    private const AUTH_URL    = 'https://account.withings.com/oauth2_user/authorize2';
    private const TOKEN_URL   = 'https://wbsapi.withings.net/v2/oauth2';
    private const MEASURE_URL = 'https://wbsapi.withings.net/measure';

    // ── Auth URL ──────────────────────────────────────────────────────────────

    /**
     * Return the Withings OAuth URL for the authenticated user.
     * The Flutter app copies/opens this in a PC browser to start the OAuth flow.
     */
    public function authUrl(Request $request): JsonResponse
    {
        $user = $request->user();

        // Encode user_id in state so the callback can identify who authorised
        $state = base64_encode(json_encode([
            'user_id' => $user->id,
            'nonce'   => Str::random(16),
        ]));

        $params = http_build_query([
            'response_type' => 'code',
            'client_id'     => env('WITHINGS_CLIENT_ID'),
            'redirect_uri'  => env('WITHINGS_REDIRECT_URI'),
            'state'         => $state,
            'scope'         => 'user.metrics',
        ]);

        return response()->json(['url' => self::AUTH_URL . '?' . $params]);
    }

    // ── OAuth callback ────────────────────────────────────────────────────────

    /**
     * Handles the browser redirect from Withings after the user authorises.
     * This is a web-style route — it returns HTML, not JSON.
     */
    public function callback(Request $request)
    {
        $code  = $request->query('code');
        $state = $request->query('state');

        if (! $code || ! $state) {
            return $this->htmlResponse('❌ Authorization failed', 'Missing code or state parameter.', 400);
        }

        $stateData = json_decode(base64_decode($state), true);
        if (! $stateData || ! isset($stateData['user_id'])) {
            return $this->htmlResponse('❌ Invalid state', 'Could not decode state parameter.', 400);
        }

        $user = User::find($stateData['user_id']);
        if (! $user) {
            return $this->htmlResponse('❌ User not found', 'No matching user for this authorisation.', 400);
        }

        // Exchange the code for access + refresh tokens
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'action'        => 'requesttoken',
            'grant_type'    => 'authorization_code',
            'client_id'     => env('WITHINGS_CLIENT_ID'),
            'client_secret' => env('WITHINGS_CLIENT_SECRET'),
            'code'          => $code,
            'redirect_uri'  => env('WITHINGS_REDIRECT_URI'),
        ]);

        if ($response->failed() || $response->json('status') !== 0) {
            $err = $response->json('error') ?? 'status ' . $response->json('status');
            return $this->htmlResponse('❌ Token exchange failed', $err, 400);
        }

        $tokens = $response->json('body');

        $user->update([
            'withings_access_token'    => $tokens['access_token'],
            'withings_refresh_token'   => $tokens['refresh_token'],
            'withings_token_expires_at' => now()->addSeconds((int) $tokens['expires_in'] - 60),
        ]);

        return $this->htmlResponse(
            '✅ Withings Connected!',
            'You can close this tab and return to the app. Tap <strong>Sync from Withings</strong> to load your data.',
        );
    }

    // ── Status ────────────────────────────────────────────────────────────────

    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'connected' => ! empty($request->user()->withings_access_token),
        ]);
    }

    // ── Sync ─────────────────────────────────────────────────────────────────

    /**
     * Fetch all body measurements from Withings (up to 2 years back)
     * and upsert into body_metrics. One row per user per day.
     */
    public function sync(Request $request): JsonResponse
    {
        $user = $request->user();

        if (empty($user->withings_access_token)) {
            return response()->json(['error' => 'Withings not connected'], 422);
        }

        // Refresh the token if it has expired
        if ($user->withings_token_expires_at && now()->gte($user->withings_token_expires_at)) {
            if (! $this->refreshToken($user)) {
                return response()->json(
                    ['error' => 'Token refresh failed — please reconnect Withings in the app.'],
                    401
                );
            }
            $user->refresh();
        }

        // Fetch weight (1) and fat ratio (6) for the past 2 years
        $response = Http::withToken($user->withings_access_token)
            ->get(self::MEASURE_URL, [
                'action'     => 'getmeas',
                'meastype'   => '1,6',
                'category'   => '1',
                'startdate'  => now()->subYears(2)->timestamp,
                'enddate'    => now()->timestamp,
            ]);

        if ($response->failed() || $response->json('status') !== 0) {
            return response()->json(['error' => 'Failed to fetch data from Withings'], 502);
        }

        $groups   = $response->json('body.measuregrps') ?? [];
        $heightM  = (float) env('WITHINGS_USER_HEIGHT_M', 1.87);
        $imported = 0;

        foreach ($groups as $group) {
            $date     = date('Y-m-d', $group['date']);
            $measures = collect($group['measures'])->keyBy('type');

            $weightKg = null;
            $fatPct   = null;

            if (isset($measures[1])) {
                $m        = $measures[1];
                $weightKg = round($m['value'] * (10 ** $m['unit']), 2);
            }

            if (isset($measures[6])) {
                $m      = $measures[6];
                $fatPct = round($m['value'] * (10 ** $m['unit']), 1);
            }

            // BMI calculated from weight + fixed height (stored in .env)
            $bmi = $weightKg ? round($weightKg / ($heightM ** 2), 1) : null;

            BodyMetric::updateOrCreate(
                ['user_id' => $user->id, 'measured_at' => $date],
                [
                    'weight_kg'        => $weightKg,
                    'fat_percentage'   => $fatPct,
                    'bmi'              => $bmi,
                    'withings_group_id' => $group['grpid'],
                ]
            );

            $imported++;
        }

        return response()->json([
            'message' => "Synced {$imported} measurements.",
            'count'   => $imported,
        ]);
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    /**
     * Return all body metrics for the authenticated user, oldest first
     * (so charts can plot chronologically without client-side sorting).
     */
    public function index(Request $request): JsonResponse
    {
        $metrics = BodyMetric::where('user_id', $request->user()->id)
            ->orderBy('measured_at', 'asc')
            ->get(['measured_at', 'weight_kg', 'fat_percentage', 'bmi']);

        return response()->json($metrics);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function refreshToken(User $user): bool
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'action'          => 'requesttoken',
            'grant_type'      => 'refresh_token',
            'client_id'       => env('WITHINGS_CLIENT_ID'),
            'client_secret'   => env('WITHINGS_CLIENT_SECRET'),
            'refresh_token'   => $user->withings_refresh_token,
        ]);

        if ($response->failed() || $response->json('status') !== 0) {
            return false;
        }

        $tokens = $response->json('body');

        $user->update([
            'withings_access_token'     => $tokens['access_token'],
            'withings_refresh_token'    => $tokens['refresh_token'],
            'withings_token_expires_at' => now()->addSeconds((int) $tokens['expires_in'] - 60),
        ]);

        return true;
    }

    private function htmlResponse(string $heading, string $body, int $status = 200)
    {
        $html = <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
          <title>Withings — Brilliant Mind Moods</title>
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
