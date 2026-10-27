<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Addons\AddonOrders;
use App\Support\Addons\Entitlements;
use App\Support\Addons\PricingPolicy;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

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

    /** تجديدٌ صدرت فاتورتُه ولم تُسدَّد: لا تاريخَ له ولا مدّة */
    public const SUB_PENDING = 'بانتظار السداد';

    /** تجديدٌ أُلغيت فاتورتُه قبل سدادها — لم يمنح يومًا */
    public const SUB_CANCELLED = 'ملغى';

    /** فاتورةُ تجديدٍ أُلغيت قبل سدادها: لا تُسدَّد بعدها */
    public const CANCELLED = 'ملغاة';

    /*
     * ═══ ولمَ للفاتورة نوع ═══
     *
     * كانت كلُّ فاتورةٍ في الجدول دورةَ اشتراك، فلم يكن للنوع معنى. ثمّ صار
     * يُباع فيه شيءٌ ثانٍ: حزمةُ رسائل واتساب (انظر `WhatsAppPacks`). ومن
     * غير النوع تُقرأ الفاتورتان سطرًا واحدًا في دفتر التحصيل — وأسوأ:
     * `markPaid` تُعلّم اشتراكًا غيرَ مدفوعٍ مدفوعًا بفاتورةِ رسائل.
     */

    /** دورةُ باقة */
    public const KIND_SUBSCRIPTION = 'اشتراك';

    /** حزمةُ رسائل واتساب */
    public const KIND_MESSAGES = 'رسائل';

    /** إضافةُ اشتراك — فاتورتُها منفصلةٌ عن الباقة (`Addons\AddonOrders`) */
    public const KIND_ADDON = 'إضافة';

    /**
     * أسُدِّد لهذا المتجر اشتراكٌ قطّ؟ — فاتورةُ اشتراكٍ مدفوعة، ساريةً أو منقضية.
     *
     * الحقيقةُ نفسُها التي يقرؤها `Business::scopeSubscribed`: جدولُ `invoices`
     * بحالة «مدفوعة». لكنّ النوعَ يُسأل هنا كما تسأله `markPaid` — حزمةُ رسائلَ
     * سُدِّدت ليست اشتراكًا دُفع. ولا يُقرأ `ends_at`: من دفع مرّةً ثمّ انقضى
     * اشتراكُه عميلٌ دفع، لا تجربةٌ لم تُدفع.
     */
    public static function hasPaidSubscription(Business $business): bool
    {
        return Invoice::where('business_id', $business->id)
            ->where('status', self::PAID)
            ->where('kind', self::KIND_SUBSCRIPTION)
            ->exists();
    }

    /**
     * دوراتُ التجديد — وما يقبله طلبُ التجديد منها.
     *
     * نصفُ السنة ستّةُ أشهرٍ بسعر الشهر ستَّ مرّات: لا سعرَ له في الباقة ولا
     * خصم، كما يُعرض في الموقع. ودورتُه واحدة وفاتورتُه واحدة — قبله كان يُجدَّد
     * «شهرًا» ستَّ مرّات فيخرج للتاجر ستُّ فواتير عن اشتراكٍ واحد.
     */
    public const CYCLES = ['monthly', 'semiannual', 'yearly'];

    /**
     * ما تُكلّفه دورةٌ من الباقة — بسعرها في سياسة تسعير المتجر.
     *
     * متجرُ السياسة السابقة بسعر الباقة كما كان، والجديدةُ بسعرها المعتمد فيها
     * (`Addons\PricingPolicy::planPrice`). والإضافاتُ لا تدخل هنا: لكلٍّ فاتورتُها.
     */
    public static function price(Business $business, string $cycle): float
    {
        return PricingPolicy::planPrice($business, $cycle);
    }

    /**
     * إصدارُ فاتورة تجديد — والاشتراكُ كما هو حتى تُسدَّد.
     *
     * كان هذا يُطيل `ends_at` ويُنشّط المتجر لحظةَ الإصدار: فاتورةٌ تُكتب
     * فيعمل المتجر دورتَه كلَّها ولو لم يدفع منها شيئًا، والمنتهي يعود «نشطًا»
     * بضغطة. فالإصدارُ الآن يكتب الفاتورة وصفَّ تجديدٍ «بانتظار السداد» بلا
     * تاريخَين، والمدّةُ تُمنح في `markPaid` وحدَها.
     *
     * ═══ وتجديدٌ معلّقٌ واحد للمتجر ═══
     *
     * فاتورتان معلّقتان لمتجرٍ واحد لا يُعرف أيُّهما يُدفع، وسدادُهما معًا
     * يمنح دورتين عن نيّة دورة. فالثانيةُ تُرفض حتى تُسدَّد الأولى أو تُلغى.
     * والسؤالُ تحت قفل صفّ المتجر: ضغطتان معًا تنتظر إحداهما الأخرى فترى
     * تجديدَها، لا تقرآن «لا معلّق» كلتاهما.
     *
     * @return array{subscription: Subscription, invoice: Invoice}
     */
    public static function renew(Business $business, string $cycle = 'monthly'): array
    {
        if (! in_array($cycle, self::CYCLES, true)) {
            throw new InvalidArgumentException('Unknown billing cycle: '.$cycle);
        }

        return DB::transaction(function () use ($business, $cycle) {
            $business = Business::whereKey($business->id)->lockForUpdate()->firstOrFail();

            if ($open = self::pendingRenewal($business)) {
                throw new DomainException(__('للمتجر فاتورةُ تجديد :n بانتظار السداد. سجّل سدادها أو ألغِها قبل إصدار غيرها.', [
                    'n' => $open->invoice?->number ?? '—',
                ]));
            }

            $amount = self::price($business, $cycle);
            $invoice = self::issue($business, $amount);

            $subscription = Subscription::create([
                'business_id' => $business->id,
                'plan_id' => $business->plan_id,
                'starts_at' => null,
                'ends_at' => null,
                // المبلغُ مبلغُ الفاتورة: تعديلُ سعر الباقة غدًا لا يُغيّر ما صدر
                'amount' => $amount,
                'payment_status' => 'غير مدفوع',
                'status' => self::SUB_PENDING,
                'invoice_id' => $invoice->id,
                'cycle' => $cycle,
            ]);

            Activity::log('created', 'أصدر فاتورة تجديد '.$invoice->number.' لـ'.$business->name.' — بانتظار السداد', [
                'business_id' => null,
                'subject_id' => $business->id,
            ]);

            return ['subscription' => $subscription, 'invoice' => $invoice];
        });
    }

    /**
     * ترقيةُ المتجر إلى باقةٍ أخرى في حسابه نفسِه — تُفوتَر الآن وتسري بالسداد.
     *
     * ═══ ولمَ لا تُبدَّل الباقةُ الآن ═══
     *
     * قدراتُ الباقة تتبع `plan_id` لحظةَ يتغيّر (`PlanFeatures`): تبديلُه قبل
     * السداد يفتح للتجربة مزايا الاحترافيّة وفاتورتُها لم تُدفع. فالفاتورةُ تصدر
     * بباقتها وسعرِها في سياسة المتجر، والمتجرُ على باقته حتى تُسدَّد — وعندها
     * ينتقل (`start`) ببياناته كلّها: لا متجرَ جديد ولا حسابَ مكرّر.
     *
     * ودورتُها تبدأ يومَ السداد لا بعد ما بقي من الحالية: ما بقي من تجربةٍ
     * مجّانيّةٍ لا يصير أيّامًا مدفوعةً على باقةٍ أغلى.
     *
     * @return array{subscription: Subscription, invoice: Invoice}
     */
    public static function upgrade(Business $business, Plan $to, string $cycle): array
    {
        if (! in_array($cycle, self::CYCLES, true)) {
            throw new InvalidArgumentException('Unknown billing cycle: '.$cycle);
        }

        return DB::transaction(function () use ($business, $to, $cycle) {
            $business = Business::whereKey($business->id)->lockForUpdate()->firstOrFail();

            if ((int) $business->plan_id === (int) $to->id) {
                throw new DomainException(__('المتجر على «:p» أصلًا — جدّد اشتراكه بدل ترقيته.', ['p' => $to->name]));
            }
            if ($open = self::pendingRenewal($business)) {
                throw new DomainException(__('للمتجر فاتورةُ تجديد :n بانتظار السداد. سجّل سدادها أو ألغِها قبل إصدار غيرها.', [
                    'n' => $open->invoice?->number ?? '—',
                ]));
            }

            $amount = PricingPolicy::planPriceFor($business, $to, $cycle);
            $invoice = self::issue($business, $amount, $to);

            $subscription = Subscription::create([
                'business_id' => $business->id,
                'plan_id' => $to->id,
                'from_plan_id' => $business->plan_id,
                'starts_at' => null,
                'ends_at' => null,
                'amount' => $amount,
                'payment_status' => 'غير مدفوع',
                'status' => self::SUB_PENDING,
                'invoice_id' => $invoice->id,
                'cycle' => $cycle,
            ]);

            Activity::log('created', 'أصدر فاتورة ترقية '.$invoice->number.' لـ'.$business->name.' إلى «'.$to->name.'» — تسري بالسداد', [
                'business_id' => null,
                'subject_id' => $business->id,
            ]);

            return ['subscription' => $subscription, 'invoice' => $invoice];
        });
    }

    /** تجديدُ المتجر الذي صدرت فاتورتُه ولم تُسدَّد — إن كان */
    public static function pendingRenewal(Business $business): ?Subscription
    {
        return Subscription::with('invoice')
            ->where('business_id', $business->id)
            ->where('status', self::SUB_PENDING)
            ->latest('id')
            ->first();
    }

    /**
     * نهايةُ دورةٍ تبدأ من يوم.
     *
     * بلا فيضٍ إلى الشهر التالي: ستّةُ أشهرٍ من ٣١ ديسمبر تنتهي في ٣٠ يونيو،
     * لا في الأوّل من يوليو؛ وشهرٌ من ٣١ يناير ينتهي آخرَ فبراير.
     */
    public static function extend(CarbonInterface $from, ?string $cycle): CarbonInterface
    {
        return match ($cycle) {
            'yearly' => $from->copy()->addYearsNoOverflow(1),
            'semiannual' => $from->copy()->addMonthsNoOverflow(6),
            default => $from->copy()->addMonthsNoOverflow(1),
        };
    }

    /** فاتورة جديدة برقمٍ متسلسل لا يتكرّر — بباقة المتجر، أو بالتي يُرقّى إليها */
    public static function issue(Business $business, float $amount, ?Plan $plan = null): Invoice
    {
        $plan ??= $business->plan;

        return Invoice::create([
            'number' => self::nextNumber(),
            'business_id' => $business->id,
            'plan_id' => $plan?->id ?? $business->plan_id,
            'amount' => $amount,
            'issued_at' => now(),
            'status' => self::UNPAID,
            'kind' => self::KIND_SUBSCRIPTION,
            'note' => $plan?->name,
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
    public static function nextNumber(): string
    {
        $last = Invoice::where('number', 'like', 'INV-P-%')
            ->orderByRaw('LENGTH(number) DESC, number DESC')
            ->value('number');

        $n = $last ? ((int) substr($last, 6)) + 1 : 1;

        return 'INV-P-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    /**
     * تسجيلُ السداد — وهنا وحدَه يبدأ التجديد.
     *
     * في معاملةٍ واحدة وتحت قفل الفاتورة: نداءان معًا (ضغطتان، أو إعادةُ
     * محاولة) ينتظر ثانيهما الأوّلَ فيقرأها «مدفوعة» ويعود بلا شيء — لا
     * تمديدَ ثانيًا. وأيُّ فشلٍ في الطريق يُرجع الكلَّ: لا فاتورةَ مدفوعةً
     * وتجديدُها معلّق.
     *
     * والتجديدُ هو صفُّ `subscriptions` الذي يحمل هذه الفاتورة بعينها
     * (`invoice_id`)، لا «آخرُ اشتراكٍ غيرِ مدفوع» للمتجر.
     */
    public static function markPaid(Invoice $invoice): Invoice
    {
        DB::transaction(function () use ($invoice) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->first();

            if (! $invoice || $invoice->status === self::PAID) {
                return;
            }

            if ($invoice->status === self::CANCELLED) {
                throw new DomainException(__('الفاتورة :n ملغاة، فلا يُسجَّل سدادها.', ['n' => $invoice->number]));
            }

            /*
             * ولا يُمسّ اشتراكٌ إلّا بفاتورةِ اشتراك.
             *
             * فلمّا دخلت فاتورةُ الرسائل كان سدادُ خمسةِ ريالاتٍ يُقيّد اشتراكًا
             * سنويًّا مدفوعًا — خطأٌ في المال لا في الشاشة.
             */
            if (($invoice->kind ?? self::KIND_SUBSCRIPTION) === self::KIND_SUBSCRIPTION) {
                $renewal = Subscription::where('invoice_id', $invoice->id)->lockForUpdate()->first();

                $renewal
                    ? self::start($invoice, $renewal)
                    : self::settleLegacy($invoice);
            }

            $invoice->update(['status' => self::PAID]);

            /*
             * وحزمةُ الرسائل تُشحن هنا لا في شاشةٍ أخرى — داخل المعاملة نفسِها:
             * إمّا أن تُقيَّد الفاتورة مدفوعةً ويُضاف الرصيد معًا، وإمّا ألّا يقع شيء.
             */
            WhatsAppPacks::settle($invoice);

            // وفاتورةُ الإضافة يُفعَّل استحقاقُها هنا — في المعاملة نفسِها وتحت قفلها
            AddonOrders::settle($invoice);

            Activity::log('created', 'سجّل سداد الفاتورة: '.$invoice->number, [
                'business_id' => null,
                'subject_id' => $invoice->id,
            ]);
        });

        return $invoice->fresh();
    }

    /**
     * يبدأ التجديدُ المسدَّد: من نهاية الاشتراك إن لم تمضِ، وإلّا من اليوم.
     *
     * من جدّد قبل أسبوعٍ من انتهائه لا يخسر ذلك الأسبوع — وإلّا صار التجديدُ
     * المبكّر عقوبة. ويُحسب يومَ السداد لا يومَ الإصدار: من أُصدرت فاتورتُه
     * في أكتوبر ودفع بعد انقضاء اشتراكه تبدأ دورتُه يومَ دفع.
     */
    private static function start(Invoice $invoice, Subscription $renewal): void
    {
        if ((int) $renewal->business_id !== (int) $invoice->business_id) {
            throw new DomainException(__('تجديد هذه الفاتورة لمتجرٍ آخر، فلم يُسجَّل السداد.'));
        }

        if ($renewal->status !== self::SUB_PENDING) {
            throw new DomainException(__('تجديد الفاتورة :n ليس بانتظار السداد، فلم يُسجَّل السداد.', ['n' => $invoice->number]));
        }

        $business = Business::whereKey($invoice->business_id)->lockForUpdate()->firstOrFail();

        /*
         * ═══ وفاتورةُ باقةٍ لا تُفعَّل على باقةٍ أخرى ═══
         *
         * صدرت لـ«الأساسية» ثمّ نُقل المتجر إلى «المؤسسات» قبل السداد: تفعيلُها
         * يمنحه دورةً على باقةٍ أغلى بسعر الأرخص — أو العكس. فيُرفض السداد
         * ويُقال لماذا: تُلغى وتُصدر فاتورةٌ بالباقة الحاليّة.
         */
        $upgrade = $renewal->from_plan_id !== null;

        // والترقيةُ صدرت لنقله من باقةٍ بعينها: إن نُقل بعدها بيدٍ أخرى فهي لغيره
        $expected = $upgrade ? $renewal->from_plan_id : $renewal->plan_id;
        if ($expected && (int) $expected !== (int) $business->plan_id) {
            throw new DomainException(__('تغيّرت باقة المتجر بعد إصدار الفاتورة :n. ألغِها وأصدر فاتورة تجديد بالباقة الحالية.', ['n' => $invoice->number]));
        }

        $from = (! $upgrade && $business->ends_at && $business->ends_at->isFuture())
            ? $business->ends_at->copy()
            : now()->startOfDay();
        $ends = self::extend($from, $renewal->cycle);

        $renewal->update([
            'starts_at' => $from,
            'ends_at' => $ends,
            'payment_status' => 'مدفوع',
            'status' => self::SUB_ACTIVE,
        ]);

        $business->update([
            'starts_at' => $business->starts_at ?? now()->startOfDay(),
            'ends_at' => $ends,
            'status' => 'نشط',
        ] + ($upgrade ? ['plan_id' => $renewal->plan_id] : []));

        if ($upgrade) {
            // قدراتُ الباقة الجديدة تُقرأ من الآن — لا ما خُزّن قبل الانتقال
            Entitlements::flush();
        }

        Activity::log('updated', 'فُعّل تجديد '.$business->name.' حتى '.$ends->format('Y-m-d').' بسداد '.$invoice->number, [
            'business_id' => null,
            'subject_id' => $business->id,
        ]);
    }

    /**
     * فاتورةُ اشتراكٍ صدرت قبل أن يحمل التجديدُ فاتورتَه.
     *
     * يومها مُدّ الاشتراك عند الإصدار، فسدادُها لا يمدّ شيئًا ثانيةً. ودورتُها
     * تُعلَّم مدفوعةً إن عُرفت بلا تخمين: صفٌّ واحدٌ للمتجر نفسِه، غيرُ مدفوع،
     * بالمبلغ نفسِه، كُتب مع الفاتورة في الثواني نفسِها (المعاملةُ القديمة كتبتهما
     * معًا). وإلّا تُسدَّد الفاتورة وحدَها، ودورتُها تُراجع من شاشة الاشتراكات.
     */
    private static function settleLegacy(Invoice $invoice): void
    {
        if (! $invoice->created_at) {
            return;
        }

        $candidates = Subscription::where('business_id', $invoice->business_id)
            ->whereNull('invoice_id')
            ->where('payment_status', 'غير مدفوع')
            ->where('amount', $invoice->amount)
            ->whereBetween('created_at', [$invoice->created_at->copy()->subSeconds(5), $invoice->created_at->copy()->addSeconds(5)])
            ->lockForUpdate()
            ->limit(2)
            ->get();

        if ($candidates->count() === 1) {
            $candidates->first()->update(['payment_status' => 'مدفوع']);
        }
    }

    /**
     * إلغاءُ فاتورة تجديدٍ لم تُسدَّد — وتجديدُها معها.
     *
     * لا يُلغى إلّا ما لم يمنح شيئًا: فاتورةٌ قديمةٌ مُدّ اشتراكُها عند إصدارها
     * لا تُلغى هنا (إلغاؤها يترك المدّة الممنوحة بلا فاتورة)، ولا فاتورةُ
     * رسائل (لها طلبُها وإلغاؤه في شاشة واتساب).
     */
    public static function cancelRenewal(Invoice $invoice): Invoice
    {
        DB::transaction(function () use ($invoice) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $renewal = Subscription::where('invoice_id', $invoice->id)->lockForUpdate()->first();

            if ($invoice->status !== self::UNPAID || ! $renewal || $renewal->status !== self::SUB_PENDING) {
                throw new DomainException(__('لا تُلغى إلا فاتورة تجديدٍ بانتظار السداد.'));
            }

            $invoice->update(['status' => self::CANCELLED]);
            $renewal->update(['status' => self::SUB_CANCELLED]);

            Activity::log('deleted', 'ألغى فاتورة التجديد: '.$invoice->number, [
                'business_id' => null,
                'subject_id' => $invoice->id,
            ]);
        });

        return $invoice->fresh();
    }
}
