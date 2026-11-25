<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ‏‏متجرٌ واحد يُرسل قبل الإثبات ليُرى على من تظهر رسومُه — انظر `App\Support\WhatsAppBillingTrials` */
class WhatsAppBillingTrial extends Model
{
    protected $table = 'whatsapp_billing_trials';

    protected $guarded = [];

    protected $casts = [
        'message_limit' => 'integer',
        'messages_used' => 'integer',
        'proof' => 'array',
        'opened_at' => 'datetime',
        'expires_at' => 'datetime',
        'closed_at' => 'datetime',
        'passed_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    public const PASSED = 'passed';

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
