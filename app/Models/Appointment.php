<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'reminded' => 'boolean',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }
}
