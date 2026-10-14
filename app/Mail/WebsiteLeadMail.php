<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Log;

/**
 * إشعارُ بريد أبعاد بطلبٍ من «تواصل معنا» — والطلبُ محفوظٌ في الدفتر قبله.
 *
 * ═══ المُرسِل عنوانُ النظام، والردُّ إلى الزائر ═══
 *
 * لا `from` هنا: يبقى `MAIL_FROM_ADDRESS` الموثوق. وبريدُ الزائر في «From»
 * انتحالٌ يسقط في SPF/DMARC — فيوضع في Reply-To، وزرُّ «رد» في Gmail يصله.
 *
 * ═══ وفي الطابور ═══
 *
 * بعد إغلاق الـtransaction، بثلاث محاولاتٍ متباعدة. والحمولةُ مشفّرة: فيها
 * اسمُ الزائر وهاتفُه وبريدُه، ولا تُخزَّن في `jobs` و`failed_jobs` نصًّا مقروءًا.
 */
class WebsiteLeadMail extends Mailable implements ShouldBeEncrypted, ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  array{name:string,business_name:string,phone:string,email:string,activity:string,message:string,locale:string,received_at:string,source:string,lead_url:string}  $inquiry
     */
    public function __construct(
        public int $leadId,
        public array $inquiry,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->inquiry['email'], $this->inquiry['name'])],
            subject: __('طلب تواصل جديد من موقع أبعاد — :business', ['business' => $this->inquiry['business_name']]),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.website-lead', with: ['inquiry' => $this->inquiry]);
    }

    /** المحاولةُ الأخيرة سقطت — والطلبُ في الدفتر. يُسجَّل رقمُه ونوعُ الخطأ، لا ما كتبه الزائر */
    public function failed(\Throwable $e): void
    {
        Log::error('website contact: notification failed', ['lead' => $this->leadId, 'error' => class_basename($e)]);
    }
}
