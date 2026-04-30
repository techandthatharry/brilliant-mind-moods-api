<?php

namespace App\Http\Controllers;

use App\Models\TrainingSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class TrainingController extends Controller
{
    // ── Today's session ───────────────────────────────────────────────────────

    public function today(Request $request): JsonResponse
    {
        $session = TrainingSession::where('user_id', $request->user()->id)
            ->where('session_date', today()->toDateString())
            ->first();

        return response()->json($session);
    }

    // ── Upcoming sessions (next N days) ────────────────────────────────────

    public function upcoming(Request $request): JsonResponse
    {
        $days = (int) $request->query('days', 7);

        $sessions = TrainingSession::where('user_id', $request->user()->id)
            ->whereBetween('session_date', [
                today()->toDateString(),
                today()->addDays($days)->toDateString(),
            ])
            ->orderBy('session_date')
            ->get();

        return response()->json($sessions);
    }

    // ── Toggle complete ────────────────────────────────────────────────────

    public function toggleComplete(Request $request, TrainingSession $trainingSession): JsonResponse
    {
        $this->authorise($request, $trainingSession);

        $trainingSession->update([
            'is_completed' => ! $trainingSession->is_completed,
            'completed_at' => ! $trainingSession->is_completed ? now() : null,
        ]);

        return response()->json($trainingSession->fresh());
    }

    // ── Update a single session (used by Gemini chat) ─────────────────────

    public function updateSession(Request $request, TrainingSession $trainingSession): JsonResponse
    {
        $this->authorise($request, $trainingSession);

        $validated = $request->validate([
            'phase'   => 'sometimes|string|max:255',
            'focus'   => 'sometimes|string|max:255',
            'details' => 'sometimes|string',
            'notes'   => 'sometimes|nullable|string',
        ]);

        $trainingSession->update($validated);

        return response()->json($trainingSession->fresh());
    }

    // ── Gemini chat ───────────────────────────────────────────────────────────

    /**
     * Send a message to Gemini with the current training plan context.
     * Gemini can optionally return structured changes to apply to the plan.
     *
     * Request body:
     *   message      string   The user's message
     *   history      array    Conversation history [{role, text}]
     */
    public function chat(Request $request): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:2000',
            'history' => 'nullable|array',
        ]);

        $apiKey = env('GEMINI_API_KEY');
        if (! $apiKey) {
            return response()->json(['error' => 'Gemini API key not configured.'], 503);
        }

        // Build plan context: next 21 days + past 3 days (keep token count manageable)
        $sessions = TrainingSession::where('user_id', $request->user()->id)
            ->whereBetween('session_date', [
                today()->subDays(3)->toDateString(),
                today()->addDays(21)->toDateString(),
            ])
            ->orderBy('session_date')
            ->get(['id', 'session_date', 'phase', 'focus', 'details', 'is_completed'])
            ->map(fn ($s) => [
                'id'           => $s->id,
                'date'         => $s->session_date->format('Y-m-d'),
                'day'          => $s->session_date->format('l'),
                'phase'        => $s->phase,
                'focus'        => $s->focus,
                'details'      => $s->details,
                'is_completed' => $s->is_completed,
            ]);

        $planJson = json_encode($sessions, JSON_PRETTY_PRINT);
        $today    = today()->format('l, j F Y');

        $systemPrompt = <<<PROMPT
You are Harry's personal training coach assistant, embedded in his Brilliant Mind Moods health app.
Today is {$today}.

Harry's current training plan (next 30 days + recent 7 days) is:
{$planJson}

You can answer questions about the plan, suggest modifications, and update sessions.

When the user asks you to change, reschedule, replace, or update any sessions, you MUST include
a JSON block at the END of your response (after your conversational reply) in this exact format:
<plan_changes>
[
  {
    "id": 123,
    "focus": "New focus text",
    "details": "New details text"
  }
]
</plan_changes>

