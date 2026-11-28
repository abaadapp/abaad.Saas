<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ‏‏جهةُ اتّصالٍ من تطبيق واتساب للأعمال عند التاجر — تصل بمزامنة التعايش (`WhatsAppCoexistence::contacts`).
 */
class WhatsAppContact extends Model
{
    protected $table = 'whatsapp_contacts';

    protected $guarded = [];

    protected $casts = [
        'removed_at' => 'datetime',
        'synced_at' => 'datetime',
    ];
}
