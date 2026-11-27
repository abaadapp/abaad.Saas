<?php

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\BoutiqueInvoiceLine;
use App\Models\BoutiqueInvoiceReturn;
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
use App\Models\User;
use App\Support\Boutiques;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * أيدٍ معًا على فاتورة عميلٍ واحدة — تُحصَّل وتُسوّى ويُردّ منها مرّةً واحدة.
 *
 * عمليّاتٌ حقيقيّة باتّصالاتٍ مستقلّة على PostgreSQL، كـ`TwoHandsOnOneBoutiqueSheetPayItOnceTest`.
 */
class TwoHandsOnOneCollectedInvoiceCountItOnceTest extends TestCase
{
    use DatabaseTruncation;

    private const PERIOD = '2027-03';

    private Business $shop;

    private User $owner;

    private Boutique $lama;

    private Customer $company;

    private CustomerInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('يُثبَت على PostgreSQL وحدها — SQLite لا تُبقي معاملتين مفتوحتين معًا');
        }
        Carbon::setTestNow('2027-03-01 09:00:00');
        $this->shop = Business::create(['name' => 'متجر', 'type' => 'عام', 'status' => 'نشط', 'boutiques_enabled' => true]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        $branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $this->lama = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك لمى', 'commission_rate' => 20, 'active' => true]);
        $silver = Product::create(['business_id' => $this->shop->id, 'name' => 'قطعة', 'price' => 6, 'cost' => 1, 'quantity' => 100, 'alert_qty' => 0, 'active' => true, 'boutique_id' => $this->lama->id]);
        $this->company = Customer::create(['language' => 'ar', 'business_id' => $this->shop->id, 'name' => 'شركة', 'allow_credit_sales' => true]);
        $this->invoice = CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $this->company, ['branch_id' => $branch->id], [
            ['product_id' => $silver->id, 'description' => 'قطعة', 'quantity' => 2, 'unit_price' => 6],
        ]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }
        parent::tearDown();
    }

    /** @return array{0: resource, 1: array<int, resource>} */
    private function hand(string $body, float $startAt): array
    {
        $c = DB::connection()->getConfig();
        $env = [
            'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'), 'APP_TIMEZONE' => (string) config('app.timezone'),
            'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_HOST' => (string) $c['host'], 'DB_PORT' => (string) ($c['port'] ?? 5432),
            'DB_DATABASE' => (string) $c['database'], 'DB_USERNAME' => (string) $c['username'], 'DB_PASSWORD' => (string) ($c['password'] ?? ''),
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
            'BROADCAST_CONNECTION' => 'null', 'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false',
        ];
        $code = sprintf(
            'require %s; $app = require %s; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();'
            .' Illuminate\Support\Carbon::setTestNow("2027-03-01 10:00:00"); Illuminate\Support\Facades\Auth::loginUsingId(%d);'
            .' while (microtime(true) < %F) { usleep(300); }'
            .' $out = "ok"; try { %s } catch (App\Exceptions\SettlementRefused $e) { $out = "refused"; } catch (Illuminate\Validation\ValidationException $e) { $out = "invalid"; } catch (RuntimeException $e) { $out = "runtime"; } catch (Throwable $e) { $out = get_class($e).": ".$e->getMessage(); }'
            .' echo json_encode($out);',
            var_export(base_path('vendor/autoload.php'), true), var_export(base_path('bootstrap/app.php'), true), $this->owner->id, $startAt, $body,
        );
        $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
        $this->assertIsResource($proc, 'تعذّر تشغيل اليد الأخرى');

        return [$proc, $pipes];
    }

    /**
     * @param  list<string>  $bodies
     * @return list<string>
     */
    private function hands(array $bodies): array
    {
        $start = microtime(true) + 1.2;
        $running = array_map(fn ($b) => $this->hand($b, $start), $bodies);
        $out = [];
        foreach ($running as [$proc, $pipes]) {
            $out[] = (string) json_decode(stream_get_contents($pipes[1]));
            $err = stream_get_contents($pipes[2]);
            proc_close($proc);
            $this->assertSame('', trim($err), 'يدٌ انهارت: '.$err);
        }

        return $out;
    }

    private function balanced(): void
    {
        $this->assertEquals(0, round((float) DB::table('journal_lines')->selectRaw('SUM(debit) - SUM(credit) as d')->value('d'), 3));
    }

    private function settleBody(): string
    {
        return sprintf('App\Support\Boutiques::settle(App\Models\Business::find(%d), App\Models\Boutique::find(%d), "%s");', $this->shop->id, $this->lama->id, self::PERIOD);
    }

    private function payBody(float $amount): string
    {
        return sprintf('App\Support\CustomerPayments::record(%d, App\Models\Customer::find(%d), %F, ["method" => "نقدي"], [%d => %F]);', $this->shop->id, $this->company->id, $amount, $this->invoice->id, $amount);
    }

    private function net(): float
    {
        return (float) Boutiques::statement($this->shop->id, $this->lama->id, self::PERIOD)['net'];
    }

    /** إصدارٌ بثلاث أيدٍ: لقطةٌ واحدة للبند */
    public function test_three_hands_issuing_one_invoice_keep_one_line(): void
    {
        $draft = CustomerInvoices::create($this->shop->id, $this->company, [], [['product_id' => Product::firstOrFail()->id, 'description' => 'قطعة', 'quantity' => 1, 'unit_price' => 6]]);
        $out = $this->hands(array_fill(0, 3, sprintf('App\Support\CustomerInvoices::issue(App\Models\CustomerInvoice::find(%d));', $draft->id)));
        $this->assertSame(['ok', 'ok', 'ok'], $out);
        $this->assertSame(1, BoutiqueInvoiceLine::where('customer_invoice_id', $draft->id)->count());
        $this->balanced();
    }

    /** دفعتان تكملانها معًا: تصير مستحقّةً مرّةً — ولا يزيد المخصَّصُ على الفاتورة */
    public function test_payments_that_complete_it_together_make_it_due_once(): void
    {
        $out = $this->hands([$this->payBody(6), $this->payBody(6), $this->payBody(6)]);
        $this->assertSame(['ok', 'ok', 'ok'], $out);
        $this->assertEquals(0.0, $this->invoice->fresh()->outstanding());
        $this->assertEquals(12.0, (float) DB::table('customer_payment_allocations')->sum('amount'));
        $this->assertEquals(9.6, $this->net());
        $this->balanced();
    }

    public function test_three_hands_issuing_one_months_sheet_carry_it_once(): void
    {
        CustomerPayments::record($this->shop->id, $this->company, 12, ['method' => 'نقدي'], [$this->invoice->id => 12]);
        $out = $this->hands(array_fill(0, 3, $this->settleBody()));
        sort($out);
        $this->assertSame(['ok', 'refused', 'refused'], $out);
        $this->assertSame([1, 1], [BoutiqueSettlement::count(), Expense::where('business_id', $this->shop->id)->count()]);
        $this->assertEquals(9.6, round(Ledger::balance($this->shop->id, 'boutique_payable'), 3));
        $this->balanced();
    }

    /** إلغاءُ التحصيل والورقةُ معًا: إمّا ورقةٌ حملته (فيُقرأ استثناءً)، أو لا ورقةَ وهو ينتظر — لا نصفَ بينهما */
    public function test_a_cancelled_payment_and_a_sheet_at_once_leave_no_half(): void
    {
        $payment = CustomerPayments::record($this->shop->id, $this->company, 12, ['method' => 'نقدي'], [$this->invoice->id => 12]);
        [$cancel, $settle] = $this->hands([
            sprintf('App\Support\CustomerPayments::cancel(App\Models\CustomerPayment::find(%d), "خطأ");', $payment->id),
            $this->settleBody(),
        ]);
        $this->assertSame('ok', $cancel);
        $line = BoutiqueInvoiceLine::sole();
        if ($settle === 'ok') {
            $this->assertSame(1, BoutiqueSettlement::count());
            $this->assertNotNull($line->boutique_settlement_id);
            $this->assertEquals(9.6, (float) $line->settled_net);
            $this->assertSame(1, Boutiques::statement($this->shop->id, $this->lama->id, self::PERIOD)['reopened']['lines']);
        } else {
            $this->assertSame('refused', $settle);
            $this->assertSame([0, null, null], [BoutiqueSettlement::count(), $line->boutique_settlement_id, $line->collected_at]);
            // وفي 2160 ما قُيّد مع الفاتورة يومَ صدورها — لا ورقةَ تحمله ولا سدادَ له قبل تحصيله
            $this->assertEquals(9.6, round(Ledger::balance($this->shop->id, 'boutique_payable'), 3));
        }
        $this->balanced();
    }

    /** مرتجعُ القطعتين بثلاث أيدٍ، كلٌّ منها يردّهما: يُردّ مرّةً */
    public function test_three_hands_returning_the_same_pieces_return_them_once(): void
    {
        $item = (int) $this->invoice->items()->value('id');
        $out = $this->hands(array_fill(0, 3, sprintf(
            '$i = App\Models\CustomerInvoice::find(%d); App\Support\CustomerInvoices::creditNote($i, 4, 0, "مرتجع", null, items: App\Support\BoutiqueInvoiceDues::noteItems($i, [%d => ["quantity" => 2]]), basis: "return");',
            $this->invoice->id, $item,
        )));
        sort($out);
        $this->assertSame(['invalid', 'invalid', 'ok'], $out);
        $this->assertSame([1, 1], [BoutiqueInvoiceReturn::count(), DB::table('customer_credit_notes')->count()]);
        $this->assertEquals(2.0, (float) BoutiqueInvoiceLine::sole()->returned_quantity);
        $this->balanced();
    }

    /** مرتجعٌ وورقةٌ معًا: إمّا نقص قبلها فحملت ما بقي، أو حملته كاملًا فكُتب مرتجعُه إشعارًا */
    public function test_a_return_and_a_sheet_at_once_owe_the_boutique_what_is_left(): void
    {
        CustomerPayments::record($this->shop->id, $this->company, 12, ['method' => 'نقدي'], [$this->invoice->id => 12]);
        $this->lama->update(['returns_terms' => 'all', 'returns_terms_ref' => 'عقد']);
        $item = (int) $this->invoice->items()->value('id');
        [$return] = $this->hands([
            sprintf('$i = App\Models\CustomerInvoice::find(%d); App\Support\CustomerInvoices::creditNote($i, 6, 0, "مرتجع", null, items: App\Support\BoutiqueInvoiceDues::noteItems($i, [%d => ["quantity" => 1, "sellable" => 1]]), basis: "return");', $this->invoice->id, $item),
            $this->settleBody(),
        ]);
        $this->assertSame('ok', $return);
        if (BoutiqueSettlement::count() === 0) {
            // الورقةُ قرأت قبل أن يتغيّر فرُدّت — وتُصدَر بما بقي
            $this->assertEquals(4.8, $this->net());
            Boutiques::settle($this->shop->fresh(), $this->lama, self::PERIOD);
        }
        $sheet = BoutiqueSettlement::sole();
        $notes = round((float) DB::table('boutique_returns')->sum('amount'), 3);
        $this->assertEquals(4.8, round((float) $sheet->net - $notes, 3), 'للبوتيك غيرُ ما بقي');
        $this->assertEquals(4.8, round(Ledger::balance($this->shop->id, 'boutique_payable'), 3));
        $this->balanced();
    }

    /* ═══════════ تحصيلٌ أُلغي بعد الورقة — `BoutiqueHolds` ═══════════ */

    private function paySheetBody(BoutiqueSettlement $s): string
    {
        return sprintf('app(App\Http\Controllers\Admin\ExpenseController::class)->markPaid(Illuminate\Http\Request::create("/", "POST", ["method" => "نقدي"]), %d);', $s->expense_id);
    }

    /** سدادُ الورقة وإلغاءُ التحصيل معًا: إمّا سُدّدت قبله فتُراجَع، أو عُلّقت فلا يخرج شيء — ولا حالٌ بينهما */
    public function test_paying_a_sheet_and_cancelling_its_collection_at_once_is_one_or_the_other(): void
    {
        $seen = [];
        for ($n = 0; $n < 4; $n++) {
            $invoice = $n === 0 ? $this->invoice : CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $this->company, [], [['product_id' => Product::firstOrFail()->id, 'description' => 'قطعة', 'quantity' => 2, 'unit_price' => 6]]));
            $payment = CustomerPayments::record($this->shop->id, $this->company, 12, ['method' => 'نقدي'], [$invoice->id => 12]);
            $sheet = Boutiques::settle($this->shop, $this->lama, self::PERIOD);
            $cash = Ledger::balance($this->shop->id, 'cash');
            $owed = Ledger::balance($this->shop->id, 'boutique_payable');

            $out = $this->hands([$this->paySheetBody($sheet), sprintf('App\Support\CustomerPayments::cancel(App\Models\CustomerPayment::find(%d), "ارتدّ");', $payment->id)]);
            $this->assertSame('ok', $out[1], 'إلغاءُ التحصيل سقط: '.$out[1]);
            $this->assertSame('ok', $out[0], 'سدادُ الورقة سقط: '.$out[0]);

            $hold = BoutiqueSheetHold::where('boutique_settlement_id', $sheet->id)->sole();
            $paid = Expense::find($sheet->expense_id)->isPaid();
            // إلغاءُ السند يردّ 12 من الصندوق، والورقةُ 9.600 إن سُدّدت
            $left = round($cash - Ledger::balance($this->shop->id, 'cash'), 3);
            $this->assertSame($paid, $hold->paid_before, 'سُدّدت والتعليقُ يقول لا، أو العكس');
            $this->assertEquals($paid ? 21.6 : 12.0, $left);
            $this->assertEquals(round($owed - ($paid ? 9.6 : 0.0), 3), round(Ledger::balance($this->shop->id, 'boutique_payable'), 3));
            $this->assertNull($hold->withheld_at);
            $seen[] = $paid;
            $this->balanced();
        }
        $this->assertCount(4, $seen);
    }

    public function test_three_hands_paying_what_was_withheld_pay_it_once(): void
    {
        $other = CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $this->company, [], [['product_id' => Product::firstOrFail()->id, 'description' => 'قطعة', 'quantity' => 1, 'unit_price' => 6]]));
        $payment = CustomerPayments::record($this->shop->id, $this->company, 12, ['method' => 'نقدي'], [$this->invoice->id => 12]);
        CustomerPayments::record($this->shop->id, $this->company, 6, ['method' => 'نقدي'], [$other->id => 6]);
        $sheet = Boutiques::settle($this->shop, $this->lama, self::PERIOD);
        CustomerPayments::cancel($payment->fresh(), 'ارتدّ');
        $this->assertSame(['ok'], $this->hands([$this->paySheetBody($sheet)]));
        $this->assertEquals(9.6, round(Ledger::balance($this->shop->id, 'boutique_payable'), 3));
        CustomerPayments::record($this->shop->id, $this->company, 12, ['method' => 'نقدي'], [$this->invoice->id => 12]);

        $hold = BoutiqueSheetHold::sole();
        $out = $this->hands(array_fill(0, 3, sprintf('App\Support\BoutiqueHolds::pay(App\Models\BoutiqueSheetHold::find(%d), "نقدي");', $hold->id)));
        sort($out);
        $this->assertSame(['ok', 'refused', 'refused'], $out);
        $this->assertEquals(0.0, round(Ledger::balance($this->shop->id, 'boutique_payable'), 3));
        $this->assertSame(1, DB::table('journal_entries')->where('source', \App\Support\BoutiqueHolds::SOURCE)->count());
        $this->balanced();
    }

    /** إلغاءان وإفراجٌ وتحصيلٌ معًا: تعليقٌ واحدٌ مفتوحٌ على الأكثر، ولا عطب */
    public function test_cancelling_releasing_and_collecting_at_once_leave_one_row_decided_once(): void
    {
        $payment = CustomerPayments::record($this->shop->id, $this->company, 12, ['method' => 'نقدي'], [$this->invoice->id => 12]);
        Boutiques::settle($this->shop, $this->lama, self::PERIOD);
        $cancel = sprintf('App\Support\CustomerPayments::cancel(App\Models\CustomerPayment::find(%d), "ارتدّ");', $payment->id);
        $this->assertSame(['ok', 'ok'], $this->hands([$cancel, $cancel]));
        $this->assertSame(1, BoutiqueSheetHold::count());

        $hold = BoutiqueSheetHold::sole();
        $out = $this->hands([
            sprintf('App\Support\BoutiqueHolds::release(App\Models\BoutiqueSheetHold::find(%d), "قرار");', $hold->id),
            sprintf('App\Support\BoutiqueHolds::release(App\Models\BoutiqueSheetHold::find(%d), "قرار");', $hold->id),
            $this->payBody(12),
        ]);
        $this->assertSame('ok', $out[2]);
        $this->assertLessThanOrEqual(1, count(array_filter(array_slice($out, 0, 2), fn ($o) => $o === 'ok')), 'حُسم مرّتين');
        $this->assertSame([], array_values(array_diff(array_slice($out, 0, 2), ['ok', 'refused'])));
        $this->assertSame(1, BoutiqueSheetHold::count());
        $this->assertNotNull(BoutiqueSheetHold::sole()->closed_at);
        $this->balanced();
    }
}
