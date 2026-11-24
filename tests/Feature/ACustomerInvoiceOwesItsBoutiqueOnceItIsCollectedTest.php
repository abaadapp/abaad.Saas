<?php

namespace Tests\Feature;

use App\Exceptions\SettlementRefused;
use App\Models\Boutique;
use App\Models\BoutiqueInvoiceLine;
use App\Models\BoutiqueInvoiceReturn;
use App\Models\BoutiqueReturn;
use App\Models\BoutiqueSettlement;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\BoutiqueInvoiceDues;
use App\Support\BoutiqueReturns;
use App\Support\Boutiques;
use App\Support\Cheques;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use App\Support\Loyalty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * فاتورةُ عميلٍ بلا طلب تستحقّ لبوتيكها — لقطةً عند الإصدار، ودَينًا بعد اكتمال التحصيل، مرّةً.
 *
 * ═══ ما يثبته هذا الملفّ بالبيسة ═══
 *
 *   ١. اللقطةُ تُكتب عند الإصدار ولا تتغيّر بنسبةٍ أو سعرٍ أو صاحبٍ يتبدّل بعدها.
 *   ٢. «بانتظار التحصيل» حتّى يكتمل ما قُبض — بأيّ وسيلةٍ وبأيّ عددٍ من الدفعات.
 *   ٣. ما صار مستحقًّا يحمله الكشفُ والورقةُ والدفترُ برقمٍ واحد، ولا يُحسب مرّتين.
 *   ٤. المرتجعُ يمسّ بوتيكَه وحده، قبل الورقة وبعدها وبعد سدادها.
 *   ٥. الخصمُ على المتجر، ولا قيدَ ولا مخزونَ ولا طلبَ يُكتب لأجل المستحقّ.
 */
