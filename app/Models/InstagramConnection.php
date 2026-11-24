<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ‏‏حسابُ إنستقرام احترافيّ ربطه متجر — واحدٌ لكلّ متجر، ولا يُربط حسابٌ بمتجرين (`App\Support\InstagramLink`).
 */
class InstagramConnection extends Model
{
    public const ACTIVE = 'active';

    /** ‏‏رمزٌ انتهى أو أُلغي من إنستقرام — يعيد التاجر الربط */
    public const NEEDS_RELINK = 'needs_relink';

    /** ‏‏فصله التاجر أو ألغى إذن أبعاد من إنستقرام — لا رمزَ فيه */
    public const REVOKED = 'revoked';

    protected $guarded = [];

    protected $casts = [
        'access_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'permissions' => 'array',
        'subscribed_fields' => 'array',
        'last_webhook_at' => 'datetime',
        'connected_at' => 'datetime',
        'refreshed_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected $hidden = ['access_token'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** ‏‏يعمل الآن: مربوطٌ ورمزُه حاضرٌ ولم تنقضِ أيّامُه */
    public function usable(): bool
    {
        return $this->status === self::ACTIVE
            && filled($this->access_token)
            && ($this->token_expires_at === null || $this->token_expires_at->isFuture());
    }
}
