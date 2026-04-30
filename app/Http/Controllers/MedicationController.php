<?php

namespace App\Http\Controllers;

use App\Models\Medication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MedicationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $medications = $request->user()
            ->medications()
            ->where('active', true)
            ->orderBy('name')
            ->get();

        return response()->json($medications);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'dosage' => 'nullable|string|max:100',
        ]);

        $medication = $request->user()->medications()->create($data);

        return response()->json($medication, 201);
    }

    public function destroy(Request $request, Medication $medication): JsonResponse
    {
        abort_if($medication->user_id !== $request->user()->id, 403);
        $medication->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
