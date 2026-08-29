<?php

namespace App\Http\Controllers\WhatsApp;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Support\MetaWhatsAppClient;
use App\Support\WhatsAppStatus;
use Illuminate\Http\Request;

/**
 * إشعارات ميتا — بابٌ مفتوح على الإنترنت، فلا يُوثق بحرفٍ ممّا يصله.
 *
 * ولا يُقرأ منه معرّف متجرٍ ولا معرّف طلب: من يعرف عنوان هذا الباب يستطيع
 * أن يُرسل إليه ما شاء. فالمتجر يُستنتج من `phone_number_id` — وهو ما ربطناه
 * نحن وسجّلناه عندنا — والرسالةُ من `provider_message_id` الذي أعادته ميتا
 * لنا عند الإرسال.
 *
 * ---------------------------------------------------------------------
 *
 * وحال الرسالة ليست حال الطلب.
 *
 * `delivered` هنا تعني أنّ الرسالة وصلت إلى جهاز. و«تم التسليم» في الطلب
 * تعني أنّ الورد وصل إلى يد. ولو كتب هذا الباب في `orders` حرفًا لَأقفل
 * إشعارٌ من ميتا طلبًا لم يخرج أحدٌ لتسليمه — وهو ما لا يُكتشف إلا حين يتّصل
 * الزبون سائلًا أين وردُه.
 *
 * فلا سطر في هذا الملفّ يكتب في `orders`.
 */
class WebhookController extends Controller
{
    /**
     * تسجيل العنوان عند ميتا — تُنادي مرّةً بكلمةٍ اتفقنا عليها فتُردّ إليها.
     *
     * والكلمة من ملفّ الخادم؛ وغيابها يعني الرفض لا القبول: بابٌ يُسجَّل بلا
     * كلمةٍ يستطيع أيّ أحدٍ أن يوجّه إشعاراته إليه.
     */
    public function verify(Request $request)
    {
        $token = (string) config('whatsapp.verify_token');

        if ($token === ''
            || $request->query('hub_mode') !== 'subscribe'
            || ! hash_equals($token, (string) $request->query('hub_verify_token'))) {
            return response('', 403);
        }

        return response((string) $request->query('hub_challenge'), 200)
            ->header('Content-Type', 'text/plain');
    }

    public function handle(Request $request)
    {
        if (! MetaWhatsAppClient::verifySignature(
            $request->header('X-Hub-Signature-256'),
            $request->getContent(),
        )) {
            return response()->json(['ok' => false], 403);
        }

        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $this->applyChange((array) ($change['value'] ?? []));
            }
        }

        /*
         * تُردّ ٢٠٠ دائمًا بعد التوقيع.
         *
         * ميتا تُعيد ما لا يُقبَل، وتُوقف الإشعارات عن عنوانٍ يُكثر الخطأ.
         * وحمولةٌ لا نفهمها ليست عطلًا عندهم — فتُتجاهل بهدوء.
         */
        return response()->json(['ok' => true]);
    }

    private function applyChange(array $value): void
    {
        // الرقم الذي وصله الإشعار — به وحده تُعرف الوصلة
        $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;

        if (blank($phoneNumberId)) {
            return;
        }

        $connection = WhatsAppConnection::where('phone_number_id', $phoneNumberId)->first();

        // رقمٌ لا نعرفه: إشعارٌ ليس لنا، أو وصلةٌ حُذفت — لا يُكتب منه شيء
        if (! $connection) {
            return;
        }

        foreach ((array) ($value['statuses'] ?? []) as $status) {
            $this->applyStatus($connection, (array) $status);
        }

        /*
         * الرسائل الواردة من الزبائن: لا صندوقَ وارد في هذه النسخة.
         *
         * والرقم مشترك، فبناء محادثاتٍ عليه يعني أن تصل رسالة زبون محلٍّ إلى
         * محلٍّ آخر إن أخطأ سطرٌ واحد في الربط. فلا تُخزَّن ولا تُعرض — وما
         * لا يُخزَّن لا يُسرَّب.
         */
    }

    private function applyStatus(WhatsAppConnection $connection, array $status): void
    {
        $id = $status['id'] ?? null;

        if (blank($id)) {
            return;
        }

        /*
         * الرسالة تُطابَق بمعرّف المزوّد **وبوصلتها معًا**.
         *
         * المعرّف وحده يكفي عمليًّا، لكنّ إضافة الوصلة تعني أنّ حمولةً مزوَّرة
         * وقّعها من سرق سرّ التطبيق لا تستطيع أن تُعدّل رسالة متجرٍ من وصلة
         * متجرٍ آخر. عزلٌ في العمق لا في الطبقة الأولى وحدها.
         */
        $message = WhatsAppMessage::where('provider_message_id', $id)
            ->where('whatsapp_connection_id', $connection->id)
            ->first();

        if (! $message) {
            return;
        }

        $stamp = isset($status['timestamp']) ? now()->setTimestamp((int) $status['timestamp']) : now();

        $next = match ($status['status'] ?? '') {
            'sent' => [WhatsAppStatus::SENT, 'sent_at'],
            'delivered' => [WhatsAppStatus::DELIVERED, 'delivered_at'],
            'read' => [WhatsAppStatus::READ, 'read_at'],
            'failed' => [WhatsAppStatus::FAILED, 'failed_at'],
            default => null,
        };

        if ($next === null) {
            return;
        }

        [$state, $column] = $next;

        /*
         * الحال لا ترجع إلى الوراء.
         *
         * ميتا لا تضمن ترتيب الإشعارات: «قُرئت» قد تصل قبل «سُلّمت». وبلا
         * هذا الترتيب تُكتب الأحدث ثمّ تُمحى بالأقدم — فتقول الشاشة «أُرسلت»
         * عن رسالةٍ قرأها صاحبها.
         */
        $rank = [
            WhatsAppStatus::QUEUED => 0, WhatsAppStatus::SENT => 1,
            WhatsAppStatus::DELIVERED => 2, WhatsAppStatus::READ => 3,
        ];

        $attributes = [$column => $stamp];

        if ($state === WhatsAppStatus::FAILED) {
            $error = $status['errors'][0] ?? [];
            $attributes['status'] = WhatsAppStatus::FAILED;
            $attributes['error_code'] = (string) ($error['code'] ?? 'provider_failed');
            $attributes['error_message'] = mb_substr((string) ($error['title'] ?? ($error['message'] ?? '')), 0, 500);
        } elseif (($rank[$state] ?? 0) > ($rank[$message->status] ?? -1)) {
            $attributes['status'] = $state;
        }

        $message->forceFill($attributes)->save();
    }
}
