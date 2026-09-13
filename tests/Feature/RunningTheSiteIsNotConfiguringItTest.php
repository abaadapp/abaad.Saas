<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use App\Models\Website;
use App\Support\Permissions;
use App\Support\Website\Blueprints;
use App\Support\Website\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * تشغيلُ الموقع ليس ضبطَه — ولا يُمنحان بمفتاحٍ واحد.
 *
 * ═══ ما كان ═══
 *
 * قسمُ `website` واحدٌ يفتح كلَّ شيء. فمن مُنح «الموقع الإلكتروني» ليتفقّد
 * منتجاتِه الظاهرة كلَّ صباح مُنح معه: تبديلَ قالب الموقع، وحذفَ صفحاته،
 * وتحويلَ نطاقه، ونشرَ ما في المسوّدة على زبائنه.
 *
 * وأثقلُها النطاق: حرفٌ يُبدَّل فيه يُطفئ المتجر على كلّ من يفتح رابطه، ولا
 * يكتشفه صاحبُه إلّا من زبونٍ يتّصل. وهذه صلاحيةٌ لا تُعطى لمن وظيفتُه أن
 * يكتب كمّيةً في خانة.
 *
 * ═══ ما صار ═══
 *
 * القسمُ للتشغيل — لوحةُ الموقع ومنتجاتُه — وفعلُ `website.configure` للضبط.
 * ويرثه `admin` و`manager` فلا يفقد أحدٌ اليومَ بابًا كان يفتحه أمس، ويُمنح
 * لمن سواهما باسمه.
 *
 * والحراسةُ في المسار لا في الشاشة: إخفاءُ زرٍّ ليس حراسة، والعنوانُ يُكتب
 * بالید.
 */
class RunningTheSiteIsNotConfiguringItTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'ورود مسقط', 'type' => 'محل ورود', 'status' => 'نشط',
        ]);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        Builder::create($this->business, Blueprints::STORE, 'modern', $this->owner->id);
    }

    /** موظّفٌ مُنح التشغيل وحده — بصلاحياتٍ مكتوبةٍ بيدها لا موروثةٍ بدور */
    private function operator(array $permissions): User
    {
        return User::create([
            'business_id' => $this->business->id, 'name' => 'موظّف',
            'email' => 'staff'.count($permissions).rand(1, 9999).'@abaad.om',
            'password' => bcrypt('password'), 'role' => 'staff', 'status' => 'نشط',
            'permissions' => $permissions,
        ]);
    }

    /* ==================== التشغيل مفتوحٌ لمن مُنح القسم ==================== */

    public function test_an_operator_opens_the_hub(): void
    {
        $this->actingAs($this->operator(['website']));

        $this->assertSame(
            'Admin/Website/Hub',
            $this->get(route('admin.website.index'))->assertOk()->viewData('page')['component'],
        );
    }

    /** ويرى منتجاتِ موقعه وحالَها — وهي منتجات أبعاد نفسُها */
    public function test_the_hub_lists_the_shops_own_products(): void
    {
        Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد',
            'price' => 12.5, 'active' => true, 'published' => true, 'quantity' => 4,
        ]);

        $this->actingAs($this->operator(['website']));

        $props = $this->get(route('admin.website.index'))->assertOk()->viewData('page')['props'];

        $this->assertSame('باقة ورد', $props['products'][0]['name']);
        $this->assertTrue($props['products'][0]['in_stock']);
        $this->assertSame(1, $props['counts']['shown']);
    }

    /* ==================== والضبطُ مغلقٌ دونه ==================== */

    /** @return array<int, array{0: string}> */
    public static function configScreens(): array
    {
        return [
            ['admin.website.site'],
            ['admin.website.design'],
            ['admin.website.pages'],
            ['admin.website.shop'],
            ['admin.website.seo'],
            ['admin.website.domain'],
            ['admin.website.editor'],
        ];
    }

    #[DataProvider('configScreens')]
    public function test_an_operator_cannot_reach_a_configuration_screen(string $name): void
    {
        $this->actingAs($this->operator(['website']));

        $this->get(route($name))->assertForbidden();
    }

    /** ولا يكتب: المنعُ على الفعل لا على الشاشة وحدها */
    public function test_an_operator_cannot_write_configuration(): void
    {
        $this->actingAs($this->operator(['website']));

        $this->put(route('admin.website.design.update'), ['template' => 'bold'])->assertForbidden();
        $this->post(route('admin.website.publish'))->assertForbidden();
        $this->put(route('admin.website.domain.save'), ['host' => 'x.om'])->assertForbidden();
    }

    /* ==================== ومن مُنح الفعل يفتحها ==================== */

    public function test_an_operator_granted_the_action_configures(): void
    {
        $this->actingAs($this->operator(['website', Permissions::WEBSITE_CONFIGURE]));

        $this->get(route('admin.website.site'))->assertOk();
        $this->get(route('admin.website.design'))->assertRedirect();
    }

    /**
     * وصاحبُ المتجر ومديرُه يرثانه — فلا يُقفل بابٌ كان مفتوحًا أمس.
     *
     * وهذا شرطُ إضافةِ فعلٍ إلى نظامٍ يعمل: الوراثةُ بالدور تُبقي الحال على
     * ما هو، والمنعُ يقع على من لم يكن يملكه أصلًا.
     */
    public function test_the_owner_and_the_manager_inherit_it(): void
    {
        $this->actingAs($this->owner);
        $this->get(route('admin.website.site'))->assertOk();

        $manager = User::create([
            'business_id' => $this->business->id, 'name' => 'مدير', 'email' => 'm@abaad.om',
            'password' => bcrypt('password'), 'role' => 'manager', 'status' => 'نشط',
        ]);

        $this->actingAs($manager);
        $this->get(route('admin.website.site'))->assertOk();
    }

    /** ومن لا موقع له ولا يملك ضبطَه لا يُعرض عليه إنشاؤه */
    public function test_an_operator_is_not_offered_a_site_he_cannot_create(): void
    {
        Website::query()->delete();

        $this->actingAs($this->operator(['website']));

        $this->get(route('admin.website.index'))->assertForbidden();
    }
}
