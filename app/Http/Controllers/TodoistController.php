<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class TodoistController extends Controller
{
    private const BASE      = 'https://api.todoist.com/api/v1';
    private const SYNC_BASE = 'https://api.todoist.com/sync/v9';

    // ── Helper: authenticated REST client ─────────────────────────────────────

    private function http(Request $request)
    {
        return Http::withToken($request->user()->todoist_api_token)->timeout(15);
    }

    // ── Helper: Todoist Sync API command ──────────────────────────────────────
    // The Sync API is more reliable for task state changes across all API key types.

    private function syncCommand(Request $request, string $type, array $args)
    {
        return Http::withToken($request->user()->todoist_api_token)
            ->timeout(15)
            ->asForm()
            ->post(self::SYNC_BASE . '/sync', [
                'commands' => json_encode([[
                    'type' => $type,
                    'uuid' => (string) \Illuminate\Support\Str::uuid(),
                    'args' => $args,
                ]]),
            ]);
    }

    // ── Connection status ─────────────────────────────────────────────────────

    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'connected' => ! empty($request->user()->todoist_api_token),
        ]);
    }

    // ── Save personal API token ───────────────────────────────────────────────

    public function saveToken(Request $request): JsonResponse
    {
        $request->validate(['token' => 'required|string']);

        // Verify the token works before saving
        $test = Http::withToken($request->token)
            ->timeout(10)
            ->get(self::BASE . '/projects');

        if ($test->failed()) {
            return response()->json([
                'error' => 'Token not accepted by Todoist — double-check it and try again.',
            ], 422);
        }

        $request->user()->update(['todoist_api_token' => $request->token]);

        return response()->json(['connected' => true]);
    }

    // ── Projects list ─────────────────────────────────────────────────────────

    public function getProjects(Request $request): JsonResponse
    {
        if (empty($request->user()->todoist_api_token)) {
            return response()->json([]);
        }

        $r = $this->http($request)->get(self::BASE . '/projects');

        if ($r->failed()) {
            return response()->json([], 502);
        }

        return response()->json($r->json('results') ?? $r->json());
    }

    // ── Today's tasks (today + overdue) ───────────────────────────────────────

    public function getTasks(Request $request): JsonResponse
    {
        if (empty($request->user()->todoist_api_token)) {
            return response()->json([]);
        }

        [$tasks, $projects] = $this->fetchTasksAndProjects($request, 'today | overdue');

        return response()->json(
            $this->enrichTasks($tasks, $projects)
        );
    }

    // ── Tasks for a specific date (used by calendar view) ────────────────────

    public function getTasksForDate(Request $request): JsonResponse
    {
        if (empty($request->user()->todoist_api_token)) {
            return response()->json([]);
        }

        $date = $request->query('date', today()->toDateString());

        [$tasks, $projects] = $this->fetchTasksAndProjects($request, "due: {$date}");

        return response()->json(
            $this->enrichTasks($tasks, $projects)
        );
    }

    // ── Complete a task ───────────────────────────────────────────────────────
    // Uses the Todoist Sync API v9 (item_close command) — more reliable than
    // the REST close endpoint which varies across API key types.

    public function completeTask(Request $request, string $taskId): JsonResponse
    {
        $r = $this->syncCommand($request, 'item_close', ['id' => $taskId]);

        if ($r->failed()) {
            $msg = $r->json('error') ?? $r->body();
            return response()->json(['error' => "Could not complete task: {$msg}"], 502);
        }

        return response()->json(['success' => true]);
    }

    // ── Reopen (undo complete) a task ─────────────────────────────────────────

    public function reopenTask(Request $request, string $taskId): JsonResponse
    {
        $r = $this->syncCommand($request, 'item_uncomplete', ['id' => $taskId]);

        if ($r->failed()) {
            $msg = $r->json('error') ?? $r->body();
            return response()->json(['error' => "Could not reopen task: {$msg}"], 502);
        }

        return response()->json(['success' => true]);
    }

    // ── Create a task ─────────────────────────────────────────────────────────

    public function createTask(Request $request): JsonResponse
    {
        $request->validate([
            'content'    => 'required|string|max:500',
            'project_id' => 'nullable|string',
            'due_date'   => 'nullable|date_format:Y-m-d',
            'priority'   => 'nullable|integer|between:1,4',
        ]);

        $data = ['content' => $request->content];

        if ($request->filled('project_id')) {
            $data['project_id'] = $request->project_id;
        }
        if ($request->filled('due_date')) {
            $data['due_date'] = $request->due_date;
        }
        if ($request->filled('priority')) {
            $data['priority'] = (int) $request->priority;
        }

        $r = $this->http($request)->post(self::BASE . '/tasks', $data);

        if ($r->failed()) {
            return response()->json(['error' => 'Could not create the task.'], 502);
        }

        $task = $r->json();

        // Enrich with project name
        $projects = $this->http($request)->get(self::BASE . '/projects');
        if ($projects->ok()) {
            $projectList = $projects->json('results') ?? $projects->json();
            $project = collect($projectList)->firstWhere('id', $task['project_id']);
            $task['project_name'] = $project['name'] ?? 'Inbox';
        } else {
            $task['project_name'] = 'Inbox';
        }

        return response()->json($task);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function fetchTasksAndProjects(Request $request, string $filter): array
    {
        $http = $this->http($request);

        $tasksResp    = $http->get(self::BASE . '/tasks',    ['filter' => $filter]);
        $projectsResp = $http->get(self::BASE . '/projects');

        // API v1 wraps list responses in {"results": [...]}
        $tasks    = collect($tasksResp->ok()    ? ($tasksResp->json('results')    ?? []) : []);
        $projects = collect($projectsResp->ok() ? ($projectsResp->json('results') ?? []) : [])->keyBy('id');

        return [$tasks, $projects];
    }

    private function enrichTasks(\Illuminate\Support\Collection $tasks, \Illuminate\Support\Collection $projects): \Illuminate\Support\Collection
    {
        return $tasks->map(function ($task) use ($projects) {
            $project = $projects->get($task['project_id'] ?? '');
            return array_merge($task, [
                'project_name' => $project['name'] ?? 'Inbox',
            ]);
        })->sortBy('child_order')->values();
    }
}
