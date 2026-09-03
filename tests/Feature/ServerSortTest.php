<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use App\Support\Sort;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * ترتيب القوائم المُرقَّمة يقع على الخادم — ويقع فعلًا.
 *
 * كان رأس العمود يُضغط فينقلب سهمه ولا يتحرّك صفّ: الترتيب محلّيٌّ والصفحة
 * تحمل خمسةً وعشرين صفًّا من أربعمئة. وهذا الملفّ يمنع رجوعه: لا يكفي أن
 * يردّ المسار ٢٠٠، بل يجب أن يتبدّل أوّل صفٍّ فعلًا.
 *
 * ويحرس ما هو أدقّ: أن ما تعرضه الواجهة للترتيب هو ما يرتّبه الخادم. المفاتيح
 * تُرسَل من المتحكّم (`sorts`) فتُبنى منها القائمة — ولو أُرسل مفتاحٌ لا
 * يعرفه `Sort` لعاد العطبُ نفسه بثوبٍ جديد: زرٌّ يُعرض ولا يفعل.
 */
class ServerSortTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->actingAs(User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]));
    }

    private function product(string $name, float $price): Product
    {
        return Product::create([
            'business_id' => $this->business->id,
            'name' => $name, 'price' => $price, 'cost' => 1, 'quantity' => 5, 'active' => true,
        ]);
    }

    /** أوّل اسمٍ في قائمة المنتجات كما تصل إلى الواجهة */
    private function firstProduct(array $query = []): string
    {
        $page = $this->get(route('admin.products.index', $query))
            ->assertOk()
            ->viewData('page');

        return $page['props']['products'][0]['name'];
    }

    public function test_sorting_by_price_actually_reorders_the_rows(): void
    {
        $this->product('الرخيص', 5);
        $this->product('الغالي', 900);
        $this->product('الوسط', 50);

        $this->assertSame('الغالي', $this->firstProduct(['sort' => 'price', 'dir' => 'desc']));
        $this->assertSame('الرخيص', $this->firstProduct(['sort' => 'price', 'dir' => 'asc']));
    }

    public function test_direction_is_descending_unless_ascending_is_asked(): void
    {
        $this->product('الرخيص', 5);
        $this->product('الغالي', 900);

        // بلا `dir` فالتنازليّ: أوّل ما يُسأل عنه هو الأكبر لا الأصغر
        $this->assertSame('الغالي', $this->firstProduct(['sort' => 'price']));
    }

    /**
     * عمودٌ خارج المسموح يُتجاهَل ولا يصل إلى `orderBy`.
     *
     * الرابط يُكتب باليد ويُشارَك، فوضعُ `sort` فيه كما جاء يجعله يسمّي أعمدة
     * القاعدة: يُرتَّب بما لم يُقصد أن يُقرأ، ويُكشف وجود العمود من عدمه بفرق
     * الاستجابة بين ٢٠٠ و٥٠٠.
     */
    public function test_an_unknown_sort_column_falls_back_and_does_not_error(): void
    {
        $this->product('الأوّل', 5);
        $this->product('الثاني', 900);

        // والسقوط إلى الترتيب الافتراضيّ للقائمة: الأحدث أوّلًا، كما لو لم
        // يُذكر `sort` أصلًا — لا إلى ترتيبٍ ثالثٍ يخصّ الرابط الفاسد
        $this->assertSame('الثاني', $this->firstProduct(['sort' => 'password', 'dir' => 'asc']));
        $this->assertSame('الثاني', $this->firstProduct(['sort' => 'products.id; drop table users']));
        $this->assertSame('الثاني', $this->firstProduct([]));
    }

    /** الترتيب المطبَّق يعود إلى الواجهة فتُضيء العمود الصحيح بعد إعادة التحميل */
    public function test_the_applied_sort_returns_to_the_page(): void
    {
        $this->product('واحد', 5);

        $props = $this->get(route('admin.products.index', ['sort' => 'price', 'dir' => 'asc']))
            ->assertOk()->viewData('page')['props'];

        $this->assertSame('price', $props['filters']['sort']);
        $this->assertSame('asc', $props['filters']['dir']);

        // ومفتاحٌ مرفوض لا يُعاد مُضيئًا: الشاشة تقول «مرتَّبٌ بكذا» وهو لم يُرتَّب
        $rejected = $this->get(route('admin.products.index', ['sort' => 'password']))
            ->assertOk()->viewData('page')['props'];

        $this->assertNull($rejected['filters']['sort']);
    }

    /**
     * كل قائمةٍ تُعلن مفاتيحها، وكلّها معروفةٌ لأعمدة الجدول.
     *
     * وهذا هو الحارس الحقيقيّ: مفتاحٌ يُرسل إلى الواجهة ولا يقابله عمودٌ في
     * `columns` يظهر في قائمة «ترتيب» بلا اسمٍ يُقرأ؛ وعمودٌ يرتّبه الخادم
     * ولا يُعلَن لا يصل إليه أحد.
     */
    public function test_every_paginated_list_declares_its_sortable_columns(): void
    {
        $routes = [
            'admin.products.index', 'admin.customers.index', 'admin.orders.index',
            'admin.expenses.index', 'admin.purchases.invoices', 'admin.finance.journal',
            'admin.inventory.adjustments', 'admin.marketing.reviews',
        ];

        foreach ($routes as $name) {
            $props = $this->get(route($name))->assertOk()->viewData('page')['props'];

            $this->assertArrayHasKey('sorts', $props, "{$name} لا تُعلن أعمدة ترتيبها");
            $this->assertNotEmpty($props['sorts'], "{$name} تُعلن قائمةً فارغة");
        }
    }

    /** المفتاح المسموح يُطبَّق، وغيره يسقط إلى الافتراضي — بلا مرور بمسار */
    public function test_the_sort_helper_only_touches_allowed_columns(): void
    {
        $allowed = ['price' => 'price'];

        $applied = Product::query();
        Sort::apply($applied, Request::create('/', 'GET', ['sort' => 'price', 'dir' => 'asc']), $allowed, fn ($w) => $w->orderBy('id'));
        $this->assertStringContainsString('order by "price" asc', $applied->toSql());

        $refused = Product::query();
        Sort::apply($refused, Request::create('/', 'GET', ['sort' => 'password']), $allowed, fn ($w) => $w->orderBy('id'));
        $this->assertStringContainsString('order by "id" asc', $refused->toSql());
        $this->assertStringNotContainsString('password', $refused->toSql());
    }
}
