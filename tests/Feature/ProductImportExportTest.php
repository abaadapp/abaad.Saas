<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * تصدير المنتجات واستيرادها — الغرض نقل تاجرٍ من نظامه السابق.
 *
 * وأهمّ ما يُختبر هنا شيئان:
 * 1. **دوران الملف**: ما يخرج من التصدير يجب أن يعود من الاستيراد كما هو.
 *    تصديرٌ لا يُقبل عند إعادة رفعه ليس أداة نقل.
 * 2. **توازن الدفاتر**: كل كمية تدخل يجب أن تصل فرعًا. كتابتها في
 *    products.quantity وحدها تُجيز البيع من فرعٍ فارغ — وهو الخلل نفسه
 *    الذي أُصلح في نقطة البيع.
 */
class ProductImportExportTest extends TestCase
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
        // BOM حتى يقرأ إكسل العربية، وهو ما يُصدّره أي نظام سابق محترم
        file_put_contents($path, "\xEF\xBB\xBF" . $body);

        return new UploadedFile($path, 'products.csv', 'text/csv', null, true);
    }

    private function importAndConfirm(string $csv, ?int $branchId = null)
    {
        $this->actingAs($this->owner)->post(route('admin.products.import.upload'), [
            'file' => $this->csv($csv),
            'branch_id' => $branchId ?? $this->muscat->id,
        ])->assertRedirect(route('admin.products.import.preview'));

        return $this->actingAs($this->owner)->post(route('admin.products.import.confirm'));
    }

    private function product(string $name): Product
    {
        return Product::where('business_id', $this->business->id)->where('name', $name)->firstOrFail();
    }

    /* ============================== التصدير ============================== */

    public function test_the_export_carries_the_columns_the_import_expects(): void
    {
        Category::create(['business_id' => $this->business->id, 'name' => 'ورود']);
        Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد',
            'category_id' => Category::first()->id, 'sku' => 'SKU-1', 'barcode' => '0001234567890',
            'price' => 10, 'cost' => 4, 'quantity' => 12, 'alert_qty' => 3, 'active' => true,
        ]);

        $res = $this->actingAs($this->owner)->get(route('admin.products.export.xlsx'))->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'exp') . '.xlsx';
        file_put_contents($path, $res->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet()->toArray();

        $this->assertSame(
            ['الاسم', 'القسم', 'SKU', 'الباركود', 'السعر', 'التكلفة', 'الكمية', 'حد التنبيه', 'الضريبة %', 'الخصم %', 'الحالة'],
            array_map('strval', $sheet[0]),
        );
        $this->assertSame('باقة ورد', $sheet[1][0]);
        $this->assertSame('ورود', $sheet[1][1]);
        $this->assertSame(12, (int) $sheet[1][6]);
    }

    public function test_the_barcode_survives_the_trip_through_excel(): void
    {
        // باركود بصفر بادئ: لو كُتب رقمًا عاد «1234567890» — ملصقٌ لا يُقرأ
        Product::create([
            'business_id' => $this->business->id, 'name' => 'صنف',
            'sku' => '007', 'barcode' => '0001234567890',
            'price' => 5, 'cost' => 2, 'quantity' => 1, 'alert_qty' => 1, 'active' => true,
        ]);

        $res = $this->actingAs($this->owner)->get(route('admin.products.export.xlsx'))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'exp') . '.xlsx';
        file_put_contents($path, $res->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet()->toArray();

        $this->assertSame('007', (string) $sheet[1][2], 'ضاع الصفر البادئ من SKU');
        $this->assertSame('0001234567890', (string) $sheet[1][3], 'ضاع الصفر البادئ من الباركود');
    }

    public function test_the_exported_file_can_be_imported_back_unchanged(): void
    {
        Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد', 'sku' => 'SKU-1',
            'price' => 10, 'cost' => 4, 'quantity' => 12, 'alert_qty' => 3, 'active' => true,
        ]);
        BranchStock::create([
            'business_id' => $this->business->id, 'branch_id' => $this->muscat->id,
            'product_id' => Product::first()->id, 'quantity' => 12,
        ]);

        $res = $this->actingAs($this->owner)->get(route('admin.products.export.xlsx'))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'exp') . '.xlsx';
        file_put_contents($path, $res->streamedContent());

        $this->actingAs($this->owner)->post(route('admin.products.import.upload'), [
            'file' => new UploadedFile($path, 'products.xlsx', null, null, true),
            'branch_id' => $this->muscat->id,
        ])->assertRedirect(route('admin.products.import.preview'));

        $counts = $this->actingAs($this->owner)->get(route('admin.products.import.preview'))
            ->assertOk()->viewData('page')['props']['counts'];

        $this->assertSame(1, $counts['update'], 'الملف المُصدَّر لم يُطابق نفسه عند إعادة رفعه');
        $this->assertSame(0, $counts['new']);

        $this->actingAs($this->owner)->post(route('admin.products.import.confirm'));

        $this->assertSame(1, Product::count(), 'تكرّر المنتج بدل تحديثه');
        $this->assertSame(12, (int) $this->product('باقة ورد')->quantity);
    }

    public function test_the_pdf_export_opens(): void
    {
        Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد',
            'price' => 10, 'cost' => 4, 'quantity' => 1, 'alert_qty' => 1, 'active' => true,
        ]);

        $this->actingAs($this->owner)->get(route('admin.products.export.pdf'))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    /* ============================== الاستيراد ============================== */

    public function test_a_plain_file_creates_the_products(): void
    {
        $this->importAndConfirm(
            "الاسم,القسم,SKU,الباركود,السعر,التكلفة,الكمية,حد التنبيه\n" .
            "باقة ورد,ورود,SKU-1,111,10,4,12,3\n" .
            "شوكولاتة,حلويات,SKU-2,222,2.5,1,50,10\n"
        );

        $this->assertSame(2, Product::count());
        $this->assertSame(10.0, (float) $this->product('باقة ورد')->price);
        $this->assertSame(50, (int) $this->product('شوكولاتة')->quantity);
    }

    public function test_missing_categories_are_created_and_reused(): void
    {
        $this->importAndConfirm(
            "الاسم,القسم,السعر\nورد أحمر,ورود,10\nورد أبيض,ورود,12\n"
        );

        $this->assertSame(1, Category::where('business_id', $this->business->id)->count(), 'أُنشئ القسم مرّتين');
        $this->assertSame(
            $this->product('ورد أحمر')->category_id,
            $this->product('ورد أبيض')->category_id,
        );
    }

    public function test_imported_quantities_reach_the_chosen_branch(): void
    {
        $this->importAndConfirm("الاسم,السعر,الكمية\nباقة ورد,10,12\n", $this->salalah->id);

        $product = $this->product('باقة ورد');

        $this->assertSame(12, (int) $product->quantity);
        $this->assertSame(12, (int) BranchStock::where('branch_id', $this->salalah->id)
            ->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(
            (int) $product->quantity,
            (int) BranchStock::where('product_id', $product->id)->sum('quantity'),
            'مجموع الفروع لا يساوي كمية المنتج',
        );
    }

    public function test_updating_a_quantity_applies_the_difference_not_the_whole_number(): void
    {
        // 30 موزّعة: 20 مسقط + 10 صلالة. الملف يقول 50.
        $product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد', 'sku' => 'SKU-1',
            'price' => 10, 'cost' => 4, 'quantity' => 30, 'alert_qty' => 3, 'active' => true,
        ]);
        foreach ([[$this->muscat->id, 20], [$this->salalah->id, 10]] as [$branch, $qty]) {
            BranchStock::create([
                'business_id' => $this->business->id, 'branch_id' => $branch,
                'product_id' => $product->id, 'quantity' => $qty,
            ]);
        }

        $this->importAndConfirm("الاسم,SKU,السعر,الكمية\nباقة ورد,SKU-1,10,50\n", $this->muscat->id);

        $this->assertSame(50, (int) $product->fresh()->quantity);
        // الفارق 20 وصل مسقط وحدها؛ صلالة لم تُمَسّ
        $this->assertSame(40, (int) BranchStock::where('branch_id', $this->muscat->id)
            ->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(10, (int) BranchStock::where('branch_id', $this->salalah->id)
            ->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(
            50,
            (int) BranchStock::where('product_id', $product->id)->sum('quantity'),
            'كُتبت الكمية كاملة فوق التوزيع فاختلّ التوازن',
        );
    }

    public function test_an_existing_product_is_matched_by_sku_not_duplicated(): void
    {
        Product::create([
            'business_id' => $this->business->id, 'name' => 'اسم قديم', 'sku' => 'SKU-1',
            'price' => 10, 'cost' => 4, 'quantity' => 5, 'alert_qty' => 3, 'active' => true,
        ]);

        $this->importAndConfirm("الاسم,SKU,السعر,الكمية\nاسم جديد,SKU-1,15,5\n");

        $this->assertSame(1, Product::count());
        $this->assertSame('اسم جديد', Product::first()->name);
        $this->assertSame(15.0, (float) Product::first()->price);
    }

    public function test_rows_without_a_name_or_price_are_skipped_not_saved(): void
    {
        $this->importAndConfirm(
            "الاسم,السعر,الكمية\n,10,5\nبلا سعر,,5\nسالب,-3,5\nسليم,10,5\n"
        );

        $this->assertSame(1, Product::count(), 'حُفظ صفّ غير صالح');
        $this->assertSame('سليم', Product::first()->name);
    }

    public function test_a_row_duplicated_inside_the_file_is_imported_once(): void
    {
        $this->importAndConfirm(
            "الاسم,SKU,السعر,الكمية\nباقة ورد,SKU-1,10,5\nنسخة,SKU-1,12,7\n"
        );

        $this->assertSame(1, Product::count());
        $this->assertSame(5, (int) Product::first()->quantity, 'اعتُمد الصفّ المكرر');
    }

    public function test_arabic_numerals_and_currency_text_are_understood(): void
    {
        // ما يخرج من أنظمة عربية سابقة: أرقام هندية وفاصلة عربية ولاحقة عملة
        $this->importAndConfirm("الاسم,السعر,الكمية\nباقة ورد,١٢٫٥٠٠ ر.ع,٧\n");

        $this->assertSame(12.5, (float) $this->product('باقة ورد')->price);
        $this->assertSame(7, (int) $this->product('باقة ورد')->quantity);
    }

    public function test_columns_in_any_order_are_detected_from_the_header(): void
    {
        $this->importAndConfirm("Quantity,Price,Product,Barcode\n9,3.5,Rose,555\n");

        $p = $this->product('Rose');
        $this->assertSame(3.5, (float) $p->price);
        $this->assertSame(9, (int) $p->quantity);
        $this->assertSame('555', $p->barcode);
    }

    public function test_a_file_with_no_header_falls_back_to_the_standard_order(): void
    {
        $this->importAndConfirm("باقة ورد,ورود,SKU-9,999,10,4,6,2\n");

        $p = $this->product('باقة ورد');
        $this->assertSame('SKU-9', $p->sku);
        $this->assertSame(10.0, (float) $p->price);
        $this->assertSame(6, (int) $p->quantity);
    }

    public function test_the_disabled_status_column_is_honoured(): void
    {
        $this->importAndConfirm("الاسم,السعر,الحالة\nمعطّل,10,معطّل\nمفعّل,10,مفعّل\n");

        $this->assertFalse((bool) $this->product('معطّل')->active);
        $this->assertTrue((bool) $this->product('مفعّل')->active);
    }

    /* ============================== الحراسة ============================== */

    public function test_an_unsupported_file_type_is_refused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bad') . '.txt';
        file_put_contents($path, 'ليس جدولًا');

        $this->actingAs($this->owner)->post(route('admin.products.import.upload'), [
            'file' => new UploadedFile($path, 'notes.txt', 'text/plain', null, true),
        ])->assertRedirect();

        $this->assertSame(0, Product::count());
    }

    public function test_confirming_without_an_uploaded_file_changes_nothing(): void
    {
        $this->actingAs($this->owner)->post(route('admin.products.import.confirm'))
            ->assertRedirect(route('admin.products.index'));

        $this->assertSame(0, Product::count());
    }

    public function test_cancelling_drops_the_pending_file(): void
    {
        $this->actingAs($this->owner)->post(route('admin.products.import.upload'), [
            'file' => $this->csv("الاسم,السعر\nباقة ورد,10\n"),
        ]);

        $this->actingAs($this->owner)->post(route('admin.products.import.cancel'))
            ->assertRedirect(route('admin.products.index'));

        $this->actingAs($this->owner)->post(route('admin.products.import.confirm'));

        $this->assertSame(0, Product::count(), 'استُورد ملف أُلغي');
    }

    public function test_the_import_never_touches_another_business(): void
    {
        $other = Business::create(['name' => 'جارنا', 'type' => 'عام', 'status' => 'نشط']);
        Product::create([
            'business_id' => $other->id, 'name' => 'صنف الجار', 'sku' => 'SKU-1',
            'price' => 99, 'cost' => 1, 'quantity' => 5, 'alert_qty' => 1, 'active' => true,
        ]);

        $this->importAndConfirm("الاسم,SKU,السعر,الكمية\nصنفي,SKU-1,10,3\n");

        // نفس الـSKU لكنه لنشاط آخر: يُضاف جديدًا ولا يُكتب فوق الجار
        $this->assertSame(99.0, (float) Product::where('business_id', $other->id)->first()->price);
        $this->assertSame(1, Product::where('business_id', $this->business->id)->count());
    }

    public function test_the_export_never_leaks_another_business(): void
    {
        $other = Business::create(['name' => 'جارنا', 'type' => 'عام', 'status' => 'نشط']);
        Product::create([
            'business_id' => $other->id, 'name' => 'سرّ الجار',
            'price' => 99, 'cost' => 1, 'quantity' => 5, 'alert_qty' => 1, 'active' => true,
        ]);

        $res = $this->actingAs($this->owner)->get(route('admin.products.export.xlsx'))->assertOk();

        $this->assertStringNotContainsString('سرّ الجار', $res->streamedContent());
    }

    public function test_the_endpoints_are_closed_to_guests(): void
    {
        $this->get(route('admin.products.export.xlsx'))->assertRedirect();
        $this->get(route('admin.products.import.preview'))->assertRedirect();
        $this->post(route('admin.products.import.confirm'))->assertRedirect();
    }
}
