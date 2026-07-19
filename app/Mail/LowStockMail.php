<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

class LowStockMail extends Mailable
{
    public function __construct(public string $businessName, public Collection $products) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'تنبيه انخفاض المخزون — ' . $this->businessName);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.low-stock', with: [
            'businessName' => $this->businessName,
            'products' => $this->products,
        ]);
    }
}
