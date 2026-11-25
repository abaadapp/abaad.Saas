<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** ‏‏سطرٌ لا يُعدَّل في سجلّ إثبات الفوترة — من فعل ماذا ومتى (`App\Support\WhatsAppBillingLock`) */
class WhatsAppBillingEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'whatsapp_billing_events';

    protected $guarded = [];

    protected $casts = ['data' => 'array', 'created_at' => 'datetime'];
}
