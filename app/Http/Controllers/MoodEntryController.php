<?php

namespace App\Http\Controllers;

use App\Models\MoodEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MoodEntryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $entries = $request->user()
            ->moodEntries()
            ->orderBy('entry_date', 'desc')
            ->get();

        return response()->json($entries);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'score' => 'required|numeric|min:-5|max:5',
            'sleep_score' => 'required|numeric|min:0|max:8',
            'appetite_score' => 'required|numeric|min:0|max:8',
            'activity_score' => 'required|numeric|min:0|max:8',
            'interests_score' => 'required|numeric|min:0|max:8',
            'social_score' => 'required|numeric|min:0|max:8',
            'focus_score' => 'required|numeric|min:0|max:8',
            'diary' => 'nullable|string',
            'medication_unchanged' => 'boolean',
            'medications_snapshot' => 'nullable|array',
            'entry_date' => 'required|date',
        ]);

        $entry = $request->user()->moodEntries()->updateOrCreate(
            ['entry_date' => $data['entry_date']],
            $data
        );

        return response()->json($entry, 201);
    }

    public function update(Request $request, MoodEntry $moodEntry): JsonResponse
    {
        $this->authorise($request, $moodEntry);

        $data = $request->validate([
            'score' => 'sometimes|numeric|min:-5|max:5',
            'sleep_score' => 'sometimes|numeric|min:0|max:8',
            'appetite_score' => 'sometimes|numeric|min:0|max:8',
            'activity_score' => 'sometimes|numeric|min:0|max:8',
            'interests_score' => 'sometimes|numeric|min:0|max:8',
            'social_score' => 'sometimes|numeric|min:0|max:8',
            'focus_score' => 'sometimes|numeric|min:0|max:8',
            'diary' => 'nullable|string',
            'medication_unchanged' => 'boolean',
            'medications_snapshot' => 'nullable|array',
        ]);

        $moodEntry->update($data);

        return response()->json($moodEntry);
    }

    public function destroy(Request $request, MoodEntry $moodEntry): JsonResponse
    {
        $this->authorise($request, $moodEntry);
        $moodEntry->delete();

        return response()->json(['message' => 'Deleted']);
    }

    private function authorise(Request $request, MoodEntry $entry): void
    {
        abort_if($entry->user_id !== $request->user()->id, 403);
    }
}
