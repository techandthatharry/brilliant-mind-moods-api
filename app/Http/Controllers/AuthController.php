<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AuthController extends Controller
{
    /**
     * Exchange a Firebase ID token for a Sanctum API token.
     *
     * The Flutter app now uses FirebaseAuth.signInWithProvider which issues a
     * Firebase ID token (not a raw Google ID token), so we verify it against
     * the Firebase Identity Toolkit rather than Google's tokeninfo endpoint.
     */
    public function googleCallback(Request $request): JsonResponse
    {
        $request->validate(['id_token' => 'required|string']);

        // Verify the Firebase ID token via the Identity Toolkit accounts:lookup API.
        $firebaseApiKey = env('FIREBASE_API_KEY');
        $response = Http::post(
            "https://identitytoolkit.googleapis.com/v1/accounts:lookup?key={$firebaseApiKey}",
            ['idToken' => $request->id_token]
        );

        if ($response->failed() || empty($response->json('users'))) {
            return response()->json(['error' => 'Invalid or expired Firebase token'], 401);
        }

        $payload = $response->json('users')[0];
        // Identity Toolkit returns: localId (Firebase UID), email, displayName, photoUrl

        $user = User::updateOrCreate(
            ['google_id' => $payload['localId']],
            [
                'name'   => $payload['displayName'] ?? $payload['email'],
                'email'  => $payload['email'],
                'avatar' => $payload['photoUrl'] ?? null,
            ]
        );

        $token = $user->createToken('flutter-app')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id'     => $user->id,
                'name'   => $user->name,
                'email'  => $user->email,
                'avatar' => $user->avatar,
            ],
        ]);
    }

    /**
     * Dev-only login — creates/returns a test user without Google OAuth.
     * Only available when APP_ENV=local.
     */
    public function devLogin(): JsonResponse
    {
        abort_unless(app()->environment('local'), 403);

        $devEmail = env('DEV_LOGIN_EMAIL', 'dev@brilliantmindmoods.test');

        // Look up by email only — avoids google_id unique-constraint collisions
        // when switching DEV_LOGIN_EMAIL after a previous dev user already exists.
        $user = User::where('email', $devEmail)->first();

        if (!$user) {
            $user = User::create([
                'email'     => $devEmail,
                'name'      => $devEmail,
                'google_id' => 'dev-' . md5($devEmail),
                'avatar'    => null,
            ]);
        }

        $token = $user->createToken('flutter-dev')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar,
            ],
        ]);
    }

    /**
     * Return the authenticated user's profile.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }

    /**
     * Revoke current token (logout).
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out']);
    }

    /**
     * Permanently delete account and all data.
     */
    public function deleteAccount(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();
        $request->user()->delete();

        return response()->json(['message' => 'Account deleted']);
    }
}
