<?php

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Demo;
use App\Support\Ledger;
use App\Support\ReportData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * صنفٌ خارج دفتر المخزون لا يُعدّ مخزونًا — في أيّ شاشة.
 *
 * خدمةٌ أو رسمُ توصيلٍ أو صنفٌ أُوقف تتبّعُه (`tracks_stock = false`) لا رفَّ له
 * (`AProductMayLiveOutsideTheStockBookTest`). وكانت شاشاتٌ تعرفه وأخرى تجهله:
 *
 *   - **بطاقةُ «منتجات منخفضة المخزون» بفرعٍ مختار** (`Demo::lowStockInBranch`)
 *     تعدّه — والإجماليُّ لا يعدّه (`needsStockAlert`). فخدمةٌ رصيدُها صفرٌ
 *     تحت حدٍّ عشرةٍ ترفع العدّاد كلّما اختار التاجرُ فرعًا.
 *   - **قيمةُ المخزون** في شاشة المخزون وتقريره: `التكلفة × الكمية` لكلّ صنف،
 *     وصنفٌ أُوقف تتبّعُه تبقى له كميّتُه القديمة (لا تُمحى: تعود إن أُعيد
 *     التتبّع) — فتُحسب بضاعةً لا رفَّ لها.
 *   - **تقريرُ المخزون** يعدّ «تحت الحدّ» بـ`<=` ويضمّ الخدمات، واللوحةُ
 *     بـ`<` وبلاها: صنفٌ كميّتُه تساوي حدَّه «متوفر» هنا و«تحت الحدّ» هناك.
 *   - **اقتراحاتُ إعادة الطلب** تقترح شراءَ خدمة.
 *   - **مرشِّحُ حالة المخزون** في قائمة المنتجات يضع الخدمةَ تحت «نفد المخزون»
 *     والقائمةُ نفسُها تقول عنها «غير مرتبط بالمخزون».
 *   - **وأمانةُ البوتيك** تدخل قيمةَ مخزون المحلّ، والتصميمُ يقول إنّها لا
 *     تدخلها (`Boutiques::CONSIGNED_COST`).
 *
 * والقاعدةُ واحدة: `Product::statusFor` و`needsStockAlert` — متتبَّعٌ، وأقلُّ من
 * الحدّ. ولا يُمسّ رصيدٌ ولا كميّةٌ في القاعدة: هذا عرضٌ وقراءة.
 */
