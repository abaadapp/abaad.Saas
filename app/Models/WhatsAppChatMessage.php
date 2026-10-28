<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * رسالةٌ في صندوق المحادثات — واردةٌ من الزبون أو ردٌّ من موظّف.
 *
 * ═══ سجلٌّ لا مسوّدة ═══
 *
 * من ردّ ومتى ومن أيّ رقمٍ وبماذا — يُكتب مرّةً ولا يُعدَّل ولا يُحذف. وما
 * يتبدّل بعدها حالُ التسليم وحدَه (أُرسلت ← سُلّمت ← قُرئت، أو فشلت) وتكتبه
 * ميتا لا الموظّف. فلا بابَ في الشاشة يعدّل ردًّا أو يمحوه، وهذا حارسٌ ثانٍ
 * خلفه: من يكتب `update` أو `delete` على صفٍّ منها في كودٍ قادم يُردّ.
 */
class WhatsAppChatMessage extends Model
{
    protected $table = 'whatsapp_chat_messages';

    protected $guarded = [];

    protected $casts = [
        'received_at' => 'datetime',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public const IN = 'in';

    public const OUT = 'out';

    public const RECEIVED = 'received';

    /** ما لا يتبدّل بعد الكتابة — هويّةُ الرسالة ونصُّها ومن كتبها */
    public const SEALED = [
        'business_id', 'chat_id', 'direction', 'body', 'media_type',
        'whatsapp_connection_id', 'phone_number_id', 'sender_user_id', 'sender_name',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $message) {
            /*
             * و`wamid` يُكتب مرّةً: فارغٌ قبل أن تقبل ميتا الرسالة، ثمّ معرّفُها.
             * وما سوى ذلك من الهويّة لا يتبدّل — لا مرسِلٌ ولا نصٌّ ولا رقم.
             */
            $sealed = array_values(array_filter(self::SEALED, fn ($c) => $message->isDirty($c)));

            if ($message->isDirty('wamid') && filled($message->getOriginal('wamid'))) {
                $sealed[] = 'wamid';
            }

            if ($sealed !== []) {
                throw new LogicException('رسالةُ المحادثة سجلٌّ لا يُعدَّل: '.implode(', ', $sealed));
            }
        });

        static::deleting(function () {
            throw new LogicException('رسالةُ المحادثة سجلٌّ لا يُحذف.');
        });
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(WhatsAppChat::class, 'chat_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(WhatsAppChatAttachment::class, 'message_id');
    }
}
