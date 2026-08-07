<?php

namespace App\Mail;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * رابط إعادة تعيين كلمة المرور.
 *
 * لا يُوضع في طابور (خلافًا لبقية الرسائل): من ينتظر أمام الشاشة يجب أن
 * تصله الرسالة الآن، لا حين يمرّ العامل على الطابور.
 */
class PasswordResetMail extends Mailable
{
    public function __construct(
        public string $name,
        public string $loginEmail,
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('إعادة تعيين كلمة المرور — Abad POS'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.password-reset', with: [
            'name' => $this->name,
            'loginEmail' => $this->loginEmail,
            'url' => $this->url,
            'minutes' => (int) config('auth.passwords.users.expire', 60),
        ]);
    }
}
