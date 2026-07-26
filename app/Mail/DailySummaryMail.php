<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class DailySummaryMail extends Mailable
{
    public function __construct(
        public string $businessName,
        public array $summary,
        public string $dateLabel,
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
        ]);
    }
}
