<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Category;
use App\Models\Currency;
use App\Models\ImportBatch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * الثلاثة التي تجعل الاستيراد قابلًا للإصلاح بدل أن يكون نهائيًّا:
 * الإسناد اليدوي، وسؤالا ما ليس في الملف، والتراجع.
 *
 * كلّها تعالج خطأً **صامتًا**: عمودٌ يُسنَد خطأً، أو سعرٌ شامل الضريبة يدخل
 * صافيًا، أو كميةٌ تصل فرعًا غير المقصود — ثلاثتها لا تُكتشف إلا في تقرير
 * الأرباح بعد شهر، وقد بِيعت ألف فاتورة بهامش خاطئ.
 */
class ProductImportSafetyTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $muscat;

    private Branch $salalah;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->muscat = Branch::create(['business_id' => $this->business->id, 'name' => 'مسقط']);
        $this->salalah = Branch::create(['business_id' => $this->business->id, 'name' => 'صلالة']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function csv(string $body): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp') . '.csv';
        file_put_contents($path, "\xEF\xBB\xBF" . $body);

        return new UploadedFile($path, 'products.csv', 'text/csv', null, true);
    }

    private function upload(string $csv, array $extra = []): void
    {
        $this->actingAs($this->owner)->post(route('admin.products.import.upload'), array_merge([
            'file' => $this->csv($csv),
            'branch_id' => $this->muscat->id,
        ], $extra))->assertRedirect(route('admin.products.import.preview'));
    }

    private function previewProps(): array
    {
        return $this->actingAs($this->owner)->get(route('admin.products.import.preview'))
            ->assertOk()->viewData('page')['props'];
    }

    private function product(string $name): Product
    {
        return Product::where('business_id', $this->business->id)->where('name', $name)->firstOrFail();
    }

    /* ==================== 1. الإسناد اليدوي للأعمدة ==================== */

    public function test_the_preview_offers_every_file_column_for_mapping(): void
    {
        $this->upload("الاسم,السعر,الكمية\nباقة ورد,10,5\n");

        $props = $this->previewProps();

        $this->assertCount(3, $props['fileColumns']);
        $this->assertSame('الاسم', $props['fileColumns'][0]['label']);
        // عيّنة من أول صفّ حتى يُعرف ما في العمود لا اسمه فقط
        $this->assertSame('باقة ورد', $props['fileColumns'][0]['sample']);
        $this->assertNotEmpty($props['fields']);
    }

    public function test_an_unrecognised_header_can_be_declared_by_the_merchant(): void
    {
        // عناوين لا يعرفها الكاشف: يُقرأ الصفّ الأول بيانات، فيدخل صفٌّ زائف
        $this->upload("عمود أول,عمود ثانٍ\nباقة ورد,10\n");

        $this->assertFalse($this->previewProps()['hasHeader']);
        $this->assertSame(2, $this->previewProps()['counts']['total'], 'لم يُقرأ الصفّ الأول بيانات');

        $this->actingAs($this->owner)->post(route('admin.products.import.remap'), [
            'mapping' => ['name' => 0, 'price' => 1],
            'has_header' => 1,
        ]);

        $props = $this->previewProps();
        $this->assertSame(1, $props['counts']['total'], 'بقي صفّ العناوين منتجًا');
        $this->assertSame('عمود أول', $props['fileColumns'][0]['label']);

        $this->actingAs($this->owner)->post(route('admin.products.import.confirm'));
        $this->assertSame(1, Product::count());
        $this->assertSame('باقة ورد', Product::first()->name);
    }

    public function test_a_remap_that_omits_the_header_flag_leaves_it_alone(): void
    {
        $this->upload("الاسم,السعر\nباقة ورد,10\n");

        $this->actingAs($this->owner)->post(route('admin.products.import.remap'), [
            'mapping' => ['name' => 0, 'price' => 1],
        ]);

        $this->assertTrue($this->previewProps()['hasHeader'], 'انقلبت الترويسة بلا أن يطلب أحد');
        $this->assertSame(1, $this->previewProps()['counts']['total']);
    }

    public function test_the_merchant_can_correct_a_wrong_guess(): void
    {
        // «سعر الجملة» و«سعر التجزئة»: الكاشف يلتقط الأول ويخطئ المقصود
        $this->upload("الصنف,سعر الجملة,سعر التجزئة\nباقة ورد,6,10\n");

        $before = $this->previewProps();
        $this->assertSame(6.0, $before['rows'][0]['price'], 'تغيّر سلوك الكاشف — راجع الاختبار');

        $this->actingAs($this->owner)->post(route('admin.products.import.remap'), [
            'mapping' => ['name' => 0, 'price' => 2],
        ])->assertRedirect(route('admin.products.import.preview'));

        $this->assertSame(10.0, $this->previewProps()['rows'][0]['price'], 'لم يُعتمد الإسناد اليدوي');

        $this->actingAs($this->owner)->post(route('admin.products.import.confirm'));
        $this->assertSame(10.0, (float) $this->product('باقة ورد')->price, 'حُفظ غير ما عُرض');
    }

    public function test_unmapping_the_price_column_invalidates_the_rows_instead_of_saving_zero(): void
    {
        $this->upload("الاسم,السعر\nباقة ورد,10\n");

        $this->actingAs($this->owner)->post(route('admin.products.import.remap'), [
            'mapping' => ['name' => 0, 'price' => ''],
        ]);

        $props = $this->previewProps();
        $this->assertSame('invalid', $props['rows'][0]['status'], 'صفٌّ بلا سعر مُسنَد اعتُبر صالحًا');

        $this->actingAs($this->owner)->post(route('admin.products.import.confirm'));
        $this->assertSame(0, Product::count(), 'حُفظ منتج بسعر صفر');
    }

    public function test_a_column_index_outside_the_file_is_ignored_not_trusted(): void
    {
        $this->upload("الاسم,السعر\nباقة ورد,10\n");

        $this->actingAs($this->owner)->post(route('admin.products.import.remap'), [
            'mapping' => ['name' => 0, 'price' => 1, 'sku' => 99],
        ]);

        $this->assertNull($this->previewProps()['mapping']['sku']);
    }

    /* ==================== 2. سؤالا ما ليس في الملف ==================== */

    public function test_tax_inclusive_prices_are_stored_net(): void
    {
        $this->upload("الاسم,السعر\nباقة ورد,10.500\n", ['prices_include_tax' => 1]);

        $props = $this->previewProps();
        // 10.500 ÷ 1.05 = 10.000
        $this->assertSame(10.0, $props['rows'][0]['price']);
        $this->assertSame(10.5, $props['rows'][0]['grossPrice'], 'ضاع السعر الخام فلا يرى التاجر أن الضريبة خُصمت');

        $this->actingAs($this->owner)->post(route('admin.products.import.confirm'));
        $this->assertSame(10.0, (float) $this->product('باقة ورد')->price);
    }

    public function test_the_row_tax_rate_beats_the_business_default(): void
    {
        $this->upload("الاسم,السعر,الضريبة\nباقة ورد,11.000,10\n", ['prices_include_tax' => 1]);

        // 11 ÷ 1.10 = 10.000 — لا ÷1.05
        $this->assertSame(10.0, $this->previewProps()['rows'][0]['price']);
    }

    public function test_net_prices_are_left_alone(): void
    {
        $this->upload("الاسم,السعر\nباقة ورد,10.500\n");

        $this->assertSame(10.5, $this->previewProps()['rows'][0]['price']);
    }

    public function test_a_column_per_branch_splits_the_quantity(): void
    {
        $this->upload("الاسم,السعر,مسقط,صلالة\nباقة ورد,10,30,20\n", ['branch_mode' => 'columns']);

        $props = $this->previewProps();
        $this->assertSame(50, $props['rows'][0]['quantity'], 'لم تُجمع أعمدة الفروع');
        $this->assertCount(2, $props['branchColumns']);

        $this->actingAs($this->owner)->post(route('admin.products.import.confirm'));

        $product = $this->product('باقة ورد');
        $this->assertSame(30, (int) BranchStock::where('branch_id', $this->muscat->id)
            ->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(20, (int) BranchStock::where('branch_id', $this->salalah->id)
            ->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(
            50,
            (int) BranchStock::where('product_id', $product->id)->sum('quantity'),
            'مجموع الفروع لا يساوي كمية المنتج',
        );
    }

    public function test_branch_mode_with_no_matching_column_is_reported_not_guessed(): void
    {
        $this->upload("الاسم,السعر,مخزن أ\nباقة ورد,10,30\n", ['branch_mode' => 'columns']);

        $props = $this->previewProps();

        $this->assertEmpty($props['branchColumns'], 'طُوبق عمود لا يحمل اسم فرع');
        $this->assertSame(0, $props['rows'][0]['quantity'], 'خُمّنت كمية من عمود لم يُطابَق');
    }

    public function test_switching_the_answers_from_the_preview_re_reads_the_file(): void
    {
        $this->upload("الاسم,السعر\nباقة ورد,10.500\n");
        $this->assertSame(10.5, $this->previewProps()['rows'][0]['price']);

        $this->actingAs($this->owner)->post(route('admin.products.import.remap'), [
            'mapping' => ['name' => 0, 'price' => 1],
            'prices_include_tax' => 1,
        ]);

        $this->assertSame(10.0, $this->previewProps()['rows'][0]['price']);
    }

    /* ========================= 3. التراجع ========================= */

    public function test_undo_removes_what_the_import_added(): void
    {
        $this->upload("الاسم,القسم,السعر,الكمية\nباقة ورد,ورود,10,12\n");
        $this->actingAs($this->owner)->post(route('admin.products.import.confirm'));
        $this->assertSame(1, Product::count());

        $this->actingAs($this->owner)->post(route('admin.products.import.undo'))
            ->assertRedirect(route('admin.products.index'));

        $this->assertSame(0, Product::count(), 'بقي المنتج بعد التراجع');
        $this->assertSame(0, BranchStock::count(), 'بقي رصيد فرعٍ لمنتج محذوف');
        $this->assertSame(0, Category::count(), 'بقي قسمٌ أنشأه الاستيراد وحده');
    }

    public function test_undo_restores_an_updated_product_exactly(): void
    {
        $product = Product::create([
            'business_id' => $this->business->id, 'name' => 'اسم قديم', 'sku' => 'SKU-1',
            'price' => 7, 'cost' => 3, 'quantity' => 20, 'alert_qty' => 4, 'active' => true,
        ]);
        BranchStock::create([
            'business_id' => $this->business->id, 'branch_id' => $this->muscat->id,
            'product_id' => $product->id, 'quantity' => 20,
        ]);

        $this->upload("الاسم,SKU,السعر,التكلفة,الكمية\nاسم جديد,SKU-1,99,50,80\n");
        $this->actingAs($this->owner)->post(route('admin.products.import.confirm'));
        $this->assertSame(99.0, (float) $product->fresh()->price);

        $this->actingAs($this->owner)->post(route('admin.products.import.undo'));

        $back = $product->fresh();
        $this->assertSame('اسم قديم', $back->name);
        $this->assertSame(7.0, (float) $back->price);
        $this->assertSame(3.0, (float) $back->cost);
        $this->assertSame(20, (int) $back->quantity);
        $this->assertSame(20, (int) BranchStock::where('branch_id', $this->muscat->id)
            ->where('product_id', $product->id)->value('quantity'), 'لم يُعكس أثر الفرع');
    }

    public function test_undo_keeps_a_product_that_has_already_been_sold(): void
    {
        $this->upload("الاسم,السعر,الكمية\nباقة ورد,10,12\n");
        $this->actingAs($this->owner)->post(route('admin.products.import.confirm'));

        $product = $this->product('باقة ورد');
        $order = Order::create([
            'business_id' => $this->business->id, 'number' => 'INV-1', 'status' => 'مكتمل',
            'payment_method' => 'نقدي', 'subtotal' => 10, 'tax' => 0.5, 'total' => 10.5,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'name' => $product->name, 'price' => 10, 'quantity' => 1, 'total' => 10,
        ]);

        $this->actingAs($this->owner)->post(route('admin.products.import.undo'));

        $this->assertSame(1, Product::count(), 'حُذف منتج بِيع — فقدت الفاتورة صنفها');
        $this->assertSame(1, OrderItem::count());
    }

    public function test_a_category_that_gained_other_products_survives_the_undo(): void
    {
        $this->upload("الاسم,القسم,السعر\nباقة ورد,ورود,10\n");
        $this->actingAs($this->owner)->post(route('admin.products.import.confirm'));

        // منتج أُضيف يدويًا إلى القسم بعد الاستيراد
        Product::create([
            'business_id' => $this->business->id, 'name' => 'ورد آخر',
            'category_id' => Category::first()->id,
            'price' => 5, 'cost' => 2, 'quantity' => 1, 'alert_qty' => 1, 'active' => true,
        ]);

        $this->actingAs($this->owner)->post(route('admin.products.import.undo'));

        $this->assertSame(1, Category::count(), 'حُذف قسمٌ ما زال يحوي منتجًا');
    }

    public function test_undo_can_only_be_used_once(): void
    {
        $this->upload("الاسم,السعر\nباقة ورد,10\n");
        $this->actingAs($this->owner)->post(route('admin.products.import.confirm'));

        $this->actingAs($this->owner)->post(route('admin.products.import.undo'));
        $this->actingAs($this->owner)->post(route('admin.products.import.undo'));

        $this->assertSame(0, Product::count());
        $this->assertNotNull(ImportBatch::first()->undone_at);
    }

    public function test_undo_without_a_previous_import_changes_nothing(): void
    {
        $this->actingAs($this->owner)->post(route('admin.products.import.undo'));

        $this->assertSame(0, Product::count());
    }

    public function test_undo_never_reaches_another_business(): void
    {
        $other = Business::create(['name' => 'جارنا', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Product::create([
            'business_id' => $other->id, 'name' => 'صنف الجار',
            'price' => 99, 'cost' => 1, 'quantity' => 5, 'alert_qty' => 1, 'active' => true,
        ]);
        // دفعةُ الجار تدّعي ملكية منتجه — ولا يجوز أن يمسّها تاجرنا
        ImportBatch::create([
            'business_id' => $other->id, 'type' => 'products', 'file' => 'x.csv',
            'added' => 1, 'updated' => 0,
            'payload' => ['created' => [$theirs->id], 'created_categories' => [], 'updated' => [], 'created_branch' => []],
        ]);

        $this->actingAs($this->owner)->post(route('admin.products.import.undo'));

        $this->assertNotNull($theirs->fresh(), 'تراجعَ تاجرٌ عن استيراد جاره');
    }

    public function test_the_products_page_only_offers_undo_when_there_is_one(): void
    {
        $props = $this->actingAs($this->owner)->get(route('admin.products.index'))
            ->assertOk()->viewData('page')['props'];
        $this->assertNull($props['lastImport']);

        $this->upload("الاسم,السعر\nباقة ورد,10\n");
        $this->actingAs($this->owner)->post(route('admin.products.import.confirm'));

        $props = $this->actingAs($this->owner)->get(route('admin.products.index'))
            ->assertOk()->viewData('page')['props'];
        $this->assertSame(1, $props['lastImport']['added']);
    }

    /* ========================= لا اقتطاع صامت ========================= */

    public function test_extra_sheets_are_declared_not_silently_dropped(): void
    {
        // ملف بورقتين: تُقرأ الأولى، ويُقال ذلك
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->getActiveSheet()->setTitle('المنتجات')->fromArray([['الاسم', 'السعر'], ['باقة ورد', 10]]);
        $spreadsheet->createSheet()->setTitle('الموردون');
        $path = tempnam(sys_get_temp_dir(), 'two') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);

        $this->actingAs($this->owner)->post(route('admin.products.import.upload'), [
            'file' => new UploadedFile($path, 'two.xlsx', null, null, true),
        ]);

        $this->assertCount(2, $this->previewProps()['sheets'], 'أُهملت ورقة بصمت');
    }
}
