<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** رابطُ دخولٍ شخصيٌّ إلى بوابة عملاء RIBBON — انظر `RibbonPortal\Access` */
class CustomerPortalLink extends Model
{
    protected $guarded = [];

    // لا يُكتب أيٌّ منها في ردٍّ ولا ملفّ
    protected $hidden = ['token_hash', 'nonce', 'phone_hash'];

    protected $casts = ['last_used_at' => 'datetime', 'revoked_at' => 'datetime'];

    /** العميلُ الحيّ وحده: المحذوفُ إلى السلّة لا بابَ له */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(CustomerPortalSession::class, 'link_id');
    }
}
