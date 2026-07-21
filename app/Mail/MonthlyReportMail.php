<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class MonthlyReportMail extends Mailable
{
    public function __construct(public string $businessName, public string $period, public array $stats) {}

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
        ]);
    }
}
