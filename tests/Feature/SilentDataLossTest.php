<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * أعطالٌ لا تُرى: الأرقام تبقى معقولة بعدها.
 *
 * ثلاثةٌ من نوعٍ واحد — النظام يقرأ الغياب قيمةً: عمودٌ ناقصٌ في ملفّ
 * الاستيراد يعني صفرًا، وتاريخُ يومٍ يعني منتصف ليلته. ولا شيء في الشاشة
 * يقول إن شيئًا ضاع.
 */
class SilentDataLossTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'مسقط']);
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

    private function file(string $body, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".$body);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    /* ======================= استيراد المنتجات ======================= */

    public function test_a_price_list_does_not_wipe_the_stock(): void
    {
        /*
         * أكثر ما يُستورد: قائمة أسعارٍ محدَّثة من المورّد — اسمٌ وسعر لا غير.
         * وكانت **تمحو مخزون المتجر كلّه إلى صفر** لأن العمود الغائب يُقرأ
         * صفرًا. لا رسالة، ولا تراجعَ يخطر ببال أحد: الأرقام تبدو معقولة.
         */
        $p = Product::create([
            'business_id' => $this->business->id, 'name' => 'كيس أرز',
            'price' => 10, 'cost' => 6, 'quantity' => 40, 'barcode' => '6291001', 'sku' => 'R-1', 'active' => true,
        ]);
        BranchStock::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'product_id' => $p->id, 'quantity' => 40,
        ]);

        $this->import("الاسم,السعر\nكيس أرز,12\n", 'products.csv', 'products');

        $fresh = $p->fresh();
        $this->assertSame(40, (int) $fresh->quantity, 'مُحي المخزون لأن الملفّ لم يذكره');
        $this->assertSame(12.0, (float) $fresh->price, 'لم يُحدَّث السعر وهو المقصود من الملفّ');
        $this->assertSame(6.0, (float) $fresh->cost, 'مُحيت التكلفة فصار كل بيعٍ ربحًا صافيًا');
        $this->assertSame('6291001', $fresh->barcode, 'مُحي الباركود فتوقّف الماسح');
        $this->assertSame(40, (int) BranchStock::where('product_id', $p->id)->value('quantity'));
    }

    public function test_a_stated_quantity_is_still_applied(): void
    {
        // الحماية ليست تجميدًا: ما ذكره الملفّ يُكتب كما كان
        $p = Product::create([
            'business_id' => $this->business->id, 'name' => 'كيس أرز',
            'price' => 10, 'cost' => 6, 'quantity' => 40, 'active' => true,
        ]);
        BranchStock::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'product_id' => $p->id, 'quantity' => 40,
        ]);

        $this->import("الاسم,السعر,الكمية\nكيس أرز,10,55\n", 'products.csv', 'products');

        $this->assertSame(55, (int) $p->fresh()->quantity);
        $this->assertSame(55, (int) BranchStock::where('product_id', $p->id)->value('quantity'));
    }

    public function test_a_zero_written_on_purpose_is_obeyed(): void
    {
        // صفرٌ مكتوبٌ صراحةً نيّةٌ لا غياب: «نفد الصنف» تُكتب صفرًا
        $p = Product::create([
            'business_id' => $this->business->id, 'name' => 'كيس أرز',
            'price' => 10, 'cost' => 6, 'quantity' => 40, 'active' => true,
        ]);
        BranchStock::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'product_id' => $p->id, 'quantity' => 40,
        ]);

        $this->import("الاسم,السعر,الكمية\nكيس أرز,10,0\n", 'products.csv', 'products');

        $this->assertSame(0, (int) $p->fresh()->quantity);
    }

    public function test_the_preview_says_what_will_not_be_touched(): void
    {
        Product::create([
            'business_id' => $this->business->id, 'name' => 'كيس أرز',
            'price' => 10, 'cost' => 6, 'quantity' => 40, 'active' => true,
        ]);

        $this->actingAs($this->owner)->post(route('admin.products.import.upload'), [
            'file' => $this->file("الاسم,السعر\nكيس أرز,12\n", 'products.csv'),
            'branch_id' => $this->branch->id,
        ]);

        $props = $this->actingAs($this->owner)->get(route('admin.products.import.preview'))
            ->assertOk()->viewData('page')['props'];

        $this->assertContains('الكمية', $props['untouched']);
        $this->assertContains('التكلفة', $props['untouched']);
        $this->assertNotContains('السعر', $props['untouched']);
    }

    /* ======================= استيراد العملاء ======================= */

    public function test_a_contact_list_does_not_wipe_loyalty_points(): void
    {
        $c = Customer::create([
            'business_id' => $this->business->id, 'name' => 'سالم',
            'phone' => '99887766', 'email' => 'salem@abaad.om', 'points' => 350,
        ]);

        $this->import("الاسم,الهاتف\nسالم,99887766\n", 'customers.csv', 'customers');

        $fresh = $c->fresh();
        $this->assertSame(350, (int) $fresh->points, 'مُحيت نقاط العميل لأن الملفّ لم يذكرها');
        $this->assertSame('salem@abaad.om', $fresh->email, 'مُحي البريد');
    }

    public function test_stated_points_are_still_applied(): void
    {
        $c = Customer::create([
            'business_id' => $this->business->id, 'name' => 'سالم', 'phone' => '99887766', 'points' => 350,
        ]);

        $this->import("الاسم,الهاتف,النقاط\nسالم,99887766,500\n", 'customers.csv', 'customers');

        $this->assertSame(500, (int) $c->fresh()->points);
    }

    private function import(string $csv, string $filename, string $kind): void
    {
        $this->actingAs($this->owner)->post(route("admin.{$kind}.import.upload"), [
            'file' => $this->file($csv, $filename),
            'branch_id' => $this->branch->id,
        ]);
        $this->actingAs($this->owner)->post(route("admin.{$kind}.import.confirm"));
    }

    /* ========================== الكوبونات ========================== */

    public function test_a_coupon_expiring_today_works_today(): void
    {
        /*
         * التاريخ يُحفظ «2026-08-12» فيُقرأ 00:00:00 — فكوبونُ اليوم ميّتٌ من
         * لحظة إنشائه. عرض «خصم اليوم فقط» لم يكن يعمل ولا مرّة، والتاجر
         * يظنّ الكود خطأً من الكاشير.
         */
        $coupon = Coupon::create([
            'business_id' => $this->business->id, 'code' => 'اليوم', 'type' => 'نسبة',
            'value' => 10, 'min_order' => 0, 'active' => true, 'expires_at' => now()->toDateString(),
        ]);

        $this->assertTrue($coupon->isValid(), 'كوبونٌ ينتهي اليوم لا يعمل اليوم');
        $this->assertFalse($coupon->isExpired());

        $this->actingAs($this->owner);
        $codes = array_column(Demo::activeCoupons(), 'code');
        $this->assertContains('اليوم', $codes, 'لم يُعرض في نقطة البيع');
    }

    public function test_a_coupon_that_expired_yesterday_stays_expired(): void
    {
        $coupon = Coupon::create([
            'business_id' => $this->business->id, 'code' => 'أمس', 'type' => 'نسبة',
            'value' => 10, 'active' => true, 'expires_at' => now()->subDay()->toDateString(),
        ]);

        $this->assertTrue($coupon->isExpired());
        $this->assertFalse($coupon->isValid());
    }

    public function test_the_coupon_cost_is_recorded_apart_from_loyalty(): void
    {
        // `discount` يجمع الاثنين، فلا يُعرف كم كلّف كوبونٌ بعينه
        $product = Product::create([
            'business_id' => $this->business->id, 'name' => 'كيس أرز',
            'price' => 100, 'cost' => 60, 'quantity' => 50, 'active' => true,
        ]);
        Coupon::create([
            'business_id' => $this->business->id, 'code' => 'خصم', 'type' => 'مبلغ',
            'value' => 20, 'min_order' => 0, 'active' => true,
        ]);
        $this->openShiftFor($this->business->id);

        $this->actingAs($this->owner)->postJson(route('pos.checkout'), [
            'items' => [['id' => $product->id, 'name' => $product->name, 'qty' => 1]],
            'coupon_code' => 'خصم',
            'payment_method' => 'نقدي',
        ])->assertOk();

        $order = Order::latest('id')->first();

        $this->assertSame(20.0, (float) $order->coupon_discount);
        $this->assertSame('خصم', $order->coupon_code);
    }

    /* =========================== المالية =========================== */

    public function test_an_expense_booked_in_finance_actually_counts(): void
    {
        /*
         * كانت المالية تقبل «مصروف» وتكتبه صفًّا في المعاملات — وبطاقاتها
         * تجمع الدخل وحده، والربح يُقرأ من جدول المصروفات. فالمبلغ يظهر في
         * الجدول ولا ينقص ربحًا ولا يدخل تقريرًا.
         */
        $this->actingAs($this->owner)->post(route('admin.finance.store'), [
            'type' => 'مصروف', 'amount' => 45, 'method' => 'نقدي', 'description' => 'كهرباء',
        ]);

        $this->assertSame(45.0, (float) Expense::where('business_id', $this->business->id)->sum('amount'));
    }

    public function test_an_expense_screen_entry_shows_in_the_ledger_too(): void
    {
        $this->actingAs($this->owner)->post(route('admin.expenses.store'), [
            'type' => 'إيجار', 'amount' => 300, 'method' => 'تحويل بنكي',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Transaction::where('type', 'مصروف')->count(), 'المصروف غائب عن دفتر المالية');
        $this->assertSame(300.0, (float) Expense::sum('amount'));
    }

    public function test_transaction_references_never_collide(): void
    {
        /*
         * كان `'TRX-' . random_int(60000, 99999)`: أربعون ألف قيمة بلا قيد،
         * فاحتمال التكرار يبلغ النصف بعد ٢٣٥ معاملة — وفي دفترٍ ماليّ مرجعان
         * متطابقان يعنيان أن التاجر لا يعرف أيّ صفٍّ يُصحّح.
         */
        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($this->owner)->post(route('admin.finance.store'), [
                'type' => 'دخل', 'amount' => 5, 'method' => 'نقدي',
            ]);
        }

        $refs = Transaction::where('business_id', $this->business->id)->pluck('reference');

        $this->assertCount(30, $refs->unique(), 'مرجعان متطابقان في دفتر ماليّ');
        $this->assertSame('TRX-000001', $refs->first());
    }

    public function test_each_store_numbers_its_own_transactions(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Transaction::create([
            'business_id' => $other->id, 'reference' => 'TRX-000900', 'description' => '—',
            'method' => 'نقدي', 'type' => 'دخل', 'amount' => 1, 'occurred_at' => now(),
        ]);

        $this->actingAs($this->owner)->post(route('admin.finance.store'), [
            'type' => 'دخل', 'amount' => 5, 'method' => 'نقدي',
        ]);

        $this->assertSame('TRX-000001', Transaction::where('business_id', $this->business->id)->value('reference'));
    }
}
