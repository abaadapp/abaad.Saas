<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentAllocation;
use App\Models\CustomerPaymentLink;
use App\Models\PointTransaction;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * نقاطُ الولاء على فاتورة عميلٍ سُدّدت برابط Paymob.
 *
 * كانت النقاطُ تُكتسب في الصندوق وحده (`PosController::recordLoyalty`): زبونٌ
 * يدفع فاتورتَه برابطٍ أرسله المتجر يدفع المالَ نفسه ولا يكسب شيئًا. فيكسب هنا
 * بقاعدة الصندوق نفسِها: مفتاحُ المتجر وباقتُه معًا، و`floor(المبلغ × النسبة)`.
 *
 * ═══ متى ═══
 *
 * بعد أن يثبت المال — إشعارُ Paymob الموقَّع وقد كُتب تحصيلُه (`PaymentLinks::settle`)
 * — لا عند صفحة النجاح في المتصفّح. وخارجَ معاملة التحصيل عن قصد: سقوطٌ هنا لا
 * يمحو مالًا، يُكتب على الرابط (`loyalty_error`) ويُعاد كلَّ عشر دقائق
 * (`customer-invoices:reconcile-loyalty`).
 *
 * ═══ على ماذا ═══
 *
 *   - ما خُصِّص من الدفعة لهذه الفاتورة فعلًا — لا ما بقي رصيدًا للعميل.
 *   - وما لم يكتسب نقاطَه قبلُ: فاتورةٌ تغطّي طلبَ صندوقٍ كسب عليه الزبونُ
 *     نقاطَه لا يُكسَب عليها ثانيةً. ويُطرح نصيبُ تلك الطلبات من الفاتورة،
 *     ويُكسب على الباقي.
 *   - والدفعاتُ الجزئيّة تُحسب تراكميًّا: ٣٫٣٣٣ ثمّ ٦٫٦٦٧ بنسبة ٥ تعطي ١٦ ثمّ ٣٤ —
 *     خمسين كما يعطيها سدادُ العشرة مرّةً، لا ٤٩ يأكلها التقريبُ مرّتين.
 *
 * ═══ ومرّةً واحدة ═══
 *
 * علَمٌ على الرابط (`meta.loyalty`) يكتبه التحصيلُ الجديد وحده «بانتظار النقاط».
 * فرابطٌ سُدّد قبل هذه الميزة لا علَمَ له — وإشعارٌ مكرّرٌ له اليوم لا يمنح نقاطًا
 * عن مالٍ قديم. والحسمُ تحت قفل صفّ الرابط، فإشعاران متزامنان: يحسم الأوّل،
 * ويجد الثاني العلَمَ محسومًا. و`source_key` فريدٌ في القاعدة خلف ذلك كلّه.
 *
 * ═══ والعكس ═══
 *
 * إلغاءُ التحصيل (`CustomerPayments::cancel`) يعكس نقاطَه في معاملته، بحركةٍ
 * ثانية لا بمحو الأولى — ولا يُنزل الرصيدَ تحت الصفر، كـ`OrderCorrection::syncLoyalty`.
 * واسترجاعُ Paymob على رابطٍ مدفوع يُقال ولا يمحو التحصيل (`PaymentLinks::notSettled`)،
 * فالنقاطُ باقيةٌ معه حتّى يُلغيه صاحبُ المحلّ.
 */
class InvoiceLoyalty
{
    /** علَمُ الرابط في `meta.loyalty` */
    public const PENDING = 'pending';

    public const AWARDED = 'awarded';

    public const NONE = 'none';

    /** مصدرُ الحركة — واحدٌ لكلّ رابط، وعكسُه بلاحقة */
    public static function key(CustomerPaymentLink $link): string
    {
        return 'link:'.$link->id;
    }

    /** هل الولاءُ عاملٌ في المتجر الآن — مفتاحُ التاجر وباقتُه، كـ`PosController::loyaltyOn` */
    public static function on(int $businessId): bool
    {
        $enabled = (string) (Setting::where('business_id', $businessId)->where('key', 'loyalty_enabled')->value('value') ?? '1') !== '0';

        return $enabled && PlanFeatures::allows(Business::find($businessId), 'loyalty');
    }

    public static function rate(int $businessId): float
    {
        return (float) (Setting::where('business_id', $businessId)->where('key', 'loyalty_earn_rate')->value('value') ?? 5);
    }

    /**
     * بعد أن ثبت المال — يُنادى من إشعار Paymob ومن إعادة المعالجة. لا يرمي.
     */
    public static function afterLinkPaid(CustomerPaymentLink $link): void
    {
        try {
            self::award($link);
        } catch (\Throwable $e) {
            report($e);
            self::remember($link, $e);
        }
    }