class AnUntrackedProductIsNeverCountedAsStockTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $neighbour;

    private User $owner;

    private User $theirOwner;

    private Branch $main;

    private Branch $second;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        [$this->shop, $this->owner] = $this->business('زهور مسقط', 'owner@stock.om');
        [$this->neighbour, $this->theirOwner] = $this->business('متجر الجار', 'n@stock.om');

        $this->main = Branch::create(['business_id' => $this->shop->id, 'name' => 'مسقط']);
        $this->second = Branch::create(['business_id' => $this->shop->id, 'name' => 'صلالة']);

        // متتبَّعةٌ: منخفضٌ، ومتوفّر، وعلى الحدّ بالضبط، وبلا تنبيهٍ اختاره التاجر
        $this->product('ورد أحمر', 3, 10, 1.5);
        $this->product('ورد أبيض', 20, 10, 1);
        $this->product('ورد وردي', 10, 10, 2);
        $this->product('زهرة بلا تنبيه', 0, 0, 4);

        // وخارج الدفتر: خدمةٌ بلا كميّة، وصنفٌ أُوقف تتبّعُه وبقيت له سبعٌ قديمة
        $this->product('رسم توصيل', 0, 10, 0, tracked: false);
        $this->product('تغليف', 7, 10, 2, tracked: false);

        // وعند الجار صنفٌ نفد — لا يُعدّ هنا
        $theirs = Branch::create(['business_id' => $this->neighbour->id, 'name' => 'فرعهم']);
        $p = Product::create([
            'business_id' => $this->neighbour->id, 'name' => 'صنف الجار', 'price' => 5,
            'cost' => 3, 'quantity' => 0, 'alert_qty' => 5, 'active' => true,
        ]);
        BranchStock::adjust($this->neighbour->id, $theirs->id, $p->id, 0);
    }

    /** @return array{0: Business, 1: User} */
    private function business(string $name, string $email): array
    {
        $b = Business::create(['name' => $name, 'type' => 'عام', 'status' => 'نشط']);
        Currency::create([
            'business_id' => $b->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($b->id);
        Setting::create(['business_id' => $b->id, 'key' => 'vat_enabled', 'value' => '0']);
        $u = User::create([
            'business_id' => $b->id, 'name' => 'المالك', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        return [$b, $u];
    }

    /** صنفٌ رصيدُه كلُّه في فرع مسقط — وما أُوقف تتبّعُه أُوقف بعد أن كانت له كميّة */
    private function product(string $name, int $qty, int $alert, float $cost, bool $tracked = true): Product
    {
        $p = Product::create([
            'business_id' => $this->shop->id, 'name' => $name, 'price' => 10, 'cost' => $cost,
            'quantity' => $qty, 'alert_qty' => $alert, 'active' => true,
        ]);
        BranchStock::adjust($this->shop->id, $this->main->id, $p->id, $qty);

        if (! $tracked) {
            $p->forceFill(['tracks_stock' => false])->save();
        }

        return $p;
    }

    private function lowStockCard(?Branch $branch = null): int
    {
        $branch ? session(['current_branch' => $branch->id]) : session()->forget('current_branch');

        return (int) collect(Demo::adminStats())->firstWhere('label', 'منتجات منخفضة المخزون')['value'];
    }

    /* ═══════════ التنبيهات ═══════════ */

    public function test_the_low_stock_card_counts_the_same_products_with_and_without_a_branch(): void
    {
        $this->actingAs($this->owner);

        // «ورد أحمر» وحده: ٣ < ١٠. والوردي على حدّه، وبلا تنبيهٍ صفرٌ ليس أقلَّ من صفر
        $this->assertSame(1, $this->lowStockCard(), 'كل الفروع');
        $this->assertSame(1, $this->lowStockCard($this->main), 'فرعُ مسقط وفيه الرصيدُ كلُّه — والخدمتان لا تُعدّان');

        // وصلالة بلا بضاعة: المتتبَّعةُ ذاتُ الحدّ كلُّها تحته — والخدمتان لا
        $this->assertSame(3, $this->lowStockCard($this->second));
    }

    public function test_the_neighbours_empty_shelf_is_never_counted_here(): void
    {
        $this->actingAs($this->owner);
        $this->assertSame(1, $this->lowStockCard());
        $this->assertNotContains('صنف الجار', array_column(Demo::reorderSuggestions(), 'name'));

        // وعند الجار: صنفُه وحده
        session()->forget('current_branch');
        $this->actingAs($this->theirOwner);
        $this->assertSame(1, $this->lowStockCard());
    }

    /* ═══════════ القيمة ═══════════ */

    public function test_the_inventory_value_leaves_out_what_is_not_on_a_shelf(): void
    {
        // ٣×١٫٥ + ٢٠×١ + ١٠×٢ + ٠×٤ = ٤٤٫٥ — لا ٥٨٫٥ بسبع علب تغليفٍ لا رفَّ لها
        $this->actingAs($this->owner)->get(route('admin.inventory.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('summary.value', 44.5)->etc());

        $rows = collect(Demo::inventory())->keyBy('name');
        $this->assertEquals(0, $rows['تغليف']['value']);
        $this->assertEquals(0, $rows['تغليف']['totalValue']);
        $this->assertFalse($rows['تغليف']['tracked']);
        $this->assertEquals(4.5, $rows['ورد أحمر']['value']);
        $this->assertTrue($rows['ورد أحمر']['tracked']);

        // والفرعُ المختار يقيس ما يقيسه الإجماليّ
        session(['current_branch' => $this->main->id]);
        $this->assertEquals(44.5, round(collect(Demo::inventory())->sum('value'), 3));
    }

    public function test_a_boutiques_consigned_goods_are_counted_on_the_shelf_but_not_in_the_shops_value(): void
    {
        /*
         * وبضاعةُ البوتيك أمانة: يملكها هو حتّى تُباع، و«لا تدخل قيمةَ مخزون
         * المحلّ» (`Boutiques::CONSIGNED_COST`). عشرُ زجاجاتِ عطرٍ بخمسةٍ كانت
         * تزيد قيمةَ مخزون التاجر خمسين لا يملكها.
         */
        $boutique = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك العطور', 'commission_rate' => 20, 'active' => true]);
        $perfume = Product::create([
            'business_id' => $this->shop->id, 'name' => 'عطر', 'price' => 12, 'cost' => 5,
            'quantity' => 10, 'alert_qty' => 2, 'active' => true, 'boutique_id' => $boutique->id,
        ]);
        BranchStock::adjust($this->shop->id, $this->main->id, $perfume->id, 10);

        $this->actingAs($this->owner)->get(route('admin.inventory.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('summary.value', 44.5)->etc());
        $row = collect(Demo::inventory())->firstWhere('name', 'عطر');
        $this->assertEquals(0, $row['value']);
        $this->assertTrue($row['consigned']);
        $this->assertSame(10, (int) $row['qty'], 'والعدُّ على الرفّ كما هو');

        $report = ReportData::inventory($this->shop->id, []);
        $this->assertEquals(44.5, $report['summary']['value']);
        $this->assertSame(43, $report['summary']['quantity'], 'وكميّتُها على الرفّ تُعدّ: ٣٣ + ١٠');
    }

    public function test_the_old_quantity_of_an_untracked_product_is_kept_and_still_shown(): void
    {
        $this->actingAs($this->owner)->get(route('admin.inventory.index'))->assertOk();
        ReportData::inventory($this->shop->id, []);

        $wrap = Product::where('business_id', $this->shop->id)->where('name', 'تغليف')->sole();
        $this->assertSame(7, (int) $wrap->quantity, 'مُحيت الكميّةُ القديمة — وهي ما يعود إن أُعيد التتبّع');
        $this->assertSame(7, (int) BranchStock::where('product_id', $wrap->id)->sum('quantity'));

        $row = collect(Demo::inventory())->firstWhere('name', 'تغليف');
        $this->assertSame(7, (int) $row['qty']);
        $this->assertSame(Product::UNTRACKED, $row['status']);
    }

    /* ═══════════ تقريرُ المخزون ═══════════ */

    public function test_the_inventory_report_counts_value_and_below_by_the_same_rule(): void
    {
        $this->actingAs($this->owner);
        $report = ReportData::inventory($this->shop->id, []);

        $this->assertEquals(44.5, $report['summary']['value']);
        $this->assertSame(33, $report['summary']['quantity'], 'الكميّةُ للمتتبَّع: ٣ + ٢٠ + ١٠ + ٠');
        $this->assertSame(1, $report['summary']['below'], '«ورد أحمر» وحده — كما تقول اللوحة');

        $rows = collect($report['rows'])->keyBy('name');
        $this->assertFalse($rows['ورد وردي']['below'], 'على حدّه بالضبط ليس تحته');
        $this->assertFalse($rows['رسم توصيل']['below']);
        $this->assertEquals(0, $rows['تغليف']['value']);
        $this->assertSame(7, $rows['تغليف']['quantity'], 'والصفُّ يقول كميّتَه كما هي');
    }

    /* ═══════════ إعادةُ الطلب والقائمة ═══════════ */

    public function test_no_one_is_asked_to_reorder_a_service(): void
    {
        $this->actingAs($this->owner);
        $names = array_column(Demo::reorderSuggestions(), 'name');

        $this->assertContains('ورد أحمر', $names);
        $this->assertNotContains('رسم توصيل', $names);
        $this->assertNotContains('تغليف', $names);
    }

    public function test_the_product_list_stock_filter_holds_only_shelved_products(): void
    {
        $names = fn (string $stock) => collect($this->actingAs($this->owner)
            ->get(route('admin.products.index', ['stock' => $stock]))->assertOk()
            ->viewData('page')['props']['products'])->pluck('name')->all();

        $out = $names('نفد المخزون');
        $this->assertContains('زهرة بلا تنبيه', $out);
        $this->assertNotContains('رسم توصيل', $out, 'خدمةٌ بلا رفٍّ «نفدت»');

        $low = $names('منخفض');
        $this->assertContains('ورد أحمر', $low);
        $this->assertNotContains('تغليف', $low);

        $this->assertNotContains('تغليف', $names('متوفر'));
    }
}