class ACustomerInvoiceOwesItsBoutiqueOnceItIsCollectedTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2027-02';

    private Business $shop;

    private User $owner;

    private Boutique $lama;

    private Boutique $reem;

    private Product $silver;

    private Product $scent;

    private Product $mine;

    private Customer $company;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Carbon::setTestNow('2027-02-10 10:00:00');
        [$this->shop, $this->owner, $this->branch] = $this->openShop('متجر', 'o@dues.om');
        $this->lama = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك لمى', 'commission_rate' => 20, 'active' => true]);
        $this->reem = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك ريم', 'commission_rate' => 10, 'active' => true]);
        $this->silver = $this->product('قطعة فضّة', 6, $this->lama);
        $this->scent = $this->product('عطر', 10, $this->reem);
        $this->mine = $this->product('باقة المتجر', 20);
        $this->company = Customer::create(['language' => 'ar', 'business_id' => $this->shop->id, 'name' => 'شركة الورد', 'phone' => '92222222', 'allow_credit_sales' => true, 'payment_terms_days' => 30]);
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Business, 1: User, 2: Branch} */
    private function openShop(string $name, string $email): array
    {
        $shop = Business::create(['name' => $name, 'type' => 'عام', 'status' => 'نشط', 'boutiques_enabled' => true]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        $branch = Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);

        return [$shop, User::create(['business_id' => $shop->id, 'name' => 'المالك', 'email' => $email, 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']), $branch];
    }

    private function product(string $name, float $price, ?Boutique $of = null, array $extra = []): Product
    {
        return Product::create($extra + ['business_id' => $this->shop->id, 'name' => $name, 'price' => $price, 'cost' => 1, 'quantity' => 500, 'alert_qty' => 0, 'active' => true, 'published' => true, 'boutique_id' => $of?->id]);
    }

    /**
     * فاتورةٌ صادرة — وبنودُها `[الصنف، الكمّيّة، الخصم، السعر]`.
     *
     * @param  list<array<int, mixed>>  $lines
     */
    private function invoice(array $lines, array $data = [], bool $issue = true): CustomerInvoice
    {
        $invoice = CustomerInvoices::create((int) $this->shop->id, $this->company, $data + ['branch_id' => $this->branch->id], array_map(fn ($l) => [
            'product_id' => $l[0]->id, 'description' => $l[0]->name, 'quantity' => $l[1] ?? 1,
            'unit_price' => $l[3] ?? (float) $l[0]->price, 'discount' => $l[2] ?? 0,
        ], $lines), $this->owner->id);

        return $issue ? CustomerInvoices::issue($invoice, $this->owner->id) : $invoice;
    }

    /** المثالُ الإلزاميّ: لمى 12 بنسبة 20٪، وريم 10 بنسبة 10٪، وبضاعةُ المتجر 20 — الفاتورةُ 42 */
    private function mixed(): CustomerInvoice
    {
        return $this->invoice([[$this->silver, 2], [$this->scent], [$this->mine]]);
    }

    private function pay(CustomerInvoice $invoice, float $amount, string $method = 'نقدي', array $extra = []): CustomerPayment
    {
        return CustomerPayments::record((int) $this->shop->id, $this->company, $amount, $extra + ['method' => $method], [$invoice->id => $amount], $this->owner->id);
    }

    private function statement(Boutique $b, string $period = self::PERIOD): array
    {
        return Boutiques::statement((int) $this->shop->id, (int) $b->id, $period);
    }

    /** ما يقوله الكشف: مستحقٌّ الآن، وبانتظار التحصيل */
    private function assertStands(Boutique $b, float $due, float $awaiting, string $why = '', string $period = self::PERIOD): void
    {
        $s = $this->statement($b, $period);
        $this->assertSame([$due, $awaiting], [$s['net'] + 0.0, $s['awaiting']['net']], $b->name.' — '.$why);
    }

    private function settle(Boutique $b, string $period = self::PERIOD): BoutiqueSettlement
    {
        return Boutiques::settle($this->shop->fresh(), $b, $period, 'المالك');
    }

    private function paySheet(BoutiqueSettlement $sheet): void
    {
        $this->actingAs($this->owner)->post(route('admin.expenses.paid', $sheet->expense_id), ['payment_method' => 'نقدي'])->assertSessionHasNoErrors();
        $this->assertTrue($sheet->fresh()->paid(), 'الورقةُ لم تُسدَّد — الاختبارُ لا يقيس شيئًا');
    }

    private function credit(CustomerInvoice $invoice, float $amount, array $pieces = [], string $basis = CustomerCreditNote::RETURN): CustomerCreditNote
    {
        $items = BoutiqueInvoiceDues::noteItems($invoice, $pieces);

        return CustomerInvoices::creditNote($invoice->fresh(), $amount, 0.0, 'مرتجع', $this->owner->id, items: $items === [] ? null : $items, basis: $basis);
    }

    private function itemOf(CustomerInvoice $invoice, Product $p): int
    {
        return (int) $invoice->items()->where('product_id', $p->id)->value('id');
    }

    private function payable(): float
    {
        return Ledger::balance((int) $this->shop->id, 'boutique_payable');
    }

    /* ═══════════ ١ · اللقطة ═══════════ */

    public function test_issuing_keeps_whose_piece_it_was_and_both_shares_and_a_draft_keeps_nothing(): void
    {
        $draft = $this->invoice([[$this->silver, 2, 1.0], [$this->mine]], issue: false);
        $this->assertSame(0, BoutiqueInvoiceLine::count(), 'المسودّةُ ليست بيعًا');

        $invoice = CustomerInvoices::issue($draft, $this->owner->id);
        $line = BoutiqueInvoiceLine::sole();
        $this->assertSame([(int) $this->lama->id, 'بوتيك لمى', (int) $this->silver->id, 'قطعة فضّة', (int) $this->branch->id],
            [(int) $line->boutique_id, $line->boutique_name, (int) $line->product_id, $line->name, (int) $line->branch_id]);
        // 2 × 6 = 12، والخصمُ 1 على المتجر: العمولة 2.400 والصافي 9.600 من الاثني عشر
        $this->assertEquals([2, 6, 6, 12, 1, 12, 20, 2.4, 9.6], array_map('floatval', [$line->quantity, $line->unit_price, $line->list_price, $line->gross, $line->discount, $line->base, $line->rate, $line->commission, $line->net]));
        $this->assertSame('as_recorded', $line->rule);
        $this->assertNull($line->collected_at);
        $this->assertNull($line->boutique_settlement_id);

        // وإصدارٌ ثانٍ لا يكتب ثانية
        CustomerInvoices::issue($invoice, $this->owner->id);
        $this->assertSame(1, BoutiqueInvoiceLine::count());
    }

    public function test_a_rate_a_price_an_owner_and_a_name_that_change_later_change_nothing_kept(): void
    {
        $invoice = $this->mixed();
        $before = BoutiqueInvoiceLine::orderBy('id')->get()->toArray();

        $this->lama->update(['commission_rate' => 50, 'name' => 'لمى الجديدة']);
        $this->silver->update(['price' => 99, 'boutique_id' => $this->reem->id, 'name' => 'اسم آخر']);
        $this->scent->update(['boutique_id' => null]);
        $this->shop->update(['boutiques_enabled' => false]);

        $this->assertSame($before, BoutiqueInvoiceLine::orderBy('id')->get()->toArray());
        $this->pay($invoice, 42);
        $this->assertStands($this->lama, 9.6, 0.0, 'بنسبة يوم الإصدار');
        $this->assertStands($this->reem, 9.0, 0.0, 'ولو نُزع الصنفُ منها بعدها');
    }

    public function test_the_kept_columns_cannot_be_rewritten(): void
    {
        $this->mixed();
        $line = BoutiqueInvoiceLine::firstOrFail();
        foreach (['rate' => 1, 'net' => 1, 'commission' => 1, 'gross' => 1, 'quantity' => 1, 'unit_price' => 1, 'boutique_id' => 9, 'base' => 1] as $column => $value) {
            try {
                $line->fresh()->update([$column => $value]);
                $this->fail($column.' كُتب فوق لقطته');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_an_invoice_over_an_order_or_a_month_of_orders_keeps_no_line_of_its_own(): void
    {
        $this->actingAs($this->owner)->postJson('/pos/checkout', [
            'items' => [['id' => $this->silver->id, 'name' => $this->silver->name, 'qty' => 2, 'price' => 6]],
            'payment_method' => 'نقدي', 'credit' => true, 'customer_id' => $this->company->id, 'client_uuid' => 'till-credit',
        ])->assertOk();
        $order = Order::firstOrFail();
        $this->assertNotNull(CustomerInvoice::whereHas('orders', fn ($q) => $q->whereKey($order->id))->first(), 'لا فاتورةَ للآجل — الاختبارُ لا يقيس شيئًا');
        $this->assertSame(0, BoutiqueInvoiceLine::count());
        // ودفعُ فاتورته لا يزيد حرفًا: بيعُه في طلبه
        $this->assertStands($this->lama, 9.6, 0.0, 'الآجلُ من الصندوق');
        $this->pay(CustomerInvoice::firstOrFail(), 12);
        $this->assertStands($this->lama, 9.6, 0.0, 'بعد تحصيل فاتورته');
        $this->assertSame(0, BoutiqueInvoiceLine::count());
    }

    /** وكلُّ واحدٍ من المانعين يكفي وحده: الفاتورةُ تغطّي طلبًا، أو البندُ مولودٌ من بند طلب */
    public function test_each_of_the_two_guards_against_counting_an_order_again_stands_alone(): void
    {
        $this->actingAs($this->owner)->postJson('/pos/checkout', [
            'items' => [['id' => $this->silver->id, 'name' => $this->silver->name, 'qty' => 2, 'price' => 6]],
            'payment_method' => 'نقدي', 'client_uuid' => 'till-two-guards',
        ])->assertOk();
        $order = Order::with('items')->firstOrFail();

        // تغطّي الطلبَ وبنودُها بلا رقم بنده
        $covering = $this->invoice([[$this->silver, 2]], issue: false);
        $covering->orders()->attach($order->id);
        CustomerInvoices::issue($covering, $this->owner->id);
        $this->assertSame(0, BoutiqueInvoiceLine::count(), 'فاتورةٌ تغطّي طلبًا كُتبت لها لقطة');

        // ولا تغطّيه، وبندُها مولودٌ من بنده
        $born = $this->invoice([[$this->silver, 2]], issue: false);
        $born->items()->update(['order_item_id' => $order->items->first()->id]);
        CustomerInvoices::issue($born, $this->owner->id);
        $this->assertSame(0, BoutiqueInvoiceLine::count(), 'بندٌ بيع في طلبٍ كُتبت له لقطة');

        foreach ([$covering, $born] as $invoice) {
            $this->pay($invoice, 12);
        }
        $this->assertStands($this->lama, 9.6, 0.0, 'الطلبُ وحده');
    }

    public function test_a_shops_addon_on_a_boutique_piece_and_the_shops_own_lines_are_the_shops(): void
    {
        $addon = \App\Models\Addon::create(['business_id' => $this->shop->id, 'name' => 'تغليف', 'price' => 3, 'active' => true, 'scope' => \App\Models\Addon::SCOPE_ALL]);
        $items = \App\Support\InvoiceLineDetails::expand((int) $this->shop->id, [[
            'product_id' => $this->silver->id, 'description' => $this->silver->name, 'quantity' => 1, 'unit_price' => 6, 'addons' => [['addon_id' => $addon->id, 'quantity' => 1]],
        ]]);
        $invoice = CustomerInvoices::issue(CustomerInvoices::create((int) $this->shop->id, $this->company, [], $items));
        $this->assertEquals(9.0, (float) $invoice->total);
        $this->pay($invoice, 9);
        $this->assertStands($this->lama, 4.8, 0.0, 'ستّةٌ بعشرين في المئة — والتغليفُ للمتجر');
    }

    /* ═══════════ ٢ · التحصيل ═══════════ */

    /** المثالُ 42.000 بالبيسة: لا شيء قبل اكتمال السداد، ثمّ كلُّ جهةٍ بنصيبها، والدفترُ يطابق */
    public function test_the_mixed_invoice_waits_for_full_payment_then_gives_each_boutique_its_own(): void
    {
        $invoice = $this->mixed();
        $this->assertEquals(42.0, (float) $invoice->total);
        $this->assertStands($this->lama, 0.0, 9.6, 'صدرت ولم تُدفع');
        $this->assertStands($this->reem, 0.0, 9.0, 'صدرت ولم تُدفع');

        foreach ([10.0, 31.999] as $part) {
            $this->pay($invoice, $part);
            $this->assertStands($this->lama, 0.0, 9.6, 'دُفع منها '.$part);
            try {
                $this->settle($this->lama);
                $this->fail('صدرت ورقةٌ عن فاتورةٍ لم يكتمل تحصيلُها');
            } catch (SettlementRefused) {
                $this->addToAssertionCount(1);
            }
        }

        $this->pay($invoice, 0.001);
        $this->assertStands($this->lama, 9.6, 0.0, 'اكتمل');
        $this->assertStands($this->reem, 9.0, 0.0, 'اكتمل');

        $a = $this->settle($this->lama);
        $b = $this->settle($this->reem);
        $this->assertEquals([12.0, 2.4, 9.6], [(float) $a->gross, (float) $a->commission, (float) $a->net]);
        $this->assertEquals([10.0, 1.0, 9.0], [(float) $b->gross, (float) $b->commission, (float) $b->net]);
        // العمولة 3.400، وما للبوتيكين 18.600 دَينًا في الدفتر، وما بقي للمتجر 23.400 — والبيعُ 42 مرّةً
        $this->assertEquals(18.6, $this->payable());
        $this->assertEquals(42.0, Ledger::balance((int) $this->shop->id, 'sales'));
        $this->assertEquals(23.4, round(42.0 - 18.6, 3));

        $this->paySheet($a);
        $this->assertEquals(9.0, $this->payable(), 'بقي لريم وحدها');
        $this->assertSame([], array_values(array_filter([$this->statement($this->lama)['net'], $this->statement($this->reem)['net']])), 'حملته ورقةٌ وبقي في الكشف');
    }

    /** @return array<string, array{0: string}> */
    public static function methods(): array
    {
        return ['نقدي' => ['نقدي'], 'بطاقة' => ['بطاقة'], 'تحويل' => ['تحويل']];
    }

    #[DataProvider('methods')]
    public function test_every_way_of_paying_in_full_makes_it_due(string $method): void
    {
        $invoice = $this->mixed();
        $this->pay($invoice, 42, $method);
        $this->assertStands($this->lama, 9.6, 0.0, $method);
    }

    public function test_many_payments_of_different_kinds_are_due_only_with_the_last(): void
    {
        $invoice = $this->mixed();
        $this->pay($invoice, 12, 'نقدي');
        $this->pay($invoice, 10, 'تحويل');
        $this->pay($invoice, 15, 'بطاقة');
        $this->assertStands($this->lama, 0.0, 9.6, 'بقيت خمسة');
        // والأخيرةُ دفعةٌ على حساب العميل تذهب إلى أقدم ما عليه
        CustomerPayments::record((int) $this->shop->id, $this->company, 5, ['method' => 'نقدي'], [], $this->owner->id);
        $this->assertStands($this->lama, 9.6, 0.0, 'اكتمل بالحساب');
    }

    /** الشيكُ تحت التحصيل لم يُقبض: الفاتورةُ «مدفوعة» والبوتيكُ ينتظر صرفَه */
    public function test_a_cheque_counts_when_it_clears_and_never_when_it_bounces(): void
    {
        $invoice = $this->mixed();
        $cheque = $this->pay($invoice, 42, 'شيك');
        $this->assertSame('مدفوعة', $invoice->fresh()->paymentState());
        $this->assertStands($this->lama, 0.0, 9.6, 'تحت التحصيل');

        Cheques::bounce($cheque, 'لا رصيد', $this->owner->id);
        $this->assertStands($this->lama, 0.0, 9.6, 'ارتدّ');

        $again = $this->pay($invoice, 42, 'شيك');
        Cheques::clear($again->fresh(), null, null, $this->owner->id);
        $this->assertStands($this->lama, 9.6, 0.0, 'صُرف');
    }

    public function test_a_cancelled_payment_takes_it_back_and_a_new_one_gives_it_again_once(): void
    {
        $invoice = $this->mixed();
        $payment = $this->pay($invoice, 42);
        $this->assertStands($this->lama, 9.6, 0.0, 'اكتمل');

        CustomerPayments::cancel($payment, 'خطأ', $this->owner->id);
        $this->assertStands($this->lama, 0.0, 9.6, 'أُلغي التحصيل');

        $this->pay($invoice, 42);
        $this->assertStands($this->lama, 9.6, 0.0, 'حُصّل ثانيةً');
        $this->assertSame(2, BoutiqueInvoiceLine::count(), 'زاد بندٌ بإعادة التحصيل');
    }

    /** والورقةُ مجمَّدة: تحصيلٌ أُلغي بعدها لا يغيّرها ولا يُحمل بندُها ثانيةً — ويُقرأ استثناءً */
    public function test_a_payment_cancelled_after_the_sheet_leaves_the_sheet_and_is_shown_as_an_exception(): void
    {
        $invoice = $this->mixed();
        $payment = $this->pay($invoice, 42);
        $sheet = $this->settle($this->lama);
        CustomerPayments::cancel($payment, 'استرداد', $this->owner->id);

        $s = $this->statement($this->lama);
        $this->assertSame([0.0, 0.0, 9.6], [$s['net'] + 0.0, $s['awaiting']['net'], $s['reopened']['net']]);
        $this->assertEquals(9.6, (float) $sheet->fresh()->net);
        $this->assertStands($this->reem, 0.0, 9.0, 'ولم تحمل ريمَ ورقةٌ فعادت تنتظر');

        $this->pay($invoice, 42);
        $this->assertSame([0.0, 0.0, 0.0], [$this->statement($this->lama)['net'] + 0.0, $this->statement($this->lama)['awaiting']['net'], $this->statement($this->lama)['reopened']['net']]);
        $this->expectException(SettlementRefused::class);
        $this->settle($this->lama);
    }

    /** وشهرُه شهرُ اكتمال التحصيل لا شهرُ الإصدار */
    public function test_it_is_due_in_the_month_its_collection_completed(): void
    {
        $invoice = $this->mixed();
        $this->pay($invoice, 20);
        Carbon::setTestNow('2027-03-04 09:00:00');
        $this->pay($invoice, 22);
        $this->assertStands($this->lama, 0.0, 0.0, 'فبراير');
        $this->assertStands($this->lama, 9.6, 0.0, 'مارس', '2027-03');
        $this->assertEquals(9.6, (float) $this->settle($this->lama, '2027-03')->net);
    }

    /** ومن باب التحصيل المُلغى لا يتسرّب بندٌ إلى ورقة ولو بقي ختمُه */
    public function test_a_stale_stamp_never_reaches_a_sheet(): void
    {
        $invoice = $this->mixed();
        $payment = $this->pay($invoice, 42);
        // بابٌ نسي أن يُقيم المستحقّ: الدفعةُ أُلغيت من القاعدة والختمُ باقٍ
        DB::table('customer_payments')->where('id', $payment->id)->update(['cancelled_at' => now()]);
        $this->assertNotNull(BoutiqueInvoiceLine::first()->collected_at);
        $this->assertEquals(0.0, $this->statement($this->lama)['net']);
        $this->expectException(SettlementRefused::class);
        $this->settle($this->lama);
    }

    /* ═══════════ ٣ · لا ازدواج، ولا أثرَ جانبيّ ═══════════ */

    public function test_nothing_but_the_sheet_is_posted_and_no_order_no_stock_and_no_second_revenue(): void
    {
        $entries = fn () => JournalEntry::where('business_id', $this->shop->id)->count();
        $invoice = $this->mixed();
        $this->assertSame(1, $entries(), 'الإصدارُ كتب غيرَ قيده');
        $this->pay($invoice, 42);
        $this->assertSame(2, $entries(), 'التحصيلُ كتب غيرَ قيده');
        $this->assertSame(0, Order::count(), 'كُتب طلبٌ وهميّ');
        $this->assertSame([500, 500], [(int) $this->silver->fresh()->quantity, (int) $this->scent->fresh()->quantity]);

        $this->settle($this->lama);
        $this->assertSame(3, $entries());
        $this->assertEquals(42.0, Ledger::balance((int) $this->shop->id, 'sales'));
    }

    public function test_one_sheet_carries_it_once_however_many_times_it_is_asked(): void
    {
        $this->pay($this->mixed(), 42);
        $sheet = $this->settle($this->lama);
        for ($i = 0; $i < 3; $i++) {
            try {
                $this->settle($this->lama);
                $this->fail('ورقةٌ ثانية للبند نفسِه');
            } catch (SettlementRefused) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(1, BoutiqueSettlement::count());
        $line = BoutiqueInvoiceLine::where('boutique_id', $this->lama->id)->sole();
        $this->assertSame((int) $sheet->id, (int) $line->boutique_settlement_id);
        $this->assertEquals([2.0, 12.0, 2.4, 9.6], array_map('floatval', [$line->settled_quantity, $line->settled_gross, $line->settled_commission, $line->settled_net]));
    }

    /** الورقةُ الواحدة تحمل ما بيع في الصندوق وما حُصّل من الفواتير معًا — سطرًا لكلّ مصدر */
    public function test_one_sheet_carries_the_tills_sales_and_the_collected_invoices_together(): void
    {
        $this->actingAs($this->owner)->postJson('/pos/checkout', [
            'items' => [['id' => $this->silver->id, 'name' => $this->silver->name, 'qty' => 3, 'price' => 6]],
            'payment_method' => 'نقدي', 'client_uuid' => 'till-cash',
        ])->assertOk();
        $this->pay($this->mixed(), 42);

        $s = $this->statement($this->lama);
        $this->assertSame([[18.0, 'orders'], [12.0, 'invoices']], array_map(fn ($l) => [$l['gross'], $l['source']], $s['lines']));
        $this->assertEquals([30.0, 6.0, 24.0], [$s['gross'], $s['commission'], $s['net']]);
        $sheet = $this->settle($this->lama);
        $this->assertEquals([30.0, 6.0, 24.0], [(float) $sheet->gross, (float) $sheet->commission, (float) $sheet->net]);
        $this->assertEquals(24.0, $this->payable());
        // وفرعُ المصروف فرعُ بيعه كلِّه
        $this->assertSame((int) $this->branch->id, (int) Expense::find($sheet->expense_id)->branch_id);
    }

    public function test_the_report_the_statement_the_sheet_and_the_ledger_say_one_number(): void
    {
        foreach ([[$this->silver, 2, 0, 0.335], [$this->silver, 1, 0, 1.005], [$this->silver, 3, 0, 7.777]] as $l) {
            $invoice = $this->invoice([$l]);
            $this->pay($invoice, (float) $invoice->total);
        }
        // 0.670 ← 0.134، و1.005 ← 0.201، و23.331 ← 4.666 — كلُّ بندٍ بالبيسة
        $net = round((0.670 - 0.134) + (1.005 - 0.201) + (23.331 - 4.666), 3);
        $this->assertSame(20.005, $net);

        $report = Boutiques::soldTotals((int) $this->shop->id, $this->lama);
        $s = $this->statement($this->lama);
        $sheet = $this->settle($this->lama);
        $this->assertSame([$net, $net, $net, $net], [$report['net'], $s['net'], round((float) $sheet->net, 3), round((float) Expense::find($sheet->expense_id)->amount, 3)]);
        $this->assertEquals($net, $this->payable());
        $this->assertSame([3, 0.0], [$report['invoices'], $report['awaiting_net']]);
    }

    /** التقريرُ يعدّ البيعَ بيوم فاتورته، ويقول كم منه لم يُحصَّل بعد */
    public function test_the_report_counts_the_sale_on_its_day_and_says_what_still_waits(): void
    {
        $this->mixed();
        $r = Boutiques::soldTotals((int) $this->shop->id, $this->lama);
        $this->assertSame([12.0, 2.4, 9.6, 1, 9.6], [$r['gross'], $r['commission'], $r['net'], $r['invoices'], $r['awaiting_net']]);
        // ومع قناةٍ بعينها لا تُقرأ: فاتورةُ العميل ليست قناة
        $this->assertSame(0.0, Boutiques::soldTotals((int) $this->shop->id, $this->lama, channel: \App\Support\SalesChannel::POS)['gross']);
        // وخارج مدّتها لا تُعدّ
        $this->assertSame(0.0, Boutiques::soldTotals((int) $this->shop->id, $this->lama, Carbon::parse('2027-03-01'))['gross']);
    }

    /* ═══════════ ٤ · الخصم والضريبة ═══════════ */

    /** خصمُ البند على المتجر — ولو جاوز عمولتَه كلَّها */
    public function test_a_line_discount_is_the_shops_however_large(): void
    {
        $invoice = $this->invoice([[$this->silver, 2, 5.0]]);
        $this->assertEquals(7.0, (float) $invoice->total);
        $this->pay($invoice, 7);
        $this->assertStands($this->lama, 9.6, 0.0, 'الخصمُ خمسةٌ والعمولة 2.400 — والزيادةُ على المتجر');
        $this->assertEquals(-2.6, round(7.0 - 9.6, 3));
    }

    /** والنقاطُ كذلك — وورقةٌ غطّتها النقاطُ كلُّها صدرت محصَّلة */
    public function test_points_are_the_shops_and_a_paper_they_cover_is_due_when_it_is_issued(): void
    {
        // نقطةٌ بريالٍ وبلا حدّ: ورقةُ الاثني عشر تغطّيها اثنتا عشرة نقطة
        foreach (['loyalty_enabled' => '1', 'loyalty_redeem_enabled' => '1', 'loyalty_redeem_mode' => Loyalty::FIXED, 'loyalty_redeem_min' => '1', 'loyalty_redeem_points' => '1', 'loyalty_redeem_value' => '1', 'loyalty_redeem_min_invoice' => '0', 'loyalty_redeem_max_pct' => '', 'loyalty_redeem_max_amount' => ''] as $k => $v) {
            Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => $k], ['value' => $v]);
        }
        $this->company->update(['points' => 1000]);
        $quote = CustomerInvoices::loyaltyQuote((int) $this->shop->id, $this->company->fresh(), [['quantity' => 2, 'unit_price' => 6]]);
        $this->assertEquals([12, 12.0], [$quote['max_points'], $quote['max_discount']], 'النقاطُ لا تغطّي الورقة — الاختبارُ لا يقيس شيئًا');
        $invoice = $this->invoice([[$this->silver, 2]], ['redeem_points' => $quote['max_points']]);
        $this->assertEquals(0.0, (float) $invoice->total);
        $this->assertStands($this->lama, 9.6, 0.0, 'غطّتها النقاط — والبوتيكُ بحقّه كاملًا');
    }

    /** الأسعارُ الشاملةُ والمضافة: الأساسُ كما سُجّل — وهو ما يحسبه الصندوق للسلّة نفسِها */
    #[DataProvider('taxModes')]
    public function test_the_base_is_the_one_the_till_uses_for_the_same_basket(string $mode, float $rate): void
    {
        Setting::where('business_id', $this->shop->id)->where('key', 'vat_enabled')->update(['value' => '1']);
        foreach (['tax_rate' => (string) $rate, 'tax_mode' => $mode, 'vat_rate' => (string) $rate] as $k => $v) {
            Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => $k], ['value' => $v]);
        }
        $this->actingAs($this->owner)->postJson('/pos/checkout', [
            'items' => [['id' => $this->silver->id, 'name' => $this->silver->name, 'qty' => 2, 'price' => 6]],
            'payment_method' => 'نقدي', 'client_uuid' => 'till-'.$mode,
        ])->assertOk();
        $till = $this->statement($this->lama);

        $invoice = $this->invoice([[$this->silver, 2]]);
        $this->pay($invoice, (float) $invoice->total);
        $both = $this->statement($this->lama);

        $this->assertEquals([12.0, 2.4, 9.6], [$till['gross'], $till['commission'], $till['net']]);
        $this->assertEquals([24.0, 4.8, 19.2], [$both['gross'], $both['commission'], $both['net']], 'بابان بأساسين');
        $line = BoutiqueInvoiceLine::sole();
        $this->assertSame([$mode === 'inclusive' && $rate > 0, round((float) $invoice->items->first()->tax_amount, 3)], [(bool) $line->tax_inclusive, round((float) $line->tax_amount, 3)]);
    }

    /** @return array<string, array{0: string, 1: float}> */
    public static function taxModes(): array
    {
        return ['مضافة' => ['exclusive', 5.0], 'شاملة' => ['inclusive', 5.0]];
    }

    /** مسودّةٌ كُتبت والأسعارُ شاملة ثمّ بُدّل الإعداد: الضريبةُ من البند نفسِه لا من إعداد اليوم */
    public function test_where_the_tax_sits_is_read_from_the_line_not_from_todays_setting(): void
    {
        Setting::where('business_id', $this->shop->id)->where('key', 'vat_enabled')->update(['value' => '1']);
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'tax_mode'], ['value' => 'inclusive']);
        $draft = $this->invoice([[$this->silver, 2]], issue: false);
        Setting::where('business_id', $this->shop->id)->where('key', 'tax_mode')->update(['value' => 'exclusive']);
        CustomerInvoices::issue($draft, $this->owner->id);
        $line = BoutiqueInvoiceLine::sole();
        $this->assertGreaterThan(0.0, (float) $line->tax_amount, 'بلا ضريبة — الاختبارُ لا يقيس شيئًا');
        $this->assertTrue((bool) $line->tax_inclusive);
    }

    /** سعرٌ كُتب أقلَّ من البطاقة يبقى سعرَ البند — ويُرى ليُراجَع */
    public function test_a_price_written_under_the_card_is_the_lines_price_and_is_flagged(): void
    {
        $invoice = $this->invoice([[$this->silver, 2, 0, 5.0]]);
        $of = BoutiqueInvoiceDues::of($invoice)[0];
        $this->assertSame([10.0, 2.0, 8.0, 6.0], [$of['gross'], $of['commission'], $of['net'], $of['under_list']]);
        $this->assertNull(BoutiqueInvoiceDues::of($this->mixed())[0]['under_list']);
    }

    /* ═══════════ ٥ · المرتجع قبل الورقة ═══════════ */

    public function test_a_returned_piece_lowers_its_boutique_alone_before_the_sheet(): void
    {
        $invoice = $this->mixed();
        $note = $this->credit($invoice, 6, [$this->itemOf($invoice, $this->silver) => ['quantity' => 1]]);
        $this->assertSame(['return', 'i'.$this->itemOf($invoice, $this->silver), 1.0], [$note->basis, $note->items[0]['key'], (float) $note->items[0]['quantity_after']]);
        $this->assertStands($this->lama, 0.0, 4.8, 'عادت واحدة');
        $this->assertStands($this->reem, 0.0, 9.0, 'لا شأن لها');

        $this->pay($invoice, 36);
        $this->assertStands($this->lama, 4.8, 0.0, 'اكتمل ما بقي');
        $this->assertStands($this->reem, 9.0, 0.0, 'كاملة');
        $this->assertEquals([6.0, 1.2, 4.8], [(float) ($sheet = $this->settle($this->lama))->gross, (float) $sheet->commission, (float) $sheet->net]);
        // واللقطةُ كما كُتبت، وما رُدّ بجانبها
        $line = BoutiqueInvoiceLine::where('boutique_id', $this->lama->id)->sole();
        $this->assertEquals([9.6, 4.8, 4.8], array_map('floatval', [$line->net, $line->returned_net, $line->settled_net]));
        $this->assertSame(0, BoutiqueReturn::count(), 'قبل الورقة لا إشعارَ مرتجع');
    }

    public function test_a_credit_that_names_no_piece_is_the_shops_and_touches_no_boutique(): void
    {
        $invoice = $this->mixed();
        $this->credit($invoice, 12, [], CustomerCreditNote::PRICE);
        $this->assertStands($this->lama, 0.0, 9.6, 'خصمٌ بعد البيع على المتجر');
        $this->pay($invoice, 30);
        $this->assertStands($this->lama, 9.6, 0.0, 'اكتمل بما بقي');
        $this->assertStands($this->reem, 9.0, 0.0, 'كذلك');
        $this->assertSame(0, BoutiqueInvoiceReturn::count());
    }

    public function test_the_whole_piece_returned_leaves_nothing_to_settle(): void
    {
        $invoice = $this->mixed();
        $this->credit($invoice, 12, [$this->itemOf($invoice, $this->silver) => ['quantity' => 2]]);
        $this->pay($invoice, 30);
        $this->assertStands($this->lama, 0.0, 0.0, 'عاد كلُّه');
        $this->assertStands($this->reem, 9.0, 0.0, 'كاملة');
        $this->expectException(SettlementRefused::class);
        $this->settle($this->lama);
    }

    public function test_returns_by_difference_add_up_to_the_line_to_the_baisa(): void
    {
        $this->lama->update(['commission_rate' => 10]);
        $invoice = $this->invoice([[$this->silver, 3, 0, 0.335]]);
        $item = $this->itemOf($invoice, $this->silver);
        $this->assertEquals([1.005, 0.101, 0.904], array_map('floatval', [($l = BoutiqueInvoiceLine::sole())->gross, $l->commission, $l->net]));
        foreach ([0.335, 0.335, 0.335] as $amount) {
            $this->credit($invoice, $amount, [$item => ['quantity' => 1]]);
        }
        $this->assertSame([0.301, 0.302, 0.301], BoutiqueInvoiceReturn::orderBy('id')->pluck('net')->map(fn ($v) => (float) $v)->all());
        $this->assertEquals(0.904, (float) BoutiqueInvoiceLine::sole()->returned_net);
    }

    public function test_no_more_is_returned_than_was_left_and_a_refused_note_writes_nothing(): void
    {
        $invoice = $this->mixed();
        $item = $this->itemOf($invoice, $this->silver);
        $this->credit($invoice, 6, [$item => ['quantity' => 1]]);
        foreach ([['quantity' => 2], ['quantity' => 1, 'sellable' => 1, 'damaged' => 1]] as $bad) {
            $notes = CustomerCreditNote::count();
            $entries = JournalEntry::count();
            try {
                $this->credit($invoice, 6, [$item => $bad]);
                $this->fail('قُبل مرتجعٌ لا يصحّ: '.json_encode($bad));
            } catch (ValidationException) {
                $this->assertSame([$notes, $entries], [CustomerCreditNote::count(), JournalEntry::count()], 'رُدّ وبقي أثرُه');
            }
        }
        $this->expectException(RuntimeException::class);
        BoutiqueInvoiceDues::noteItems($invoice, [999999 => ['quantity' => 1]]);
    }

    /* ═══════════ ٦ · المرتجع بعد الورقة وبعد سدادها ═══════════ */

    /** ورقةٌ لم تُسدَّد وشرطُ الاتّفاقية يحسم: يُسدَّد صافيها، وريم لا تتحرّك */
    public function test_a_piece_returned_after_an_unpaid_sheet_lowers_what_will_be_paid(): void
    {
        $this->lama->update(['returns_terms' => BoutiqueReturns::TERMS_ALL, 'returns_terms_ref' => 'عقد 2027/1']);
        $invoice = $this->mixed();
        $this->pay($invoice, 42);
        $lama = $this->settle($this->lama);
        $reem = $this->settle($this->reem);
        $shelf = (int) $this->silver->fresh()->quantity;

        $this->credit($invoice, 6, [$this->itemOf($invoice, $this->silver) => ['quantity' => 1, 'sellable' => 1]]);

        $note = BoutiqueReturn::sole();
        $this->assertSame([BoutiqueReturn::REDUCED, BoutiqueReturn::INVOICE, null], [$note->status, $note->source, $note->order_id]);
        $this->assertEquals([1.0, 6.0, 4.8], array_map('floatval', [$note->quantity, $note->gross, $note->amount]));
        $this->assertEquals(4.8, BoutiqueReturns::payable(Expense::find($lama->expense_id)));
        $this->assertEquals(9.0, BoutiqueReturns::payable(Expense::find($reem->expense_id)), 'بوتيكٌ لا شأن له');
        // والورقةُ كما صدرت، والدَّينُ نقص بقيمته
        $this->assertEquals([9.6, 9.0], [(float) $lama->fresh()->net, (float) $reem->fresh()->net]);
        $this->assertEquals(13.8, $this->payable());
        $this->assertSame($shelf, (int) $this->silver->fresh()->quantity, 'فاتورةُ العميل لم تُخرج من الرفّ فلا يعود إليه شيء');

        $this->paySheet($lama->fresh());
        $this->assertEquals(9.0, $this->payable());
    }

    public function test_a_piece_returned_after_the_sheet_was_paid_is_a_claim_on_its_boutique_alone(): void
    {
        $this->lama->update(['returns_terms' => BoutiqueReturns::TERMS_ALL, 'returns_terms_ref' => 'عقد 2027/1']);
        $invoice = $this->mixed();
        $this->pay($invoice, 42);
        $sheet = $this->settle($this->lama);
        $this->paySheet($sheet);

        $this->credit($invoice, 12, [$this->itemOf($invoice, $this->silver) => ['quantity' => 2, 'sellable' => 1, 'damaged' => 1]]);
        $this->assertSame([BoutiqueReturn::CLAIM, BoutiqueReturn::CLAIM], BoutiqueReturn::orderBy('id')->pluck('status')->all());
        $this->assertEquals(9.6, round((float) BoutiqueReturn::sum('amount'), 3), 'أجزاءُ المرتجع تجمع إلى ما على الورقة');
        $this->assertEquals(9.6, Ledger::balance((int) $this->shop->id, 'boutique_receivable'));
        $this->assertEquals(9.6, (float) $sheet->fresh()->net, 'الورقةُ المسدَّدة كما هي');
        $this->assertSame(0, BoutiqueReturn::where('boutique_id', $this->reem->id)->count());
    }

    /** وبلا شرطٍ موثَّق ينتظر قرارَ المحاسب — لا يُفترض أنّ البوتيك يتحمّل */
    public function test_without_agreed_terms_a_return_after_the_sheet_waits_for_the_accountant(): void
    {
        $invoice = $this->mixed();
        $this->pay($invoice, 42);
        $sheet = $this->settle($this->lama);
        $this->credit($invoice, 6, [$this->itemOf($invoice, $this->silver) => ['quantity' => 1, 'sellable' => 1]]);
        $note = BoutiqueReturn::sole();
        $this->assertSame(BoutiqueReturn::REVIEW, $note->status);
        $this->assertEquals(9.6, BoutiqueReturns::payable(Expense::find($sheet->expense_id)));

        $this->actingAs($this->owner)->post(route('admin.boutiques.returns.review', $this->lama->id), ['note' => $note->id, 'bearer' => 'shop', 'why' => 'تالفٌ عند المتجر'])->assertSessionHasNoErrors();
        $this->assertSame(BoutiqueReturn::SHOP, $note->fresh()->status);
        $this->assertEquals(9.6, BoutiqueReturns::payable(Expense::find($sheet->expense_id)));
        $this->assertSame(500, (int) $this->silver->fresh()->quantity);
    }

    /** ولم تُستلم بعد: تنتظر، ثمّ يُقال حالُها من شاشة البوتيك — ولا حركةَ رفّ */
    public function test_a_piece_not_yet_received_waits_and_is_received_from_the_boutiques_screen(): void
    {
        $this->lama->update(['returns_terms' => BoutiqueReturns::TERMS_SELLABLE, 'returns_terms_ref' => 'عقد 2027/1']);
        $invoice = $this->mixed();
        $this->pay($invoice, 42);
        $sheet = $this->settle($this->lama);
        $this->credit($invoice, 12, [$this->itemOf($invoice, $this->silver) => ['quantity' => 2]]);
        $note = BoutiqueReturn::sole();
        $this->assertSame([BoutiqueReturn::AWAITING, BoutiqueReturn::PENDING], [$note->status, $note->condition]);

        $this->actingAs($this->owner)->post(route('admin.boutiques.returns.receive', $this->lama->id), ['note' => $note->id, 'sellable' => 1, 'damaged' => 1])->assertSessionHasNoErrors();
        $this->assertSame([[BoutiqueReturn::SELLABLE, BoutiqueReturn::REDUCED], [BoutiqueReturn::DAMAGED, BoutiqueReturn::REVIEW]],
            BoutiqueReturn::orderBy('id')->get()->map(fn ($n) => [$n->condition, $n->status])->all());
        $this->assertEquals(4.8, BoutiqueReturns::payable(Expense::find($sheet->expense_id)));
        $this->assertSame(500, (int) $this->silver->fresh()->quantity);
    }

    /** وكمّيّةٌ بكسر تُردّ بكسرها */
    public function test_a_fraction_is_sold_and_returned_as_a_fraction(): void
    {
        $this->lama->update(['returns_terms' => BoutiqueReturns::TERMS_ALL, 'returns_terms_ref' => 'عقد']);
        $invoice = $this->invoice([[$this->silver, 1.5]]);
        $this->pay($invoice, 9);
        $this->assertStands($this->lama, 7.2, 0.0, '1.5 × 6 بعشرين');
        $sheet = $this->settle($this->lama);
        $this->credit($invoice, 3, [$this->itemOf($invoice, $this->silver) => ['quantity' => 0.5, 'sellable' => 0.5]]);
        $this->assertEquals([0.5, 2.4], [(float) BoutiqueReturn::sole()->quantity, (float) BoutiqueReturn::sole()->amount]);
        $this->assertEquals(4.8, BoutiqueReturns::payable(Expense::find($sheet->expense_id)));
    }

    /* ═══════════ ٧ · الإلغاء ═══════════ */

    public function test_an_unpaid_invoice_cancelled_owes_nobody_and_moves_no_money(): void
    {
        $invoice = $this->mixed();
        CustomerInvoices::cancel($invoice, 'أُلغيت', $this->owner->id);
        $this->assertStands($this->lama, 0.0, 0.0, 'ملغاة');
        $this->assertSame(2, BoutiqueInvoiceLine::whereNotNull('voided_at')->count());
        $this->assertSame(0.0, Boutiques::soldTotals((int) $this->shop->id, $this->lama)['gross']);
        $this->assertEquals(0.0, $this->payable());
    }

    /** أُلغي تحصيلُها بعد ورقتها ثمّ أُلغيت: ما حملته الورقةُ يُكتب مرتجعُه إشعارًا */
    public function test_an_invoice_cancelled_after_its_sheet_writes_the_return_and_leaves_the_sheet(): void
    {
        $invoice = $this->mixed();
        $payment = $this->pay($invoice, 42);
        $sheet = $this->settle($this->lama);
        CustomerPayments::cancel($payment, 'استرداد', $this->owner->id);
        CustomerInvoices::cancel($invoice->fresh(), 'أُلغيت', $this->owner->id);

        $note = BoutiqueReturn::sole();
        $this->assertEquals([2.0, 9.6], [(float) $note->quantity, (float) $note->amount]);
        $this->assertSame(BoutiqueReturn::AWAITING, $note->status);
        $this->assertEquals(9.6, (float) $sheet->fresh()->net);
        $this->assertSame(1, BoutiqueInvoiceLine::where('boutique_id', $this->reem->id)->whereNotNull('voided_at')->count(), 'وما لم تحمله ورقةٌ سقط');
        $this->assertSame(0.0, $this->statement($this->lama)['reopened']['net'], 'رُدّ كلُّه فلا استثناء');
    }

    /* ═══════════ ٨ · العزل والصلاحيات والإطفاء ═══════════ */

    public function test_another_shops_invoices_lines_and_boutiques_are_never_reached(): void
    {
        $this->pay($this->mixed(), 42);
        [$other, $stranger] = $this->openShop('متجر آخر', 'x@dues.om');
        $theirs = Boutique::create(['business_id' => $other->id, 'name' => 'بوتيكهم', 'commission_rate' => 33.33, 'active' => true]);
        $this->assertSame(0.0, Boutiques::statement((int) $other->id, (int) $this->lama->id, self::PERIOD)['net'] + 0.0, 'كشفُ متجرٍ قرأ بنودَ آخر');
        $this->assertSame(0, Boutiques::soldTotals((int) $other->id, $theirs)['invoices']);

        $invoice = CustomerInvoice::firstOrFail();
        $this->actingAs($stranger)->getJson(route('admin.boutiques.sales.invoice', $invoice->id))->assertNotFound();
        $this->actingAs($stranger)->post(route('admin.boutiques.returns.receive', $this->lama->id), ['note' => 1, 'sellable' => 0, 'damaged' => 0])->assertNotFound();
        $this->actingAs($stranger)->post('/admin/customer-invoices/'.$invoice->id.'/credit-note', ['amount' => 1, 'reason' => 'x', 'basis' => 'price'])->assertNotFound();
    }

    public function test_the_shares_are_for_who_reads_boutique_money_and_the_pieces_for_who_writes_the_note(): void
    {
        $invoice = $this->mixed();
        $page = fn (User $u) => $this->actingAs($u)->get(route('admin.customerInvoices.show', $invoice->id))->assertOk()->viewData('page')['props'];
        $props = $page($this->owner);
        $this->assertSame([9.6, 9.0], array_column($props['boutiqueShares'], 'net'));
        $this->assertSame([['قطعة فضّة', 2.0], ['عطر', 1.0]], array_map(fn ($p) => [$p['name'], (float) $p['left']], $props['boutiquePieces']));

        $seller = User::create(['business_id' => $this->shop->id, 'name' => 'بائع', 'email' => 's@dues.om', 'password' => bcrypt('x'), 'role' => 'sales', 'status' => 'نشط']);
        $sold = $page($seller);
        $this->assertSame([], $sold['boutiqueShares']);
        $this->assertStringNotContainsString('commission', json_encode($sold['boutiquePieces']));
        $this->actingAs($seller)->getJson(route('admin.boutiques.sales.invoice', $invoice->id))->assertForbidden();
        $this->actingAs($seller)->post(route('admin.boutiques.returns.receive', $this->lama->id), ['note' => 1, 'sellable' => 1, 'damaged' => 0])->assertForbidden();
    }

    /** مفتاحُ الإيواء أُطفئ: ما صدر يكتمل تحصيلُه ويُسوّى ويُسدَّد */
    public function test_switching_hosting_off_neither_drops_what_is_owed_nor_stops_its_payment(): void
    {
        $invoice = $this->mixed();
        $this->shop->update(['boutiques_enabled' => false]);
        $this->assertStands($this->lama, 0.0, 9.6, 'أُطفئ وهي تنتظر');
        $this->pay($invoice, 42);
        $sheet = $this->settle($this->lama);
        $this->paySheet($sheet);
        $this->assertEquals(0.0, $this->payable());
    }

    public function test_a_boutique_with_dues_on_invoices_is_not_deleted_until_they_are_settled(): void
    {
        $invoice = $this->mixed();
        $try = fn () => $this->actingAs($this->owner)->delete(route('admin.boutiques.destroy', $this->lama->id));
        $try()->assertSessionHasErrors('boutique');
        $this->pay($invoice, 42);
        $try()->assertSessionHasErrors('boutique');
        $this->assertNotNull($this->lama->fresh());
    }

    /* ═══════════ ٩ · إشعار الدائن من بابه ═══════════ */

    public function test_the_door_asks_what_the_credit_is_for_only_when_a_boutiques_line_is_there(): void
    {
        $plain = $this->invoice([[$this->mine]]);
        $this->actingAs($this->owner)->post('/admin/customer-invoices/'.$plain->id.'/credit-note', ['amount' => 5, 'reason' => 'خصم'])->assertSessionHasNoErrors();

        $invoice = $this->mixed();
        $url = '/admin/customer-invoices/'.$invoice->id.'/credit-note';
        $this->actingAs($this->owner)->post($url, ['amount' => 6, 'reason' => 'عادت'])->assertSessionHasErrors('basis');
        $this->assertSame(1, CustomerCreditNote::count());

        $item = $this->itemOf($invoice, $this->silver);
        $this->actingAs($this->owner)->post($url, ['amount' => 6, 'reason' => 'عادت', 'basis' => 'return', 'pieces' => [$item => ['quantity' => 1]]])->assertSessionHasNoErrors();
        $this->assertStands($this->lama, 0.0, 4.8, 'من باب الشاشة');
        $this->actingAs($this->owner)->post($url, ['amount' => 1, 'reason' => 'خصم', 'basis' => 'price', 'pieces' => [$item => ['quantity' => 1]]])->assertSessionHasNoErrors();
        $this->assertStands($this->lama, 0.0, 4.8, 'خصمٌ أُرسلت معه قطعٌ فلم تُقرأ');
        // وأكثرُ ممّا بقي يُردّ على حقله
        $this->actingAs($this->owner)->post($url, ['amount' => 6, 'reason' => 'x', 'basis' => 'return', 'pieces' => [$item => ['quantity' => 5]]])->assertSessionHasErrors('pieces');
    }

    /** بنودُ تصحيح الفاتورة (#195) بالشكل نفسِه — يُنقص بها ما نقصت كمّيّتُه، ولا يُنقص بخفض السعر */
    public function test_a_correction_shaped_like_pr195_lowers_by_quantity_and_never_by_price(): void
    {
        $invoice = $this->mixed();
        $i = $this->itemOf($invoice, $this->silver);
        $row = fn (float $before, float $after, float $p1, float $p2) => ['key' => 'i'.$i, 'change' => 'changed', 'quantity_before' => $before, 'quantity_after' => $after, 'unit_price_before' => $p1, 'unit_price_after' => $p2];
        CustomerInvoices::creditNote($invoice, 2, 0, 'تخفيض سعر', $this->owner->id, items: [$row(2, 2, 6, 5)], basis: 'error');
        $this->assertStands($this->lama, 0.0, 9.6, 'خفضُ السعر على المتجر');
        CustomerInvoices::creditNote($invoice->fresh(), 5, 0, 'كُتبت ثلاثٌ وهي اثنتان', $this->owner->id, items: [$row(2, 1, 5, 5)], basis: 'error');
        $this->assertStands($this->lama, 0.0, 4.8, 'نقصُ الكمّيّة ينقصه');
    }

    /* ═══════════ ١٠ · القديم ═══════════ */

    /** فاتورةٌ صدرت قبل أن تُحفظ اللقطة: تُحصَّل بعدها ولا يُكتب لها مستحقّ ولا يُستنتج */
    public function test_an_invoice_issued_before_is_collected_after_and_owes_nothing(): void
    {
        $invoice = $this->mixed();
        DB::table('boutique_invoice_lines')->delete();
        $this->pay($invoice, 42);
        CustomerInvoices::issue($invoice->fresh(), $this->owner->id);
        $this->assertSame(0, BoutiqueInvoiceLine::count());
        $this->assertStands($this->lama, 0.0, 0.0, 'قديمة');
    }

    /** كلُّ بابٍ يغيّر ما قُبض عن فاتورة يُقيم ما فيها — بابٌ جديد لا يُفتح بلا جواب */
    public function test_every_writer_of_a_collection_answers_for_the_boutiques(): void
    {
        $writers = [];
        foreach (\Symfony\Component\Finder\Finder::create()->files()->in(app_path())->name('*.php') as $file) {
            $code = $file->getContents();
            if (preg_match("/CustomerPaymentAllocation::(query\(\)->)?(create|insert|forceCreate)\(|CustomerCreditNote::(query\(\)->)?(create|insert|forceCreate)\(|'cheque_status' => self::(CLEARED|BOUNCED)|\$(payment|invoice)->update\(\[\s*('status' => CustomerInvoice::CANCELLED,\s*)?'cancelled_at' => now\(\)/", $code)) {
                $writers[str_replace('\\', '/', $file->getRelativePathname())] = str_contains($code, 'BoutiqueInvoiceDues::');
            }
        }
        ksort($writers);
        $this->assertSame([
            'Support/Cheques.php' => true,
            'Support/CustomerInvoices.php' => true,
            'Support/CustomerPayments.php' => true,
        ], $writers, 'بابٌ جديد يغيّر ما قُبض أو رُدّ عن فاتورة — أيُقيم ما فيها للبوتيك؟');
    }
}
