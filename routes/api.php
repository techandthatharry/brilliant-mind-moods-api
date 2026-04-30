<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\MedicationController;
use App\Http\Controllers\MoodEntryController;
use App\Http\Controllers\SupportContactController;
use App\Http\Controllers\TodoistController;
use App\Http\Controllers\TrainingController;
use App\Http\Controllers\WithingsController;
use Illuminate\Support\Facades\Route;

// Public — exchange Google ID token for Sanctum token
Route::post('/auth/google', [AuthController::class, 'googleCallback']);

// Dev-only bypass — returns a token for a test account (local environment only)
if (app()->environment('local')) {
    Route::post('/auth/dev-login', [AuthController::class, 'devLogin']);
}

// Protected
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::delete('/account', [AuthController::class, 'deleteAccount']);

    // Mood entries
    Route::get('/mood-entries', [MoodEntryController::class, 'index']);
    Route::post('/mood-entries', [MoodEntryController::class, 'store']);
    Route::put('/mood-entries/{moodEntry}', [MoodEntryController::class, 'update']);
    Route::delete('/mood-entries/{moodEntry}', [MoodEntryController::class, 'destroy']);

    // Medications
    Route::get('/medications', [MedicationController::class, 'index']);
    Route::post('/medications', [MedicationController::class, 'store']);
    Route::delete('/medications/{medication}', [MedicationController::class, 'destroy']);

    // Support contacts
    Route::get('/support-contacts', [SupportContactController::class, 'index']);
    Route::post('/support-contacts', [SupportContactController::class, 'store']);
    Route::put('/support-contacts/{supportContact}', [SupportContactController::class, 'update']);
    Route::delete('/support-contacts/{supportContact}', [SupportContactController::class, 'destroy']);

    // Training plan
    Route::get('/training/today',                    [TrainingController::class, 'today']);
    Route::get('/training/upcoming',                 [TrainingController::class, 'upcoming']);
    Route::patch('/training/{trainingSession}/complete', [TrainingController::class, 'toggleComplete']);
    Route::patch('/training/{trainingSession}',      [TrainingController::class, 'updateSession']);
    Route::post('/training/chat',                    [TrainingController::class, 'chat']);
    if (app()->environment('local')) {
        Route::post('/training/import', [TrainingController::class, 'importCsv']);
    }

    // Todoist
    Route::get('/todoist/status',              [TodoistController::class, 'status']);
    Route::post('/todoist/token',              [TodoistController::class, 'saveToken']);
    Route::get('/todoist/projects',            [TodoistController::class, 'getProjects']);
    Route::get('/todoist/tasks',               [TodoistController::class, 'getTasks']);
    Route::get('/todoist/tasks/date',          [TodoistController::class, 'getTasksForDate']);
    Route::post('/todoist/tasks/{taskId}/complete', [TodoistController::class, 'completeTask']);
    Route::post('/todoist/tasks/{taskId}/reopen',   [TodoistController::class, 'reopenTask']);
    Route::post('/todoist/tasks',              [TodoistController::class, 'createTask']);

    // Withings / body metrics
    Route::get('/withings/auth-url', [WithingsController::class, 'authUrl']);
    Route::get('/withings/status',   [WithingsController::class, 'status']);
    Route::post('/withings/sync',    [WithingsController::class, 'sync']);
    Route::get('/body-metrics',      [WithingsController::class, 'index']);
});

// Withings OAuth callback — public, called by browser redirect (no Sanctum auth)
Route::get('/withings/callback', [WithingsController::class, 'callback']);
