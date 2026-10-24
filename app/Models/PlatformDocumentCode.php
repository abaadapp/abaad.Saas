<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * رمزُ تحقّقٍ أُرسل إلى هاتف العميل قبل التوقيع — بصمتُه لا هو.
 *
 * انظر `SigningCodes`: من يُصدره ويتحقّق منه ويستهلكه.
 */
class PlatformDocumentCode extends Model
{
    protected $guarded = [];

    protected $hidden = ['code_hash', 'grant_hash'];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
        'consumed_at' => 'datetime',
        'revoked_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(PlatformDocument::class, 'document_id');
    }
}
