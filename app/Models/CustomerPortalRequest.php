<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** جهازٌ ينتظر الاعتماد في بوابة عملاء RIBBON — انظر `RibbonPortal\Devices` */
class CustomerPortalRequest extends Model
{
    protected $guarded = [];

    protected $hidden = ['token_hash', 'phone_hash'];

    protected $casts = ['tries' => 'integer', 'expires_at' => 'datetime', 'approved_at' => 'datetime', 'closed_at' => 'datetime'];

    /** من اعتمده — ولو تركَ العمل */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by')->withTrashed();
    }
}
