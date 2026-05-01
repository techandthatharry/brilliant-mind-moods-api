<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('strava_access_token',  512)->nullable()->after('todoist_api_token');
            $table->string('strava_refresh_token', 512)->nullable()->after('strava_access_token');
            $table->timestamp('strava_token_expires_at')->nullable()->after('strava_refresh_token');
            $table->bigInteger('strava_athlete_id')->unsigned()->nullable()->after('strava_token_expires_at');
            $table->timestamp('strava_last_synced_at')->nullable()->after('strava_athlete_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'strava_access_token',
                'strava_refresh_token',
                'strava_token_expires_at',
                'strava_athlete_id',
                'strava_last_synced_at',
            ]);
        });
    }
};
