<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strava_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Strava's own ID — unique per user
            $table->bigInteger('strava_id')->unsigned();
            $table->unique(['user_id', 'strava_id']);

            $table->string('name');

            // e.g. Run, VirtualRun, Ride, VirtualRide, WeightTraining
            $table->string('sport_type', 60);
            $table->boolean('is_indoor')->default(false);

            // Distance in metres (null / 0 for weightlifting)
            $table->float('distance_metres')->nullable();

            // Times in seconds
            $table->unsignedInteger('moving_time_seconds')->default(0);
            $table->unsignedInteger('elapsed_time_seconds')->default(0);

            $table->dateTime('start_date');             // stored as UTC

            // Optional stats (absent when HR monitor not worn, etc.)
            $table->float('average_heartrate')->nullable();
            $table->float('average_speed_mps')->nullable(); // metres per second → converted to pace in app
            $table->float('total_elevation_gain')->nullable();
            $table->unsignedSmallInteger('suffer_score')->nullable(); // Strava Relative Effort

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strava_activities');
    }
};
