<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** آخرُ رسالةٍ قرأها موظّفٌ في محادثة — ومنه يُحسب «غير المقروء» له وحده */
class WhatsAppChatRead extends Model
{
    protected $table = 'whatsapp_chat_reads';

    protected $guarded = [];
}
