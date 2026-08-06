<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Invoice;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;

/**
 * الاشتراكات والفواتير — يكتبها النظام لا بذرةُ العرض التجريبي.
 *
 * كان الجدولان لا يكتب فيهما إلا `DemoSeeder` وأمرُ اختبار: شاشة «الاشتراكات»
 * وشاشة «الفواتير» تقرآن جدولين فارغين في أي تركيبٍ حقيقي. والشاشة الفارغة
 * تُقرأ «لا فواتير هذا الشهر» لا «الآلية غير مبنيّة» — وهو أسوأ من غيابها.
 *
 * وهذا يُنشئ السجلّ ويُصدر الفاتورة ويسجّل السداد. أما التحصيل الإلكتروني
 * فيحتاج بوّابة دفع (ثواني/أموال في عُمان) — والسداد هنا يُسجَّل يدويًّا كما
 * يجري فعلًا اليوم: تحويلٌ بنكي يُراجعه صاحب المنصّة.
 */
class Billing
{
    public const UNPAID = 'غير مدفوعة';

    public const PAID = 'مدفوعة';

    public const SUB_ACTIVE = 'نشط';

    public const SUB_EXPIRED = 'منتهي';

    /** ما تُكلّفه دورةٌ من الباقة */
    public static function price(Business $business, string $cycle): float
    {
        $plan = $business->plan;
        if (! $plan) {
            return 0.0;
        }

        return (float) ($cycle === 'yearly' ? $plan->yearly_price : $plan->monthly_price);
    }

    /**
     * تجديد الاشتراك دورةً كاملة، وإصدار فاتورتها.
     *
     * التمديد من تاريخ الانتهاء لا من اليوم ما دام لم يمضِ: من جدّد قبل
     * أسبوعٍ من انتهائه لا يجوز أن يخسر ذلك الأسبوع — وإلا صار التجديد
     * المبكر عقوبةً فلا يجدّد أحدٌ إلا متأخّرًا.
     *
     * @return array{subscription: Subscription, invoice: Invoice}
     */
    public static function renew(Business $business, string $cycle = 'monthly'): array
    {
        return DB::transaction(function () use ($business, $cycle) {
            $from = ($business->ends_at && $business->ends_at->isFuture())
                ? $business->ends_at->copy()
                : now()->startOfDay();

            $ends = $cycle === 'yearly' ? $from->copy()->addYear() : $from->copy()->addMonth();
            $amount = self::price($business, $cycle);

            $business->update([
                'starts_at' => $business->starts_at ?? now()->startOfDay(),
                'ends_at' => $ends,
                'status' => 'نشط',
            ]);

            $subscription = Subscription::create([
                'business_id' => $business->id,
                'plan_id' => $business->plan_id,
                'starts_at' => $from,
                'ends_at' => $ends,
                'amount' => $amount,
                'payment_status' => 'غير مدفوع',
                'status' => self::SUB_ACTIVE,
            ]);

            $invoice = self::issue($business, $amount);

            Activity::log('created', 'جدّد اشتراك: '.$business->name.' حتى '.$ends->format('Y-m-d'), [
                'business_id' => null,
                'subject_id' => $business->id,
            ]);

            return ['subscription' => $subscription, 'invoice' => $invoice];
        });
    }

    /** فاتورة جديدة برقمٍ متسلسل لا يتكرّر */
    public static function issue(Business $business, float $amount): Invoice
    {
        return Invoice::create([
            'number' => self::nextNumber(),
            'business_id' => $business->id,
            'plan_id' => $business->plan_id,
            'amount' => $amount,
            'issued_at' => now(),
            'status' => self::UNPAID,
        ]);
    }

    /**
     * الرقم التالي: INV-P-0001.
     *
     * بادئة خاصّة بفواتير المنصة تفصلها عن فواتير بيع التجّار — الجدولان
     * مختلفان، والخلط بين رقمَيهما في مكالمةٍ مع محاسبٍ يُضيّع نصف ساعة.
     * والحساب من أعلى رقمٍ قائم لا من العدد: حذفُ فاتورةٍ كان سيُعيد رقمًا
     * مستعملًا.
     */
    private static function nextNumber(): string
    {
        $last = Invoice::where('number', 'like', 'INV-P-%')
            ->orderByRaw('LENGTH(number) DESC, number DESC')
            ->value('number');

        $n = $last ? ((int) substr($last, 6)) + 1 : 1;

        return 'INV-P-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    /** تسجيل السداد — ويُعلَّم اشتراك الدورة مدفوعًا معه */
    public static function markPaid(Invoice $invoice): Invoice
    {
        if ($invoice->status === self::PAID) {
            return $invoice;
        }

        DB::transaction(function () use ($invoice) {
            $invoice->update(['status' => self::PAID]);

            Subscription::where('business_id', $invoice->business_id)
                ->where('payment_status', 'غير مدفوع')
                ->latest('id')->limit(1)
                ->update(['payment_status' => 'مدفوع']);

            Activity::log('created', 'سجّل سداد الفاتورة: '.$invoice->number, [
                'business_id' => null,
                'subject_id' => $invoice->id,
            ]);
        });

        return $invoice->fresh();
    }
}
