<?php

namespace App\Jobs;

use App\Models\Business;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Support\MetaWhatsAppClient;
use App\Support\WhatsAppConnections;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppQuota;
use App\Support\WhatsAppStatus;
use App\Support\WhatsAppTemplates;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * الإرسال — بعيدًا عن الطلب الذي أطلقه.
 *
 * الكاشير لا ينتظر ميتا: نداءٌ يستغرق ثانيتين على شبكة متجرٍ بطيئة يعني
 * زبونًا يقف أمام شاشةٍ لا تتحرّك، ونداءٌ يفشل يعني بيعةً تُلغى لأنّ إشعارًا
 * لم يخرج. فالبيع يكتب صفًّا ويمضي، وهذا يعمل بعده.
 *
 * ولا يحمل الوظيفةُ نصًّا ولا رقمًا ولا رمزًا: تحمل معرّف صفٍّ وحده. جسمُ
 * الوظيفة يُكتب في جدول الطابور نصًّا مقروءًا، فما وُضع فيه خرج من الحماية.
 */
class SendWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    /**
     * ثلاثٌ ثمّ يُترك.
     *
     * الإعادة تُفيد في انقطاعٍ أو حدّ معدّل، ولا تُفيد في رقمٍ خاطئ أو قالبٍ
     * غير معتمَد. وبلا حدٍّ تدور الوظيفة على خطأٍ دائمٍ إلى الأبد، تستهلك
     * الطابور وحدّ المعدّل معًا وتُخفي ما بعدها.
     */
    public $tries = 3;

    /** انتظارٌ متصاعد: دقيقة، ثمّ خمس، ثمّ خمس عشرة */
    public array $backoff = [60, 300, 900];

    /**
     * لا تُنفَّذ إلا بعد أن تُثبَّت المعاملة التي أنشأتها.
     *
     * بيعُ الصندوق يكتب الطلب وبنودَه ومخزونَه وقيدَه في معاملةٍ واحدة قد
     * تُلغى. ووظيفةٌ تبدأ داخلها قد تقرأ صفًّا يُمحى بعد لحظة — فتُرسل رسالةً
     * عن طلبٍ لا وجود له.
     */
    public function __construct(public int $messageId)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $message = WhatsAppMessage::find($this->messageId);

        /*
         * الحالة تُعاد قراءتها لا تُفترض.
         *
         * الوظيفة قد تُعاد بعد نجاحٍ سُجّل ثمّ انقطع الاتصال قبل أن يُعلَم
         * الطابور. فما خرج لا يخرج ثانية — والزبون لا يقرأ «طلبك جاهز»
         * مرّتين.
         */
        if (! $message || $message->status !== WhatsAppStatus::QUEUED) {
            return;
        }

        $business = Business::find($message->business_id);
        $connection = $message->whatsapp_connection_id
            ? WhatsAppConnection::find($message->whatsapp_connection_id)
            : null;

        if (! $business) {
            return;
        }

        // الوصلة قد تكون انقطعت بين الحجز والتنفيذ — تُعاد قراءتها لا تُفترض
        if (! $connection || ! $connection->isUsable()) {
            $current = WhatsAppConnections::resolve($business);

            if (! $current) {
                $this->stop($message, WhatsAppStatus::SKIPPED, WhatsAppStatus::SKIP_NO_CONNECTION);

                return;
            }

            $connection = $current;
            $message->whatsapp_connection_id = $connection->id;
        }

        /*
         * الحجز قبل النداء — وهو الترتيب الوحيد الآمن.
         *
         * لو خُصمت الحصّة بعد قبول ميتا لَخرجت رسالتان متزامنتان من حدٍّ فيه
         * واحدة: كلتاهما تقرأ «بقيت واحدة» ثمّ تُرسل. فتُحجز أوّلًا ذرّةً
         * واحدة، ثمّ يُنادى — وما يرفضه المزوّد تُردّ حصّته أدناه.
         *
         * ورقم المحلّ الخاص لا يمرّ من هنا: الإرسال على حسابه لا على حسابنا.
         */
        $shared = $message->source_mode === WhatsAppMode::ABAAD_SHARED;

        if ($shared && ! WhatsAppQuota::reserve($business)) {
            $this->stop($message, WhatsAppStatus::QUOTA_EXCEEDED, WhatsAppStatus::SKIP_QUOTA);

            return;
        }

        if ($shared) {
            $message->quota_consumed = true;
        }

        $order = $message->order;
        $variables = $order
            ? WhatsAppTemplates::variables($business, $order)
            : [(string) $business->name, ''];

        $result = MetaWhatsAppClient::sendTemplate(
            $connection,
            (string) $message->recipient_phone,
            (string) $message->template_name,
            (string) ($message->language_code ?: config('whatsapp.language', 'ar')),
            $variables,
        );

        if ($result['ok']) {
            $message->forceFill([
                'status' => WhatsAppStatus::SENT,
                'provider_message_id' => $result['id'],
                'sent_at' => now(),
                'error_code' => null,
                'error_message' => null,
            ])->save();

            return;
        }

        // لم تُقبل: تُردّ الحصّة — لا يُحاسَب التاجر على رسالةٍ لم تخرج
        if ($shared) {
            WhatsAppQuota::release($business);
            $message->quota_consumed = false;
        }

        $message->forceFill([
            'error_code' => $result['code'],
            'error_message' => $result['message'],
        ])->save();

        /*
         * ما يُعاد يبقى في الطابور بحاله «مُدرَجة».
         *
         * ولو قُيّد فشلًا الآن لَما التقطته المحاولة التالية: أوّل سطرٍ في
         * `handle` يخرج على كلّ حالٍ غير «مُدرَجة».
         */
        if ($result['retryable'] && $this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 900);

            return;
        }

        $this->stop($message, WhatsAppStatus::FAILED, $result['code'], $result['message']);
    }

    /** آخر محاولةٍ سقطت باستثناء — يُقيَّد الفشل ولا يُترك الصفّ «مُدرَجة» أبدًا */
    public function failed(\Throwable $e): void
    {
        $message = WhatsAppMessage::find($this->messageId);

        if (! $message || $message->status !== WhatsAppStatus::QUEUED) {
            return;
        }

        if ($message->source_mode === WhatsAppMode::ABAAD_SHARED && $message->quota_consumed) {
            $business = Business::find($message->business_id);
            if ($business) {
                WhatsAppQuota::release($business);
            }
        }

        $message->forceFill([
            'status' => WhatsAppStatus::FAILED,
            'quota_consumed' => false,
            'failed_at' => now(),
            'error_code' => 'job_failed',
            'error_message' => mb_substr($e->getMessage(), 0, 500),
        ])->save();
    }

    private function stop(WhatsAppMessage $message, string $status, ?string $code, ?string $reason = null): void
    {
        $message->forceFill([
            'status' => $status,
            'quota_consumed' => false,
            'failed_at' => $status === WhatsAppStatus::FAILED ? now() : null,
            'error_code' => $code,
            'error_message' => $reason,
        ])->save();
    }
}
