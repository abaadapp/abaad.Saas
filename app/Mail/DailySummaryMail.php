<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class DailySummaryMail extends Mailable
{
    /**
     * والعملةُ تصل مع الملخّص لا تُقرأ من الجلسة.
     *
     * هذا يُرسَل من أمرٍ في الطابور يمرّ على المتاجر واحدًا واحدًا — ولا
     * جلسةَ فيه ولا متجرَ «حاليّ». فعملةُ صاحب الرسالة تُمرَّر إليه.
     *
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $currency  وصفٌ من `Money::of`
     */
    public function __construct(
        public string $businessName,
        public array $summary,
        public string $dateLabel,
        public array $currency,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('ملخّص اليوم — :business', ['business' => $this->businessName]));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.daily-summary', with: [
            'businessName' => $this->businessName,
            'summary' => $this->summary,
            'dateLabel' => $this->dateLabel,
            'currency' => $this->currency,
        ]);
    }
}
