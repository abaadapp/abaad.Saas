<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use App\Support\Demo;
use App\Support\TillCatalog;
use App\Support\TillStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * ‏‏صفحةُ الصندوق لا تحمل الكتالوج — يقرؤه الجهاز ممّا حفظ، وكميّاتُ الفرع معها.
 *
 * ═══ ما كان ═══
 *
 * كلُّ فتحٍ للصندوق يحمل الأصنافَ كلَّها: عند عشرة آلاف صنف 5.3MB يبنيها الخادمُ
 * في 860ms. ولم يتغيّر منها شيء.
 *
 * ═══ ما صار ═══
 *
 * المفتاحُ مشعَل (`pos.catalog_on_device`): الصفحةُ تحمل مفتاحَ نسخة الجهاز
 * (`catalog.scope`) وكميّاتِ الفرع الآن (`stock`)، والأصنافُ تُطلب بعينها إن
 * احتيج إليها (`products` — الطريقُ القديم). ومطفأٌ: الصفحةُ كما كانت حرفًا.
 */
class TheTillPageLeavesItsCatalogToTheDeviceTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $muscat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل الورد', 'status' => 'نشط']);
        $this->muscat = Branch::create(['business_id' => $this->business->id, 'name' => 'مسقط']);
        $owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        foreach ([3, 0, 12] as $k => $qty) {
            $p = Product::create(['business_id' => $this->business->id, 'name' => "صنف $k", 'price' => 2, 'quantity' => 40, 'alert_qty' => 5, 'active' => true]);
            BranchStock::create(['business_id' => $this->business->id, 'branch_id' => $this->muscat->id, 'product_id' => $p->id, 'quantity' => $qty]);
        }

        $this->actingAs($owner);
        $this->activatePosDevice($this->business->id, $this->muscat->id);
        session(['current_branch' => $this->muscat->id]);
    }

    public function test_the_page_carries_the_catalogs_key_and_the_branch_stock_not_the_products(): void
    {
        $this->get(route('pos.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Pos/Index')
            ->missing('products')
            ->where('catalog.scope', TillCatalog::scope($this->business->id, $this->muscat->id, 'ar'))
            ->where('stock.full', true)
            ->where('stock.scope', TillStock::scope($this->business->id, $this->muscat->id))
            ->has('stock.products', 3)
            // ‏‏رصيدُ الفرع — لا مجموعُ الشركة ولا كميّةُ يومِ حُفظ الكتالوج
            ->where('stock.products.0.qty', 3)
            ->where('stock.products.1.stock_status', 'نفد المخزون')
            ->has('stock.h')
            ->has('stock.updated_at'));
    }

    /** ‏‏والأصنافُ تُطلب بعينها — حين تعذّر الكتالوجُ وليس في الجهاز شيء — كما كانت */
    public function test_the_products_are_still_there_when_asked_for(): void
    {
        $version = $this->get(route('pos.index'))->assertOk()->viewData('page')['version'];

        $reload = $this->get(route('pos.index'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'Pos/Index',
            'X-Inertia-Partial-Data' => 'products',
        ])->assertOk()->json('props');

        $this->assertSame(self::asSent(Demo::tillProducts($this->muscat->id)), $reload['products']);
        $this->assertArrayNotHasKey('stock', $reload, 'إعادةُ الأصناف لا تحمل غيرَها');
    }

    /** ‏‏كما تصل الصفحةَ: JSON، فالعددُ الصحيحُ عددٌ صحيح */
    private static function asSent(array $rows): array
    {
        return json_decode(json_encode($rows), true);
    }

    /** ‏‏مفتاحُ الرجوع: مطفأٌ، الصفحةُ تحمل الأصنافَ كلَّها كما كانت، ولا كتالوجَ ولا لقطة */
    public function test_switched_off_the_page_is_what_it_was(): void
    {
        config(['pos.catalog_on_device' => false]);

        $this->get(route('pos.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('products', self::asSent(Demo::tillProducts($this->muscat->id)))
            ->where('catalog', null)
            ->where('stock', null)
            ->where('stockScope', TillStock::scope($this->business->id, $this->muscat->id)));
    }

    /** ‏‏ومفتاحُ نسخة الجهاز يتبع الجلسة: فرعٌ آخر ولغةٌ أخرى نسخةٌ أخرى */
    public function test_the_catalog_key_follows_the_session(): void
    {
        $salalah = Branch::create(['business_id' => $this->business->id, 'name' => 'صلالة']);
        $this->activatePosDevice($this->business->id, $salalah->id);
        session(['current_branch' => $salalah->id, 'locale' => 'en']);

        $this->get(route('pos.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('catalog.scope', TillCatalog::scope($this->business->id, $salalah->id, 'en')));
    }
}
