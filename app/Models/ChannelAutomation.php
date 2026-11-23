<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ‏‏أتمتةٌ كتبها التاجر لقناة — كلماتٌ إذا كتبها الزبون خرج الردّ وحده (`App\Support\ChannelAutomations`).
 */
class ChannelAutomation extends Model
{
    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const PAUSED = 'paused';

    public const STATUSES = [self::DRAFT, self::PUBLISHED, self::PAUSED];

    protected $guarded = [];

    protected $casts = [
        'keywords' => 'array',
        'excluded' => 'array',
        'buttons' => 'array',
        'cooldown_minutes' => 'integer',
        'published_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ChannelAutomationRun::class, 'automation_id');
    }
}
