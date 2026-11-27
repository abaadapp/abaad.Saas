<?php

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\BoutiqueInvoiceLine;
use App\Models\BoutiqueSettlement;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Product;
use App\Models\Setting;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * هجرةُ مستحقّ فواتير العملاء إضافةٌ وحدها: ما كُتب قبلها يبقى حرفًا بحرف، ولا صفَّ يُكتب له —
 * ولا يُتراجع عنها وفيها مستحقّ.
 */
class TheInvoiceDuesMigrationTouchesNothingOldTest extends TestCase
{
    use RefreshDatabase;

    private static ?Migration $migration = null;

    private static ?Migration $holds = null;
    private static ?Migration $guard = null;

    private Business $shop;

    private Boutique $lama;

    private Customer $company;

    private Product $silver;

    private function migration(): Migration
    {
        return self::$migration ??= require database_path('migrations/2026_11_29_090000_a_customer_invoice_line_owes_its_boutique.php');
    }

    /** هجرةُ التعليق فوقها وتشير إلى جدولها: يُتراجع عنها أوّلًا وتُعاد بعدها — كما يفعل `migrate:rollback` */
    private function undo(): void
    {
        $this->guard()->down();
        $this->holds()->down();
        $this->migration()->down();
    }

    private function redo(): void
    {
        $this->migration()->up();
        $this->holds()->up();
        $this->guard()->up();
    }

    /** وفوقهما حمايةُ السداد: قادحُها يقرأ جدولَ هذه الهجرة */
    private function guard(): Migration
    {
        return self::$guard ??= require database_path('migrations/2026_12_02_090000_a_sheet_with_invoice_money_is_paid_only_by_code_that_read_its_holds.php');
    }

