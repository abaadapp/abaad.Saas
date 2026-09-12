<?php

namespace App\Http\Controllers\WhatsApp;

use App\Http\Controllers\Controller;
use App\Models\CrmMessage;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Support\CrmWhatsApp;
use App\Support\MetaWhatsAppClient;
use App\Support\SupportWhatsApp;
use App\Support\WhatsAppMode;
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
         * والرسائل الواردة: لا تُقرأ إلّا على خطّ دعمٍ أُذن له، ولا يُخزَّن
         * منها إلّا ما جاء من رقمٍ يُطابق مستخدمًا واحدًا له متجر.
         *
         * الرقمُ مشترك، فأكثرُ ما يصله ردودُ زبائنَ على إشعاراتِ طلباتهم:
         * «وصل؟»، «غيّر العنوان». وتلك رسائلُ زبونٍ لمحلِّه لا رسائلُ تاجرٍ
         * لأبعاد، وخزنُها هنا يعني أنّ من يفتح لوحةَ المنصّة يقرؤها.
         *
         * والقرارُ كلُّه في `SupportWhatsApp::receive` — بابٌ واحد يُغلق،
         * وشرطٌ مكرَّرٌ في موضعين يُخفَّف في أحدهما يومًا.
         */
        foreach ((array) ($value['messages'] ?? []) as $message) {
            /*
             * والوجهةُ تُقرأ من **غرض الوصلة** لا من محتوى الرسالة.
             *
             * ═══ ولمَ هذا هو الفاصل ═══
             *
             * الرقمان يخدمان نطاقين لا يلتقيان: رقمُ الإشعارات يُرسل نيابةً
             * عن المحلّات فيردّ عليه زبائنُهم — ولا يُخزَّن من وارده إلّا ما
             * طابق مستخدمًا له متجر. ورقمُ المبيعات يستقبل من يريد أن يشتري
             * أبعاد، فهو يقرأ **المجهول** بالضرورة.
             *
             * فلو وُزّع بالحدس — بنصّ الرسالة أو بمعرفة المُرسِل — لَصار
             * سؤالُ زبونةٍ عن هديّتها «عميلًا محتملًا» يوم تُكتب بصيغةٍ
             * تُشبه سؤالَ تاجر. والعمودُ لا يحدس.
             *
             * وكلُّ بابٍ يحرس نفسَه ثانيةً: كلتا الدالّتين تفحص الغرضَ عندها
             * — شرطٌ في موضعٍ واحد يُخفَّف يومًا بلا أن يلحظه أحد.
             */
            if ($connection->purpose === WhatsAppMode::PURPOSE_CRM_SALES) {
                CrmWhatsApp::receive($connection, (array) $message);

                continue;
            }

            SupportWhatsApp::receive($connection, (array) $message);
        }
    }

    private function applyStatus(WhatsAppConnection $connection, array $status): void
    {
        $id = $status['id'] ?? null;

        if (blank($id)) {
            return;
        }

        /*
         * وحالُ رسالةِ مبيعاتٍ تُكتب في جدولها.
         *
         * ═══ ولمَ لا تُترك ═══
         *
         * الإرسالُ يكتب `sent` — وهي تعني «قبلتها ميتا» لا «وصلت». وبلا هذا
         * السطر تبقى كلُّ رسالةٍ في دفتر المبيعات «أُرسلت» أبدًا: يقرأ موظّفُ
         * المبيعات أنّ رسالتَه خرجت وهي راقدةٌ عند ميتا، أو فشلت بعد القبول
         * ولا شيء يقول ذلك. وتقريرُ حالٍ كاذب أسوأ من غياب التقرير.
         */
        if ($connection->purpose === WhatsAppMode::PURPOSE_CRM_SALES) {
            $this->applyCrmStatus((string) $id, $status);

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

    /**
     * حالُ رسالةٍ في دفتر المبيعات — ولا ترجع إلى الوراء.
     *
     * ميتا لا تضمن ترتيب الإشعارات: «قُرئت» قد تصل قبل «سُلّمت». وبلا ترتيبٍ
     * تُكتب الأحدث ثمّ تُمحى بالأقدم، فتقول الشاشة «أُرسلت» عن رسالةٍ قرأها
     * صاحبها.
     *
     * و`failed` تُكتب دائمًا: فشلٌ بعد التسليم خبرٌ لا يُبتلع.
     *
     * @param  array<string, mixed>  $status
     */
    private function applyCrmStatus(string $wamid, array $status): void
    {
        $message = CrmMessage::where('external_message_id', $wamid)
            ->where('direction', CrmMessage::OUT)->first();

        if (! $message) {
            return;
        }

        $state = match ($status['status'] ?? '') {
            'sent' => 'sent',
            'delivered' => 'delivered',
            'read' => 'read',
            'failed' => 'failed',
            default => null,
        };

        if ($state === null) {
            return;
        }

        if ($state === 'failed') {
            $error = $status['errors'][0] ?? [];

            $message->forceFill([
                'delivery' => 'failed',
                'delivery_error' => mb_substr(
                    (string) ($error['title'] ?? ($error['message'] ?? __('ردّتها ميتا'))), 0, 200
                ),
            ])->save();

            return;
        }

        $rank = ['blocked' => -1, 'failed' => -1, 'sent' => 1, 'delivered' => 2, 'read' => 3];

        if (($rank[$state] ?? 0) > ($rank[$message->delivery] ?? 0)) {
            $message->forceFill(['delivery' => $state])->save();
        }
    }
}
