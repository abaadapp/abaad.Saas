<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

class AppointmentReminderMail extends Mailable
{
    public function __construct(public string $businessName, public Collection $appointments) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'تذكير بمواعيد الغد — ' . $this->businessName);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.appointment-reminder', with: [
            'businessName' => $this->businessName,
            'appointments' => $this->appointments,
        ]);
    }
}