    /**
     * يحسم نقاطَ رابطٍ مدفوع — مرّةً واحدة.
     *
     * @return PointTransaction|null حركةُ الاكتساب إن كُتبت الآن
     */
    public static function award(CustomerPaymentLink $link): ?PointTransaction
    {
        return DB::transaction(function () use ($link) {
            $locked = CustomerPaymentLink::whereKey($link->id)->lockForUpdate()->first();

            // سُدّد قبل هذه الميزة، أو حُسم قبلُ: لا شيء
            if (! $locked || ($locked->meta['loyalty'] ?? null) !== self::PENDING) {
                return null;
            }

            [$points, $why, $invoice, $payment] = self::decide($locked);

            if ($points <= 0) {
                self::flag($locked, self::NONE, $why);

                return null;
            }

            $customer = Customer::withTrashed()->where('business_id', $locked->business_id)
                ->whereKey($locked->customer_id)->lockForUpdate()->firstOrFail();
            $customer->increment('points', $points);

            $earn = PointTransaction::create([
                'business_id' => (int) $locked->business_id,
                'customer_id' => $customer->id,
                'customer_invoice_id' => $invoice->id,
                'customer_payment_id' => $payment->id,
                'type' => 'earn',
                'points' => $points,
                'balance_after' => (int) $customer->points,
                // بالعربيّة كما يكتبها الصندوق — لا بلغة طلبٍ لا مستخدمَ له (إشعار Paymob)
                'note' => Str::limit('اكتساب من سداد الفاتورة '.$invoice->number.' برابط الدفع — التحصيل '.$payment->number, 250, ''),
                'source_key' => self::key($locked),
            ]);

            self::flag($locked, self::AWARDED, null);

            return $earn;
        });
    }

    /**
     * كم نقطةً يستحقّها هذا الرابط — ولماذا لا شيء إن لم يستحقّ.
     *
     * @return array{0: int, 1: ?string, 2: ?CustomerInvoice, 3: ?CustomerPayment}
     */
    private static function decide(CustomerPaymentLink $link): array
    {
        $bid = (int) $link->business_id;
        // والسببُ بالعربيّة على الرابط — سجلٌّ يُقرأ كما كُتب، لا بلغة الطلب
        $none = fn (string $why) => [0, $why, null, null];

        $payment = CustomerPayment::where('business_id', $bid)->whereKey($link->customer_payment_id)->first();
        if (! $payment || $payment->cancelled_at !== null) {
            return $none('التحصيل ملغى');
        }

        // والفاتورةُ والعميلُ من متجر الرابط — ولا تُكسب نقاطٌ لعميلٍ غيرِ صاحب الفاتورة
        $invoice = $link->customer_invoice_id
            ? CustomerInvoice::where('business_id', $bid)->whereKey($link->customer_invoice_id)->first()
            : null;
        if (! $invoice) {
            return $none('رابطُ تحصيلٍ بلا فاتورة بيع');
        }
        if ((int) $invoice->customer_id !== (int) $link->customer_id || (int) $payment->customer_id !== (int) $link->customer_id) {
            return $none('الفاتورة ليست على عميل الرابط');
        }

        if (! self::on($bid)) {
            return $none('برنامج الولاء متوقّف أو خارج باقة المتجر');
        }
        $rate = self::rate($bid);
        if ($rate <= 0) {
            return $none('نسبة الاكتساب صفر');
        }

        $allocated = round((float) CustomerPaymentAllocation::where('customer_payment_id', $payment->id)
            ->where('customer_invoice_id', $invoice->id)->sum('amount'), 3);
        if ($allocated <= 0) {
            return $none('لم يُخصَّص للفاتورة شيء — بقي المبلغ رصيدًا للعميل');
        }

        $share = self::eligibleShare($invoice);
        if ($share <= 0) {
            return $none('الفاتورة تغطّي طلباتٍ اكتسبت نقاطها عند البيع');
        }

        // ما خُصِّص قبلُ من دفعاتِ روابطَ كسبت نقاطَها على هذه الفاتورة — ولم تُلغَ
        $before = round((float) CustomerPaymentAllocation::where('customer_invoice_id', $invoice->id)
            ->whereIn('customer_payment_id', PointTransaction::where('customer_invoice_id', $invoice->id)
                ->where('type', 'earn')->where('source_key', 'like', 'link:%')->select('customer_payment_id'))
            ->whereIn('customer_payment_id', CustomerPayment::whereNull('cancelled_at')->select('id'))
            ->where('customer_payment_id', '<>', $payment->id)
            ->sum('amount'), 3);

        $points = self::floor(($before + $allocated) * $share * $rate) - self::floor($before * $share * $rate);

        return $points > 0
            ? [$points, null, $invoice, $payment]
            : $none('المبلغ أصغر من أن يعطي نقطة');
    }

    /**
     * نصيبُ الفاتورة الذي لم تُكسب عليه نقاطٌ قبلُ — بين صفرٍ وواحد.
     *
     * فاتورةٌ تغطّي طلباتٍ (`customer_invoice_orders`) كسب الزبونُ نقاطَها في
     * الصندوق: يُطرح إجماليُّ تلك الطلبات من إجماليّ الفاتورة. وفاتورةٌ يدويّةٌ
     * بلا طلباتٍ كلُّها مؤهَّلة.
     */
    public static function eligibleShare(CustomerInvoice $invoice): float
    {
        $total = round((float) $invoice->total, 3);
        if ($total <= 0) {
            return 0.0;
        }

        $earned = round((float) DB::table('customer_invoice_orders as cio')
            ->join('orders as o', 'o.id', '=', 'cio.order_id')
            ->where('cio.customer_invoice_id', $invoice->id)
            ->where(fn ($q) => $q->where('o.points_earned', '>', 0)
                ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('point_transactions as pt')
                    ->whereColumn('pt.order_id', 'o.id')->where('pt.type', 'earn')))
            ->sum('o.total'), 3);

