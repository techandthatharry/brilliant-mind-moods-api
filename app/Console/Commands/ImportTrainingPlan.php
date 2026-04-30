<?php

namespace App\Console\Commands;

use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Console\Command;

class ImportTrainingPlan extends Command
{
    protected $signature = 'training:import {file} {--email= : User email to assign sessions to}';
    protected $description = 'Import a training plan CSV into the database';

    public function handle(): int
    {
        $file = $this->argument('file');

        if (! file_exists($file)) {
            $this->error("File not found: {$file}");
            return 1;
        }

        $email = $this->option('email') ?? env('DEV_LOGIN_EMAIL', 'harry@techandthat.com');
        $user  = User::where('email', $email)->first();

        if (! $user) {
            $this->error("User not found: {$email}");
            return 1;
        }

        $this->info("Importing for user: {$user->email} (id={$user->id})");

        $handle   = fopen($file, 'r');
        $imported = 0;
        $skipped  = 0;
        $header   = true;

        while (($row = fgetcsv($handle)) !== false) {
            // Skip header
            if ($header) { $header = false; continue; }
            if (count($row) < 4) { $skipped++; continue; }

            [$rawDate, $phase, $focus, $details] = $row;

            // Parse date format: "Thursday, 5 Mar 2026"
            $date = date_create_from_format('l, j M Y', trim($rawDate));
            if (! $date) {
                $this->warn("Could not parse date: {$rawDate}");
                $skipped++;
                continue;
            }

            TrainingSession::updateOrCreate(
                ['user_id' => $user->id, 'session_date' => $date->format('Y-m-d')],
                [
                    'phase'   => trim($phase),
                    'focus'   => trim($focus),
                    'details' => trim($details),
                ]
            );

            $imported++;
        }

        fclose($handle);

        $this->info("✅ Imported {$imported} sessions, skipped {$skipped}.");
        return 0;
    }
}
