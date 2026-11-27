<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ‏‏رقمٌ في حملة — خرجت إليه رسالتُها أو وصلت أو فشلت، وبأيّ سبب.
 */
class WhatsAppCampaignRecipient extends Model
{
    public const PENDING = 'pending';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const DELIVERED = 'delivered';

    public const READ = 'read';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    /** ‏‏ما قبلته Meta — وصل بعدُ أو لم يصل */
    public const OUT = [self::SENT, self::DELIVERED, self::READ];

    protected $table = 'whatsapp_campaign_recipients';

    protected $guarded = [];

    protected $casts = [
        'claimed_at' => 'datetime',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(WhatsAppCampaign::class, 'campaign_id');
    }
}
