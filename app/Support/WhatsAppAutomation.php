<?php

namespace App\Support;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Business;
use App\Models\Order;
use App\Models\WhatsAppMessage;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * القرار كلّه هنا — لا في متحكّم ولا في شاشة.
 *
 * تسع عشرة إجابةً تسبق كلّ رسالة: هل واتساب مفعَّل في المنصّة؟ في المتجر؟
 * هل هذا الحدث مُطفأ؟ من أيّ رقمٍ يُرسل؟ أهناك وصلةٌ صالحة؟ أهناك قالب؟
 * أهناك رقم زبون؟ أبقيت حصّة؟ أسبق أن أُرسلت؟
 *
 * ولو وُزّعت هذه على مواضع الاستدعاء لَافترقت: المتحكّم الذي يُضاف غدًا
 * يتذكّر ستًّا وينسى ثلاثًا، فيرسل ما لا يجوز أو يمتنع عمّا يجوز. وهذا صنفٌ
 * من العطب لا يظهر في اختبار: كلّ مسارٍ وحده يبدو سليمًا.
 *
 * ولا يُرسل من هنا شيء: يُكتب صفٌّ ويُدفع إلى الطابور. البيعُ لا ينتظر ميتا،
 * والزبون واقفٌ عند الصندوق.
 */
class WhatsAppAutomation
{
    /**
     * حدثٌ وقع على طلب — يُقرَّر ويُقيَّد.
     *
     * ولا يرفع استثناءً أبدًا. مصدرُ ندائه حفظُ طلبٍ أو بيعةٌ عند الصندوق،
     * وخطأٌ في إشعارٍ لا يجوز أن يُسقط بيعة. فما لا يُفهَم يُبتلع ويُبلَّغ
     * عنه في السجلّ.
     */
    public static function handle(Order $order, string $event): ?WhatsAppMessage
    {
        try {
            return self::decide($order, $event);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    private static function decide(Order $order, string $event): ?WhatsAppMessage
    {
        if (! in_array($event, WhatsAppEvent::ALL, true)) {
            return null;
        }

        $business = Business::find($order->business_id);

        if (! $business) {
            return null;
        }

        /*
         * ما يُمنع قبل أن يُكتب له صفّ.
         *
         * متجرٌ لم يفعّل واتساب أصلًا لا يُكتب له سجلٌّ عند كلّ حالةِ طلب:
         * ألفُ صفٍّ في الشهر تقول جميعًا «مُطفأ» ليست سجلًّا — هي ضجيجٌ يُخفي
         * الأسباب التي تُقرأ.
         */
        if (WhatsAppFeature::blockReason($business) !== null) {
            return null;
        }

        $mode = WhatsAppFeature::effectiveMode($business);
        $dedupe = self::dedupeKey($business->id, $order->id, $event);

        // الحدث مُطفأ في إعدادات المتجر — قرارُ التاجر، لا يُقيَّد عطلًا
        if (! self::eventEnabled($business->id, $event)) {
            return null;
        }

        $phone = WhatsAppRecipient::forOrder($order);
        $connection = WhatsAppConnections::resolve($business);
        $template = WhatsAppTemplates::resolve($business, $event, $mode);

        $base = [
            'business_id' => $business->id,
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'whatsapp_connection_id' => $connection?->id,
            'source_mode' => $mode,
            'event_type' => $event,
            'direction' => 'outbound',
            'recipient_phone' => $phone,
            'template_name' => $template?->template_name,
            'language_code' => $template?->language_code,
            'dedupe_key' => $dedupe,
        ];

        // ما يُقيَّد ممتنعًا: له سببٌ يُقرأ، ولا يستهلك حصّة
        $skip = match (true) {
            $phone === null => WhatsAppStatus::SKIP_NO_RECIPIENT,
            $connection === null => WhatsAppStatus::SKIP_NO_CONNECTION,
            $template === null => WhatsAppStatus::SKIP_NO_TEMPLATE,
            default => null,
        };

        if ($skip !== null) {
            return self::record($base + [
                'status' => WhatsAppStatus::SKIPPED,
                'error_code' => $skip,
                'quota_consumed' => false,
            ]);
        }

        $message = self::record($base + [
            'status' => WhatsAppStatus::QUEUED,
            'quota_consumed' => false,
            'queued_at' => now(),
        ]);

        // صفٌّ موجودٌ سلفًا: الحدث نفسه وصل مرّتين — لا يُدفع إلى الطابور ثانيةً
        if ($message === null) {
            return null;
        }

        SendWhatsAppMessage::dispatch($message->id)->onQueue((string) config('whatsapp.queue', 'whatsapp'));

        return $message;
    }

    /**
     * المفتاح الذي يمنع التكرار.
     *
     * المتجر والطلب والحدث — لا الوقت ولا رقم المحاولة. فطلبٌ نُقل إلى
     * «جاهز» ثمّ أُعيد إلى «قيد التجهيز» ثمّ أُعيد إلى «جاهز» يُرسل مرّةً
     * واحدة، وهو الصواب: الزبون لا يهمّه تردّد الموظّف.
     */
    public static function dedupeKey(int $businessId, int $orderId, string $event): string
    {
        return $businessId.':'.$orderId.':'.$event;
    }

    /**
     * الكتابة يحرسها الفهرس الفريد لا فحصٌ قبلها.
     *
     * `exists()` ثمّ `create()` يترك نافذةً يمرّ منها نداءان متزامنان —
     * ضغطتان على الزرّ، أو إعادةُ محاولةٍ من الطابور بينما الأولى تكتب.
     */
    private static function record(array $attributes): ?WhatsAppMessage
    {
        try {
            return WhatsAppMessage::create($attributes);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /** هل فعّل التاجر هذا الحدث؟ — من مفاتيح `wa_on_*` القائمة */
    private static function eventEnabled(int $businessId, string $event): bool
    {
        $key = WhatsAppEvent::SETTING_KEYS[$event] ?? null;

        if ($key === null) {
            return false;
        }

        return (MarketingSettings::group($businessId, 'whatsapp')[$key] ?? '0') !== '0';
    }
}
