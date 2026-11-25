<?php

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\BoutiqueInvoiceLine;
use App\Models\BoutiqueReturn;
use App\Models\BoutiqueSettlement;
use App\Models\BoutiqueSheetHold;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\BooksPeriod;
use App\Support\BoutiqueHolds;
use App\Support\BoutiqueInvoiceDues;
use App\Support\BoutiqueMovements;
use App\Support\BoutiqueReturns;
use App\Support\Boutiques;
use App\Support\Cheques;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use App\Support\Profitability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الاعترافُ بنصيب البوتيك غيرُ تسويته: يُقيَّد مع الفاتورة، ويُسدَّد بعد تحصيلها.
 *
 * المثالُ الإلزاميّ: لمى ١٢ بنسبة ٢٠٪، وريم ١٠ بنسبة ١٠٪، وبضاعةُ المتجر ٢٠ — فاتورةٌ بـ٤٢
 * صدرت ٢٨ فبراير واكتمل قبضُها ٥ مارس. للبوتيكين ١٨٫٦٠٠.
 *
 *   «ب» (المختار): مصروفُ فبراير ١٨٫٦٠٠ ومارس صفر، والسدادُ في مارس.
 *   «أ» (`recognise` = sheet): فبراير صفر ومارس ١٨٫٦٠٠.
 */
