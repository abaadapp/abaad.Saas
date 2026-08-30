<?php

namespace App\Mail;

use App\Models\PasswordRecoveryOtp;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * رمز التحقّق — ستّة أرقامٍ ولا شيء غيرها.
 *
 * لا تحمل كلمة مرور، ولا رمز كاشير، ولا رابطًا يفتح شيئًا، ولا اسم المتجر
 * ولا معرّفه: صندوقُ البريد قد يكون مخترقًا أصلًا، والرسالة تُقرأ على شاشةٍ
 * في مكانٍ عامّ. فما لا يلزم لا يُكتب.
 *
 * ولا تُوضع في طابور (كسابقتها): صاحبها واقفٌ أمام الشاشة ينتظرها الآن، لا
 * حين يمرّ العامل على الطابور.
 */
class RecoveryOtpMail extends Mailable
{
    public function __construct(
        public string $code,
        public string $purpose,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->purpose === PasswordRecoveryOtp::PURPOSE_PASSWORD_RESET
                ? __('رمز إعادة تعيين كلمة المرور - أبعاد')
                : __('رمز التحقق - أبعاد'),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.recovery-otp', with: [
            'code' => $this->code,
            'minutes' => (int) ceil(((int) config('recovery.otp_ttl', 600)) / 60),
            'isReset' => $this->purpose === PasswordRecoveryOtp::PURPOSE_PASSWORD_RESET,
        ]);
    }
}
