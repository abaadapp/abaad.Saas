<?php

namespace App\Jobs;

use App\Models\Business;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Support\MetaWhatsAppClient;
use App\Support\PublicDocument;
use App\Support\WhatsAppConnections;
use App\Support\WhatsAppStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * إرسالُ فاتورة العميل من رقم المتجر الخاص.
 *
 * لا نحمّل URL ولا token في جسم الوظيفة؛ الوظيفة تحمل معرّف سجل الرسالة
 * فقط وتعيد قراءة كل شيء عند التنفيذ مثل بقية وظائف واتساب.
 */
class SendWhatsAppInvoice implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(public int $messageId)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $message = WhatsAppMessage::find($this->messageId);

        if (! $message || $message->status !== WhatsAppStatus::QUEUED) {
            return;
        }

        $business = Business::find($message->business_id);
        $invoice = $message->customerInvoice;

        if (! $business || ! $invoice) {
            $this->stop($message, WhatsAppStatus::SKIPPED, WhatsAppStatus::SKIP_NO_SUBJECT);

            return;
        }

        $connection = $message->whatsapp_connection_id
            ? WhatsAppConnection::find($message->whatsapp_connection_id)
            : null;

        if (! $connection || ! $connection->isUsable() || ! $connection->coexistence) {
            $connection = WhatsAppConnections::forBusiness($business->id);
        }

        if (! $connection || ! $connection->isUsable() || ! $connection->coexistence) {
            $this->stop($message, WhatsAppStatus::SKIPPED, WhatsAppStatus::SKIP_NO_CONNECTION);

            return;
        }

        $url = (string) (($message->metadata ?? [])['public_url'] ?? PublicDocument::url($invoice) ?? '');

        if ($url === '') {
            $this->stop($message, WhatsAppStatus::FAILED, 'invoice_url_missing', __('تعذّر إنشاء رابط الفاتورة.'));

            return;
        }

        $message->whatsapp_connection_id = $connection->id;
        $message->save();

        /*
         * القالب المعتمَد لدى Meta:
         * {{1}} اسم النشاط، {{2}} رقم الفاتورة، {{3}} رابط الفاتورة الآمن.
         * وهو Utility Template حتى يعمل الإرسال خارج نافذة الـ24 ساعة.
         */
        $result = MetaWhatsAppClient::sendTemplate(
            $connection,
            (string) $message->recipient_phone,
            (string) $message->template_name,
            (string) ($message->language_code ?: config('whatsapp.language', 'ar')),
            [
                (string) ($business->name ?: __('متجر')),
                (string) $invoice->number,
                $url,
            ],
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

        $message->forceFill([
            'error_code' => $result['code'],
            'error_message' => $result['message'],
        ])->save();

        if ($result['retryable'] && $this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 900);

            return;
        }

        $this->stop($message, WhatsAppStatus::FAILED, $result['code'], $result['message']);
    }

    public function failed(\Throwable $e): void
    {
        $message = WhatsAppMessage::find($this->messageId);

        if (! $message || $message->status !== WhatsAppStatus::QUEUED) {
            return;
        }

        $this->stop(
            $message,
            WhatsAppStatus::FAILED,
            'job_failed',
            mb_substr($e->getMessage(), 0, 500),
        );
    }

    private function stop(WhatsAppMessage $message, string $status, string $code, ?string $text = null): void
    {
        $message->forceFill([
            'status' => $status,
            'failed_at' => $status === WhatsAppStatus::FAILED ? now() : $message->failed_at,
            'error_code' => $code,
            'error_message' => $text,
        ])->save();
    }
}
