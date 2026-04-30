<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportContact extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'phone',
        'is_aware',
        'share_reports',
    ];

    protected function casts(): array
    {
        return [
            'is_aware' => 'boolean',
            'share_reports' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