    private function holds(): Migration
    {
        return self::$holds ??= require database_path('migrations/2026_11_29_100000_a_sheet_is_paid_without_what_was_not_collected.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Business::create(['name' => 'متجر الهجرة', 'type' => 'محل ورد', 'status' => 'نشط', 'boutiques_enabled' => true]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $this->lama = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك', 'commission_rate' => 20, 'active' => true]);
        $this->company = Customer::create(['language' => 'ar', 'business_id' => $this->shop->id, 'name' => 'شركة', 'allow_credit_sales' => true]);
        $this->silver = Product::create(['business_id' => $this->shop->id, 'name' => 'قطعة', 'price' => 6, 'cost' => 1, 'quantity' => 9, 'alert_qty' => 0, 'active' => true, 'boutique_id' => $this->lama->id]);
    }

    private function invoice(): CustomerInvoice
    {
        return CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $this->company, [], [['product_id' => $this->silver->id, 'description' => 'قطعة', 'quantity' => 2, 'unit_price' => 6]]));
    }

    private function standing(): array
    {
        return [
            Schema::hasTable('boutique_invoice_lines'), Schema::hasTable('boutique_invoice_returns'),
            Schema::hasColumn('customer_credit_notes', 'items'), Schema::hasColumn('customer_credit_notes', 'basis'),
            Schema::hasColumn('boutique_returns', 'boutique_invoice_line_id'), Schema::hasColumn('boutique_settlements', 'accrued_at_sale'),
        ];
    }

    /** بصمةُ كلّ جدولٍ له مالٌ قبل الهجرة — بأعمدته قبلها: ما أُضيف إليه يُسأل عنه وحده */
    private function money(): array
    {
        return collect(['boutiques', 'boutique_settlements', 'boutique_component_dues', 'customer_invoices', 'customer_invoice_items', 'customer_payments', 'customer_payment_allocations', 'orders', 'order_items', 'expenses', 'journal_entries', 'journal_lines', 'products'])
            ->mapWithKeys(fn ($t) => [$t => hash('sha256', json_encode(DB::table($t)->orderBy('id')->get()
                ->map(fn ($r) => array_diff_key((array) $r, ['accrued_at_sale' => 1]))))])->all();
    }

    /** مرتجعٌ قديمٌ بكمّيّته الصحيحة وطلبه — يبقى بهما بعد الهجرة */
    private function oldReturn(): int
    {
        $sheet = BoutiqueSettlement::create([
            'business_id' => $this->shop->id, 'boutique_id' => $this->lama->id, 'number' => 'BQ-T-1', 'period' => '2027-02',
            'covered_from' => '2027-02-01 00:00:00', 'covered_to' => '2027-02-28 00:00:00', 'gross' => 100, 'commission' => 20,
            'net' => 80, 'lines_count' => 1, 'kind' => BoutiqueSettlement::SETTLEMENT, 'issued_at' => now(),
        ]);

        return DB::table('boutique_returns')->insertGetId([
            'business_id' => $this->shop->id, 'boutique_id' => $this->lama->id, 'boutique_settlement_id' => $sheet->id,
            'order_id' => 77, 'source' => 'cancel', 'name' => 'عطر', 'rate' => 20, 'unit_price' => 50, 'quantity' => 2, // رقمٌ مقصود: لا مفتاحَ أجنبيّ على الطلب
            'gross' => 100, 'amount' => 80, 'returned_at' => now(), 'condition' => 'sellable', 'status' => 'claim',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_it_adds_and_changes_nothing_that_was_written_before_it(): void
    {
        $note = $this->oldReturn();
        $invoice = $this->invoice();
        CustomerPayments::record($this->shop->id, $this->company, 5, ['method' => 'نقدي'], [$invoice->id => 5]);
        DB::table('boutique_invoice_lines')->delete();

        $this->undo();
        $this->assertSame([false, false, false, false, false, false], $this->standing());
        $before = $this->money();
        $old = DB::table('boutique_returns')->find($note);
        $this->assertSame([77, 2], [(int) $old->order_id, (int) $old->quantity]);

        $this->redo();
        $this->assertSame([true, true, true, true, true, true], $this->standing());
        $this->assertSame($before, $this->money(), 'الهجرةُ غيّرت ما كُتب قبلها');
        $this->assertSame(0, DB::table('boutique_invoice_lines')->count(), 'كُتب مستحقٌّ لفاتورةٍ صدرت قبلها');
        $new = DB::table('boutique_returns')->find($note);
        $this->assertEquals([77, 2, null], [(int) $new->order_id, (float) $new->quantity, $new->boutique_invoice_line_id]);

        // وما بقي من الفاتورة القديمة يُحصَّل ولا يُكتب لها شيء
        CustomerPayments::record($this->shop->id, $this->company, 7, ['method' => 'نقدي'], [$invoice->id => 7]);
        $this->assertSame(0, BoutiqueInvoiceLine::count());
        // والجديدةُ تحفظ
        $this->invoice();
        $this->assertSame(1, BoutiqueInvoiceLine::count());
    }

    public function test_it_is_not_undone_over_a_due(): void
    {
        $this->invoice();
        try {
            $this->migration()->down();
            $this->fail('تُراجع عن الهجرة وفيها مستحقٌّ لبوتيك');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('boutique_invoice_lines: 1', $e->getMessage());
            $this->assertStringContainsString('ALLOW_MIGRATIONS', $e->getMessage());
        }
        $this->assertSame([true, true, true, true, true, true], $this->standing(), 'حُذف شيءٌ قبل الرفض');
    }

    /** وطلبُ إشعارات الدائن (#195) يضيف العمودين نفسَيهما: أيُّهما سبق لا يسقط الآخر */
    public function test_it_steps_over_the_two_note_columns_when_the_corrections_branch_added_them_first(): void
    {
        DB::table('boutique_invoice_lines')->delete();
        $this->undo();
        Schema::table('customer_credit_notes', function ($table) {
            $table->json('items')->nullable();
            $table->string('basis', 20)->nullable();
        });
        $this->redo();
        $this->assertSame([true, true, true, true, true, true], $this->standing());
    }
}
