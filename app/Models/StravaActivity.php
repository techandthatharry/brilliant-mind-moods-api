<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StravaActivity extends Model
{
    protected $fillable = [
        'user_id',
        'strava_id',
        'name',
        'sport_type',
        'is_indoor',
        'distance_metres',
        'moving_time_seconds',
        'elapsed_time_seconds',
        'start_date',
        'average_heartrate',
        'average_speed_mps',
        'total_elevation_gain',
        'suffer_score',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'is_indoor'  => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Formatted array sent to the Flutter app and to Gemini. */
    public function toApiArray(): array
    {
        return [
            'id'                    => $this->id,
            'strava_id'             => $this->strava_id,
            'name'                  => $this->name,
            'sport_type'            => $this->sport_type,
            'is_indoor'             => $this->is_indoor,
            'distance_metres'       => $this->distance_metres,
            'moving_time_seconds'   => $this->moving_time_seconds,
            'elapsed_time_seconds'  => $this->elapsed_time_seconds,
            'start_date'            => $this->start_date->toIso8601String(),
            'average_heartrate'     => $this->average_heartrate,
            'average_speed_mps'     => $this->average_speed_mps,
            'total_elevation_gain'  => $this->total_elevation_gain,
            'suffer_score'          => $this->suffer_score,
        ];
    }
}
