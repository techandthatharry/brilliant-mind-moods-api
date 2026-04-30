<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingSession extends Model
{
    protected $fillable = [
        'user_id',
        'session_date',
        'phase',
        'focus',
        'details',
        'is_completed',
        'completed_at',
        'notes',
    ];

    protected $casts = [
        'session_date' => 'date:Y-m-d',
        'is_completed' => 'boolean',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
