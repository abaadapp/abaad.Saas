<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftMovement extends Model
{
    public const IN = 'in';

    public const OUT = 'out';

    protected $guarded = [];

    protected $casts = ['amount' => 'float'];

    public function shift(): BelongsTo { return $this->belongsTo(Shift::class); }

    /** موجبٌ للإيداع وسالبٌ للسحب — أثرها على ما في الدرج */
    public function signed(): float
    {
        return $this->type === self::OUT ? -$this->amount : $this->amount;
    }
}