        return max(0.0, min(1.0, ($total - min($earned, $total)) / $total));
    }

    /**
     * يعكس نقاطَ تحصيلٍ أُلغي — في معاملة الإلغاء، مرّةً واحدة.
     *
     * بحركةٍ ثانيةٍ لا بمحو الأولى، ولا تحت الصفر: زبونٌ أنفق نقاطَه بين السداد
     * والإلغاء لا يُطالَب بما لم يعد عنده.
     */
    public static function reversePayment(CustomerPayment $payment, ?int $userId = null): void
    {
        $earns = PointTransaction::where('business_id', $payment->business_id)
            ->where('customer_payment_id', $payment->id)->where('type', 'earn')
            ->where('source_key', 'like', 'link:%')->orderBy('id')->get();

        foreach ($earns as $earn) {
            $key = $earn->source_key.':reverse';
            if (PointTransaction::where('source_key', $key)->exists()) {
                continue;
            }

            $customer = Customer::withTrashed()->where('business_id', $payment->business_id)
                ->whereKey($earn->customer_id)->lockForUpdate()->first();
            if (! $customer) {
                continue;
            }

            $take = min((int) $earn->points, max(0, (int) $customer->points));
            if ($take > 0) {
                $customer->decrement('points', $take);
            }

            PointTransaction::create([
                'business_id' => (int) $payment->business_id,
                'customer_id' => $customer->id,
                'customer_invoice_id' => $earn->customer_invoice_id,
                'customer_payment_id' => $payment->id,
                'type' => 'redeem',
                'points' => -$take,
                'balance_after' => (int) $customer->points,
                'note' => Str::limit('عكس نقاط تحصيلٍ أُلغي — '.$payment->number, 250, ''),
                'source_key' => $key,
            ]);
        }
    }

    /**
     * إعادةُ ما بقي «بانتظار النقاط» — روابطُ سقط حسمُها بعد أن ثبت مالُها.
     *
     * لا تمسّ رابطًا بلا علَم: ما سُدّد قبل هذه الميزة لا يُمنح نقاطًا بأثرٍ رجعيّ.
     */
    public static function reconcile(): int
    {
        $done = 0;

        CustomerPaymentLink::where('status', CustomerPaymentLink::PAID)
            ->where('meta->loyalty', self::PENDING)
            ->orderBy('id')
            ->chunkById(100, function ($links) use (&$done) {
                foreach ($links as $link) {
                    try {
                        $done += self::award($link) ? 1 : 0;
                    } catch (\Throwable $e) {
                        report($e);
                        self::remember($link, $e);
                    }
                }
            });

        return $done;
    }

    /**
     * حركاتُ نقاط فاتورةٍ — لصفحتها.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(CustomerInvoice $invoice): array
    {
        return PointTransaction::where('business_id', $invoice->business_id)
            ->where('customer_invoice_id', $invoice->id)
            ->with('payment:id,number')
            ->orderBy('id')->get()
            ->map(fn (PointTransaction $t) => [
                'id' => $t->id,
                'type' => $t->type,
                'points' => (int) $t->points,
                'balance_after' => (int) $t->balance_after,
                'payment' => $t->payment?->number,
                'at' => $t->created_at?->toIso8601String(),
            ])->all();
    }

    /** يكتب حسمَ الرابط — ويمحو خطأً سابقًا إن كان */
    private static function flag(CustomerPaymentLink $link, string $state, ?string $why): void
    {
        $meta = (array) ($link->meta ?? []);
        unset($meta['loyalty_error']);
        $meta['loyalty'] = $state;
        if ($why !== null) {
            $meta['loyalty_note'] = $why;
        }

        $link->forceFill(['meta' => $meta])->save();
    }

    /** سببُ السقوط على الرابط — ولا يُسقط شيئًا إن سقط هو */
    private static function remember(CustomerPaymentLink $link, \Throwable $e): void
    {
        try {
            $fresh = CustomerPaymentLink::find($link->id);
            if (! $fresh) {
                return;
            }
            $meta = (array) ($fresh->meta ?? []);
            $meta['loyalty_error'] = Str::limit($e->getMessage(), 300);
            $fresh->forceFill(['meta' => $meta])->save();
        } catch (\Throwable) {
            // السجلُّ قال ما حدث (`report`)، والرابطُ ينتظر إعادةَ المعالجة
        }
    }

    /** `floor` بعد تقريبٍ يمحو ذيلَ العائم: ١٠ × ٥ تُقرأ ٥٠ لا ٤٩٫٩٩٩٩ */
    private static function floor(float $value): int
    {
        return (int) floor(round($value, 6));
    }
}
