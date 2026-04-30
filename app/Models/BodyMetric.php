<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BodyMetric extends Model
{
    protected $fillable = [
        'user_id',
        'measured_at',
        'weight_kg',
        'fat_percentage',
        'bmi',
        'withings_group_id',
    ];

    protected $casts = [
        'measured_at'    => 'date:Y-m-d',
        'weight_kg'      => 'float',
        'fat_percentage' => 'float',
        'bmi'            => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
