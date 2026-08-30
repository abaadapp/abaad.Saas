<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * تنبيهٌ إلى العنوان القديم: بريد الاستعادة تغيّر.
 *
 * ومن غُيّر بريد استعادته دون علمه لا يعرف إلا حين ينسى كلمته — وقد فات
 * الأمر. وهذه الرسالة فرصته الوحيدة لينتبه اليوم.
 *
 * ولا تحمل العنوان الجديد: صندوقٌ قديم قد يكون هو المخترَق، فلا يُدلّ صاحبُه
 * على وجهة الحساب.
 */
class RecoveryEmailChangedMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: __('تنبيه أمني - أبعاد'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.recovery-email-changed');
    }
}
