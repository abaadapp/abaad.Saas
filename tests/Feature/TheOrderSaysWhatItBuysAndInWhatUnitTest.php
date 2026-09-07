<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\GoodsReceiptNote;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\Ledger;
use App\Support\PurchaseOrders;
use App\Support\PurchaseOrderTotals;
use App\Support\SupplierInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * أمرُ الشراء يقول ماذا يشتري وبأيّ وحدة.
 *
 * ═══ ثلاثةُ أشياء تُختبر هنا ═══
 *
 * أوّلُها أنّ الأمرَ **نيّةٌ لا حدث**: لا رصيدَ يتحرّك، ولا ذمّةَ تنشأ، ولا
 * قيدَ يُكتب. وثانيها أنّ **وحدةَ الشراء غيرُ وحدة التخزين**: خمسُ ربطاتٍ في
 * العشرين مئةُ حبّة على الرفّ — وتُترجَم عند اعتماد الاستلام وحدَه. وثالثها
 * أنّ **الإجماليَّ يُحسب في الخادم**: ما تُرسله الشاشة من أرقامٍ يُهمل.
 */
class TheOrderSaysWhatItBuysAndInWhatUnitTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Supplier $supplier;

    private Product $product;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($this->business->id);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'ورد الخليج']);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'وردة حمراء',
            'sku' => 'FLW-1', 'price' => 1, 'cost' => 0, 'quantity' => 0,
        ]);
        // والضريبةُ مطفأةٌ ما لم يقل الاختبارُ غيرَ ذلك — فالحسابُ يُقرأ صافيًا
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0']);
    }

    /** @return array<string, mixed> */
    private function payload(array $over = []): array
    {
        return $over + [
            'supplier_id' => $this->supplier->id,
            'branch_id' => $this->branch->id,
            'ordered_at' => now()->toDateString(),
            'items' => [[
                'product_id' => $this->product->id,
                'name' => $this->product->name,
                'purchase_unit' => 'ربطة',
                'units_per_purchase_unit' => 20,
                'cost' => 6,
                'quantity' => 5,
            ]],
        ];
    }

    private function create(array $over = []): PurchaseOrder
    {
        $this->actingAs($this->owner)->post(route('admin.purchases.store'), $this->payload($over))
            ->assertRedirect();

        return PurchaseOrder::latest('id')->firstOrFail();
    }

    /* ==================== الشاشة ==================== */

    public function test_the_screen_opens_with_what_it_needs(): void
    {
        $this->actingAs($this->owner)->get(route('admin.purchases.create'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->has('suppliers')->has('branches')->has('products')
                ->where('today', now()->toDateString())
                ->where('taxRate', 0)
                ->has('formToken')
                ->etc());
    }

    /** والكاشيرُ لا يفتحها: «المشتريات» ليست من أقسامه */
    public function test_an_unauthorised_user_is_kept_out(): void
    {
        $cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@abaad.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->actingAs($cashier)->get(route('admin.purchases.create'))->assertForbidden();
    }

    /* ==================== ما يُردّ ==================== */

    public function test_an_order_without_a_supplier_is_refused(): void
    {
        $body = $this->payload();
        unset($body['supplier_id']);

        $this->actingAs($this->owner)->post(route('admin.purchases.store'), $body)
            ->assertSessionHasErrors('supplier_id');
        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_an_order_without_a_branch_is_refused(): void
    {
        $body = $this->payload();
        unset($body['branch_id']);

        $this->actingAs($this->owner)->post(route('admin.purchases.store'), $body)
            ->assertSessionHasErrors('branch_id');
    }

    public function test_an_order_without_items_is_refused(): void
    {
        $this->actingAs($this->owner)->post(route('admin.purchases.store'), $this->payload(['items' => []]))
            ->assertSessionHasErrors('items');
        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_a_zero_or_negative_quantity_is_refused(): void
    {
        foreach ([0, -3] as $qty) {
            $this->actingAs($this->owner)->post(route('admin.purchases.store'), $this->payload([
                'items' => [['name' => 'ورد', 'cost' => 1, 'quantity' => $qty]],
            ]))->assertSessionHasErrors('items.0.quantity');
        }

        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_a_cost_that_is_not_a_number_is_refused(): void
    {
        $this->actingAs($this->owner)->post(route('admin.purchases.store'), $this->payload([
            'items' => [['name' => 'ورد', 'cost' => 'ثلاثة', 'quantity' => 1]],
        ]))->assertSessionHasErrors('items.0.cost');
    }

    /** والوصولُ لا يسبق الطلب: وعدٌ بالأمس لطلبٍ اليوم لا معنى له */
    public function test_delivery_cannot_precede_the_order(): void
    {
        $this->actingAs($this->owner)->post(route('admin.purchases.store'), $this->payload([
            'ordered_at' => '2026-09-10',
            'expected_delivery_at' => '2026-09-09',
        ]))->assertSessionHasErrors('expected_delivery_at');
    }

    public function test_a_discount_larger_than_the_goods_is_refused(): void
    {
        $this->actingAs($this->owner)->post(route('admin.purchases.store'), $this->payload([
            'supplier_discount' => 500,
        ]))->assertSessionHasErrors('supplier_discount');
        $this->assertSame(0, PurchaseOrder::count());
    }

    /* ==================== العزل بين المتاجر ==================== */

    /**
     * ومعرّفُ مورّدٍ أو فرعٍ من متجرٍ آخر يُردّ — لا يُقبل لأنّه رقمٌ صحيح.
     *
     * والمنتجُ يُطرح ويبقى البند باسمه: أمرُ هذا المتجر لا يرتبط بصنفٍ لا
     * يملكه، ولا يسقط الأمرُ كلُّه على سطرٍ واحد.
     */
    public function test_ids_from_another_shop_do_not_pass(): void
    {
        $other = Business::create(['name' => 'جار', 'type' => 'عام', 'status' => 'نشط']);
        $theirBranch = Branch::create(['business_id' => $other->id, 'name' => 'فرعهم']);
        $theirSupplier = Supplier::create(['business_id' => $other->id, 'name' => 'مورّدهم']);
        $theirProduct = Product::create([
            'business_id' => $other->id, 'name' => 'صنفهم', 'price' => 1, 'cost' => 1, 'quantity' => 9,
        ]);

        $this->actingAs($this->owner)->post(route('admin.purchases.store'), $this->payload([
            'supplier_id' => $theirSupplier->id,
        ]))->assertSessionHasErrors('supplier_id');

        $this->actingAs($this->owner)->post(route('admin.purchases.store'), $this->payload([
            'branch_id' => $theirBranch->id,
        ]))->assertSessionHasErrors('branch_id');

        $po = $this->create(['items' => [[
            'product_id' => $theirProduct->id, 'name' => 'وردٌ كُتب بيد', 'cost' => 2, 'quantity' => 3,
        ]]]);

        $this->assertNull($po->items()->first()->product_id, 'ارتبط الأمر بصنفٍ من متجرٍ آخر');
        $this->assertSame('وردٌ كُتب بيد', $po->items()->first()->name);
    }

    /* ==================== الحساب ==================== */

    /**
     * الإجماليُّ يُحسب في الخادم — وما تُرسله الشاشة يُهمل.
     *
     * ومن يفتح أدوات المتصفّح يستطيع إرسال إجماليٍّ صفرٍ لأمرٍ بثلاثين.
     */
    public function test_the_server_computes_the_total_and_ignores_what_is_sent(): void
    {
        $po = $this->create([
            'total' => 0, 'items_subtotal' => 0, 'tax' => 999,
            'supplier_discount' => 2, 'shipping_cost' => 3.5,
            // وإجماليُّ السطر يُزوَّر كذلك: الخادمُ يحسبه من التكلفة والكميّة
            'items' => [[
                'product_id' => $this->product->id, 'name' => $this->product->name,
                'purchase_unit' => 'ربطة', 'units_per_purchase_unit' => 20,
                'cost' => 6, 'quantity' => 5, 'line_total' => 0,
            ]],
        ]);

        // ‏٥ ربطات × ٦ = ٣٠، ناقصَ خصمٍ ٢، زائدَ شحنٍ ٣٫٥ = ٣١٫٥ — والضريبةُ مطفأة
        $this->assertSame('30.000', (string) $po->items_subtotal);
        $this->assertSame('2.000', (string) $po->supplier_discount);
        $this->assertSame('3.500', (string) $po->shipping_cost);
        $this->assertSame('0.000', (string) $po->tax);
        $this->assertSame('31.500', (string) $po->total);
        $this->assertSame('30.000', (string) $po->items()->first()->line_total);
    }

    /** والضريبةُ من إعدادات المتجر لا من الشاشة — ولا خمسةٌ مكتوبةٌ في الكود */
    public function test_tax_follows_the_shop_setting(): void
    {
        Setting::where('business_id', $this->business->id)->where('key', 'vat_enabled')->update(['value' => '1']);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '10']);

        $po = $this->create(['supplier_discount' => 2, 'shipping_cost' => 3.5]);

        // الوعاء ٣١٫٥ × ١٠٪ = ٣٫١٥٠
        $this->assertSame('10.00', (string) $po->tax_rate);
        $this->assertSame('3.150', (string) $po->tax);
        $this->assertSame('34.650', (string) $po->total);
    }

    /** ومطفأةً تبقى صفرًا مهما كانت النسبة المحفوظة */
    public function test_a_disabled_tax_is_zero(): void
    {
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);

        $po = $this->create();

        $this->assertSame('0.000', (string) $po->tax);
        $this->assertSame('30.000', (string) $po->total);
    }

    /**
     * والصيغةُ واحدةٌ في الخادم والشاشة.
     *
     * `lib/purchase-totals.ts` نسخةٌ حرفيّة من `PurchaseOrderTotals`، ولو
     * افترقتا لرأى التاجرُ رقمًا وحُفظ غيرُه ولا يشتكي أحد. فتُقرأ نسخةُ
     * الشاشة هنا حرفًا بحرف.
     */
    public function test_the_screen_formula_matches_the_server_formula(): void
    {
        $ts = file_get_contents(base_path('resources/js/lib/purchase-totals.ts'));

        $this->assertStringContainsString(
            'r3(items_subtotal - supplier_discount + shipping_cost)',
            $ts,
            'ترتيبُ الوعاء الضريبيّ في الشاشة يخالف الخادم',
        );
        $this->assertStringContainsString('r3(taxable * (Math.max(0, taxRate) / 100))', $ts);
        $this->assertStringContainsString('r3(taxable + tax)', $ts);

        // والخادمُ يقول الشيء نفسه بالأرقام
        $totals = PurchaseOrderTotals::compute(
            [['cost' => 6, 'quantity' => 5]], 2, 3.5, 10,
        );
        $this->assertSame(31.5, $totals['taxable']);
        $this->assertSame(3.15, $totals['tax']);
        $this->assertSame(34.65, $totals['total']);
    }

    /* ==================== المسودّة والإصدار ==================== */

    public function test_a_draft_is_saved_as_a_draft(): void
    {
        $this->actingAs($this->owner)->post(route('admin.purchases.store'), $this->payload(['draft' => true]))
            ->assertRedirect();

        $this->assertSame(PurchaseOrders::DRAFT, PurchaseOrder::firstOrFail()->status);
    }

    public function test_an_issued_order_is_marked_sent(): void
    {
        $this->assertSame(PurchaseOrders::SENT, $this->create()->status);
    }

    /** والرقمُ تسلسلٌ لا قرعة: `random_int` كانت تُصادِف رقمًا استُعمل */
    public function test_numbers_run_in_sequence_and_do_not_collide(): void
    {
        $first = $this->create()->number;
        $second = $this->create(['items' => [['name' => 'ب', 'cost' => 1, 'quantity' => 1]]])->number;

        $this->assertSame('PO-000001', $first);
        $this->assertSame('PO-000002', $second);
    }

    /** ونموذجٌ أُرسل مرّتين أمرٌ واحد — ضغطتان على الزرّ لا تكتبان أمرين */
    public function test_the_same_form_sent_twice_creates_one_order(): void
    {
        $body = $this->payload(['form_token' => 'abc-123']);

        $this->actingAs($this->owner)->post(route('admin.purchases.store'), $body)->assertRedirect();
        $this->actingAs($this->owner)->post(route('admin.purchases.store'), $body)->assertRedirect();

        $this->assertSame(1, PurchaseOrder::count());
    }

    /* ==================== وحدةُ الشراء ==================== */

    public function test_the_line_keeps_its_unit_and_its_pack_size(): void
    {
        $item = $this->create()->items()->first();

        $this->assertSame('ربطة', $item->purchase_unit);
        $this->assertSame('20.000', (string) $item->units_per_purchase_unit);
        $this->assertSame(5, (int) $item->quantity);
        // ‏٥ ربطات × ٢٠ = ١٠٠ حبّة على الرفّ — والكميّةُ تبقى ٥ بوحدة الشراء
        $this->assertSame(100.0, $item->base_quantity);
    }

    /** وبندٌ بلا معامل يُقرأ بواحد: كلُّ ما مضى اشتُري بوحدة التخزين */
    public function test_a_line_without_a_pack_size_defaults_to_one(): void
    {
        $item = $this->create(['items' => [['name' => 'ورد', 'cost' => 2, 'quantity' => 7]]])->items()->first();

        $this->assertSame('1.000', (string) $item->units_per_purchase_unit);
        $this->assertSame(7.0, $item->base_quantity);
    }

    /* ==================== ولا شيءَ يتحرّك ==================== */

    /**
     * إنشاءُ الأمر لا يزيد رصيدًا ولا يكتب قيدًا ولا يُنشئ ذمّة.
     *
     * وهذا هو الحارسُ الذي يمنع أن يصير الأمرُ حدثًا ماليًّا: مئةُ حبّةٍ
     * تدخل الرفَّ بنيّةِ شراءٍ لم يصل منها شيء تُفسد الجرد والتسعير معًا.
     */
    public function test_creating_an_order_moves_neither_stock_nor_ledger(): void
    {
        foreach ([true, false] as $draft) {
            InventoryMovement::query()->delete();
            JournalEntry::query()->delete();
            PurchaseOrder::query()->delete();

            $this->create(['draft' => $draft, 'form_token' => 'tok-'.(int) $draft]);

            $this->assertSame(0, (int) $this->product->fresh()->quantity, 'تحرّك المخزون بأمرِ شراء');
            $this->assertSame(0, InventoryMovement::count());
            $this->assertSame(0, JournalEntry::count(), 'كُتب قيدٌ بأمرِ شراء');
            $this->assertSame(0, SupplierInvoice::count());
        }
    }

    /* ==================== إلى الرفّ عبر الاعتماد ==================== */

    /**
     * ثلاثُ ربطاتٍ تُعتمد فتدخل ستّون حبّة — لا ثلاث.
     *
     * والتكلفةُ تُقسَّم معها: ستّةٌ للربطة تصير ثلاثَ مئةٍ للحبّة. ولولا
     * القسمة لدخل الرفُّ ستّين حبّةً بستّةٍ للحبّة، فيرتفع متوسّطُ التكلفة
     * عشرين ضعفًا ويُفسد تسعيرَ كلّ بيعةٍ بعده.
     */
    public function test_approving_three_bundles_shelves_sixty_flowers(): void
    {
        $po = $this->create();
        $line = $po->items()->first();

        $this->actingAs($this->owner)->post(route('admin.purchases.receive', $po->id), [
            'items' => [['id' => $line->id, 'quantity' => 3]],
        ])->assertSessionHasNoErrors();

        // ‏والمعلَّقُ لا يُدخل شيئًا
        $this->assertSame(0, (int) $this->product->fresh()->quantity);

        $this->approvePendingReceipts($this->business->id, $this->owner);

        $this->assertSame(60, (int) $this->product->fresh()->quantity);
        $this->assertSame('0.300', (string) $this->product->fresh()->cost);
        // والمتبقّي بوحدة الشراء: ربطتان
        $this->assertSame(2, $line->fresh()->remaining);
    }

    /** والمرفوضُ يُدخل صفرًا */
    public function test_a_rejected_receipt_shelves_nothing(): void
    {
        $po = $this->create();
        $line = $po->items()->first();

        $this->actingAs($this->owner)->post(route('admin.purchases.receive', $po->id), [
            'items' => [['id' => $line->id, 'quantity' => 3]],
        ])->assertSessionHasNoErrors();

        $note = GoodsReceiptNote::firstOrFail();
        $this->actingAs($this->owner)
            ->post(route('admin.purchases.receipts.reject', $note->id), ['reason' => 'ناقصة'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, (int) $this->product->fresh()->quantity);
    }

    /** والاستلامُ على دفعاتٍ يبلغ المئةَ بالضبط ولا يتجاوزها */
    public function test_partial_receipts_reach_exactly_one_hundred(): void
    {
        $po = $this->create();
        $line = $po->items()->first();

        foreach ([2, 2, 1] as $bundles) {
            $this->actingAs($this->owner)->post(route('admin.purchases.receive', $po->id), [
                'items' => [['id' => $line->id, 'quantity' => $bundles]],
            ])->assertSessionHasNoErrors();
            $this->approvePendingReceipts($this->business->id, $this->owner);
        }

        $this->assertSame(100, (int) $this->product->fresh()->quantity);
        $this->assertSame(0, $line->fresh()->remaining);
        $this->assertSame(PurchaseOrders::RECEIVED, $po->fresh()->status);

        // ولا سادسةَ بعد الخامسة
        $this->actingAs($this->owner)->post(route('admin.purchases.receive', $po->id), [
            'items' => [['id' => $line->id, 'quantity' => 1]],
        ]);
        $this->assertSame(100, (int) $this->product->fresh()->quantity);
    }

    /**
     * وتبديلُ وحدة الشراء بعدُ لا يُعيد كتابة أمرٍ مضى.
     *
     * اللقطةُ على البند لا قراءةٌ من المنتج: من غيّر تعبئة صنفه لا يُعيد
     * حساب ما دخل الرفَّ من أوامرَ سابقة.
     */
    public function test_changing_the_pack_later_does_not_rewrite_a_past_order(): void
    {
        $po = $this->create();
        $line = $po->items()->first();

        // أمرٌ ثانٍ بتعبئةٍ مختلفة — والأوّلُ لا يتأثّر
        $this->create(['form_token' => 't2', 'items' => [[
            'product_id' => $this->product->id, 'name' => $this->product->name,
            'purchase_unit' => 'صندوق', 'units_per_purchase_unit' => 100, 'cost' => 30, 'quantity' => 1,
        ]]]);

        $this->assertSame('20.000', (string) $line->fresh()->units_per_purchase_unit);

        $this->actingAs($this->owner)->post(route('admin.purchases.receive', $po->id), [
            'items' => [['id' => $line->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();
        $this->approvePendingReceipts($this->business->id, $this->owner);

        $this->assertSame(20, (int) $this->product->fresh()->quantity, 'قُرئت التعبئة الجديدة على أمرٍ قديم');
    }

    /* ==================== المرفق ==================== */

    /** ومرفقُ الأمر على القرص الخاصّ ويُقرأ ببابٍ يسأل */
    public function test_the_order_attachment_is_private(): void
    {
        $this->actingAs($this->owner)->post(route('admin.purchases.store'), $this->payload([
            'attachment' => UploadedFile::fake()->create('عرض سعر.pdf', 30, 'application/pdf'),
        ]))->assertRedirect();

        $po = PurchaseOrder::firstOrFail();

        $this->assertStringStartsWith('purchase-orders/', $po->attachment);
        Storage::disk('local')->assertExists($po->attachment);
        Storage::disk('public')->assertMissing($po->attachment);
        $this->assertSame('عرض سعر.pdf', $po->attachment_name);

        $this->actingAs($this->owner)->get(route('admin.purchases.attachment', $po->id))->assertOk();
    }

    /** ومرفقُ الجار لا يُفتح برقمٍ يُكتب في العنوان */
    public function test_a_neighbour_cannot_read_the_attachment(): void
    {
        $other = Business::create(['name' => 'جار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = PurchaseOrder::create([
            'business_id' => $other->id, 'number' => 'PO-000999', 'status' => 'مُرسل',
            'total' => 1, 'ordered_at' => now(),
            'attachment' => 'purchase-orders/'.$other->id.'/x.pdf', 'attachment_name' => 'سرّ.pdf',
        ]);
        Storage::disk('local')->put($theirs->attachment, 'محتوى');

        $this->actingAs($this->owner)
            ->get(route('admin.purchases.attachment', $theirs->id))->assertNotFound();
    }

    /**
     * ولا يُقبل إثباتُ دفعٍ عند إنشاء أمر شراء — الدفعُ آخرُ الدورة لا أوّلُها.
     *
     * وهي دورةٌ ستُّ خطوات: أمرٌ ← استلامٌ ← اعتمادُه ← سندُ مورّدٍ ← اعتمادُه
     * ← سدادٌ ← إثباتُه. وطلبُ الإثبات في أوّلها يجعل التاجر يدفع قبل أن يصل
     * شيء، ويجعل الورقةَ التي تُثبت الدفع معلّقةً بنيّةِ شراءٍ قد لا تتمّ.
     *
     * والحارسُ سلوكيٌّ لا قراءةَ مصدر: ملفٌّ يُرسل في `receipt` يُتجاهَل.
     */
    public function test_payment_proof_is_no_longer_taken_at_order_time(): void
    {
        $this->actingAs($this->owner)->post(route('admin.purchases.store'), $this->payload([
            'receipt' => UploadedFile::fake()->create('إيصال.pdf', 20, 'application/pdf'),
        ]))->assertRedirect();

        $po = PurchaseOrder::firstOrFail();

        $this->assertNull($po->receipt, 'أمرُ الشراء ما زال يقبل إثبات الدفع');
        $this->assertNull($po->receipt_name);
        $this->assertSame([], Storage::disk('local')->files('purchase-receipts/'.$this->business->id));

        // والبابُ يبقى لما رُفع قبلُ: أوامرُ مضت تحمل إيصالاتِها فتُقرأ
        $this->assertTrue(Route::has('admin.purchases.receiptFile'));
    }

    /* ==================== المطابقة الثلاثيّة ==================== */

    /**
     * والمطابقةُ تقابل الإجماليَّ التجاريَّ الكامل — وهذا يُصلح عطبًا قائمًا.
     *
     * `SupplierInvoices::match` تقابل `invoice.total` (وهو مجموعٌ + ضريبة)
     * بـ`purchase_orders.total`. وكان الأخيرُ مجموعَ البنود بلا ضريبة، فمتجرٌ
     * ضريبتُه مفعّلة كان **كلُّ سندٍ فيه يُمنع** بحجّة «أعلى من أمر الشراء»
     * — بمقدار الضريبة بالضبط. الحقلان يقولان شيئين ويُقابَلان كأنّهما واحد.
     *
     * فصار الأمرُ يحمل ضريبتَه، فيتطابق الرقمان.
     */
    public function test_matching_reads_the_canonical_order_total(): void
    {
        Setting::where('business_id', $this->business->id)->where('key', 'vat_enabled')->update(['value' => '1']);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);

        $po = $this->create(['items' => [[
            'product_id' => $this->product->id, 'name' => $this->product->name,
            'purchase_unit' => 'ربطة', 'units_per_purchase_unit' => 20, 'cost' => 6, 'quantity' => 5,
        ]]]);

        // ‏٣٠ + ضريبة ٥٪ = ٣١٫٥٠٠
        $this->assertSame('31.500', (string) $po->total);

        $line = $po->items()->first();
        $this->actingAs($this->owner)->post(route('admin.purchases.receive', $po->id), [
            'items' => [['id' => $line->id, 'quantity' => 5]],
        ])->assertSessionHasNoErrors();
        $this->approvePendingReceipts($this->business->id, $this->owner);

        $invoice = SupplierInvoices::create($this->business->id, [
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'supplier_ref' => 'S-1',
            'issued_at' => now()->toDateString(),
            'subtotal' => 30, 'tax' => 1.5,
        ], $this->owner);

        $this->assertSame(SupplierInvoices::MATCHED, $invoice->match_status);
    }

    /**
     * وقيمةُ ما وصل تبقى بوحدة الشراء في الطرفين.
     *
     * `receivedValue` تجمع (كميّة × تكلفة) من إشعارات الاستلام المعتمَدة —
     * وكلاهما بوحدة الشراء كما في الأمر. فلو حُوّلت الكميّةُ هناك أيضًا
     * لصارت القيمةُ عشرين ضعفَ ما اشتُري.
     */
    public function test_received_value_stays_in_purchase_units(): void
    {
        $po = $this->create();
        $line = $po->items()->first();

        $this->actingAs($this->owner)->post(route('admin.purchases.receive', $po->id), [
            'items' => [['id' => $line->id, 'quantity' => 3]],
        ])->assertSessionHasNoErrors();
        $this->approvePendingReceipts($this->business->id, $this->owner);

        // ‏٣ ربطات × ٦ = ١٨ — لا ٦٠ حبّةً × ٦
        $this->assertSame(18.0, SupplierInvoices::receivedValue($po->id));
    }

    /** ولا ذمّةَ حتى يُعتمد السند — والأمرُ وحدَه لا يُنشئ شيئًا في الدفتر */
    public function test_liability_still_waits_for_the_invoice_signature(): void
    {
        $po = $this->create();
        $line = $po->items()->first();

        $this->actingAs($this->owner)->post(route('admin.purchases.receive', $po->id), [
            'items' => [['id' => $line->id, 'quantity' => 5]],
        ])->assertSessionHasNoErrors();
        $this->approvePendingReceipts($this->business->id, $this->owner);

        // بضاعةٌ على الرفّ، ولا قيدَ بعد
        $this->assertSame(100, (int) $this->product->fresh()->quantity);
        $this->assertSame(0, JournalEntry::count());

        $invoice = SupplierInvoices::create($this->business->id, [
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'supplier_ref' => 'S-2',
            'issued_at' => now()->toDateString(),
            'subtotal' => 30, 'tax' => 0,
        ], $this->owner);

        $this->assertSame(0, JournalEntry::count(), 'نشأت ذمّةٌ بسندٍ لم يُعتمد');

        SupplierInvoices::approve($invoice, $this->owner);

        $this->assertGreaterThan(0, JournalEntry::count());
    }
}
