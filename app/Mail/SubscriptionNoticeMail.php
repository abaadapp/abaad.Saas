<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * إنذار الاشتراك — قبل الانتهاء، ويومَه، وفي المهلة، ويوم الإقفال.
 *
 * رسالةٌ واحدة بأربع نبرات لا أربع رسائل: النصّ يختلف والحقائق واحدة، وفصلُها
 * يجعل تعديل رقم الهاتف يقع في ثلاثة ملفّاتٍ وينسى الرابع.
 */
class SubscriptionNoticeMail extends Mailable
{
    /**
     * @param  'before'|'today'|'grace'|'locked'  $stage
     */
    public function __construct(
        public string $stage,
        public string $businessName,
        public string $endsAt,
        public int $days,
        public array $contact = [],
    ) {}

    public function envelope(): Envelope
    {
        $subject = match ($this->stage) {
            'before' => __('اشتراكك ينتهي خلال :n يومًا — :business', ['n' => $this->days, 'business' => $this->businessName]),
            'today' => __('اشتراكك ينتهي اليوم — :business', ['business' => $this->businessName]),
            // النبرة تتصاعد مع القرب: عنوانٌ واحدٌ لكل المراحل يُقرأ آخره كأوّله
            'grace' => __('انتهى اشتراكك — يتوقّف النظام بعد :n يومًا — :business', ['n' => $this->days, 'business' => $this->businessName]),
            'locked' => __('توقّف النظام — :business', ['business' => $this->businessName]),
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.subscription-notice');
    }
}
