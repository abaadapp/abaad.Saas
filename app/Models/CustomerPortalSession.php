<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** جلسةُ عميلٍ في البوابة — ليست جلسةَ النظام ولا حارسَه. انظر `RibbonPortal\Access` */
class CustomerPortalSession extends Model
{
    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected $casts = ['last_seen_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function link(): BelongsTo
    {
        return $this->belongsTo(CustomerPortalLink::class, 'link_id');
    }
}
