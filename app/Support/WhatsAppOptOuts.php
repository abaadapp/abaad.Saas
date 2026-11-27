<?php

namespace App\Support;

use App\Models\Business;
use App\Models\WhatsAppCampaignRecipient;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppInboundMessage;
use App\Models\WhatsAppOptOut;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ‏‏من طلب ألّا تصله عروضُ المتجر — وكيف يطلب، وما يترتّب على طلبه.
 *
 * ═══ أربعةُ طرق ═══
 *
 *  - زرُّ «إيقاف الرسائل» تحت رسالة الحملة، أو كلمةُ «إيقاف» يكتبها من وصلته حملةٌ في الأشهر الماضية.
 *  - إيقافُ العروض من إعدادات واتساب عنده (إشعارُ `user_preferences`، أو رفضُ Meta برمز 131050).
 *  - فريقُ المتجر يضيفه بطلبه.
 *
 * ولا تُرسل إليه حملةٌ بعدها من هذا المتجر. وإشعاراتُ طلباته لا تتأثّر: ليست عروضًا.
 *
 * و«اشتراك» تعيده إن كان هو من أوقف — ولا يعيده التاجر بنفسه.
 */
class WhatsAppOptOuts
{
    public const STOP = ['إيقاف', 'إيقاف الرسائل', 'وقف الرسائل', 'إلغاء الاشتراك', 'stop', 'stop messages', 'unsubscribe'];

    public const START = ['اشتراك', 'start', 'subscribe'];

    /** ‏‏كلمةُ «إيقاف» ممّن لم تصله حملةٌ في هذه المدّة ليست طلبَ إيقاف عروض */
    public const RECENT_DAYS = 180;

    public static function add(int $businessId, string $phone, string $source): bool
    {
        $row = Contention::attempt(fn () => WhatsAppOptOut::create(['business_id' => $businessId, 'phone' => mb_substr($phone, 0, 20), 'source' => $source]));

        return $row instanceof WhatsAppOptOut;
    }

    public static function has(int $businessId, string $phone): bool
    {
        return WhatsAppOptOut::where('business_id', $businessId)->where('phone', $phone)->exists();
    }

