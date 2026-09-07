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

    /**
     * والبحثُ يقرأ الرمزَ والباركود لا الاسمَ وحده.
     *
     * لا مُشغِّل اختباراتٍ للواجهة في هذا المستودع، فما لا يُشغَّل إلّا في
     * المتصفّح يُحرَس على مصدره: حارسٌ ضعيف، وهو خيرٌ من لا حارس. ونقطةُ
     * الأثر تحرس نفسها.
     */
    public function test_the_search_reads_the_codes_too(): void
    {
        $source = $this->screen();

        $this->assertStringContainsString('fold(p.sku ?? \'\').includes(needle)', $source);
        $this->assertStringContainsString('fold(p.barcode ?? \'\').includes(needle)', $source);
    }

    /**
     * والمطابقةُ تُهمل الهمزةَ والشدّةَ وشكلَ الرقم.
     *
     * `fold` هي «النصُّ كما يُقارَن به» في هذا النظام، ومقارنةٌ حرفًا بحرف
     * تردّ «لا نتائج» على اسمٍ مكتوبٍ في القائمة أمام عين صاحبه.
     */
    public function test_the_match_is_blind_to_hamza_and_to_the_shape_of_a_digit(): void
    {
        $this->assertStringContainsString("import { fold } from '@/lib/pages';", $this->screen());

        $fold = file_get_contents(base_path('resources/js/lib/pages.ts'));
        // الأرقام العربية والفارسية تُردّ إلى شكلٍ واحد قبل المقارنة
        $this->assertStringContainsString('\\u0660-\\u0669', $fold);
        $this->assertStringContainsString('\\u06F0-\\u06F9', $fold);
        $this->assertStringContainsString("'٧': '7'", $fold);
    }

    /** ولوحةُ المفاتيح تكفي: سهمان وEnter — وقارئُ الباركود يضغط Enter */
    public function test_the_keyboard_alone_can_pick_a_line(): void
    {
        $source = $this->screen();

        $this->assertStringContainsString("e.key === 'ArrowDown'", $source);
        $this->assertStringContainsString("e.key === 'ArrowUp'", $source);
        $this->assertStringContainsString("e.key === 'Enter'", $source);
    }

    /**
     * والنافذةُ لا تُغلق عند أوّل اختيار.
     *
     * `take` تُضيف وتُفرّغ البحثَ وتعيد المؤشّر — ولا تنادي `onOpenChange`.
     * ويومَ يُعاد الإغلاقُ إليها يسقط هذا.
     */
    public function test_the_window_stays_open_for_the_next_line(): void
    {
        $source = $this->screen();
        $start = strpos($source, 'const take = (p: ProductRow) => {');
        $this->assertNotFalse($start);
        $body = substr($source, $start, strpos($source, '};', $start) - $start);

        $this->assertStringContainsString('onPick(p)', $body);
        $this->assertStringContainsString('setQ(\'\')', $body);
        $this->assertStringNotContainsString('onOpenChange(false)', $body);
    }

    /** وقائمةٌ قُصّت تقول إنّها قُصّت — ومن رأى آخرَ صفٍّ لا يظنّه آخرَ المخزن */
    public function test_the_list_confesses_when_it_is_cut(): void
    {
        $this->assertStringContainsString('عُرض :n من :m — ضيّق البحث', $this->screen());
    }

    private function screen(): string
    {
        return file_get_contents(base_path(self::SCREEN));
    }
}
