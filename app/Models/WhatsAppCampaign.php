<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ‏‏حملةُ واتساب — رسالةُ عرضٍ إلى عملاء المتجر من رقمه (`App\Support\WhatsAppCampaigns`).
 */
class WhatsAppCampaign extends Model
{
    public const SCHEDULED = 'scheduled';

    public const SENDING = 'sending';

    public const PAUSED = 'paused';

    public const DONE = 'done';

    public const CANCELLED = 'cancelled';

    /** ‏‏ما لم ينتهِ بعد — يُوقف ويُلغى */
    public const OPEN = [self::SCHEDULED, self::SENDING, self::PAUSED];

    protected $table = 'whatsapp_campaigns';

    protected $guarded = [];

    protected $casts = [
        'message' => 'array',
        'audience' => 'array',
        'stats' => 'array',
        'total' => 'integer',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'last_run_at' => 'datetime',
        'consent_at' => 'datetime',
    ];

    public function recipients(): HasMany
    {
        return $this->hasMany(WhatsAppCampaignRecipient::class, 'campaign_id');
    }
}
