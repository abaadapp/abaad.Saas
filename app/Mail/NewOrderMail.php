<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class NewOrderMail extends Mailable
{
    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'طلب جديد ' . $this->order->number . ' — Abad POS');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.new-order', with: ['order' => $this->order]);
    }
}