Only include the <plan_changes> block when there are actual changes to make.
Keep the conversational part friendly, motivating, and concise.
PROMPT;

        // Build Gemini contents array (conversation history + new message)
        $contents = [];

        foreach (($request->history ?? []) as $turn) {
            $contents[] = [
                'role'  => $turn['role'] === 'user' ? 'user' : 'model',
                'parts' => [['text' => $turn['text']]],
            ];
        }

        $contents[] = [
            'role'  => 'user',
            'parts' => [['text' => $request->message]],
        ];

        $payload = [
            'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents'           => $contents,
            'generationConfig'   => [
                'temperature'     => 0.7,
                'maxOutputTokens' => 1024,
            ],
        ];

        $response = Http::withHeaders(['Content-Type' => 'application/json'])
            ->timeout(30)
            ->post(
                "https://generativelanguage.googleapis.com/v1/models/gemini-1.5-flash:generateContent?key={$apiKey}",
                $payload
            );

        if ($response->failed()) {
            $status = $response->status();
            $body   = $response->json();

            // Surface the real Gemini error so it's easy to diagnose
            $geminiMessage = data_get($body, 'error.message')
                ?? data_get($body, 'error.status')
                ?? $response->body();

            return response()->json([
                'error' => "Gemini error ({$status}): {$geminiMessage}",
            ], ($status >= 400 && $status < 600) ? $status : 502);
        }

        $text = data_get($response->json(), 'candidates.0.content.parts.0.text', '');

        // Extract and apply any plan changes
        $appliedChanges = [];
        if (preg_match('/<plan_changes>(.*?)<\/plan_changes>/s', $text, $matches)) {
            $changesJson = trim($matches[1]);
            $changes     = json_decode($changesJson, true);

            if (is_array($changes)) {
                foreach ($changes as $change) {
                    if (! isset($change['id'])) continue;

                    $session = TrainingSession::where('user_id', $request->user()->id)
                        ->find((int) $change['id']);

                    if ($session) {
                        $update = array_filter([
                            'focus'   => $change['focus']   ?? null,
                            'details' => $change['details'] ?? null,
                            'phase'   => $change['phase']   ?? null,
                        ], fn ($v) => $v !== null);

                        if (! empty($update)) {
                            $session->update($update);
                            $appliedChanges[] = $session->fresh();
                        }
                    }
                }
            }
        }

        // Strip the <plan_changes> block from the reply shown to the user
        $cleanReply = trim(preg_replace('/<plan_changes>.*?<\/plan_changes>/s', '', $text));

        return response()->json([
            'reply'          => $cleanReply,
            'applied_changes' => $appliedChanges,
        ]);
    }

    // ── CSV import (dev/admin only) ────────────────────────────────────────

    /**
     * One-time import of the training plan CSV.
     * POST /api/training/import
     * Body: { "csv": "<raw csv content>", "clear_existing": true }
     */
    public function importCsv(Request $request): JsonResponse
    {
        abort_unless(app()->environment('local'), 403);

        $request->validate(['csv' => 'required|string']);

        $userId  = $request->user()->id;
        $csv     = $request->input('csv');
        $lines   = explode("\n", str_replace("\r\n", "\n", trim($csv)));
        $imported = 0;
        $skipped  = 0;

        // Skip header row
        array_shift($lines);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Parse CSV — handle quoted fields with commas inside
            $fields = str_getcsv($line);
            if (count($fields) < 4) { $skipped++; continue; }

            [$rawDate, $phase, $focus, $details] = $fields;

            // Parse date like "Thursday, 5 Mar 2026"
            $date = date_create_from_format('l, j M Y', trim($rawDate));
            if (! $date) { $skipped++; continue; }

            TrainingSession::updateOrCreate(
                ['user_id' => $userId, 'session_date' => $date->format('Y-m-d')],
                [
                    'phase'   => trim($phase),
                    'focus'   => trim($focus),
                    'details' => trim($details),
                ]
            );

            $imported++;
        }

        return response()->json([
            'message'  => "Imported {$imported} sessions, skipped {$skipped}.",
            'imported' => $imported,
            'skipped'  => $skipped,
        ]);
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function authorise(Request $request, TrainingSession $session): void
    {
        abort_if($session->user_id !== $request->user()->id, 403);
    }
}
