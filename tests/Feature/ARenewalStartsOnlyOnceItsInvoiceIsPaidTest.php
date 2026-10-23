<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Billing;
use App\Support\Demo;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/**
 * التجديدُ يبدأ يوم تُسدَّد فاتورتُه — لا يوم تُصدر.
 *
 * ═══ ما كان ═══
 *
 * `Billing::renew` كان يُطيل `ends_at` ويُنشّط المتجر لحظةَ الإصدار: فاتورةٌ
 * تُكتب فيعمل المتجر دورتَه كلَّها ولو لم يدفع منها شيئًا، والمنتهي يعود
 * «نشطًا» بضغطة. و`markPaid` كانت تُعلّم «آخرَ اشتراكٍ غيرِ مدفوع» مدفوعًا —
 * تخمينٌ لا رابط.
 *
 * ═══ ما صار ═══
 *
 * الإصدارُ يكتب فاتورةً واحدة وصفَّ تجديدٍ «بانتظار السداد» يحمل فاتورتَه
 * (`invoice_id`) ودورتَه، بلا تاريخَين. والسدادُ — مرّةً واحدة، تحت القفل —
 * يمدّ الاشتراك من نهايته إن لم تمضِ، وإلّا من يوم السداد.
 */
class ARenewalStartsOnlyOnceItsInvoiceIsPaidTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    /** @var array<string, Plan> */
    private array $plans = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-23 10:00:00');

        $this->super = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'super@abaad.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);

        foreach ([
            'basic' => ['الأساسية', 9.9, 99],
            'pro' => ['الاحترافية', 29.9, 299],
            'enterprise' => ['المؤسسات', 69.9, 699],
        ] as $key => [$name, $monthly, $yearly]) {
            $this->plans[$key] = Plan::create([
                'name' => $name, 'monthly_price' => $monthly, 'yearly_price' => $yearly,
                'max_branches' => null, 'max_employees' => null, 'max_products' => null,
            ]);
        }
    }

    private function shop(string $plan = 'pro', ?string $endsAt = '2026-12-31', string $status = 'نشط', string $name = 'متجري'): Business
    {
        return Business::create([
            'name' => $name, 'type' => 'عام', 'status' => $status, 'plan_id' => $this->plans[$plan]->id,
            'starts_at' => '2026-01-01', 'ends_at' => $endsAt,
        ]);
    }

    private function toast(): ?string
    {
        return session('toast')['msg'] ?? null;
    }

    /* ─────────── الدورات الثلاث: قبل السداد وبعده ─────────── */

    public function test_each_cycle_waits_for_its_payment_then_extends_by_its_own_length(): void
    {
        foreach ([
            ['monthly', '29.900', '2027-01-31'],
            ['semiannual', '179.400', '2027-06-30'],
            ['yearly', '299.000', '2027-12-31'],
        ] as [$cycle, $amount, $ends]) {
            $shop = $this->shop(name: 'متجر '.$cycle);

            $this->actingAs($this->super)
                ->post(route('super-admin.businesses.renew', $shop->id), ['cycle' => $cycle])
                ->assertSessionHasNoErrors();

            $invoices = Invoice::where('business_id', $shop->id)->get();
            $this->assertCount(1, $invoices, "{$cycle}: أكثر من فاتورة");
            $invoice = $invoices->first();
            $this->assertSame($amount, $invoice->amount, $cycle);
            $this->assertSame(Billing::UNPAID, $invoice->status, $cycle);
            $this->assertSame(
                __('تم إصدار فاتورة التجديد :n. لن يتم تمديد الاشتراك إلا بعد تسجيل السداد.', ['n' => $invoice->number]),
                $this->toast(),
                $cycle,
            );

            // قبل السداد: لا يتغيّر شيء
            $this->assertSame('2026-12-31', $shop->fresh()->ends_at->toDateString(), "{$cycle}: مُدّ قبل السداد");
            $this->assertSame('نشط', $shop->fresh()->status);
            $renewal = Subscription::where('business_id', $shop->id)->sole();
            $this->assertSame(Billing::SUB_PENDING, $renewal->status, $cycle);
            $this->assertSame($invoice->id, (int) $renewal->invoice_id, $cycle);
            $this->assertSame($cycle, $renewal->cycle);
            $this->assertNull($renewal->starts_at);
            $this->assertNull($renewal->ends_at);

            // بعد السداد: من نهاية الاشتراك، بطول دورته
            $this->actingAs($this->super)
                ->post(route('super-admin.invoices.pay', $invoice->id))
                ->assertSessionHasNoErrors();

            $this->assertSame($ends, $shop->fresh()->ends_at->toDateString(), $cycle);
            $renewal = $renewal->fresh();
            $this->assertSame(Billing::SUB_ACTIVE, $renewal->status);
            $this->assertSame('مدفوع', $renewal->payment_status);
            $this->assertSame('2026-12-31', $renewal->starts_at->toDateString(), "{$cycle}: لم يبدأ من نهاية الاشتراك");
            $this->assertSame($ends, $renewal->ends_at->toDateString());
            $this->assertSame($amount, $renewal->amount);
            $this->assertSame(Billing::PAID, $invoice->fresh()->status);
            $this->assertSame(
                __('تم تسجيل السداد وتفعيل تجديد الاشتراك بنجاح حتى :date.', ['date' => $ends]),
                $this->toast(),
            );
        }
    }

    public function test_half_a_year_costs_six_monthly_prices_on_every_plan(): void
    {
        foreach (['basic' => [9.9, 59.4, 99.0], 'pro' => [29.9, 179.4, 299.0], 'enterprise' => [69.9, 419.4, 699.0]] as $plan => [$m, $h, $y]) {
            $shop = $this->shop($plan, name: 'متجر '.$plan);
            $this->assertSame($m, Billing::price($shop, 'monthly'), $plan);
            $this->assertSame($h, Billing::price($shop, 'semiannual'), $plan);
            $this->assertSame($y, Billing::price($shop, 'yearly'), $plan);
        }
    }

    /** والمنتهي يبقى منتهيًا حتى يدفع، ثمّ تبدأ دورتُه يومَ دفع لا يومَ صدرت فاتورتُه */
    public function test_an_expired_shop_stays_expired_until_it_pays_then_starts_on_the_day_it_pays(): void
    {
        $shop = $this->shop(endsAt: '2026-09-01', status: 'منتهي');
        $invoice = Billing::renew($shop->fresh(), 'semiannual')['invoice'];

        $this->assertSame('منتهي', $shop->fresh()->status, 'نشط المنتهي بفاتورةٍ لم تُسدَّد');
        $this->assertSame('2026-09-01', $shop->fresh()->ends_at->toDateString());

        $this->travelTo('2026-10-30 09:00:00');
        Billing::markPaid($invoice);

        $this->assertSame('نشط', $shop->fresh()->status);
        $this->assertSame('2027-04-30', $shop->fresh()->ends_at->toDateString());
        $this->assertSame('2026-10-30', Subscription::where('invoice_id', $invoice->id)->sole()->starts_at->toDateString());
    }

    /* ─────────── السداد مرّةً واحدة ─────────── */

    public function test_a_payment_recorded_twice_extends_once(): void
    {
        $shop = $this->shop();
        $invoice = Billing::renew($shop->fresh(), 'monthly')['invoice'];

        $this->actingAs($this->super)->post(route('super-admin.invoices.pay', $invoice->id));
        $this->actingAs($this->super)->post(route('super-admin.invoices.pay', $invoice->id));
        Billing::markPaid($invoice);

        $this->assertSame('2027-01-31', $shop->fresh()->ends_at->toDateString(), 'سدادٌ مكرّر مدّ مرّة ثانية');
        $this->assertSame(1, Subscription::where('business_id', $shop->id)->count());
        $this->assertSame(1, ActivityLog::where('description', 'like', 'فُعّل تجديد%')->count());
        $this->assertSame(1, ActivityLog::where('description', 'سجّل سداد الفاتورة: '.$invoice->number)->count());
    }

    /** وفاتورةٌ ملغاة لا تُسدَّد ولا تمنح شيئًا — ويُصدر بعدها تجديدٌ جديد */
    public function test_a_cancelled_invoice_cannot_be_paid_and_grants_nothing(): void
    {
        $shop = $this->shop();
        ['invoice' => $invoice, 'subscription' => $renewal] = Billing::renew($shop->fresh(), 'yearly');

        $this->actingAs($this->super)->post(route('super-admin.invoices.cancel', $invoice->id))->assertSessionHasNoErrors();
        $this->assertSame(Billing::CANCELLED, $invoice->fresh()->status);
        $this->assertSame(Billing::SUB_CANCELLED, $renewal->fresh()->status);

        $this->actingAs($this->super)->post(route('super-admin.invoices.pay', $invoice->id));
        $this->assertSame('danger', session('toast')['type']);
        $this->assertSame(Billing::CANCELLED, $invoice->fresh()->status);
        $this->assertSame('2026-12-31', $shop->fresh()->ends_at->toDateString(), 'فاتورةٌ ملغاة مدّت الاشتراك');

        $this->expectException(DomainException::class);
        try {
            Billing::markPaid($invoice->fresh());
        } finally {
            // والإلغاءُ يفتح الباب لتجديدٍ جديد
            $this->assertSame(Billing::SUB_PENDING, Billing::renew($shop->fresh(), 'monthly')['subscription']->status);
        }
    }

    /** وما سُدِّد لا يُلغى، ولا فاتورةُ اشتراكٍ قديمةٌ مُدّ اشتراكُها عند إصدارها */
    public function test_only_a_waiting_renewal_invoice_can_be_cancelled(): void
    {
        $shop = $this->shop();
        $paid = Billing::renew($shop->fresh(), 'monthly')['invoice'];
        Billing::markPaid($paid);
        $legacy = Billing::issue($shop->fresh(), 29.9);

        foreach ([$paid, $legacy] as $invoice) {
            $this->actingAs($this->super)->post(route('super-admin.invoices.cancel', $invoice->id));
            $this->assertSame('warning', session('toast')['type'], $invoice->number);
            $this->assertNotSame(Billing::CANCELLED, $invoice->fresh()->status, $invoice->number);
        }
    }

    /* ─────────── تجديدٌ معلّقٌ واحد ─────────── */

    public function test_a_second_renewal_is_refused_while_one_waits(): void
    {
        $shop = $this->shop();
        $first = Billing::renew($shop->fresh(), 'monthly')['invoice'];

        $this->actingAs($this->super)
            ->post(route('super-admin.businesses.renew', $shop->id), ['cycle' => 'yearly']);

        $this->assertSame('warning', session('toast')['type']);
        $this->assertStringContainsString($first->number, $this->toast());
        $this->assertSame(1, Invoice::where('business_id', $shop->id)->count());
        $this->assertSame(1, Subscription::where('business_id', $shop->id)->where('status', Billing::SUB_PENDING)->count());

        // وبعد سداد الأولى يُصدر التالي، ويُبنى على ما منحته
        Billing::markPaid($first);
        Billing::markPaid(Billing::renew($shop->fresh(), 'semiannual')['invoice']);
        $this->assertSame('2027-07-31', $shop->fresh()->ends_at->toDateString());
    }

    public function test_an_unknown_cycle_is_refused_without_an_invoice(): void
    {
        $shop = $this->shop();

        $this->actingAs($this->super)
            ->post(route('super-admin.businesses.renew', $shop->id), ['cycle' => 'quarterly'])
            ->assertSessionHasErrors('cycle');

        $this->assertSame(0, Invoice::where('business_id', $shop->id)->count());
        $this->assertSame(0, Subscription::where('business_id', $shop->id)->count());
    }

    /* ─────────── ما لا يمسّه سدادٌ آخر ─────────── */

    public function test_paying_a_messages_invoice_does_not_start_a_waiting_renewal(): void
    {
        $shop = $this->shop();
        $renewal = Billing::renew($shop->fresh(), 'monthly')['subscription'];
        $messages = Invoice::create([
            'number' => Billing::nextNumber(), 'business_id' => $shop->id, 'plan_id' => null,
            'amount' => 29.9, 'issued_at' => now(), 'status' => Billing::UNPAID,
            'kind' => Billing::KIND_MESSAGES, 'note' => '٥٠٠ رسالة واتساب',
        ]);

        Billing::markPaid($messages);

        $this->assertSame(Billing::PAID, $messages->fresh()->status);
        $this->assertSame(Billing::SUB_PENDING, $renewal->fresh()->status, 'سدادُ رسائلَ فعّل تجديدًا');
        $this->assertSame('غير مدفوع', $renewal->fresh()->payment_status);
        $this->assertSame('2026-12-31', $shop->fresh()->ends_at->toDateString());
    }

    public function test_paying_one_shops_renewal_leaves_another_shop_alone(): void
    {
        $mine = $this->shop(name: 'متجري');
        $theirs = $this->shop('basic', '2026-11-15', name: 'متجرهم');
        $myInvoice = Billing::renew($mine->fresh(), 'monthly')['invoice'];
        $theirRenewal = Billing::renew($theirs->fresh(), 'yearly')['subscription'];

        Billing::markPaid($myInvoice);

        $this->assertSame('2027-01-31', $mine->fresh()->ends_at->toDateString());
        $this->assertSame('2026-11-15', $theirs->fresh()->ends_at->toDateString(), 'سدادُ متجرٍ مدّ متجرًا آخر');
        $this->assertSame(Billing::SUB_PENDING, $theirRenewal->fresh()->status);
        $this->assertSame(Billing::UNPAID, $theirRenewal->invoice->fresh()->status);
    }

    /* ─────────── الدورات القديمة ─────────── */

    /**
     * فاتورةٌ صدرت قبل الإصلاح مُدّ اشتراكُها يوم صدرت — فسدادُها لا يمدّ ثانيةً.
     *
     * ودورتُها تُعلَّم مدفوعةً بالمطابقة الصارمة وحدها، لا «آخرُ غيرِ مدفوع».
     */
    public function test_a_legacy_invoice_is_settled_without_a_second_extension(): void
    {
        $shop = $this->shop(endsAt: '2026-11-23');

        // كما كتبها `renew` القديم: دورةٌ ممنوحة بلا فاتورةٍ مربوطة، وفاتورتُها معها
        $this->travelTo('2026-10-23 09:00:00');
        $cycle = Subscription::create([
            'business_id' => $shop->id, 'plan_id' => $shop->plan_id, 'starts_at' => '2026-10-23', 'ends_at' => '2026-11-23',
            'amount' => 29.9, 'payment_status' => 'غير مدفوع', 'status' => 'نشط',
        ]);
        $invoice = Billing::issue($shop->fresh(), 29.9);

        // ودورةٌ أحدثُ غيرُ مدفوعةٍ بمبلغٍ آخر — كان التخمينُ القديم يلتقطها هي
        $this->travelTo('2026-10-23 10:00:00');
        $other = Subscription::create([
            'business_id' => $shop->id, 'plan_id' => $shop->plan_id, 'starts_at' => '2026-11-23', 'ends_at' => '2027-11-23',
            'amount' => 299, 'payment_status' => 'غير مدفوع', 'status' => 'نشط',
        ]);

        Billing::markPaid($invoice);

        $this->assertSame(Billing::PAID, $invoice->fresh()->status);
        $this->assertSame('مدفوع', $cycle->fresh()->payment_status);
        $this->assertSame('غير مدفوع', $other->fresh()->payment_status, 'عُلّمت دورةٌ لا تخصّ الفاتورة');
        $this->assertSame('2026-11-23', $shop->fresh()->ends_at->toDateString(), 'فاتورةٌ قديمة مدّت الاشتراك ثانيةً');
        $this->assertSame('2026-11-23', $cycle->fresh()->ends_at->toDateString());
        $this->assertSame(2, Subscription::where('business_id', $shop->id)->count());
    }

    /** وإن التبست الدورةُ القديمة بأخرى لم تُعلَّم أيٌّ منهما: تُسدَّد الفاتورة وحدها */
    public function test_an_ambiguous_legacy_invoice_marks_no_cycle(): void
    {
        $shop = $this->shop();
        $twins = collect(range(1, 2))->map(fn () => Subscription::create([
            'business_id' => $shop->id, 'plan_id' => $shop->plan_id, 'starts_at' => '2026-10-23', 'ends_at' => '2026-11-23',
            'amount' => 29.9, 'payment_status' => 'غير مدفوع', 'status' => 'نشط',
        ]));
        $invoice = Billing::issue($shop->fresh(), 29.9);

        Billing::markPaid($invoice);

        $this->assertSame(Billing::PAID, $invoice->fresh()->status);
        $twins->each(fn ($s) => $this->assertSame('غير مدفوع', $s->fresh()->payment_status));
        $this->assertSame('2026-12-31', $shop->fresh()->ends_at->toDateString());
    }

    /* ─────────── المبلغ والباقة ─────────── */

    public function test_what_was_issued_keeps_its_amount_when_the_plan_price_changes(): void
    {
        $shop = $this->shop();
        ['invoice' => $invoice, 'subscription' => $renewal] = Billing::renew($shop->fresh(), 'semiannual');

        $this->plans['pro']->update(['monthly_price' => 39.9]);
        Billing::markPaid($invoice);

        $this->assertSame('179.400', $invoice->fresh()->amount);
        $this->assertSame('179.400', $renewal->fresh()->amount);
        $this->assertSame('2027-06-30', $shop->fresh()->ends_at->toDateString());
    }

    /** وفاتورةُ باقةٍ لا تُفعَّل على باقةٍ أخرى: يُرفض السداد ويُقال لماذا */
    public function test_a_plan_changed_after_issuing_refuses_the_payment(): void
    {
        $shop = $this->shop('basic');
        ['invoice' => $invoice, 'subscription' => $renewal] = Billing::renew($shop->fresh(), 'yearly');
        $shop->update(['plan_id' => $this->plans['enterprise']->id]);

        $this->actingAs($this->super)->post(route('super-admin.invoices.pay', $invoice->id));

        $this->assertSame('danger', session('toast')['type']);
        $this->assertSame(
            __('تغيّرت باقة المتجر بعد إصدار الفاتورة :n. ألغِها وأصدر فاتورة تجديد بالباقة الحالية.', ['n' => $invoice->number]),
            $this->toast(),
        );
        $this->assertSame(Billing::UNPAID, $invoice->fresh()->status);
        $this->assertSame(Billing::SUB_PENDING, $renewal->fresh()->status);
        $this->assertSame('2026-12-31', $shop->fresh()->ends_at->toDateString());
    }

    /** وفشلٌ في منتصف التفعيل يُرجع الكلّ: لا فاتورةَ مدفوعةً وتجديدُها معلّق */
    public function test_a_failure_while_starting_the_renewal_leaves_the_invoice_unpaid(): void
    {
        $shop = $this->shop();
        ['invoice' => $invoice, 'subscription' => $renewal] = Billing::renew($shop->fresh(), 'monthly');

        Business::updating(function (Business $b) use ($shop) {
            if ($b->id === $shop->id) {
                throw new RuntimeException('انقطع الاتصال');
            }
        });

        try {
            Billing::markPaid($invoice);
            $this->fail('لم يصل الفشل');
        } catch (RuntimeException) {
        }

        $this->assertSame(Billing::UNPAID, $invoice->fresh()->status, 'سُجّل السداد وتجديدُه لم يبدأ');
        $this->assertSame(Billing::SUB_PENDING, $renewal->fresh()->status);
        $this->assertNull($renewal->fresh()->ends_at);
        $this->assertSame('2026-12-31', $shop->fresh()->ends_at->toDateString());
    }

    /* ─────────── شاشة الاشتراكات لا تفتح بابًا جانبيًّا ─────────── */

    public function test_a_waiting_renewal_cannot_be_edited_or_deleted_into_a_granted_one(): void
    {
        $shop = $this->shop();
        $renewal = Billing::renew($shop->fresh(), 'yearly')['subscription'];

        $this->actingAs($this->super)->put(route('super-admin.subscriptions.update', $renewal->id), [
            'plan_id' => $shop->plan_id, 'starts_at' => '2026-10-23', 'ends_at' => '2027-10-23',
            'amount' => '299', 'payment_status' => 'مدفوع', 'status' => 'نشط',
        ]);
        $this->assertSame('warning', session('toast')['type']);

        $this->actingAs($this->super)->delete(route('super-admin.subscriptions.destroy', $renewal->id));
        $this->assertSame('warning', session('toast')['type']);

        $fresh = $renewal->fresh();
        $this->assertNotNull($fresh, 'حُذف التجديدُ المعلّق');
        $this->assertSame(Billing::SUB_PENDING, $fresh->status);
        $this->assertNull($fresh->ends_at);
        $this->assertSame('2026-12-31', $shop->fresh()->ends_at->toDateString(), 'مُنحت المدّة من نافذة التعديل');
    }

    /**
     * وتعديلُ الدورة الحاليّة يحرّك المتجر ولو انتظر تجديدٌ بعدها.
     *
     * التجديدُ المعلّق بلا `ends_at`، وPostgreSQL تُقدّم الفارغ في الترتيب
     * التنازليّ: كان `latest('ends_at')` سيراه «أحدثَ دورة» فلا يتبع المتجرُ التعديل.
     */
    public function test_editing_the_current_cycle_still_moves_the_shop_while_a_renewal_waits(): void
    {
        $shop = $this->shop();
        $current = Billing::renew($shop->fresh(), 'monthly')['invoice'];
        Billing::markPaid($current);
        $granted = Subscription::where('invoice_id', $current->id)->sole();
        Billing::renew($shop->fresh(), 'yearly');

        $this->actingAs($this->super)->put(route('super-admin.subscriptions.update', $granted->id), [
            'plan_id' => $shop->plan_id, 'starts_at' => '2026-12-31', 'ends_at' => '2027-02-15',
            'amount' => '29.900', 'payment_status' => 'مدفوع', 'status' => 'نشط',
        ])->assertSessionHasNoErrors();

        $this->assertSame('2027-02-15', $shop->fresh()->ends_at->toDateString());
    }

    /* ─────────── ما تراه لوحةُ المنصّة ─────────── */

    public function test_the_shop_screen_shows_the_waiting_renewal_and_its_unpaid_invoice(): void
    {
        $shop = $this->shop();
        Billing::markPaid(Billing::renew($shop->fresh(), 'monthly')['invoice']);
        ['invoice' => $invoice] = Billing::renew($shop->fresh(), 'semiannual');

        $this->actingAs($this->super)
            ->get(route('super-admin.businesses.show', $shop->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('renewal.monthly', 29.9)
                ->where('renewal.semiannual', 179.4)
                ->where('renewal.yearly', 299)
                ->where('renewal.endsAt', '2027-01-31')
                ->where('renewal.status', 'نشط')
                ->where('renewal.pending.number', $invoice->number)
                ->where('renewal.pending.cycle', 'semiannual')
                ->where('renewal.pending.amount', 179.4)
                ->where('renewal.unpaid.0.number', $invoice->number)
                ->count('renewal.unpaid', 1)
                // «الاشتراك الحالي» ما مُنح، لا التجديدُ المعلّق
                ->where('subscription.status', Billing::SUB_ACTIVE)
                ->where('subscription.end', '2027-01-31'));
    }

    /** والملغاةُ لا تُحسب دَينًا، والتجديدُ المعلّق ليس إيرادًا */
    public function test_cancelled_invoices_are_not_debt_and_waiting_renewals_are_not_revenue(): void
    {
        $shop = $this->shop();
        Billing::markPaid(Billing::renew($shop->fresh(), 'monthly')['invoice']);
        Billing::cancelRenewal(Billing::renew($shop->fresh(), 'yearly')['invoice']);
        Billing::renew($shop->fresh(), 'semiannual');

        $stats = Demo::invoiceStats();
        $this->assertEqualsWithDelta(29.9, $stats['paid'], 0.0001);
        $this->assertEqualsWithDelta(179.4, $stats['unpaid'], 0.0001, 'الملغاةُ حُسبت غيرَ مدفوعة');
        $this->assertEqualsWithDelta(29.9, (float) Demo::subscriptionStats()['monthly_revenue'], 0.0001, 'التجديدُ المعلّق حُسب إيرادًا');
    }
}
