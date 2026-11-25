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
use App\Models\CustomerInvoice;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\BoutiqueHolds;
use App\Support\BoutiqueInvoiceDues;
use App\Support\BoutiqueReturns;
use App\Support\Boutiques;
use App\Support\BoutiqueSales;
use App\Support\Cheques;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * أُلغي تحصيلُ فاتورةٍ بعد أن حملت ورقةُ البوتيك حصّتَها: يوقَف سدادُ المتأثّر وحده.
 *
 * والورقةُ ومصروفُها وقيدُ صدورها لا يُمسّ منها شيء في أيّ حال، ولا تُستحقّ الحصّةُ
 * ثانيةً، ولا يخرج للبوتيك أكثرُ ممّا في 2160 ولا أقلّ.
 */
class ASheetIsPaidWithoutWhatWasNotCollectedTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2027-02';

    private Business $shop;

    private Branch $branch;

    private User $owner;

    private Customer $company;

    private Boutique $lama;

    private Product $piece;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Carbon::setTestNow('2027-02-10 10:00:00');
        foreach (array_keys((array) config('storefront')) as $key) {
            if (str_starts_with($key, 'ribbon_') && str_ends_with($key, '_businesses')) {
                config(['storefront.'.$key => []]);
            }
        }
        $this->shop = Business::create(['name' => 'متجر', 'type' => 'عام', 'status' => 'نشط', 'boutiques_enabled' => true]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $this->owner = $this->user('owner', 'admin', null);
        $this->company = Customer::create(['language' => 'ar', 'business_id' => $this->shop->id, 'name' => 'شركة', 'phone' => '92222222', 'allow_credit_sales' => true]);
        $this->lama = Boutique::create(['business_id' => $this->shop->id, 'name' => 'لمى', 'commission_rate' => 20, 'active' => true]);
        $this->piece = Product::create(['business_id' => $this->shop->id, 'name' => 'قطعة', 'price' => 12, 'cost' => 0, 'quantity' => 999, 'alert_qty' => 0, 'active' => true, 'published' => true, 'boutique_id' => $this->lama->id]);
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $mail, string $role, ?array $keys, ?Business $shop = null): User
    {
        return User::create(['business_id' => ($shop ?? $this->shop)->id, 'name' => $mail, 'email' => $mail.'@hold.om', 'password' => bcrypt('x'), 'role' => $role, 'status' => 'نشط', 'permissions' => $keys]);
    }

    private function terms(?string $terms): void
    {
        $this->lama->update(['returns_terms' => $terms, 'returns_terms_ref' => $terms === null ? null : 'عقد 7']);
    }

    private function sell(int $qty = 1): void
    {
        $this->actingAs($this->owner)->postJson('/pos/checkout', ['items' => [['id' => $this->piece->id, 'name' => 'قطعة', 'qty' => $qty, 'price' => 12]], 'payment_method' => 'نقدي', 'client_uuid' => uniqid('h', true)])->assertOk();
    }

    private function invoice(int $qty = 1): CustomerInvoice
    {
        return CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $this->company, ['branch_id' => $this->branch->id], [['product_id' => $this->piece->id, 'description' => 'قطعة', 'quantity' => $qty, 'unit_price' => 12]]));
    }

    private function pay(CustomerInvoice $i, ?float $amount = null, string $method = 'نقدي')
    {
        $amount ??= (float) $i->total;

        return CustomerPayments::record($this->shop->id, $this->company, $amount, ['method' => $method], [$i->id => $amount], $this->owner->id);
    }

    private function settle(): BoutiqueSettlement
    {
        return Boutiques::settle($this->shop->fresh(), $this->lama, self::PERIOD);
    }

    private function paySheet(BoutiqueSettlement $s)
    {
        return $this->actingAs($this->owner)->post(route('admin.expenses.paid', $s->expense_id), ['method' => 'نقدي']);
    }

    private function note(CustomerInvoice $i, float $amount, int $qty): void
    {
        $item = (int) $i->items()->value('id');
        CustomerInvoices::creditNote($i->fresh(), $amount, 0, 'أُعيدت', null, items: BoutiqueInvoiceDues::noteItems($i->fresh(), [$item => ['quantity' => $qty, 'sellable' => $qty]]), basis: 'return');
    }

    private function payable(): float
    {
        return round(Ledger::balance($this->shop->id, 'boutique_payable'), 3) + 0.0;
    }

    private function receivable(): float
    {
        return round(Ledger::balance($this->shop->id, 'boutique_receivable'), 3) + 0.0;
    }

    private function hold(): BoutiqueSheetHold
    {
        return BoutiqueSheetHold::where('business_id', $this->shop->id)->latest('id')->firstOrFail();
    }

    /** الورقةُ ومصروفُها (إلّا حالَه وطريقتَه وحركتَه) وقيدُ صدورها كما صدرت */
    private function document(BoutiqueSettlement $s): array
    {
        return [
            (array) DB::table('boutique_settlements')->where('id', $s->id)->first(),
            array_diff_key((array) DB::table('expenses')->where('id', $s->expense_id)->first(), array_flip(['status', 'method', 'transaction_id', 'updated_at'])),
            DB::table('journal_entries')->where('sourceable_type', BoutiqueSettlement::class)->where('sourceable_id', $s->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            DB::table('journal_lines')->whereIn('journal_entry_id', DB::table('journal_entries')->where('sourceable_type', BoutiqueSettlement::class)->where('sourceable_id', $s->id)->pluck('id'))->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        ];
    }

    private function summary(): array
    {
        return BoutiqueSales::summary($this->shop->id, ['period' => self::PERIOD, 'boutique' => $this->lama->id, 'type' => null, 'settle' => null, 'state' => null, 'q' => '', 'page' => 1])['settlement'];
    }

    /** @return array{0: CustomerInvoice, 1: \App\Models\CustomerPayment, 2: BoutiqueSettlement} بيعةُ صندوقٍ 12 وفاتورةٌ 12 في ورقةٍ 19.200 */
    private function mixed(): array
    {
        $this->sell();
        $i = $this->invoice();
        $p = $this->pay($i);
        $s = $this->settle();
        $this->assertEquals(19.2, (float) $s->net);

        return [$i, $p, $s];
    }

    /* ═══════════ ورقةٌ لم تُسدَّد ═══════════ */

    public function test_only_the_part_that_was_not_collected_is_held_and_the_rest_of_the_sheet_is_paid(): void
    {
        [$i, $p, $s] = $this->mixed();
        $doc = $this->document($s);
        $this->assertEquals(19.2, $this->payable());

        CustomerPayments::cancel($p->fresh(), 'ارتدّ التحويل');
        $h = $this->hold();
        $this->assertEquals([9.6, false, null], [(float) $h->amount, $h->paid_before, $h->closed_at]);
        $this->assertSame([9.6, 9.6], [BoutiqueHolds::heldOn($s->id), BoutiqueHolds::payNow(Expense::find($s->expense_id))]);
        $this->assertEquals($doc, $this->document($s), 'الورقةُ أو مصروفُها أو قيدُها تغيّر');
        $this->assertEquals(19.2, $this->payable(), 'الدَّينُ كما هو');

        $row = collect($this->get(route('admin.expenses.index', ['range' => 'all']))->viewData('page')['props']['expenses'])->firstWhere('id', $s->expense_id);
        $this->assertEquals(['held' => 9.6, 'now' => 9.6], $row['hold']);

        $cash = Ledger::balance($this->shop->id, 'cash');
        $this->paySheet($s)->assertSessionHasNoErrors();
        $this->assertEquals(9.6, round($cash - Ledger::balance($this->shop->id, 'cash'), 3), 'خرج أكثرُ ممّا قُبض ثمنُه');
        $this->assertEquals(9.6, $this->payable(), 'المعلَّقُ يبقى دَينًا');
        $this->assertEquals(9.6, (float) Transaction::find(Expense::find($s->expense_id)->transaction_id)->amount);
        $this->assertSame(Expense::PAID, Expense::find($s->expense_id)->status);
        $this->assertEquals($doc, $this->document($s));
        $this->assertEquals([9.6, 9.6], [(float) $this->hold()->withheld_amount, BoutiqueHolds::owed($this->hold())]);

        // يُقال في كلّ مكان، ولا يُستحقّ ثانيةً
        $this->assertSame([19.2, 9.6, 9.6], [$this->summary()['issued'], $this->summary()['paid'], $this->summary()['unpaid']]);
        $dues = $this->get(route('admin.finance.dues'))->viewData('page')['props'];
        $this->assertEquals([['sheet' => $s->number, 'boutique' => 'لمى', 'name' => 'قطعة', 'amount' => 9.6, 'held' => true]], array_map(fn ($r) => array_diff_key($r, ['id' => 1]), $dues['holds']));
        $this->assertEquals(9.6, $dues['totals']['expenses']);
        $this->assertEquals(0.0, Boutiques::statement($this->shop->id, $this->lama->id, self::PERIOD)['net']);
        $this->paySheet($s)->assertSessionHas('toast.type', 'info');
        $this->assertEquals(9.6, $this->payable(), 'ضغطةٌ ثانية دفعت');
    }

    public function test_a_sheet_whose_whole_rest_is_held_is_refused_and_not_marked_paid(): void
    {
        $i = $this->invoice();
        $p = $this->pay($i);
        $s = $this->settle();
        CustomerPayments::cancel($p->fresh(), 'ارتدّ');
        $before = [DB::table('journal_entries')->count(), DB::table('transactions')->count()];

        $this->paySheet($s)->assertSessionHasErrors('pay');
        $this->assertSame([Expense::UNPAID, $before], [Expense::find($s->expense_id)->status, [DB::table('journal_entries')->count(), DB::table('transactions')->count()]]);
        $this->assertNull($this->hold()->withheld_at);
        $this->assertEquals(9.6, $this->payable());

        // أُعيد التحصيل: تُسدَّد كاملة
        $this->pay($i);
        $this->assertSame([BoutiqueSheetHold::RECOLLECTED, 0.0], [$this->hold()->closed_as, BoutiqueHolds::heldOn($s->id)]);
        $this->paySheet($s)->assertSessionHasNoErrors();
        $this->assertEquals(0.0, $this->payable());
    }

    public function test_collecting_again_before_the_sheet_is_paid_pays_it_whole(): void
    {
        [$i, $p, $s] = $this->mixed();
        CustomerPayments::cancel($p->fresh(), 'ارتدّ');
        $this->pay($i, 5.0);
        $this->assertNull($this->hold()->closed_at, 'بعضُ السداد ليس تحصيلًا');
        $this->assertSame(1, BoutiqueSheetHold::count(), 'كلُّ حركةٍ فتحت تعليقًا');
        $this->pay($i, 7.0);
        $this->assertSame(BoutiqueSheetHold::RECOLLECTED, $this->hold()->closed_as);
        $this->paySheet($s)->assertSessionHasNoErrors();
        $this->assertEquals([0.0, 0.0], [$this->payable(), BoutiqueHolds::owed($this->hold())]);
    }

    /** ورقةٌ سُدّدت دونه ثمّ أُعيد تحصيلُه: يُسدَّد وحدَه، مرّةً واحدة */
    public function test_what_was_withheld_is_paid_once_after_it_is_collected_again(): void
    {
        [$i, $p, $s] = $this->mixed();
        CustomerPayments::cancel($p->fresh(), 'ارتدّ');
        $this->paySheet($s);
        $doc = $this->document($s);
        $h = $this->hold();
        $url = route('admin.boutiques.holds.pay', [$this->lama->id, $h->id]);

        $this->post($url, ['method' => 'تحويل'])->assertSessionHasErrors('holds');
        $this->assertEquals(9.6, $this->payable(), 'سُدّد وهو معلَّق');

        $this->pay($i);
        $this->assertSame('payable', BoutiqueHolds::of($this->shop->id, $this->lama->id)[0]['state']);
        $bank = Ledger::balance($this->shop->id, 'bank');
        $this->post($url, ['method' => 'تحويل'])->assertSessionHasNoErrors();
        $this->assertEquals([9.6, 0.0], [round($bank - Ledger::balance($this->shop->id, 'bank'), 3), $this->payable()]);
        $this->assertEquals([9.6, 9.6], [(float) $this->hold()->paid_amount, (float) Transaction::find($this->hold()->transaction_id)->amount]);
        $this->post($url, ['method' => 'تحويل'])->assertSessionHasErrors('holds');
        $this->assertEquals(0.0, $this->payable(), 'سُدّد مرّتين');
        $this->assertEquals($doc, $this->document($s));
        $this->assertSame([19.2, 0.0], [$this->summary()['paid'], $this->summary()['unpaid']]);
        $this->assertSame([], $this->get(route('admin.finance.dues'))->viewData('page')['props']['holds']);
    }

    public function test_a_written_decision_releases_it_and_it_is_not_held_again_until_it_is_collected_and_cancelled_anew(): void
    {
        [$i, $p, $s] = $this->mixed();
        CustomerPayments::cancel($p->fresh(), 'ارتدّ');
        $h = $this->hold();
        $release = route('admin.boutiques.holds.release', [$this->lama->id, $h->id]);

        $this->post($release, ['why' => ''])->assertSessionHasErrors('why');
        $this->post($release, ['why' => 'ضمنه العميل كتابةً'])->assertSessionHasNoErrors();
        $this->assertEquals([BoutiqueSheetHold::RELEASED, 'ضمنه العميل كتابةً', $this->owner->id], [$this->hold()->closed_as, $this->hold()->note, (int) $this->hold()->closed_by]);
        $this->post($release, ['why' => 'ثانيةً'])->assertSessionHasErrors('holds');
        $this->assertSame(19.2, BoutiqueHolds::payNow(Expense::find($s->expense_id)));

        // حركةٌ أخرى على الفاتورة لا تُعيد التعليق: القرارُ قائم
        $this->pay($i, 5.0);
        $this->assertSame(1, BoutiqueSheetHold::count());
        $this->paySheet($s)->assertSessionHasNoErrors();
        $this->assertEquals(0.0, $this->payable());

        // حُصّلت ثمّ أُلغي من جديد — والورقةُ مسدَّدة: للمراجعة لا تعليقُ سداد
        $rest = $this->pay($i, 7.0);
        CustomerPayments::cancel($rest->fresh(), 'ارتدّ ثانية');
        $this->assertSame(2, BoutiqueSheetHold::count());
        $this->assertTrue($this->hold()->paid_before);
        $this->assertEquals(0.0, $this->payable());
    }

    /** شيكٌ صُرف ثمّ أُلغي سندُه: يُعلَّق بسببه — وشيكٌ لم يُصرف لا تكتمل به فاتورةٌ فلا تحملها ورقة */
    public function test_a_cleared_cheque_whose_receipt_is_cancelled_holds_it_with_its_reason(): void
    {
        $this->sell();
        $i = $this->invoice();
        $c = $this->pay($i, null, 'شيك');
        $this->assertNull(BoutiqueInvoiceLine::sole()->collected_at);
        Cheques::clear($c->fresh());
        $s = $this->settle();
        $this->assertEquals(19.2, (float) $s->net);
        CustomerPayments::cancel($c->fresh(), 'بلا رصيد');
        $this->assertSame(9.6, BoutiqueHolds::heldOn($s->id));
        $this->assertStringContainsString((string) $c->number, $this->hold()->why);
        $this->assertSame((int) $c->id, (int) $this->hold()->customer_payment_id);
    }

    /* ═══════════ ورقةٌ سُدّدت قبل الإلغاء ═══════════ */

    public function test_a_sheet_paid_before_the_cancellation_is_shown_for_review_and_nothing_is_posted(): void
    {
        [$i, $p, $s] = $this->mixed();
        $this->paySheet($s)->assertSessionHasNoErrors();
        $doc = $this->document($s);
        $books = [$this->payable(), $this->receivable(), DB::table('journal_lines')->count()];
        $before = (array) DB::table('expenses')->where('id', $s->expense_id)->first();

        CustomerPayments::cancel($p->fresh(), 'ارتدّ');
        $h = $this->hold();
        $this->assertTrue($h->paid_before);
        $this->assertSame(0.0, BoutiqueHolds::heldOn($s->id));
        $this->assertEquals($doc, $this->document($s));
        $this->assertEquals($before, (array) DB::table('expenses')->where('id', $s->expense_id)->first(), 'مصروفٌ سُدّد تغيّر');
        $this->assertEquals([$books[0], $books[1]], [$this->payable(), $this->receivable()]);
        $this->assertSame(['paid_before', 0.0], [BoutiqueHolds::of($this->shop->id, $this->lama->id)[0]['state'], BoutiqueHolds::of($this->shop->id, $this->lama->id)[0]['held']]);

        // لا يُفرج عنه ولا يُسدَّد: دُفع أصلًا
        $this->post(route('admin.boutiques.holds.release', [$this->lama->id, $h->id]), ['why' => 'خطأ'])->assertSessionHasErrors('holds');
        $this->post(route('admin.boutiques.holds.pay', [$this->lama->id, $h->id]), ['method' => 'نقدي'])->assertSessionHasErrors('holds');
        $lines = DB::table('journal_lines')->count();
        $this->post(route('admin.boutiques.holds.review', [$this->lama->id, $h->id]), ['why' => 'يُخصم باتفاق لاحق'])->assertSessionHasNoErrors();
        $this->assertSame([BoutiqueSheetHold::REVIEWED, $lines], [$this->hold()->closed_as, DB::table('journal_lines')->count()]);
        $this->assertEquals($doc, $this->document($s));
    }

    public function test_collecting_again_after_a_paid_sheet_closes_the_review_and_owes_nothing_new(): void
    {
        [$i, $p, $s] = $this->mixed();
        $this->paySheet($s);
        CustomerPayments::cancel($p->fresh(), 'ارتدّ');
        $this->pay($i);
        $this->assertSame(BoutiqueSheetHold::RECOLLECTED, $this->hold()->closed_as);
        $this->assertEquals([0.0, 0.0], [$this->payable(), Boutiques::statement($this->shop->id, $this->lama->id, self::PERIOD)['net']]);
    }

    /* ═══════════ رُدّت القطعة ولم تُحصَّل ═══════════ */

    public function test_a_returned_piece_lowers_a_withheld_part_and_is_no_claim_on_the_boutique(): void
    {
        $this->terms('all');
        [$i, $p, $s] = $this->mixed();
        CustomerPayments::cancel($p->fresh(), 'ارتدّ');
        $this->paySheet($s);
        $this->assertEquals([9.6, 0.0], [$this->payable(), $this->receivable()]);
        $doc = $this->document($s);

        $this->note($i, 12, 1);
        $this->assertSame(BoutiqueReturn::REDUCED, BoutiqueReturn::sole()->status, 'مطالبةٌ بما لم يُدفع');
        $this->assertEquals([0.0, 0.0], [$this->payable(), $this->receivable()]);
        $this->assertSame([BoutiqueSheetHold::RETURNED, 0.0], [$this->hold()->closed_as, BoutiqueHolds::owed($this->hold())]);
        $this->assertEquals($doc, $this->document($s));
        $this->post(route('admin.boutiques.holds.pay', [$this->lama->id, $this->hold()->id]), ['method' => 'نقدي'])->assertSessionHasErrors('holds');
        $this->assertSame([9.6, 0.0], [$this->summary()['paid'], $this->summary()['unpaid']]);
    }

    public function test_a_returned_piece_before_the_sheet_is_paid_lowers_it_and_the_rest_is_paid(): void
    {
        $this->terms('all');
        [$i, $p, $s] = $this->mixed();
        CustomerPayments::cancel($p->fresh(), 'ارتدّ');
        $this->note($i, 12, 1);
        $this->assertSame([BoutiqueSheetHold::RETURNED, 9.6], [$this->hold()->closed_as, BoutiqueHolds::payNow(Expense::find($s->expense_id))]);
        $this->paySheet($s)->assertSessionHasNoErrors();
        $this->assertEquals([0.0, 0.0], [$this->payable(), $this->receivable()]);
    }

    /** بلا شرطٍ موثَّق: الإشعارُ يُسدّد الفاتورةَ حسابًا ولا يُحسم — فيبقى موقوفًا حتّى يقرّر المحاسب */
    public function test_a_return_that_waits_for_a_decision_keeps_the_part_held_until_it_is_decided(): void
    {
        foreach (['shop' => [9.6, 'payable'], 'boutique' => [0.0, 'returned']] as $bearer => [$owed, $state]) {
            BoutiqueReturn::query()->delete();
            BoutiqueSheetHold::query()->delete();
            [$i, $p, $s] = $this->mixed();
            CustomerPayments::cancel($p->fresh(), 'ارتدّ');
            $this->paySheet($s);
            $before = $this->payable();
            $this->note($i, 12, 1);
            $this->assertTrue(BoutiqueInvoiceDues::collected($i->id), 'الإشعارُ لم يُسدّد الفاتورة — الاختبارُ لا يقيس شيئًا');
            $this->assertNull($this->hold()->closed_at, "{$bearer}: عُدّ ردُّ القطعة تحصيلًا");
            $this->post(route('admin.boutiques.holds.pay', [$this->lama->id, $this->hold()->id]), ['method' => 'نقدي'])->assertSessionHasErrors('holds');

            BoutiqueReturns::review(BoutiqueReturn::where('status', BoutiqueReturn::REVIEW)->sole(), $bearer, 'قرار المحاسب');
            $this->assertEquals([$owed, $state], [BoutiqueHolds::owed($this->hold()), BoutiqueHolds::of($this->shop->id, $this->lama->id)[0]['state']], $bearer);
            $this->assertEquals($bearer === 'shop' ? $before : round($before - 9.6, 3), $this->payable(), $bearer);
            if ($owed > 0) {
                $this->post(route('admin.boutiques.holds.pay', [$this->lama->id, $this->hold()->id]), ['method' => 'نقدي'])->assertSessionHasNoErrors();
            }
            $this->assertEquals([0.0, 0.0], [$this->payable(), $this->receivable()], $bearer);
        }
    }

    public function test_a_part_of_the_pieces_returned_leaves_the_rest_held(): void
    {
        $this->terms('all');
        $i = $this->invoice(3);
        $p = $this->pay($i);
        $this->sell();
        $s = $this->settle();
        $this->assertEquals(38.4, (float) $s->net);
        CustomerPayments::cancel($p->fresh(), 'ارتدّ');
        $this->assertSame([28.8, 9.6], [BoutiqueHolds::heldOn($s->id), BoutiqueHolds::payNow(Expense::find($s->expense_id))]);
        $this->note($i, 12, 1);
        $this->assertSame([19.2, 9.6], [BoutiqueHolds::heldOn($s->id), BoutiqueHolds::payNow(Expense::find($s->expense_id))]);
        $this->paySheet($s)->assertSessionHasNoErrors();
        $this->assertEquals(19.2, $this->payable());
        $this->pay($i, 24.0);
        $this->post(route('admin.boutiques.holds.pay', [$this->lama->id, $this->hold()->id]), ['method' => 'نقدي'])->assertSessionHasNoErrors();
        $this->assertEquals([0.0, 0.0], [$this->payable(), $this->receivable()]);
    }

    /** قطعةٌ رُدّت وحُسمت قبل إلغاء التحصيل ليست من المعلَّق، ولا تُطرح منه مرّةً ثانية */
    public function test_a_piece_returned_before_the_cancellation_is_not_taken_from_what_is_held(): void
    {
        $this->terms('all');
        $i = $this->invoice(3);
        $p = $this->pay($i);
        $this->sell();
        $s = $this->settle();
        $this->note($i, 12, 1);
        $this->assertEquals(28.8, BoutiqueReturns::payable(Expense::find($s->expense_id)));
        CustomerPayments::cancel($p->fresh(), 'ارتدّ');
        $this->assertEquals(19.2, (float) $this->hold()->amount);
        $this->assertSame([19.2, 9.6], [BoutiqueHolds::heldOn($s->id), BoutiqueHolds::payNow(Expense::find($s->expense_id))]);
    }

    /** مطالبةٌ على البوتيك لا تُخصم من معلَّقٍ لم يُراجَع */
    public function test_a_claim_is_not_offset_against_what_is_held(): void
    {
        $this->terms('all');
        $old = $this->invoice(2);
        $this->pay($old);
        $first = $this->settle();
        $this->paySheet($first)->assertSessionHasNoErrors();
        $this->note($old, 24, 2);
        $this->assertEquals(19.2, $this->receivable());

        [$i, $p, $s] = $this->mixed();
        CustomerPayments::cancel($p->fresh(), 'ارتدّ');
        $this->assertSame(9.6, BoutiqueReturns::offset($this->shop, $this->lama, $s));
        $this->assertSame([9.6, 0.0], [BoutiqueHolds::heldOn($s->id), BoutiqueHolds::payNow(Expense::find($s->expense_id))]);
        $this->assertEquals([9.6, 9.6], [$this->payable(), $this->receivable()]);
    }

    /* ═══════════ بالبيسة ═══════════ */

    public function test_what_is_paid_now_and_what_is_held_always_make_what_the_sheet_owes(): void
    {
        foreach ([10, 12.5, 17.5, 17.53, 33.33, 7.25, 0, 100] as $rate) {
            $this->lama->update(['commission_rate' => $rate]);
            $this->sell(3);
            $a = $this->invoice(2);
            $b = $this->invoice(1);
            $pa = $this->pay($a);
            $this->pay($b);
            $s = $this->settle();
            CustomerPayments::cancel($pa->fresh(), 'ارتدّ');
            $e = Expense::find($s->expense_id);
            $this->assertSame((int) round(BoutiqueReturns::payable($e) * 1000), (int) round((BoutiqueHolds::payNow($e) + BoutiqueHolds::heldOn($s->id)) * 1000), (string) $rate);
            $owed = $this->payable();
            if (BoutiqueHolds::payNow($e) > 0) {
                $this->paySheet($s)->assertSessionHasNoErrors();
                $this->assertEquals(round($owed - BoutiqueHolds::payNow($e) - (float) ($s->net - BoutiqueHolds::owed($this->hold()) - BoutiqueHolds::payNow($e)), 3), $this->payable());
            }
            $this->pay($a);
            if (BoutiqueHolds::owed($this->hold()) > 0) {
                $this->post(route('admin.boutiques.holds.pay', [$this->lama->id, $this->hold()->id]), ['method' => 'نقدي'])->assertSessionHasNoErrors();
            } elseif (! Expense::find($s->expense_id)->isPaid() && (float) $s->net > 0) {
                $this->paySheet($s)->assertSessionHasNoErrors();
            }
            $this->assertEquals(0.0, $this->payable(), "{$rate}: بقي دَينٌ أو دُفع زائد");
        }
    }

    /* ═══════════ الصلاحيّات والعزل والإطفاء ═══════════ */

    public function test_the_three_doors_ask_the_same_permissions_as_the_rest_of_the_boutiques_money(): void
    {
        [$i, $p, $s] = $this->mixed();
        CustomerPayments::cancel($p->fresh(), 'ارتدّ');
        $h = $this->hold();
        $door = fn (string $n) => route('admin.boutiques.holds.'.$n, [$this->lama->id, $h->id]);
        foreach ([
            'products' => [['dashboard', 'products'], [403, 403, 403]],
            'reads' => [['dashboard', 'products', 'expenses'], [403, 302, 403]],
            'issues' => [['dashboard', 'products', 'expenses', Permissions::INVOICE_APPROVE], [403, 302, 403]],
            'pays' => [['dashboard', 'products', 'expenses', Permissions::INVOICE_PAY], [302, 302, 302]],
        ] as $who => [$keys, $want]) {
            $u = $this->user($who, 'sales', $keys);
            $got = [
                $this->actingAs($u)->post($door('release'), ['why' => ''])->status(),
                $this->actingAs($u)->post($door('review'), ['why' => ''])->status(),
                $this->actingAs($u)->post($door('pay'), ['method' => ''])->status(),
            ];
            $this->assertSame($want, $got, $who);
            $props = $this->actingAs($u)->get(route('admin.boutiques.show', $this->lama->id))->viewData('page')['props'];
            $who === 'products' ? $this->assertNull($props['holds']) : $this->assertCount(1, $props['holds']);
        }
        $this->assertNull($this->hold()->closed_at);

        // متجرٌ آخر، وبوتيكٌ آخر: ٤٠٤
        $other = Business::create(['name' => 'آخر', 'type' => 'عام', 'status' => 'نشط', 'boutiques_enabled' => true]);
        $theirs = $this->user('theirs', 'admin', null, $other);
        $theirBoutique = Boutique::create(['business_id' => $other->id, 'name' => 'ل', 'commission_rate' => 1, 'active' => true]);
        $mine = Boutique::create(['business_id' => $this->shop->id, 'name' => 'ريم', 'commission_rate' => 1, 'active' => true]);
        foreach (['release' => ['why' => 'سبب'], 'review' => ['why' => 'سبب'], 'pay' => ['method' => 'نقدي']] as $n => $data) {
            $this->actingAs($theirs)->post($door($n), $data)->assertNotFound();
            $this->actingAs($theirs)->post(route('admin.boutiques.holds.'.$n, [$theirBoutique->id, $h->id]), $data)->assertNotFound();
            $this->actingAs($this->owner)->post(route('admin.boutiques.holds.'.$n, [$mine->id, $h->id]), $data)->assertNotFound();
        }
    }

    public function test_switching_the_feature_off_lets_no_held_part_through(): void
    {
        [$i, $p, $s] = $this->mixed();
        $this->shop->update(['boutiques_enabled' => false]);
        CustomerPayments::cancel($p->fresh(), 'ارتدّ');
        $this->assertSame(9.6, BoutiqueHolds::heldOn($s->id));
        $this->paySheet($s)->assertSessionHasNoErrors();
        $this->assertEquals(9.6, $this->payable());
        $this->pay($i);
        $this->actingAs($this->owner->fresh())->post(route('admin.boutiques.holds.pay', [$this->lama->id, $this->hold()->id]), ['method' => 'نقدي'])->assertSessionHasNoErrors();
        $this->assertEquals(0.0, $this->payable());
    }

    /** ما لم تحمله ورقةٌ لا يُعلَّق: ينتظر تحصيلَه كما كان */
    public function test_what_no_sheet_carried_waits_as_before_and_is_not_held(): void
    {
        $i = $this->invoice();
        $p = $this->pay($i);
        CustomerPayments::cancel($p->fresh(), 'ارتدّ');
        $this->assertSame(0, BoutiqueSheetHold::count());
        $this->assertNull(BoutiqueInvoiceLine::sole()->collected_at);
    }

    /* ═══════════ الهجرة ═══════════ */

    public function test_the_migration_is_not_undone_over_a_held_part_and_touches_nothing_when_run(): void
    {
        [$i, $p, $s] = $this->mixed();
        $doc = $this->document($s);
        $migration = require database_path('migrations/2026_11_29_100000_a_sheet_is_paid_without_what_was_not_collected.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('boutique_sheet_holds'));
        $migration->up();
        $this->assertEquals($doc, $this->document($s));
        $this->assertSame(0, BoutiqueSheetHold::count(), 'الهجرةُ كتبت صفًّا لماضٍ');

        CustomerPayments::cancel($p->fresh(), 'ارتدّ');
        $this->expectException(RuntimeException::class);
        $migration->down();
    }
}
