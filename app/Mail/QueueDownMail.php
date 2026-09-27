<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * توقّف عاملُ الطابور — خبرٌ لمديري المنصّة.
 *
 * ولا شيءَ من بيانات تاجرٍ فيه: اسمُ وحدةٍ ووقتٌ ومضيفٌ وأوامرُ فحص. فالبريدُ
 * يمرّ بخوادمَ لا نملكها، وعطبُ خادمٍ لا يُشرح ببياناتِ من يعمل عليه.
 */
class QueueDownMail extends Mailable
{
    public function __construct(
        public string $unit,
        public string $host,
        public string $at,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('أبعاد — توقّف عامل الطابور'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.queue-down', with: [
            'unit' => $this->unit,
            'host' => $this->host,
            'at' => $this->at,
        ]);
    }
}
