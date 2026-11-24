<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ‏‏رابطُ ربط واتساب أرسله فريقُ أبعاد لصاحب متجر — انظر `App\Support\WhatsAppClientLinks` */
class WhatsAppClientLink extends Model
{
    protected $table = 'whatsapp_client_links';

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'expires_at' => 'datetime',
        'opened_at' => 'datetime',
        'connected_at' => 'datetime',
        'revoked_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConnection::class, 'whatsapp_connection_id');
    }
}
