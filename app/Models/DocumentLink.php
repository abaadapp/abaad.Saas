<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** رمزُ ورقةٍ في العنوان — انظر App\Support\PublicDocument */
class DocumentLink extends Model
{
    protected $guarded = [];

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
