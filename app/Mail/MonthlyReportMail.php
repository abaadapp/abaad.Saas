<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class MonthlyReportMail extends Mailable
{
    /**
     * والعملةُ تصل مع التقرير لا تُقرأ من الجلسة.
     *
     * هذا يُرسَل من أمرٍ مجدول يمرّ على المتاجر واحدًا واحدًا — لا جلسةَ فيه
     * ولا «متجرٌ حاليّ». فعملةُ صاحب التقرير تُمرَّر إليه. انظر `Support\Money`.
     *
     * @param  array<int, array<string, string>>  $stats
     * @param  array<string, mixed>  $currency  وصفٌ من `Money::of`
     */
    public function __construct(
        public string $businessName,
        public string $period,
        public array $stats,
        public array $currency,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('تقرير أداء :period — :business', ['period' => $this->period, 'business' => $this->businessName]));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.monthly-report', with: [
            'businessName' => $this->businessName,
            'period' => $this->period,
            'stats' => $this->stats,
            'currency' => $this->currency,
        ]);
    }
}
