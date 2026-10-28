<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * محادثةُ زبونٍ مع المحلّ على رقمٍ بعينه — انظر `App\Support\WhatsAppInbox`.
 *
 * وهويّتُها المتجر + رقمُ المحلّ (`phone_number_id`) + رقمُ الزبون. لا صفُّ
 * الوصلة: الوصلةُ صفٌّ واحدٌ يُحدَّث عند كلّ ربطٍ ولو برقمٍ آخر، فمحادثةٌ على
 * الرقم القديم تبقى عليه — تُقرأ ولا يُردّ عليها من رقمٍ غيره.
 */
class WhatsAppChat extends Model
{
    protected $table = 'whatsapp_chats';

    protected $guarded = [];

    protected $casts = [
        'last_inbound_at' => 'datetime',
        'last_message_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConnection::class, 'whatsapp_connection_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsAppChatMessage::class, 'chat_id');
    }
}