    /**
     * ‏‏رسالةٌ وصلت رقمَ المتجر — أطلبَ صاحبُها الإيقافَ أو العودة؟ `true`: أُخذت ورُدّ عليها، فلا يردّ غيرُنا.
     *
     * ولا يُسقط عطبٌ هنا بابَ الإشعارات.
     */
    public static function receive(WhatsAppConnection $connection, array $message, WhatsAppInboundMessage $row): bool
    {
        try {
            return self::take($connection, $message, $row);
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    private static function take(WhatsAppConnection $connection, array $message, WhatsAppInboundMessage $row): bool
    {
        $businessId = (int) $connection->business_id;
        $text = trim((string) ($message['button']['text'] ?? $message['button']['payload'] ?? WhatsAppOrders::input($message)['text']));
        $said = KeywordMatch::normalize($text);
        $is = fn (array $words) => in_array($said, array_map(fn ($w) => KeywordMatch::normalize($w), $words), true);
        $stop = $said !== '' && $is(self::STOP);
        $start = ! $stop && $said !== '' && $is(self::START);

        if (! $stop && ! $start) {
            return false;
        }

        $phone = (string) $row->from_phone;

        // ‏‏ولمن وصلته حملةٌ منّا وحده: «اشتراك» و«إيقاف» عند غيره كلمتان في محادثة
        $campaigned = WhatsAppCampaignRecipient::where('business_id', $businessId)->where('phone', $phone)
            ->whereIn('status', WhatsAppCampaignRecipient::OUT)
            ->where('sent_at', '>', now()->subDays(self::RECENT_DAYS))->exists();

        if (! $campaigned) {
            return false;
        }

        if ($start) {
            $mine = WhatsAppOptOut::where('business_id', $businessId)->where('phone', $phone)->where('source', WhatsAppOptOut::CUSTOMER)->delete();

            if ($mine === 0) {
                return false;
            }
        } else {
            self::add($businessId, $phone, WhatsAppOptOut::CUSTOMER);
        }

        self::confirm($connection, $row, $stop, preg_match('/\p{Arabic}/u', $text) === 1);

        return true;
    }

    /** ‏‏يُقال له إنّ طلبه وصل — بحرّاس الردّ نفسِها. وإن مُنع الردُّ فالإيقافُ وقع */
    private static function confirm(WhatsAppConnection $connection, WhatsAppInboundMessage $row, bool $stop, bool $arabic): void
    {
        $reason = WhatsAppAutoReply::blocked($connection, $row);

        if ($reason !== null) {
            $row->forceFill(['reply_status' => WhatsAppInboundMessage::SKIPPED, 'reply_reason' => mb_substr('optout_'.$reason, 0, 60)])->save();

            return;
        }

        $text = match (true) {
            $stop && $arabic => 'أوقفنا رسائل العروض إليك. تصلك رسائل طلباتك كالمعتاد. للعودة أرسل: اشتراك',
            $stop => 'You will no longer get our offers. Messages about your orders continue. To resume, send: START',
            $arabic => 'أعدنا رسائل العروض إليك. لإيقافها أرسل: إيقاف',
            default => 'You will get our offers again. To stop, send: STOP',
        };

        $r = MetaWhatsAppClient::sendText($connection, (string) $row->from_phone, $text);

        $row->forceFill($r['ok']
            ? ['reply_status' => WhatsAppInboundMessage::SENT, 'reply_reason' => $stop ? 'opted_out' : 'opted_in', 'replied_at' => now()]
            : ['reply_status' => WhatsAppInboundMessage::FAILED, 'reply_reason' => mb_substr((string) $r['code'], 0, 60)])->save();

        if (! $r['ok']) {
            Log::warning('whatsapp.optout_confirm_failed', ['business_id' => $connection->business_id, 'meta_code' => $r['code']]);
        }
    }

    /**
     * ‏‏إشعارُ Meta بأنّ عميلًا أوقف العروض من إعدادات واتساب أو أعادها — يُكتب كما قال.
     *
     * @param  array<string, mixed>  $value
     */
    public static function preferences(WhatsAppConnection $connection, array $value): void
    {
        if ($connection->owner_type !== WhatsAppMode::OWNER_BUSINESS || blank($connection->business_id)) {
            return;
        }

        foreach ((array) ($value['user_preferences'] ?? []) as $p) {
            $phone = WhatsAppPhone::normalize($p['wa_id'] ?? null);

            if ($phone === null || ($p['category'] ?? '') !== 'marketing_messages') {
                continue;
            }

            if (($p['value'] ?? '') === 'stop') {
                self::add((int) $connection->business_id, $phone, WhatsAppOptOut::META);
            } elseif (($p['value'] ?? '') === 'resume') {
                WhatsAppOptOut::where('business_id', $connection->business_id)->where('phone', $phone)->where('source', WhatsAppOptOut::META)->delete();
            }
        }
    }

    /**
     * ‏‏يضيفه فريقُ المتجر بطلبه — برقمٍ يصلح.
     *
     * @return array{ok: bool, message: ?string}
     */
    public static function byStaff(Business $business, string $raw): array
    {
        $phone = WhatsAppPhone::normalize($raw);

        if ($phone === null) {
            return ['ok' => false, 'message' => __('اكتب رقم هاتف صالحًا.')];
        }

        if (self::add((int) $business->id, $phone, WhatsAppOptOut::STAFF)) {
            Activity::log('settings', 'حملات واتساب: أُضيف رقم إلى من طلبوا الإيقاف', ['subject_type' => WhatsAppOptOut::class]);
        }

        return ['ok' => true, 'message' => null];
    }
}
