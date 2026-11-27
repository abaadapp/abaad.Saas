<?php

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\BoutiqueSettlement;
use App\Models\BoutiqueSheetHold;
use App\Models\BoutiqueSheetPayment;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\BoutiqueHolds;
use App\Support\Boutiques;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * رُجع بالكود إلى ما قبل التعليق: الجداولُ باقيةٌ ولا يقرؤها أحد — فتردّ قاعدةُ البيانات نفسُها
 * سدادَ ورقةٍ تحمل بنودَ فواتير، ويبقى ما عداها يُسدَّد كما كان. وبعد العودة يُقيَّم ما فات.
 */
class OlderCodeNeverPaysWhatASheetHoldsTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2027-02';

    private const MIGRATION = 'migrations/2026_12_02_090000_a_sheet_with_invoice_money_is_paid_only_by_code_that_read_its_holds.php';

    private Business $shop;

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
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'o', 'email' => 'o@older.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $this->company = Customer::create(['language' => 'ar', 'business_id' => $this->shop->id, 'name' => 'شركة', 'phone' => '92222222', 'allow_credit_sales' => true]);
        $this->lama = Boutique::create(['business_id' => $this->shop->id, 'name' => 'لمى', 'commission_rate' => 20, 'active' => true]);
        $this->piece = Product::create(['business_id' => $this->shop->id, 'name' => 'قطعة', 'price' => 12, 'cost' => 0, 'quantity' => 999, 'alert_qty' => 0, 'active' => true, 'boutique_id' => $this->lama->id]);
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sell(): void
    {
        $this->postJson('/pos/checkout', ['items' => [['id' => $this->piece->id, 'name' => 'قطعة', 'qty' => 1, 'price' => 12]], 'payment_method' => 'نقدي', 'client_uuid' => uniqid('h', true)])->assertOk();
    }

    private function invoice(): CustomerInvoice
    {
        return CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $this->company, [], [['product_id' => $this->piece->id, 'description' => 'قطعة', 'quantity' => 1, 'unit_price' => 12]]));
    }

    private function pay(CustomerInvoice $i): CustomerPayment
    {
        return CustomerPayments::record($this->shop->id, $this->company, 12, ['method' => 'نقدي'], [$i->id => 12], $this->owner->id);
    }

    /** @return array{0: CustomerInvoice, 1: CustomerPayment, 2: BoutiqueSettlement} بيعةُ صندوقٍ 12 وفاتورةٌ 12 في ورقةٍ 19.200 */
    private function mixed(): array
    {
        $this->sell();
        $i = $this->invoice();
        $p = $this->pay($i);

        return [$i, $p, Boutiques::settle($this->shop->fresh(), $this->lama, self::PERIOD)];
    }

    /** ما يفعله `ExpenseController::markPaid` في الإصدار المنشور اليوم — حالةٌ ثمّ حركةٌ ثمّ قيدٌ بمبلغ المصروف كلِّه */
    private function olderCodePays(BoutiqueSettlement $s): void
    {
        DB::transaction(function () use ($s) {
            $locked = Expense::whereKey($s->expense_id)->lockForUpdate()->firstOrFail();
            $locked->update(['status' => Expense::PAID, 'method' => 'نقدي']);
            $t = Transaction::create(['business_id' => $locked->business_id, 'reference' => Transaction::nextReference($locked->business_id), 'description' => 'قديم', 'method' => 'نقدي', 'type' => 'مصروف', 'amount' => $locked->amount, 'occurred_at' => now()]);
            $locked->update(['transaction_id' => $t->id]);
            Ledger::post($locked->business_id, 'قديم', [['account' => 'boutique_payable', 'debit' => (float) $locked->amount], ['account' => 'cash', 'credit' => (float) $locked->amount]], now(), 'مصروف', null, null, $locked);
        });
    }

    /** إلغاءُ التحصيل بكودٍ لا يعرف التعليق: السندُ وحده */
    private function olderCodeCancels(CustomerPayment $p): void
    {
        DB::table('customer_payments')->where('id', $p->id)->update(['cancelled_at' => now(), 'cancellation_reason' => 'ارتدّ']);
    }

    private function books(): string
    {
        $out = '';
        foreach (['expenses', 'transactions', 'journal_entries', 'journal_lines', 'boutique_settlements', 'boutique_sheet_holds', 'boutique_sheet_payments', 'boutique_invoice_lines', 'customer_payments'] as $t) {
            $out .= $t.md5((string) json_encode(DB::table($t)->orderBy('id')->get()));
        }

        return $out;
    }

    private function refused(BoutiqueSettlement $s): void
    {
        $before = $this->books();
        try {
            $this->olderCodePays($s);
            $this->fail('سدّد الكودُ القديم ورقةً فيها فاتورةُ عميل');
        } catch (QueryException $e) {
            $this->assertStringContainsString('boutique_sheet_holds_unread', $e->getMessage());
        }
        $this->assertSame($before, $this->books(), 'رُدّ السدادُ وترك أثرًا');
        $this->assertSame(Expense::UNPAID, Expense::find($s->expense_id)->status);
    }

    private function payable(): float
    {
        return round(Ledger::balance($this->shop->id, 'boutique_payable'), 3) + 0.0;
    }

    private function overview(): array
    {
        return BoutiqueHolds::overview($this->shop->id)[$this->shop->id];
    }

    /* ═══════════ الكودُ القديم ═══════════ */

    public function test_older_code_cannot_pay_a_sheet_that_holds_a_part_and_nothing_is_written(): void
    {
        [, $p, $s] = $this->mixed();
        CustomerPayments::cancel($p->fresh(), 'ارتدّ');
        $this->refused($s);
        $this->assertEquals(19.2, $this->payable());
    }

    /** أُلغي التحصيلُ والكودُ القديم يعمل: لا تعليقَ فُتح — والسدادُ يُردّ مع ذلك */
    public function test_a_collection_cancelled_while_older_code_runs_is_not_paid_either(): void
    {
        [, $p, $s] = $this->mixed();
        $this->olderCodeCancels($p);
        $this->assertSame(0, BoutiqueSheetHold::count());
        $this->refused($s);
    }

    public function test_older_code_still_pays_a_sheet_of_till_sales_alone_and_every_other_expense(): void
    {
        $this->sell();
        $s = Boutiques::settle($this->shop->fresh(), $this->lama, self::PERIOD);
        $this->olderCodePays($s);
        $this->assertSame(Expense::PAID, Expense::find($s->expense_id)->status);
        $this->assertEquals(0.0, $this->payable());

        $rent = Expense::create(['business_id' => $this->shop->id, 'reference' => 'E-9', 'type' => 'إيجار', 'amount' => 100, 'method' => 'نقدي', 'status' => Expense::UNPAID, 'spent_at' => now()]);
        $rent->update(['status' => Expense::PAID]);
        $this->assertSame(Expense::PAID, $rent->fresh()->status);

        // ومتجرٌ لا بوتيكَ فيه
        $other = Business::create(['name' => 'آخر', 'type' => 'عام', 'status' => 'نشط']);
        $e = Expense::create(['business_id' => $other->id, 'reference' => 'E-1', 'type' => 'إيجار', 'amount' => 5, 'method' => 'نقدي', 'status' => Expense::UNPAID, 'spent_at' => now()]);
        DB::table('expenses')->where('id', $e->id)->update(['status' => Expense::PAID]);
        $this->assertSame(Expense::PAID, $e->fresh()->status);
    }

    /* ═══════════ هذا الكود ═══════════ */

    public function test_this_code_pays_it_and_writes_what_left_and_what_was_held(): void
    {
        [, $p, $s] = $this->mixed();
        CustomerPayments::cancel($p->fresh(), 'ارتدّ');
        $this->post(route('admin.expenses.paid', $s->expense_id), ['method' => 'نقدي'])->assertSessionHasNoErrors();
        $row = BoutiqueSheetPayment::sole();
        $this->assertEquals([$this->shop->id, $s->id, $s->expense_id, 9.6, 9.6, $this->owner->id], [$row->business_id, $row->boutique_settlement_id, $row->expense_id, (float) $row->amount, (float) $row->held, $row->created_by]);
        $this->assertEquals((float) $row->amount, (float) Transaction::find(Expense::find($s->expense_id)->transaction_id)->amount, 'صفُّ السداد غيرُ ما خرج');
        $this->assertEquals(9.6, $this->payable());
    }

    public function test_a_refused_payment_leaves_no_marker_that_would_open_the_door_to_older_code(): void
    {
        $i = $this->invoice();
        $p = $this->pay($i);
        $s = Boutiques::settle($this->shop->fresh(), $this->lama, self::PERIOD);
        CustomerPayments::cancel($p->fresh(), 'ارتدّ');
        $this->post(route('admin.expenses.paid', $s->expense_id), ['method' => 'نقدي'])->assertSessionHasErrors('pay');
        $this->assertSame(0, BoutiqueSheetPayment::count());
        $this->refused($s);
    }

    public function test_a_payment_that_fails_after_its_marker_takes_the_marker_back_with_it(): void
    {
        [, , $s] = $this->mixed();
        $before = $this->books();
        Transaction::creating(fn () => throw new RuntimeException('انقطع'));
        try {
            $this->post(route('admin.expenses.paid', $s->expense_id), ['method' => 'نقدي'])->assertSessionHasErrors('pay');
        } finally {
            Transaction::flushEventListeners();
        }
        $this->assertSame($before, $this->books());
        $this->refused($s);
    }

    /** سدادُ ورقةٍ بهذا الكود لا يفتح بابًا لأخرى */
    public function test_the_marker_of_one_sheet_opens_no_other(): void
    {
        [, , $first] = $this->mixed();
        $this->post(route('admin.expenses.paid', $first->expense_id), ['method' => 'نقدي'])->assertSessionHasNoErrors();
        $this->assertSame(0, $this->overview()['unpaid_sheets']);
        Carbon::setTestNow('2027-03-10 10:00:00');
        $this->pay($this->invoice());
        $this->refused(Boutiques::settle($this->shop->fresh(), $this->lama, '2027-03'));
        $this->assertSame([1, 9.6], [$this->overview()['unpaid_sheets'], $this->overview()['unpaid_amount']]);
    }

    /* ═══════════ قبل الرجوع وبعده ═══════════ */

    public function test_the_check_reads_what_would_be_left_and_writes_nothing(): void
    {
        [, $p] = $this->mixed();
        CustomerPayments::cancel($p->fresh(), 'ارتدّ');
        $before = $this->books();
        $this->assertSame(0, Artisan::call('boutiques:holds'));
        $this->assertSame($before, $this->books());
        $out = Artisan::output();
        foreach (['حماية السداد قائمة', 'لم تُسدَّد: 1 بمبلغ 19.200', 'معلّق فيها الآن: 1 بمبلغ 9.600'] as $said) {
            $this->assertStringContainsString($said, $out);
        }
        $this->assertStringNotContainsString('شركة', $out, 'اسمُ عميلٍ في المخرج');
        $this->assertSame(['unpaid_sheets' => 1, 'unpaid_amount' => 19.2, 'held' => 1, 'held_amount' => 9.6, 'withheld' => 0, 'withheld_amount' => 0.0, 'drifted' => 0, 'unnamed_notes' => []], $this->overview());
    }

    public function test_the_check_alone_opens_nothing_it_only_names_what_changed(): void
    {
        [, $p] = $this->mixed();
        $this->olderCodeCancels($p);
        $before = $this->books();
        Artisan::call('boutiques:holds');
        $this->assertSame($before, $this->books());
        $this->assertStringContainsString('تغيّر تحصيلها ولم يُقيَّم: 1', Artisan::output());
    }

    public function test_the_check_fails_when_the_database_does_not_guard_the_payment(): void
    {
        $this->assertTrue(BoutiqueHolds::guarded());
        DB::unprepared(DB::getDriverName() === 'pgsql' ? 'DROP TRIGGER '.BoutiqueHolds::GUARD.' ON expenses' : 'DROP TRIGGER '.BoutiqueHolds::GUARD);
        $this->assertFalse(BoutiqueHolds::guarded());
        $this->assertSame(1, Artisan::call('boutiques:holds'));
        $this->assertStringContainsString('لا ترجع بالكود', Artisan::output());
        $this->assertSame(1, Artisan::call('boutiques:holds', ['--json' => true]));
    }

    public function test_coming_back_holds_what_was_cancelled_meanwhile_once_and_pays_the_rest(): void
    {
        [, $p, $s] = $this->mixed();
        $this->olderCodeCancels($p);
        $this->assertSame(1, $this->overview()['drifted']);
        $books = [$this->payable(), DB::table('journal_lines')->count(), DB::table('transactions')->count()];

        $this->assertSame(0, Artisan::call('boutiques:holds', ['--resync' => true]));
        $this->assertSame(0, Artisan::call('boutiques:holds', ['--resync' => true]));
        $this->assertSame(1, BoutiqueSheetHold::count(), 'عُلّق مرّتين');
        $this->assertSame([0, 9.6], [$this->overview()['drifted'], $this->overview()['held_amount']]);
        $this->assertEquals($books, [$this->payable(), DB::table('journal_lines')->count(), DB::table('transactions')->count()], 'التقييمُ كتب مالًا');

        $this->post(route('admin.expenses.paid', $s->expense_id), ['method' => 'نقدي'])->assertSessionHasNoErrors();
        $this->assertEquals(9.6, $this->payable());
        $this->assertSame([1, 9.6], [$this->overview()['withheld'], $this->overview()['withheld_amount']]);
    }

    public function test_coming_back_releases_what_was_collected_again_meanwhile(): void
    {
        [$i, $p, $s] = $this->mixed();
        CustomerPayments::cancel($p->fresh(), 'ارتدّ');
        DB::table('customer_payments')->where('id', $p->id)->update(['cancelled_at' => null]);
        $this->assertSame(1, $this->overview()['drifted']);
        Artisan::call('boutiques:holds', ['--resync' => true, '--business' => $this->shop->id]);
        $this->assertSame([0, 0.0], [$this->overview()['drifted'], BoutiqueHolds::heldOn($s->id)]);
        $this->assertSame(BoutiqueSheetHold::RECOLLECTED, BoutiqueSheetHold::sole()->closed_as);
        $this->post(route('admin.expenses.paid', $s->expense_id), ['method' => 'نقدي'])->assertSessionHasNoErrors();
        $this->assertEquals(0.0, $this->payable());
    }

    /** قرارٌ مكتوبٌ قبل الرجوع يبقى قرارًا بعد العودة */
    public function test_a_written_release_is_not_undone_by_coming_back(): void
    {
        [, $p, $s] = $this->mixed();
        CustomerPayments::cancel($p->fresh(), 'ارتدّ');
        BoutiqueHolds::release(BoutiqueSheetHold::sole(), 'ضمنه كتابةً');
        $this->assertSame(0, $this->overview()['drifted']);
        Artisan::call('boutiques:holds', ['--resync' => true]);
        $this->assertSame([1, 0.0], [BoutiqueSheetHold::count(), BoutiqueHolds::heldOn($s->id)]);
    }

    public function test_the_resync_stays_inside_the_shop_it_is_given(): void
    {
        [, $p] = $this->mixed();
        $this->olderCodeCancels($p);
        Artisan::call('boutiques:holds', ['--resync' => true, '--business' => $this->shop->id + 1]);
        $this->assertSame(0, BoutiqueSheetHold::count());
        $this->assertSame([], BoutiqueHolds::overview($this->shop->id + 1));
    }

    /** إشعارٌ كُتب بكودٍ لا يسأل ما رُدّ: يُسمّى ليُراجَع — وإشعارُ هذا الكود مبيَّنٌ فلا يُسمّى */
    public function test_a_credit_note_written_meanwhile_without_what_it_is_for_is_named(): void
    {
        [$i] = $this->mixed();
        CustomerInvoices::creditNote($i->fresh(), 1, 0, 'خصم', basis: 'discount');
        $this->assertSame([], $this->overview()['unnamed_notes']);
        $old = CustomerCreditNote::create(['business_id' => $this->shop->id, 'customer_invoice_id' => $i->id, 'number' => 'CN-OLD', 'amount' => 1, 'tax_amount' => 0, 'issued_at' => now()->toDateString(), 'reason' => 'أُعيدت', 'source' => CustomerInvoices::NOTE_MANUAL]);
        $this->assertSame(['CN-OLD'], $this->overview()['unnamed_notes']);
        $old->update(['source' => 'إلغاء طلب']);
        $this->assertSame([], $this->overview()['unnamed_notes']);
    }

    /* ═══════════ الهجرة ═══════════ */

    public function test_the_guard_is_not_lifted_while_a_sheet_carries_invoice_money(): void
    {
        [, , $s] = $this->mixed();
        try {
            (require database_path(self::MIGRATION))->down();
            $this->fail('رُفعت الحماية');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ارجع بالكود وحده', $e->getMessage());
        }
        $this->assertTrue(BoutiqueHolds::guarded());
        $this->refused($s);
    }

    public function test_the_migration_writes_no_row_and_is_undone_whole_when_nothing_was_carried(): void
    {
        $m = require database_path(self::MIGRATION);
        $before = [DB::table('expenses')->count(), BoutiqueSheetPayment::count()];
        $m->down();
        $this->assertFalse(BoutiqueHolds::guarded());
        $this->assertFalse(Schema::hasTable('boutique_sheet_payments'));
        if (DB::getDriverName() === 'pgsql') {
            $this->assertSame(0, (int) DB::table('pg_proc')->where('proname', 'boutique_sheet_is_paid_by_code_that_read_its_holds')->count());
        }
        $m->up();
        $this->assertTrue(BoutiqueHolds::guarded());
        $this->assertSame($before, [DB::table('expenses')->count(), BoutiqueSheetPayment::count()]);
    }
}
