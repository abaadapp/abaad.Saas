<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ‏‏قالبُ عرضٍ كتبه التاجر وأُرسل إلى Meta لمراجعته (`App\Support\WhatsAppCampaignTemplates`).
 */
class WhatsAppCampaignTemplate extends Model
{
    public const APPROVED = 'APPROVED';

    public const PENDING = 'PENDING';

    protected $table = 'whatsapp_campaign_templates';

    protected $guarded = [];

    protected $casts = [
        'variables' => 'array',
        'button' => 'array',
        'checked_at' => 'datetime',
    ];
}
