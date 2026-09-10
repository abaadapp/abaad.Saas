<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * رسالةٌ في محادثةِ دعم — من المتجر، أو من أبعاد، أو من النظام.
 *
 * و`is_internal` تفصل ملاحظةَ الفريق عن الردّ: الأولى لا تُعرض لصاحب
 * المتجر ولا تُشعره ولا تخرج في قناةٍ خارجيّة.
 */
class SupportMessage extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_internal' => 'boolean',
        'event_meta' => 'array',
        'external_meta' => 'array',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SupportConversation::class, 'conversation_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SupportAttachment::class, 'message_id');
    }
}
