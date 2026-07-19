<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SmartAlertMail extends Mailable
{
    public function __construct(public string $businessName, public array $alerts) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'تنبيهات ذكية — ' . $this->businessName);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.smart-alerts', with: [
            'businessName' => $this->businessName,
            'alerts' => $this->alerts,
        ]);
    }
}
