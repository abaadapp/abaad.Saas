<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ‏‏رقمٌ رُبط وينتظر أن يصير رقمَ إرسال متجره، أو صار — انظر `App\Support\WhatsAppSender` */
class WhatsAppSenderAdoption extends Model
{
    protected $table = 'whatsapp_sender_adoptions';

    protected $guarded = [];

    protected $casts = [
        'checks' => 'array',
        'health_params' => 'array',
        'attempts' => 'integer',
        'health_seen' => 'integer',
        'checked_at' => 'datetime',
        'next_check_at' => 'datetime',
        'adopted_at' => 'datetime',
        'closed_at' => 'datetime',
        'health_since' => 'datetime',
        'health_checked_at' => 'datetime',
    ];

    /** ‏‏رُبط، ويُتحقَّق من جاهزيّته */
    public const CHECKING = 'checking';

    /** ‏‏صار رقمَ إرسال متجره */
    public const ADOPTED = 'adopted';

    /** ‏‏لم يعد يُنظر فيه — فُصل، أو حلّ ربطٌ محلَّه، أو اختار التاجر غيره */
    public const CLOSED = 'closed';

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConnection::class, 'whatsapp_connection_id');
    }
}
