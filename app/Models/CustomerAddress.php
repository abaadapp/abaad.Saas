<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAddress extends Model
{
    protected $guarded = [];

    protected $casts = ['is_default' => 'boolean'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** سطر واحد للعرض: «مسقط - الخوير، شارع 18 نوفمبر» */
    public function getLineAttribute(): string
    {
        return collect([$this->city, $this->area])->filter()->implode(' - ')
            . ($this->street ? '، ' . $this->street : '');
    }
}