class AShareIsRecognisedWithItsInvoiceAndSettledOnceCollectedTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Branch $main;

    private Branch $bausher;

    private Boutique $lama;

    private Boutique $reem;

    private Product $silver;

    private Product $scent;

    private Product $mine;

    private Customer $company;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Carbon::setTestNow('2027-02-28 10:00:00');
        $this->shop = Business::create(['name' => 'متجر الاعتراف', 'type' => 'عام', 'status' => 'نشط', 'boutiques_enabled' => true]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        $this->main = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $this->bausher = Branch::create(['business_id' => $this->shop->id, 'name' => 'بوشر']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'rec@test.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $this->lama = Boutique::create(['business_id' => $this->shop->id, 'name' => 'لمى', 'commission_rate' => 20, 'active' => true, 'returns_terms' => BoutiqueReturns::TERMS_ALL, 'returns_terms_ref' => 'ع 1']);
        $this->reem = Boutique::create(['business_id' => $this->shop->id, 'name' => 'ريم', 'commission_rate' => 10, 'active' => true]);
        $this->silver = $this->product('فضّة', 6, $this->lama);
        $this->scent = $this->product('عطر', 10, $this->reem);
        $this->mine = $this->product('باقة', 20);
        $this->company = Customer::create(['language' => 'ar', 'business_id' => $this->shop->id, 'name' => 'شركة', 'phone' => '92222222', 'allow_credit_sales' => true, 'payment_terms_days' => 30]);
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(string $name, float $price, ?Boutique $of = null): Product
    {
        return Product::create(['business_id' => $this->shop->id, 'name' => $name, 'price' => $price, 'cost' => 0, 'quantity' => 500, 'alert_qty' => 0, 'active' => true, 'published' => true, 'boutique_id' => $of?->id]);
    }

    private function mixed(?Branch $at = null, array $data = []): CustomerInvoice
    {
        return CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $this->company, $data + ['branch_id' => ($at ?? $this->main)->id], array_map(
            fn ($l) => ['product_id' => $l[0]->id, 'description' => $l[0]->name, 'quantity' => $l[1], 'unit_price' => (float) $l[0]->price],
            [[$this->silver, 2], [$this->scent, 1], [$this->mine, 1]],
        ), $this->owner->id), $this->owner->id);
    }

    private function pay(CustomerInvoice $i, float $amount, string $method = 'نقدي'): CustomerPayment
    {
        return CustomerPayments::record($this->shop->id, $this->company, $amount, ['method' => $method], [$i->id => $amount], $this->owner->id);
    }

    private function settle(Boutique $b, string $period): BoutiqueSettlement
    {
        return Boutiques::settle($this->shop->fresh(), $b, $period);
    }

    private function paySheet(BoutiqueSettlement $s): void
    {
        $this->post("/admin/expenses/{$s->expense_id}/paid", ['method' => 'نقدي'])->assertSessionHasNoErrors();
    }

    private function payable(): float
    {
        return round(Ledger::balance($this->shop->id, 'boutique_payable'), 3) + 0.0;
    }

    /** @return array{0: float, 1: float} إيرادُ الشهر ومصروفُه من الدفتر */
    private function month(string $m): array
    {
        $from = Carbon::parse($m.'-01');
        $to = $from->copy()->addMonth();

        return [round(Ledger::netRevenue($this->shop->id, $from, $to), 3) + 0.0, round(Ledger::operatingExpenses($this->shop->id, $from, $to), 3) + 0.0];
    }

    private function note(CustomerInvoice $i, Product $p, float $qty, float $amount, array $state = []): CustomerCreditNote
    {
        $id = (int) $i->items()->where('product_id', $p->id)->value('id');

        return CustomerInvoices::creditNote($i->fresh(), $amount, 0.0, 'مرتجع', $this->owner->id,
            items: BoutiqueInvoiceDues::noteItems($i, [$id => ['quantity' => $qty] + $state]), basis: CustomerCreditNote::RETURN);
    }

    /** 2160 = ما بقي من أوراقٍ لم تُسدَّد + ما سُدّدت دونه + ما قُيّد مع فواتيره بلا ورقة، والسجلُّ = الأحوال */
    private function assertBooks(string $why): void
    {
        $sheets = BoutiqueSettlement::where('business_id', $this->shop->id)->with('expense')->get()
            ->filter(fn ($s) => $s->expense && ! $s->expense->isPaid() && Boutiques::accrued($s->expense))->sum(fn ($s) => BoutiqueReturns::payable($s->expense));
        $withheld = collect(BoutiqueHolds::dues($this->shop->id))->sum('amount');
        $open = BoutiqueInvoiceDues::totals(BoutiqueInvoiceDues::accruedOpen($this->shop->id))['net'];
        $this->assertSame(number_format($sheets + $withheld + $open, 3), number_format($this->payable(), 3), "$why: 2160");
        $claims = collect([$this->lama, $this->reem])->sum(fn ($b) => BoutiqueReturns::claims($this->shop->id, $b->id)->sum(fn ($c) => BoutiqueReturns::remaining($c)));
        $this->assertSame(number_format($claims, 3), number_format(round(Ledger::balance($this->shop->id, 'boutique_receivable'), 3) + 0.0, 3), "$why: 1370");
        foreach ([$this->lama, $this->reem] as $b) {
            $this->assertSame(number_format(BoutiqueMovements::balance($this->shop->id, $b->id)['net'], 3), number_format(BoutiqueMovements::effects($this->shop->id, $b->id), 3), "$why: {$b->name}");
        }
    }

    /* ═══════════ «ب» ═══════════ */

    public function test_the_share_is_an_expense_of_the_invoices_month_and_is_paid_after_it_is_collected(): void
    {
        $invoice = $this->mixed();
        $this->assertSame([42.0, 18.6], $this->month('2027-02'));
        $this->assertSame(18.6, $this->payable());
        $this->assertSame(['2027-02-28', '2027-02-28'], BoutiqueInvoiceLine::orderBy('id')->get()->map(fn ($l) => $l->accrued_on->toDateString())->all());
        // قُيّد ولم يصر مستحقَّ التسوية
        $this->assertSame([0.0, 9.6], [Boutiques::statement($this->shop->id, $this->lama->id, '2027-02')['net'] + 0.0, Boutiques::statement($this->shop->id, $this->lama->id, '2027-02')['awaiting']['net']]);

        Carbon::setTestNow('2027-03-05 10:00:00');
        $this->pay($invoice, 42);
        $sheets = [$this->settle($this->lama, '2027-03'), $this->settle($this->reem, '2027-03')];
        $this->assertSame([9.6, 9.0], array_map(fn ($s) => (float) $s->accrued_at_sale, $sheets));
        $this->assertSame(0, JournalEntry::where('source', Boutiques::ACCRUAL)->count(), 'الورقةُ قيّدت ما قُيّد مع فاتورته');
        foreach ($sheets as $s) {
            $this->paySheet($s);
        }

        $this->assertSame([0.0, 0.0], $this->month('2027-03'));
        $this->assertSame([42.0, 18.6], $this->month('2027-02'));
        $this->assertSame(0.0, $this->payable());
        $this->assertBooks('سُدّد');
        // السدادُ يُطفئ الدَّين بتاريخه: لا مصروفَ ثانٍ
        $entry = JournalEntry::where('sourceable_type', Expense::class)->orderBy('id')->first();
        $this->assertSame(['2027-03-05', 'boutique_payable'], [$entry->entry_date->toDateString(), $entry->lines()->where('debit', '>', 0)->first()->account->system_key]);
    }

    public function test_each_share_is_its_own_entry_dated_with_its_invoice_and_in_its_branch(): void
    {
        $this->mixed($this->bausher, ['issued_at' => '2027-02-11']);

        $entries = JournalEntry::where('source', BoutiqueInvoiceDues::ACCRUAL)->orderBy('id')->get();
        $this->assertSame([['2027-02-11', $this->bausher->id], ['2027-02-11', $this->bausher->id]], $entries->map(fn ($e) => [$e->entry_date->toDateString(), (int) $e->branch_id])->all());
        $this->assertSame([BoutiqueInvoiceLine::class], $entries->pluck('sourceable_type')->unique()->all());
        $this->assertSame([9.6, 9.0], $entries->map(fn ($e) => round((float) $e->lines()->sum('debit'), 3))->all());
    }

    public function test_a_boutique_whose_whole_price_is_the_shops_has_nothing_to_recognise(): void
    {
        $this->lama->update(['commission_rate' => 100]);
        $invoice = $this->mixed();
        $this->assertSame(1, JournalEntry::where('source', BoutiqueInvoiceDues::ACCRUAL)->count());
        $this->assertNull(BoutiqueInvoiceLine::where('boutique_id', $this->lama->id)->sole()->accrued_on);
        $this->pay($invoice, 42);
        $this->assertSame(0.0, (float) $this->settle($this->lama, '2027-02')->accrued_at_sale);
        $this->assertBooks('نسبة مئة');
    }

    public function test_the_day_of_recognition_is_written_once(): void
    {
        $this->mixed();
        $this->expectException(\LogicException::class);
        BoutiqueInvoiceLine::first()->update(['accrued_on' => '2027-01-01']);
    }

    /* ═══════════ «أ» — وتبديلُ القاعدة ═══════════ */

    public function test_recognised_with_the_sheet_the_expense_is_of_the_collections_month(): void
    {
        config(['boutiques.recognise' => BoutiqueInvoiceDues::AT_SHEET]);
        $invoice = $this->mixed();
        $this->assertSame([42.0, 0.0], $this->month('2027-02'));
        Carbon::setTestNow('2027-03-05 10:00:00');
        $this->pay($invoice, 42);
        foreach ([$this->lama, $this->reem] as $b) {
            $this->paySheet($this->settle($b, '2027-03'));
        }
        $this->assertSame([0.0, 18.6], $this->month('2027-03'));
        $this->assertSame(0.0, $this->payable());
        $this->assertBooks('مع الورقة');
    }

    public function test_switching_the_rule_reaches_only_what_is_issued_after_it(): void
    {
        $first = $this->mixed();
        config(['boutiques.recognise' => BoutiqueInvoiceDues::AT_SHEET]);
        $second = $this->mixed();
        $this->assertSame([2, 2], [BoutiqueInvoiceLine::whereNotNull('accrued_on')->count(), BoutiqueInvoiceLine::whereNull('accrued_on')->count()]);
        $this->pay($first, 42);
        $this->pay($second, 42);

        // ورقةٌ فيها الاثنان: تقيّد الثانيَ وحده، وصافيها كلُّه دَينٌ واحد
        $sheet = $this->settle($this->lama, '2027-02');
        $this->assertSame([19.2, 9.6], [(float) $sheet->net, (float) $sheet->accrued_at_sale]);
        $this->assertSame(9.6, round((float) Boutiques::accrual($sheet->expense)->lines()->sum('debit'), 3));
        $this->assertBooks('قاعدتان');
        $this->paySheet($sheet);
        $this->assertSame(19.2, round((float) $sheet->fresh()->expense->transaction->amount, 3));
        $this->assertBooks('قاعدتان بعد السداد');
    }

    /* ═══════════ ما سقط قبل الورقة ═══════════ */

    public function test_an_invoice_cancelled_before_its_sheet_gives_back_what_was_recognised(): void
    {
        $invoice = $this->mixed();
        Carbon::setTestNow('2027-03-02 10:00:00');
        CustomerInvoices::cancel($invoice, 'خطأ', $this->owner->id);

        $this->assertSame([42.0, 18.6], $this->month('2027-02'), 'فبرايرُ كما كُتب');
        $this->assertSame([-42.0, -18.6], $this->month('2027-03'), 'والردُّ بيومه');
        $this->assertSame(0.0, $this->payable());
        $this->assertBooks('ملغاة');
        $rows = collect(BoutiqueMovements::page($this->shop->id, $this->lama->id, ['period' => '2027-03', 'group' => null, 'page' => 1])['rows']);
        $this->assertSame([-9.6], $rows->where('kind', 'voided')->pluck('effect')->all());
        $this->assertNotNull($rows->firstWhere('kind', 'voided')['entry']);
    }

    public function test_a_piece_returned_before_the_sheet_gives_back_its_part_and_the_rest_is_settled(): void
    {
        $invoice = $this->mixed();
        Carbon::setTestNow('2027-03-02 10:00:00');
        $this->note($invoice, $this->silver, 1, 6);
        $this->assertSame(13.8, $this->payable());
        $this->assertBooks('نصفُ المرتجع');

        $this->pay($invoice, 36);
        $sheet = $this->settle($this->lama, '2027-03');
        $this->assertSame([4.8, 4.8], [(float) $sheet->net, (float) $sheet->accrued_at_sale]);
        $this->paySheet($sheet);
        $this->assertBooks('سُدّد الباقي');
        $this->assertSame(9.0, $this->payable());
    }

    public function test_returned_in_part_then_cancelled_gives_back_exactly_what_was_recognised(): void
    {
        $invoice = $this->mixed();
        $this->note($invoice, $this->silver, 1, 6);
        try {
            CustomerInvoices::cancel($invoice->fresh(), 'خطأ', $this->owner->id);
        } catch (\RuntimeException) {
            // من قال النظامُ إنّها لا تُلغى وعليها إشعارٌ فلا شيءَ يُقاس هنا
            $this->note($invoice, $this->silver, 1, 6);
            $this->note($invoice, $this->scent, 1, 10);
        }
        $this->assertSame(0.0, $this->payable());
        $this->assertSame(0.0, round((float) DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->where('e.source', BoutiqueInvoiceDues::ACCRUAL)->selectRaw('sum(l.debit - l.credit) as v')->value('v'), 3) + 0.0);
        $this->assertBooks('مرتجعٌ ثمّ إلغاء');
    }

    public function test_what_falls_after_its_month_is_closed_is_posted_in_the_open_one(): void
    {
        $invoice = $this->mixed();
        Carbon::setTestNow('2027-03-10 10:00:00');
        Setting::create(['business_id' => $this->shop->id, 'key' => 'books_closed_through', 'value' => '2027-02-28']);
        $this->assertNotNull(BooksPeriod::closedThrough($this->shop->id));
        $this->note($invoice, $this->silver, 1, 6);

        $back = JournalEntry::where('source', BoutiqueInvoiceDues::ACCRUAL)->latest('id')->first();
        $this->assertSame('2027-03-10', $back->entry_date->toDateString());
        $this->assertSame([42.0, 18.6], $this->month('2027-02'));
    }

    /* ═══════════ وبعد الورقة ═══════════ */

    public function test_a_return_after_an_unpaid_sheet_lowers_the_debt_and_after_payment_is_a_claim(): void
    {
        $first = $this->mixed();
        $this->pay($first, 42);
        $sheet = $this->settle($this->lama, '2027-02');
        $this->note($first, $this->silver, 1, 6, ['sellable' => 1]);
        $this->assertSame(BoutiqueReturn::REDUCED, BoutiqueReturn::sole()->status);
        $this->assertSame([42.0 - 6.0, 13.8], $this->month('2027-02'), 'الإشعارُ ردّ الإيرادَ والنصيب');
        $this->assertBooks('خُفّض');
        $this->paySheet($sheet);
        $this->assertSame(4.8, round((float) $sheet->fresh()->expense->transaction->amount, 3));

        $this->note($first, $this->silver, 1, 6, ['sellable' => 1]);
        $this->assertSame(BoutiqueReturn::CLAIM, BoutiqueReturn::latest('id')->first()->status);
        $this->assertBooks('مطالبة');
    }

    public function test_a_collection_cancelled_after_the_sheet_holds_its_share_and_the_recognition_stays(): void
    {
        $first = $this->mixed();
        $second = $this->mixed();
        $payment = $this->pay($first, 42);
        $this->pay($second, 42);
        $sheet = $this->settle($this->lama, '2027-02');
        CustomerPayments::cancel($payment, 'خطأ');
        $this->assertSame(37.2, $this->payable(), 'الاعترافُ باقٍ: البيعُ وقع');
        $this->assertBooks('عُلّق');

        $this->paySheet($sheet);
        $this->assertSame(9.6, round((float) $sheet->fresh()->expense->transaction->amount, 3));
        $this->assertBooks('سُدّد دون المعلّق');
        $this->pay($first, 42);
        BoutiqueHolds::pay(BoutiqueSheetHold::sole(), 'نقدي');
        $this->assertBooks('سُدّد المعلّق');
        $this->assertSame([84.0, 37.2], $this->month('2027-02'));
        $rows = collect(BoutiqueMovements::page($this->shop->id, $this->lama->id, ['period' => '2027-02', 'group' => null, 'page' => 1])['rows']);
        $this->assertSame([['held', 0.0], ['hold_closed', 0.0], ['hold_paid', -9.6]], $rows->whereIn('kind', ['held', 'hold_closed', 'hold_paid'])->sortBy('kind')->map(fn ($r) => [$r['kind'], $r['effect']])->values()->all());
        $this->assertNotNull($rows->firstWhere('kind', 'hold_paid')['transaction']);
        $this->assertNotNull($rows->firstWhere('kind', 'hold_paid')['entry']);
    }

    public function test_a_cheque_is_recognised_at_issue_and_settled_when_it_clears(): void
    {
        $invoice = $this->mixed();
        Carbon::setTestNow('2027-03-05 10:00:00');
        $payment = $this->pay($invoice, 42, 'شيك');
        $this->assertSame([18.6, 0.0], [$this->payable(), Boutiques::statement($this->shop->id, $this->lama->id, '2027-03')['net'] + 0.0]);

        Cheques::bounce($payment, 'ارتدّ');
        $this->assertSame([18.6, 0.0, 9.6], [$this->payable(), Boutiques::statement($this->shop->id, $this->lama->id, '2027-03')['net'] + 0.0, Boutiques::statement($this->shop->id, $this->lama->id, '2027-03')['awaiting']['net']]);
        Carbon::setTestNow('2027-03-20 10:00:00');
        Cheques::clear($this->pay($invoice, 42, 'شيك'));
        $this->assertSame(9.6, (float) $this->settle($this->lama, '2027-03')->net);
        $this->assertSame([42.0, 18.6], $this->month('2027-02'));
        $this->assertBooks('شيك');
    }

    /* ═══════════ التقارير ═══════════ */

    /** ربحُ المتجر من الدفتر = مجموعُ فروعه وما لا فرعَ له — في شهر الفاتورة وفي شهر الورقة */
    public function test_branch_profit_counts_the_share_in_the_invoices_month_and_once(): void
    {
        $a = $this->mixed($this->main);
        $b = $this->mixed($this->bausher);
        $this->note($b, $this->scent, 1, 10);
        Carbon::setTestNow('2027-03-05 10:00:00');
        $this->pay($a, 42);
        $this->pay($b, 32);
        foreach ([$this->lama, $this->reem] as $x) {
            $s = $this->settle($x, '2027-03');
            if ($x->is($this->lama)) {
                $this->paySheet($s);
            }
        }
        $this->note($a, $this->silver, 1, 6, ['sellable' => 1]);

        foreach (['2027-02', '2027-03'] as $m) {
            $from = Carbon::parse($m.'-01');
            $to = $from->copy()->addMonth();
            $whole = Profitability::summary($this->shop->id, $from, $to);
            $main = Profitability::summary($this->shop->id, $from, $to, $this->main->id);
            $other = Profitability::summary($this->shop->id, $from, $to, $this->bausher->id);
            $sum = round($main['expenses'] + $other['expenses'] + $main['unallocated'], 3);
            $this->assertSame(number_format($whole['expenses'], 3), number_format($sum, 3), "مصروف $m");
        }
        $feb = fn (Branch $x) => Profitability::summary($this->shop->id, Carbon::parse('2027-02-01'), Carbon::parse('2027-03-01'), $x->id)['expenses'];
        $this->assertSame([18.6, 9.6], [$feb($this->main), $feb($this->bausher)]);

        // وصفوفُ الزمن تجمع إلى جملتها
        foreach ([null, $this->main->id, $this->bausher->id] as $branch) {
            $rows = collect(Profitability::rows($this->shop->id, 'year', $branch))->sum('expenses');
            $this->assertSame(number_format(Profitability::summary($this->shop->id, Carbon::parse('2027-01-01'), Carbon::parse('2028-01-01'), $branch)['expenses'], 3), number_format($rows, 3));
        }
    }

    public function test_the_dues_screen_says_what_is_posted_and_not_payable_yet(): void
    {
        $first = $this->mixed();
        $this->mixed();
        $this->pay($first, 42);
        $props = $this->get(route('admin.finance.dues'))->assertOk()->viewData('page')['props'];
        $this->assertEquals(['awaiting' => 18.6, 'ready' => 18.6], $props['boutiqueAccrued']);
        $this->assertEquals(0.0, $props['totals']['total']);

        $this->settle($this->lama, '2027-02');
        $props = $this->get(route('admin.finance.dues'))->viewData('page')['props'];
        $this->assertEquals(['awaiting' => 18.6, 'ready' => 9.0], $props['boutiqueAccrued']);
        $this->assertEquals(9.6, $props['totals']['total']);
        $this->assertSame(37.2, $this->payable());
    }

    /** شهورٌ عشوائيّة: 2160 و1370 والسجلُّ والفروعُ متطابقةٌ بعد كلّ دفعة */
    public function test_random_months_keep_the_books_and_the_register_equal(): void
    {
        foreach ([21, 22] as $seed) {
            mt_srand($seed);
            $pick = fn ($list) => $list[mt_rand(0, count($list) - 1)];
            $at = Carbon::parse('2027-02-02 09:00:00');
            $invoices = [];
            for ($n = 0; $n < 70; $n++) {
                Carbon::setTestNow($at = $at->copy()->addHours(mt_rand(6, 40)));
                config(['boutiques.recognise' => mt_rand(0, 4) ? BoutiqueInvoiceDues::AT_ISSUE : BoutiqueInvoiceDues::AT_SHEET]);
                try {
                    match (mt_rand(0, 8)) {
                        0, 1 => $invoices[] = $this->mixed($pick([$this->main, $this->bausher])),
                        2, 3 => ($i = CustomerInvoice::whereIn('id', array_column($invoices, 'id'))->where('status', CustomerInvoice::ISSUED)->orderBy('id')->get()->random()) && $i->outstanding() > 0 ? $this->pay($i, $i->outstanding(), $pick(['نقدي', 'شيك'])) : null,
                        4 => CustomerPayments::cancel(CustomerPayment::whereNull('cancelled_at')->orderBy('id')->get()->random(), 'خطأ'),
                        5 => ($p = CustomerPayment::whereNull('cancelled_at')->where('cheque_status', Cheques::PENDING)->orderBy('id')->get()->random()) ? (mt_rand(0, 1) ? Cheques::clear($p) : Cheques::bounce($p, 'ارتدّ')) : null,
                        6 => ($i = $pick($invoices)) ? (mt_rand(0, 3) ? $this->note($i, $pick([$this->silver, $this->scent]), 1, 6, ['sellable' => mt_rand(0, 1)]) : CustomerInvoices::cancel($i->fresh(), 'خطأ', $this->owner->id)) : null,
                        7 => $this->settle($pick([$this->lama, $this->reem]), $at->copy()->subDays(mt_rand(0, 25))->format('Y-m')),
                        default => ($h = BoutiqueSheetHold::whereNull('paid_at')->orderBy('id')->get())->isNotEmpty() && mt_rand(0, 1)
                            ? (mt_rand(0, 1) ? BoutiqueHolds::release($h->random(), 'قرار') : BoutiqueHolds::pay($h->random(), 'نقدي'))
                            : $this->post('/admin/expenses/'.BoutiqueSettlement::whereNotNull('expense_id')->orderBy('id')->get()->random()->expense_id.'/paid', ['method' => 'نقدي']),
                    };
                } catch (\RuntimeException|\App\Exceptions\SettlementRefused|\InvalidArgumentException|\Illuminate\Validation\ValidationException) {
                    session()->forget('errors');
                }
                if ($n % 6 === 0) {
                    $this->assertBooks("بذرة $seed خطوة $n");
                }
            }
            // وقع من كلٍّ شيء — وإلّا لم يُقَس
            foreach ([BoutiqueSettlement::count(), BoutiqueSheetHold::count(), BoutiqueReturn::count(), BoutiqueInvoiceLine::whereNotNull('voided_at')->count(),
                BoutiqueInvoiceLine::whereNull('accrued_on')->count(), Expense::where('status', Expense::PAID)->count()] as $k => $count) {
                $this->assertGreaterThan(0, $count, "بذرة $seed: لم يقع $k");
            }
            foreach (['2027-02', '2027-03', '2027-04', '2027-05', '2027-06'] as $m) {
                $from = Carbon::parse($m.'-01');
                $to = $from->copy()->addMonth();
                $one = Profitability::summary($this->shop->id, $from, $to, $this->main->id);
                $sum = $one['expenses'] + Profitability::summary($this->shop->id, $from, $to, $this->bausher->id)['expenses'] + $one['unallocated'];
                $this->assertSame(number_format(Profitability::summary($this->shop->id, $from, $to)['expenses'], 3), number_format($sum, 3), "بذرة $seed فروع $m");
            }
        }
    }
}
