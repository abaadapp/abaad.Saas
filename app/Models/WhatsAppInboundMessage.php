<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * رسالةٌ وصلت رقمَ المحلّ — دفترُ معالجةٍ لا صندوقُ وارد.
 *
 * لا نصَّ فيها ولا مرفق: ما يُحفظ هو ما يُجيب سؤالين لا ثالثَ لهما —
 * «أعالجناها؟» و«أرددنا على هذا الرقم قريبًا؟». ومحادثاتُ زبائن المحلّ
 * ليست ممّا نخزّنه بلا حاجةٍ تُسمّى.
 */
class WhatsAppInboundMessage extends Model
{
    protected $table = 'whatsapp_inbound_messages';

    protected $guarded = [];

    protected $casts = [
        'received_at' => 'datetime',
        'replied_at' => 'datetime',
    ];

    /** رُدّ عليها فعلًا */
    public const SENT = 'sent';

    /** لم يُردّ — والسببُ في `reply_reason` */
    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    public function connection(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConnection::class, 'whatsapp_connection_id');
    }
}
