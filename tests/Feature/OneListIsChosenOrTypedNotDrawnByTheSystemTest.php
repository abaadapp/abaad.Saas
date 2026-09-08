<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\FixedAsset;
use App\Models\User;
use App\Support\AssetCategories;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * قائمةُ اختيارٍ واحدة — تُرسم في الصفحة، ولا تحدُّ من يكتب.
 *
 * ═══ ما كان ═══
 *
 * حقلان في النظام يقولان الشيء نفسه بأداةٍ واحدة عاطلة: وحدةُ الشراء
 * وتصنيفُ الأصل، وكلاهما `datalist` يرسمها نظامُ التشغيل — نافذةٌ داكنةٌ
 * ضيّقة لا سهمَ فيها يقول إنّها هناك. أُصلح الأوّلُ وبقي الثاني على عطبه،
 * وحقلان يقولان الشيء نفسه يفترقان يومًا.
 *
 * ═══ وما صار ═══
 *
 * `Components/ComboBox` واحدٌ يخدمهما، وقائمتاهما تُقرآن ممّا استُعمل فعلًا
 * — `PurchaseUnits` و`AssetCategories` — مذيَّلتين بمقترَحاتٍ لمن لم يبدأ بعد.
 */
class OneListIsChosenOrTypedNotDrawnByTheSystemTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
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

    private function asset(string $category, int $times = 1, ?int $businessId = null): void
    {
        for ($i = 0; $i < $times; $i++) {
            FixedAsset::create([
                'business_id' => $businessId ?? $this->business->id,
                'name' => 'ثلاجة '.$category.$i, 'category' => $category,
                'purchased_at' => now()->toDateString(), 'cost' => 100,
                'salvage_value' => 0, 'life_months' => 12, 'accumulated' => 0, 'status' => 'نشط',
            ]);
        }
    }

    /* ==================== تصنيفاتُ الأصول ==================== */

    public function test_a_shop_with_no_assets_gets_the_suggestions(): void
    {
        $this->assertSame(AssetCategories::SUGGESTED, AssetCategories::forBusiness($this->business->id));
    }

    public function test_what_the_shop_classifies_with_most_comes_first(): void
    {
        // ‏و«أثاث» تسبق «مركبات» أبجديًّا — فترتيبُها بعدها لا يقع إلّا بالعدّ
        $this->asset('أثاث', 1);
        $this->asset('مركبات', 3);

        $this->assertSame(['مركبات', 'أثاث'], array_slice(AssetCategories::forBusiness($this->business->id), 0, 2));
    }

    public function test_a_category_the_shop_invented_comes_back_next_time(): void
    {
        $this->asset('لوحات إعلانية');

        $this->assertContains('لوحات إعلانية', AssetCategories::forBusiness($this->business->id));
    }

    public function test_another_shops_categories_do_not_leak(): void
    {
        $other = Business::create(['name' => 'متجر آخر', 'type' => 'عام', 'status' => 'نشط']);
        $this->asset('قوارب', 1, $other->id);

        $this->assertNotContains('قوارب', AssetCategories::forBusiness($this->business->id));
        $this->assertContains('قوارب', AssetCategories::forBusiness($other->id));
    }

    public function test_no_category_is_listed_twice(): void
    {
        $this->asset('أثاث', 2);

        $categories = AssetCategories::forBusiness($this->business->id);

        $this->assertSame(array_values(array_unique($categories)), $categories);
    }

    public function test_the_assets_screen_is_given_the_categories_it_offers(): void
    {
        $this->asset('لوحات إعلانية', 2);

        $this->actingAs($this->owner)->get(route('admin.finance.assets'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('categories.0', 'لوحات إعلانية')
                ->where('categories', fn ($c) => collect($c)->contains('أجهزة'))
                ->etc()
            );
    }

    /* ==================== والرفعُ من القائمة ==================== */

    public function test_a_category_the_shop_removed_leaves_its_list(): void
    {
        $this->actingAs($this->owner)
            ->delete(route('admin.finance.assets.categories.destroy'), ['category' => 'مركبات'])
            ->assertRedirect();

        $this->assertNotContains('مركبات', AssetCategories::forBusiness($this->business->id));
        $this->assertContains('أجهزة', AssetCategories::forBusiness($this->business->id));
    }

    public function test_removing_a_category_does_not_touch_an_asset_filed_under_it(): void
    {
        $this->asset('مركبات');

        $this->actingAs($this->owner)
            ->delete(route('admin.finance.assets.categories.destroy'), ['category' => 'مركبات'])
            ->assertRedirect();

        // ‏أصلٌ سُجّل «مركبةً» يبقى كذلك في بطاقته وفي تقاريره
        $this->assertSame('مركبات', FixedAsset::latest('id')->firstOrFail()->category);
        $this->assertNotContains('مركبات', AssetCategories::forBusiness($this->business->id));
    }

    public function test_a_removed_category_comes_back_when_it_is_used_again(): void
    {
        AssetCategories::hide($this->business->id, 'مركبات');

        $this->actingAs($this->owner)->post(route('admin.finance.assets.store'), [
            'name' => 'سيارة توصيل', 'category' => 'مركبات',
            'purchased_at' => now()->toDateString(), 'cost' => 1000,
            'salvage_value' => 0, 'life_months' => 60,
        ])->assertRedirect();

        $this->assertContains('مركبات', AssetCategories::forBusiness($this->business->id));
    }

    public function test_an_empty_category_is_not_a_removal(): void
    {
        $this->actingAs($this->owner)
            ->delete(route('admin.finance.assets.categories.destroy'), ['category' => '  '])
            ->assertSessionHasErrors('category');

        $this->assertSame([], AssetCategories::hidden($this->business->id));
    }

    public function test_one_shop_does_not_empty_anothers_list(): void
    {
        $other = Business::create(['name' => 'متجر آخر', 'type' => 'عام', 'status' => 'نشط']);

        $this->actingAs($this->owner)
            ->delete(route('admin.finance.assets.categories.destroy'), ['category' => 'مركبات'])
            ->assertRedirect();

        $this->assertContains('مركبات', AssetCategories::forBusiness($other->id));
    }

    /* ==================== والأداةُ واحدة ==================== */

    public function test_no_screen_leans_on_the_list_the_system_draws(): void
    {
        /*
         * حارسُ مصدرٍ يمسح الواجهة كلَّها لا شاشةً بعينها: القائمةُ الأصليّة
         * تعود بسطرٍ واحدٍ في أيّ شاشةٍ جديدة، ولا يُكتشف ذلك إلّا حين يشتكي
         * تاجرٌ أنّ حقلًا «لا يفتح شيئًا».
         */
        $found = [];
        foreach ($this->screens() as $file) {
            if (str_contains((string) file_get_contents($file), '<datalist')) {
                $found[] = str_replace(resource_path('js').'/', '', $file);
            }
        }

        $this->assertSame([], $found, 'شاشاتٌ ما زالت تتّكئ على قائمة نظام التشغيل: '.implode('، ', $found));
    }

    public function test_the_two_fields_lean_on_the_one_widget(): void
    {
        foreach ([
            'js/Pages/Admin/Purchases/Create.tsx',
            'js/Pages/Admin/Finance/Assets.tsx',
        ] as $screen) {
            $this->assertStringContainsString(
                '<ComboBox',
                (string) file_get_contents(resource_path($screen)),
                "«{$screen}» لا تستعمل المنتقي المشترك",
            );
        }
    }

    public function test_and_both_of_them_can_remove(): void
    {
        /*
         * وزرُّ الرفع موصولٌ ببابه في الحقلين — لا مقبضًا يُدير حالةً في
         * المتصفّح وحده. و`onDelete` مطلوبةٌ في المنتقي: فرعٌ اختياريٌّ لا
         * يقرؤه أحدٌ يُرفع.
         */
        foreach ([
            'js/Pages/Admin/Purchases/Create.tsx' => "route('admin.purchases.units.destroy')",
            'js/Pages/Admin/Finance/Assets.tsx' => "route('admin.finance.assets.categories.destroy')",
        ] as $screen => $door) {
            $this->assertStringContainsString($door, (string) file_get_contents(resource_path($screen)));
        }

        $widget = (string) file_get_contents(resource_path('js/Components/ComboBox.tsx'));

        $this->assertStringContainsString('onDelete: (value: string) => void;', $widget, 'الرفعُ ما زال اختياريًّا');
        $this->assertStringNotContainsString('onDelete?:', $widget);
    }

    /** @return list<string> */
    private function screens(): array
    {
        $out = [];
        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js')));

        foreach ($walk as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.tsx')) {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }
}
