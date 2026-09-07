<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\CustomerInvoiceController;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Product;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * نافذةُ الكتالوج تجد ما في اليد.
 *
 * كانت تبحث بالاسم وحده وتفترض أنّ من يكتب الفاتورة يحفظ الأسماء. وهو يقرأ
 * رمزَ الصنف من أمر شراء العميل، أو يمسح الباركود بقارئٍ يكتب أرقامًا ثمّ
 * Enter — فيُردّ بـ«لا صنف» عن صنفٍ في يده.
 *
 * وكانت تُغلق عند أوّل اختيار، فمن يكتب فاتورةً بخمسة بنودٍ يفتحها خمس
 * مرّات. وتقصّ القائمة عند المئة بلا أن تقول إنّها قصّتها.
 */
class TheCatalogWindowFindsWhatIsInHandTest extends TestCase
{
    use RefreshDatabase;

    /** ملفُّ الشاشة — ما لا يُشغَّل إلّا في المتصفّح يُحرَس على مصدره */
    private const SCREEN = 'resources/js/Pages/Admin/CustomerInvoices/Create.tsx';

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($this->business->id);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function product(array $over = []): Product
    {
        return Product::create($over + [
            'business_id' => $this->business->id,
            'name' => 'باقة ورد', 'price' => 12.5, 'cost' => 6, 'quantity' => 4,
        ]);
    }

    /* ==================== ما يصل الشاشة ==================== */

    /**
     * الصنفُ يصل ومعه رمزُه وباركودُه.
     *
     * القائمةُ تُرشَّح في المتصفّح، فما لم يُرسَل لا يُبحث فيه: بحثٌ برمزٍ
     * لم يصل يردّ «لا صنف» مهما كتب صاحبه.
     */
    public function test_the_catalog_arrives_with_its_codes(): void
    {
        $this->product(['name' => 'باقة ورد', 'sku' => 'FLW-014', 'barcode' => '6291041500213']);

        $this->actingAs($this->owner)->get(route('admin.customerInvoices.create'))
            ->assertInertia(fn ($p) => $p
                ->where('products.0.sku', 'FLW-014')
                ->where('products.0.barcode', '6291041500213')
                ->where('products.0.name', 'باقة ورد')
                ->etc());
    }

    /** وصنفٌ بلا رمز يصل بلا رمز — لا يسقط الطلب ولا يُخترع له رمز */
    public function test_a_product_without_codes_still_arrives(): void
    {
        $this->product(['sku' => null, 'barcode' => null]);

        $this->actingAs($this->owner)->get(route('admin.customerInvoices.create'))
            ->assertInertia(fn ($p) => $p
                ->where('products.0.sku', null)
                ->where('products.0.barcode', null)
                ->etc());
    }

    /**
     * وكتالوجٌ صغيرٌ لا يُعلَن مقصوصًا.
     *
     * إنذارٌ يُرفع بلا سبب يُقرأ عطبًا، ثمّ يُتجاهَل يوم يصدق.
     */
    public function test_a_small_catalog_is_not_announced_as_cut(): void
    {
        $this->product();

        $this->actingAs($this->owner)->get(route('admin.customerInvoices.create'))
            ->assertInertia(fn ($p) => $p->where('catalog_truncated', false)->etc());
    }

    /**
     * وما قُصّ يُقال.
     *
     * الحدُّ خمسمئة، والمتجرُ الذي يتجاوزه يبحث في المتصفّح داخل ما وصل
     * وحده — فصنفٌ في مخزنه يقول عنه البحثُ «لا صنف بهذا الاسم».
     */
    public function test_a_cut_catalog_says_so(): void
    {
        $limit = (new \ReflectionClass(CustomerInvoiceController::class))
            ->getConstant('CATALOG_LIMIT');

        $rows = [];
        for ($i = 0; $i <= $limit; $i++) {
            $rows[] = [
                'business_id' => $this->business->id,
                'name' => 'صنف '.$i, 'price' => 1, 'cost' => 0, 'quantity' => 0,
            ];
        }
        Product::insert($rows);

        $this->actingAs($this->owner)->get(route('admin.customerInvoices.create'))
            ->assertInertia(fn ($p) => $p
                ->where('catalog_truncated', true)
                ->has('products', $limit)
                ->etc());
    }

    /* ==================== حرّاسٌ على الشاشة ==================== */

    /*
     * وسلوكُ النافذة نفسِها صار يُختبر في متصفّح.
     *
     * كانت هنا خمسةُ حرّاسٍ تقرأ ملفَّ الشاشة وتفتّش عن `ArrowDown` وعن
     * `onOpenChange` وعن نصّ القصّ — تمنع الحذف ولا تثبت السلوك. وقد نجت
     * تحتها مطفرةٌ لم أقدر على قتلها.
     *
     * فحلّ محلَّها `tests/js/catalog-window.test.tsx`: سبعةٌ وعشرون اختبارًا
     * تكتب في الحقل وتضغط السهم وEnter وتقرأ ما يظهر. وحارسان يقولان الشيء
     * نفسه يفترقان يومًا — فرُفع الأضعفُ منهما.
     *
     * وبقي هنا ما لا يبلغه المتصفّح: ما يُرسله الخادم إلى الشاشة.
     */

    private function screen(): string
    {
        return file_get_contents(base_path(self::SCREEN));
    }
}
