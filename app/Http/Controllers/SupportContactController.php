<?php

namespace App\Http\Controllers;

use App\Models\SupportContact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupportContactController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $request->user()->supportContacts()->orderBy('name')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:30',
            'is_aware' => 'boolean',
            'share_reports' => 'boolean',
        ]);

        $contact = $request->user()->supportContacts()->create($data);

        return response()->json($contact, 201);
    }

    public function update(Request $request, SupportContact $supportContact): JsonResponse
    {
        abort_if($supportContact->user_id !== $request->user()->id, 403);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:30',
            'is_aware' => 'boolean',
            'share_reports' => 'boolean',
        ]);

        $supportContact->update($data);

        return response()->json($supportContact);
    }

    public function destroy(Request $request, SupportContact $supportContact): JsonResponse
    {
        abort_if($supportContact->user_id !== $request->user()->id, 403);
        $supportContact->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
