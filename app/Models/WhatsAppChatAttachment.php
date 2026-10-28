<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ملفٌّ على رسالة محادثة — صورةٌ أو PDF، واسمُه المخزَّن عشوائيّ (`WhatsAppInbox::keep`) */
class WhatsAppChatAttachment extends Model
{
    protected $table = 'whatsapp_chat_attachments';

    protected $guarded = [];

    public function message(): BelongsTo
    {
        return $this->belongsTo(WhatsAppChatMessage::class, 'message_id');
    }
}
