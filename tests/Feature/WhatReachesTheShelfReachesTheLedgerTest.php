<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Expense;
use App\Models\GoodsReceiptNote;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Support\GoodsReceipts;
use App\Support\Ledger;
use App\Support\StockLedger;
use App\Support\SupplierInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ما يدخل الرفَّ يدخل الدفتر — وإلّا قرأ المتجرُ مخزونًا بالسالب.
 *
 * ═══ العطبُ الذي وُلد منه هذا الملفّ ═══
 *
 * كان المخزونُ يُقيَّد مدينًا من بابٍ واحد (سند المورّد) ويُقيَّد دائنًا من
 * أربعة (البيع، والجرد، والتسوية، وتصحيح الفاتورة). فالبضاعةُ الداخلةُ من
 * إذن استلامٍ لم يصل سندُه، أو من رصيدٍ افتتاحيّ، أو من تعديلٍ يدويّ —
 * تجلس على الرفّ ولا يعرفها الدفتر، ثمّ تخرج بالبيع فتُنقصه.
 *
 * وقيس على الإنتاج: حسابُ المخزون (1400) رصيدُه **سالب**. وأصلٌ برصيدٍ
 * دائن يعني أنّ الدفترَ يقول «في المتجر بضاعةٌ بقيمةٍ سالبة».
 *
 * ═══ وما يحرسه ═══
 *
 *  • الاستلامُ يُقيَّد أصلًا (لا ذمّةً) يومَ يصل.
 *  • والسندُ ينقله إلى الذمّة **ولا يُقيّد المخزونَ مرّتين**.
 *  • والرصيدُ الافتتاحيُّ رأسُ مال لا ربح.
 *  • والتعديلُ اليدويُّ تسويةٌ كتسوية الشاشة الأخرى — لا قاعدةٌ ثانية.
 *  • والاستدراكُ يستدرك ما لم يُفوتر وحدَه، ولا يُعيد ما استدركه.
 */
class WhatReachesTheShelfReachesTheLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Branch $branch;

    private User $owner;

    private Supplier $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'زهور الخليج', 'type' => 'محل ورود', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الفرع الرئيسي']);
        Currency::create([
            'business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($this->shop->id);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->supplier = Supplier::create(['business_id' => $this->shop->id, 'name' => 'ورد الخليج']);
        $this->product = Product::create([
            'business_id' => $this->shop->id, 'name' => 'وردة حمراء',
            'sku' => 'FLW-1', 'price' => 1, 'cost' => 0, 'quantity' => 0,
        ]);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
    }

    /* ═══════════ أدوات ═══════════ */

    /** رصيدُ حسابٍ نظاميّ — مدينُه ناقصًا دائنَه */
    private function balance(string $systemKey): float
    {
        $account = Ledger::account($this->shop->id, $systemKey);

        if (! $account) {
            return 0.0;
        }

        return round(
            (float) JournalLine::where('account_id', $account->id)->sum('debit')
            - (float) JournalLine::where('account_id', $account->id)->sum('credit'),
            3,
        );
    }

    /** أمرُ شراءٍ ببندٍ واحد: خمسُ ربطاتٍ في العشرين، الربطةُ بستّة */
    private function order(int $qty = 5, float $cost = 6, bool $withProduct = true): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'business_id' => $this->shop->id, 'branch_id' => $this->branch->id,
            'number' => 'PO-'.str_pad((string) (PurchaseOrder::count() + 1), 4, '0', STR_PAD_LEFT),
            'supplier_id' => $this->supplier->id, 'supplier_name' => $this->supplier->name,
            'status' => 'مُرسل', 'total' => $qty * $cost, 'ordered_at' => now(),
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product_id' => $withProduct ? $this->product->id : null,
            'name' => 'وردة حمراء', 'cost' => $cost, 'quantity' => $qty,
            'units_per_purchase_unit' => 20,
        ]);

        return $po->fresh('items');
    }

    /** يستلم كلَّ ما في الأمر ويعتمده */
    private function receive(PurchaseOrder $po, ?int $qty = null): void
    {
        $item = $po->items()->first();
        GoodsReceipts::record($po, [$item->id => $qty ?? (int) $item->quantity], [], $this->owner);
        $this->approvePendingReceipts((int) $this->shop->id, $this->owner);
    }

    /** سندُ مورّدٍ معتمَد */
    private function invoice(?PurchaseOrder $po, float $subtotal, string $ref = 'S-1'): void
    {
        $invoice = SupplierInvoices::create($this->shop->id, [
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po?->id,
            'supplier_ref' => $ref,
            'issued_at' => now()->toDateString(),
            'subtotal' => $subtotal, 'tax' => 0,
        ], $this->owner);

        SupplierInvoices::approve($invoice, $this->owner, 'اعتمادُ اختبار');
    }

    /* ═══════════ الاستلام يصل الدفتر ═══════════ */

    /** البضاعةُ أصلٌ من يوم وصولها — لا من يوم فاتورتها */
    public function test_goods_reaching_the_shelf_reach_the_ledger(): void
    {
        $this->receive($this->order());

        $this->assertSame(100, (int) $this->product->fresh()->quantity);
        $this->assertSame(30.0, $this->balance('inventory'));
        $this->assertSame(-30.0, $this->balance('goods_received_not_invoiced'));
        $this->assertSame(0.0, $this->balance('payable'), 'نشأت ذمّةٌ بلا سند');
    }

    /**
     * وبندٌ بلا صنفٍ لا يدخل رفًّا فلا يُقيَّد.
     *
     * ورقةُ استلامٍ قد تحمل خدمةً أو صنفًا خارج الكتالوج: يُطالب بها
     * المورّد في سنده، ولا تصير مخزونًا. ولو قُيّدت لَقال الدفترُ إنّ في
     * المتجر بضاعةً ليست فيه.
     */
    public function test_a_line_without_a_product_is_not_an_asset(): void
    {
        $this->receive($this->order(withProduct: false));

        $this->assertSame(0.0, $this->balance('inventory'));
        $this->assertSame(0.0, $this->balance('goods_received_not_invoiced'));
    }

    /** وسندٌ جزئيّ يُفرغ حصّتَه وحدَها ويترك الباقي منتظرًا */
    public function test_a_partial_invoice_clears_only_its_share(): void
    {
        $po = $this->order();
        $this->receive($po);
        $this->invoice($po, 18);

        $this->assertSame(-18.0, $this->balance('payable'));
        $this->assertSame(-12.0, $this->balance('goods_received_not_invoiced'));
        $this->assertSame(30.0, $this->balance('inventory'), 'حُسبت الشحنةُ مرّتين');
    }

    /**
     * وسندٌ أكبرُ ممّا وصل: الفائضُ مخزونٌ لا خصمٌ سالب.
     *
     * سعرٌ ارتفع، أو شحنٌ يُحمَّل على البضاعة. ولو سُدِّد الخصمُ بالمبلغ
     * كلِّه لَنزل تحت الصفر — خصمٌ برصيدٍ مدين يقول إنّ للمتجر عند مورّده
     * بضاعةً لم تصل.
     */
    public function test_an_invoice_larger_than_what_arrived_puts_the_excess_in_stock(): void
    {
        $po = $this->order();
        $this->receive($po);
        $this->invoice($po, 40);

        $this->assertSame(0.0, $this->balance('goods_received_not_invoiced'));
        $this->assertSame(40.0, $this->balance('inventory'));
        $this->assertSame(-40.0, $this->balance('payable'));
    }

    /** وسندٌ بلا أمرِ شراءٍ يُقيَّد مخزونًا كما كان — لا شيءَ ينتظره */
    public function test_an_invoice_with_no_order_still_debits_stock(): void
    {
        $this->invoice(null, 25, 'S-FREE');

        $this->assertSame(25.0, $this->balance('inventory'));
        $this->assertSame(0.0, $this->balance('goods_received_not_invoiced'));
    }

    /* ═══════════ بابا شاشة المنتج ═══════════ */

    /** الرصيدُ الافتتاحيُّ رأسُ مالٍ لا ربح: بضاعةٌ يملكها صاحبُها قبل النظام */
    public function test_an_opening_quantity_is_capital_not_profit(): void
    {
        $p = Product::create([
            'business_id' => $this->shop->id, 'name' => 'وردة بيضاء',
            'sku' => 'FLW-2', 'price' => 2, 'cost' => 1.5, 'quantity' => 10,
        ]);

        $this->actingAs($this->owner);
        StockLedger::note($this->shop->id, $this->branch->id, $p, 10, StockLedger::OPENING, 'المالك');

        $this->assertSame(15.0, $this->balance('inventory'));
        $this->assertSame(-15.0, $this->balance('capital'));
        $this->assertSame(0.0, $this->balance('other_expenses'), 'قُرئ الرصيدُ الافتتاحيُّ ربحًا');
    }

    /** وزيادةٌ يدويّةٌ بعدها تسويةُ مخزونٍ لا رأسَ مال */
    public function test_a_manual_increase_is_an_adjustment(): void
    {
        $this->actingAs($this->owner);
        $this->product->update(['cost' => 2]);
        StockLedger::note($this->shop->id, $this->branch->id, $this->product->fresh(), 5, StockLedger::MANUAL, 'المالك');

        $this->assertSame(10.0, $this->balance('inventory'));
        $this->assertSame(-10.0, $this->balance('other_expenses'));
        $this->assertSame(0.0, $this->balance('capital'));
    }

    /** ونقصٌ يدويٌّ خسارةٌ — في الدفتر وفي جدول المصروفات معًا */
    public function test_a_manual_decrease_writes_a_loss_and_an_expense(): void
    {
        $this->actingAs($this->owner);
        $this->product->update(['cost' => 2, 'quantity' => 10]);
        StockLedger::note($this->shop->id, $this->branch->id, $this->product->fresh(), -3, StockLedger::MANUAL, 'المالك');

        $this->assertSame(-6.0, $this->balance('inventory'));
        $this->assertSame(6.0, $this->balance('other_expenses'));
        $this->assertSame(1, Expense::where('business_id', $this->shop->id)->count());
    }

    /**
     * وصنفٌ بلا تكلفةٍ لا قيدَ له — في البابين معًا.
     *
     * والنوعان يُفحصان لا أحدُهما: `StockLosses` تحرس صفرَها بنفسها،
     * فالتعديلُ اليدويُّ يسقط عندها ولو رُفع الحارسُ من هنا. والرصيدُ
     * الافتتاحيُّ يمضي إلى `Ledger::post` مباشرةً — وقيدٌ بصفرٍ متوازنٌ
     * فيُقبل، فيمتلئ الدفترُ بقيودٍ لا تقول شيئًا. أثبتته طفرةٌ نجت.
     */
    public function test_a_product_without_a_cost_posts_nothing(): void
    {
        $this->actingAs($this->owner);

        StockLedger::note($this->shop->id, $this->branch->id, $this->product, 7, StockLedger::MANUAL, 'المالك');
        StockLedger::note($this->shop->id, $this->branch->id, $this->product, 7, StockLedger::OPENING, 'المالك');

        $this->assertSame(0, JournalEntry::count());
    }

    /**
     * وكلُّ تعديلٍ قيدُه: مرجعُ القيد صفُّ الحركة لا الصنف.
     *
     * ولو كان الصنفَ لَبدا تعديلُ اليوم وتعديلُ أمسٍ قيدًا واحدًا في عين
     * من يبحث عن تكرار، فيُسقط أحدُهما استدراكٌ أو تدقيق.
     */
    public function test_each_manual_change_is_its_own_entry(): void
    {
        $this->actingAs($this->owner);
        $this->product->update(['cost' => 2]);

        StockLedger::note($this->shop->id, $this->branch->id, $this->product->fresh(), 5, StockLedger::MANUAL, 'المالك');
        StockLedger::note($this->shop->id, $this->branch->id, $this->product->fresh(), 5, StockLedger::MANUAL, 'المالك');

        $entries = JournalEntry::where('sourceable_type', InventoryMovement::class)->get();

        $this->assertCount(2, $entries);
        $this->assertCount(2, $entries->pluck('sourceable_id')->unique(), 'قيدان على مرجعٍ واحد');
        $this->assertSame(20.0, $this->balance('inventory'));
    }

    /* ═══════════ الاستدراك ═══════════ */

    /** يُعيد الحالَ إلى ما كان عليه قبل الإصلاح: استلامٌ معتمَدٌ بلا قيد */
    private function forget(): void
    {
        JournalEntry::where('source', GoodsReceipts::SOURCE)->each(function ($e) {
            $e->lines()->delete();
            $e->delete();
        });
    }

    /**
     * وسندٌ يصل لاستلامٍ سبق الإصلاح يُقيَّد مخزونًا — لا يُفرِغ خصمًا خاليًا.
     *
     * ═══ وهذا ما أسقط تصميمي الأوّل ═══
     *
     * كان «ما ينتظر» يُحسب من الجداول: قيمةُ ما وصل ناقصًا ما فُوتر. وهو
     * يقول «ينتظر ثلاثون» لأمرٍ استُلم قبل الإصلاح — والخصمُ خالٍ لأنّ
     * استلامَه لم يُقيَّد. فيُفرَغ ما لم يُملأ: خصمٌ يصير مدينًا، ومخزونٌ
     * يبقى ناقصًا ثلاثين.
     *
     * وهذه هي حالُ الإنتاج يومَ النشر: أوامرُ استُلمت منذ شهور، وسنداتُها
     * قد تصل غدًا. فصار القياسُ من الدفتر — انظر `GoodsReceipts::holdingBalance`.
     */
    public function test_a_late_invoice_for_an_unposted_receipt_debits_stock(): void
    {
        $po = $this->order();
        $this->receive($po);
        $this->forget();

        $this->invoice($po, 30);

        $this->assertSame(30.0, $this->balance('inventory'), 'ضاع قيدُ المخزون');
        $this->assertSame(0.0, $this->balance('goods_received_not_invoiced'), 'أُفرغ خصمٌ لم يُملأ');
        $this->assertSame(-30.0, $this->balance('payable'));
    }

    /** ما استُلم ولم يُفوتر قطّ يُستدرك بقيمته */
    public function test_the_backfill_posts_what_was_never_invoiced(): void
    {
        $this->receive($this->order());
        $this->forget();
        $this->assertSame(0.0, $this->balance('inventory'));

        $this->artisan('finance:post-missing-goods-receipts')->assertExitCode(0);

        $this->assertSame(30.0, $this->balance('inventory'));
        $this->assertSame(-30.0, $this->balance('goods_received_not_invoiced'));
    }

    /**
     * وأمرٌ استُلم قبل أن يوجد «إذنُ الاستلام» ورقةً يُستدرك كذلك.
     *
     * ═══ وهذا ما أسقط أمري الأوّل ═══
     *
     * كان يقيس من أوراق الاستلام. وأوامرُ ما قبل هجرة
     * `goods_receipt_notes` استُلمت بلا ورقة: بضاعتُها على الرفّ،
     * و`received_quantity` يشهد، ولا إشعارَ يُجمع. فقال الأمرُ على الإنتاج
     * «١٠٫٦٥٤ ر.ع» والحقيقةُ ٢٦٠٫٦٥٤ — أمرٌ واحدٌ قديمٌ يحمل ٢٥٠ منها.
     *
     * فصار القياسُ من بنود الأمر: `received_quantity` لا يتحرّك إلّا
     * باعتماد استلام، فهو يشهد لما وصل بورقةٍ ولما وصل بغيرها.
     */
    public function test_an_order_received_before_receipt_papers_existed_is_caught(): void
    {
        $po = $this->order();
        // ما يفعله الاستلامُ القديم: يرفع المستلَم ولا يترك ورقةً ولا قيدًا
        $po->items()->first()->update(['received_quantity' => 5]);

        $this->assertSame(0, GoodsReceiptNote::where('purchase_order_id', $po->id)->count());

        $this->artisan('finance:post-missing-goods-receipts')->assertExitCode(0);

        $this->assertSame(30.0, $this->balance('inventory'));
        $this->assertSame(-30.0, $this->balance('goods_received_not_invoiced'));
    }

    /** وأمرٌ فُوتر قُيّد مخزونُه بسنده — فلا يُستدرك ولا يُضاعَف */
    public function test_the_backfill_leaves_an_invoiced_order_alone(): void
    {
        $po = $this->order();
        $this->receive($po);
        $this->forget();
        $this->invoice($po, 30);

        $before = $this->balance('inventory');
        $this->artisan('finance:post-missing-goods-receipts')->assertExitCode(0);

        $this->assertSame($before, $this->balance('inventory'), 'استُدرك ما قُيّد');
        $this->assertSame(0.0, $this->balance('goods_received_not_invoiced'));
    }

    /** ولا يُستدرك مرّتين ولو نُودي كلَّ ليلة */
    public function test_the_backfill_never_posts_twice(): void
    {
        $this->receive($this->order());
        $this->forget();

        $this->artisan('finance:post-missing-goods-receipts')->assertExitCode(0);
        $this->artisan('finance:post-missing-goods-receipts')->assertExitCode(0);

        $this->assertSame(30.0, $this->balance('inventory'));
    }

    /**
     * وسندٌ يصل **بعد** الاستدراك يُفرغ الخصمَ الوسيط ولا يُقيّد مخزونًا ثانيًا.
     *
     * وهذه هي العقدةُ كلُّها: الإصلاحُ والاستدراكُ طريقان يلتقيان على
     * الصفّ نفسه. فلو حسب أحدُهما ما حسبه الآخر لَصار المخزونُ ضِعفَه —
     * وهو عطبٌ أسوأُ من الذي أُصلح.
     */
    public function test_a_late_invoice_after_the_backfill_clears_the_holding_account(): void
    {
        $po = $this->order();
        $this->receive($po);
        $this->forget();
        $this->artisan('finance:post-missing-goods-receipts')->assertExitCode(0);

        $this->invoice($po, 30);

        $this->assertSame(30.0, $this->balance('inventory'), 'حُسبت الشحنةُ مرّتين');
        $this->assertSame(0.0, $this->balance('goods_received_not_invoiced'));
        $this->assertSame(-30.0, $this->balance('payable'));
    }

    /* ═══════════ وهذا كلُّ المقصود ═══════════ */

    /** ما دخل وما خرج يتقاصّان إلى صفر — لا إلى ما تحته */
    public function test_what_comes_in_and_goes_out_never_reads_below_zero(): void
    {
        $this->receive($this->order());
        $this->assertSame(30.0, $this->balance('inventory'));

        // ‏١٠٠ حبّة بتكلفة ٦ ÷ ٢٠ = ٠٫٣ — تخرج كلُّها
        $this->actingAs($this->owner);
        StockLedger::note($this->shop->id, $this->branch->id, $this->product->fresh(), -100, StockLedger::MANUAL, 'المالك');

        $this->assertSame(0.0, $this->balance('inventory'));
    }
}
