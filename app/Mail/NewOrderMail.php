<?php

namespace App\Mail;

use App\Models\Order;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * إشعار «طلب جديد» — الرسالة الوحيدة التي تُرسَل والزبون واقف.
 *
 * كانت تُرسَل داخل الطلب نفسه: يضغط الكاشير «إتمام» فينتظر خادمَ البريد قبل
 * أن يرى الإيصال. والاستثناء ملتقَط فلا تفشل البيعة — لكن الالتقاط لا يردّ
 * الوقت: خادمُ بريدٍ بطيء يجمّد الصندوق ثوانيَ في كل بيعة، وخادمٌ لا يستجيب
 * يجمّده حتى تنتهي المهلة.
 *
 * وبقية الرسائل (تنبيهات المخزون، التقارير الشهرية، الملخّص اليومي) تبقى
 * مباشرةً: هي أصلًا في أوامر مجدولة تعمل وحدها، وإدخالها الطابور يزيد قطعةً
 * قد تتعطّل بلا أن يربح أحدٌ ثانية.
 */
class NewOrderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('طلب جديد :number — Abad POS', ['number' => $this->order->number]));
    }

    public function content(): Content
    {
        /*
         * وعملةُ صاحب الطلب تُقرأ من متجره لا من الجلسة.
         *
         * هذه الرسالةُ `ShouldQueue`: تُرسَم في عاملِ طابورٍ لا جلسةَ فيه ولا
         * «متجرٌ حاليّ»، وقد يعالج متجرين بالتتابع. فـ`Demo::baseCurrency`
         * هناك تقرأ متجرًا غيرَ صاحب الطلب — انظر `Support\Money`.
         */
        return new Content(view: 'emails.new-order', with: [
            'order' => $this->order,
            'currency' => Money::of((int) $this->order->business_id),
        ]);
    }
}
