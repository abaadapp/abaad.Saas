<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * رسالةُ واتساب في خيط عميلٍ محتمَل — واردةً أو صادرة.
 *
 * وهي **ليست** رسالةَ متجرٍ لزبونه: تلك في `whatsapp_messages` تحت
 * `business_id`، ولا سبيلَ من هنا إليها — انظر `CrmStaysOutOfTenantDataTest`.
 */
class CrmMessage extends Model
{
    protected $table = 'crm_messages';

    protected $guarded = [];

    public const IN = 'in';

    public const OUT = 'out';

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'lead_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
