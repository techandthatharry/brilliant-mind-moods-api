<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MoodEntry extends Model
{
    protected $fillable = [
        'user_id',
        'score',
        'sleep_score',
        'appetite_score',
        'activity_score',
        'interests_score',
        'social_score',
        'focus_score',
        'diary',
        'medication_unchanged',
        'medications_snapshot',
        'entry_date',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'sleep_score' => 'float',
            'appetite_score' => 'float',
            'activity_score' => 'float',
            'interests_score' => 'float',
            'social_score' => 'float',
            'focus_score' => 'float',
            'medication_unchanged' => 'boolean',
            'medications_snapshot' => 'array',
            'entry_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
