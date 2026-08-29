<?php

namespace App\Models;

use App\Support\WhatsAppStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سجلّ الرسائل — دفتر الحقيقة الذي يُدقَّق.
 *
 * لكلّ رسالةٍ صفٌّ حتى لو لم تخرج: الممنوعة تُكتب بسببها، والمرفوضة بخطئها.
 * وبدون ذلك يصير جواب «لماذا لم تصل رسالة زبوني؟» تخمينًا — وهو أكثر سؤالٍ
 * يُسأل في نظام إشعارات.
 */
class WhatsAppMessage extends Model
{
    protected $table = 'whatsapp_messages';

    protected $guarded = [];

    protected $casts = [
        'quota_consumed' => 'boolean',
        'metadata' => 'array',
        'queued_at' => 'datetime',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConnection::class, 'whatsapp_connection_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, WhatsAppStatus::TERMINAL, true);
    }
}
