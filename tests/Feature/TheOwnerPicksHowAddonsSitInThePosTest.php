<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\WebsiteDomain;
use App\Support\Demo;
use App\Support\MarketingSettings;
use App\Support\PosAddonsLayout;
use App\Support\Storefront;
use App\Support\Website\Domain\CustomDomainProvider;
use App\Support\Website\Domain\DomainCheck;
use App\Support\Website\Domains;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * صاحبُ النشاط يختار كيف تُعرض الإضافاتُ في نقطة البيع — وزرُّ الموقع يفتح ما يُخدَم.
 *
 * ═══ الإضافات ═══
 *
 *  · تفضيلُ عرضٍ للنشاط كلِّه، في صفّ `settings` بمفتاح `pos_addons_layout`.
 *  · والغيابُ «شريط»: المتاجرُ القائمة لا تتغيّر شاشتُها بالنشر.
 *  · ولصاحب النشاط وحده (`Permissions::isOwner`) — والردُّ قبل أيّ كتابة.
 *  · ولا يمسّ الإضافاتِ نفسَها: لا سعرَ ولا نطاقَ ولا مخزون.
 *
 * ═══ زرُّ الموقع ═══
 *
 * كان يبني النطاقَ الفرعيّ دائمًا (`Storefront::url`) ولو كانت النطاقاتُ
 * الفرعيّة مطفأة — فيفتح بابًا لا يُجيب، والمتجرُ حيٌّ على `/s/{slug}`.
 * وصار يقرأ `Storefront::canonical` بمعرّف النشاط.
 */
class TheOwnerPicksHowAddonsSitInThePosTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Business $other;

    private User $otherOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create(['name' => 'ورد الخوير', 'type' => 'محل ورد', 'status' => 'نشط', 'site_slug' => 'ward']);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@layout.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $this->otherOwner = User::create([
            'business_id' => $this->other->id, 'name' => 'مالك الجار', 'email' => 'other@layout.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    /** الصندوقُ كما يفتحه صاحبُه — على جهازٍ مفعَّلٍ في الفرع */
    private function pos(): array
    {
        $this->actingAs($this->owner);
        $this->activatePosDevice($this->shop->id, Branch::where('business_id', $this->shop->id)->value('id'));

        return $this->get(route('pos.index'))->assertOk()->viewData('page')['props'];
    }

    private function layoutRow(Business $b): ?string
    {
        return Setting::where('business_id', $b->id)->where('key', PosAddonsLayout::KEY)->value('value');
    }

    private function save(User $as, mixed $layout)
    {
        return $this->actingAs($as)->putJson(route('admin.products.addons.display'), ['layout' => $layout]);
    }

    /* ═══════════════ الافتراضُ والحفظ ═══════════════ */

    /** ١ — متجرٌ قديمٌ بلا مفتاح: شريط، ولا صفَّ يُكتب له */
    public function test_a_shop_without_the_key_reads_bar(): void
    {
        $this->assertNull($this->layoutRow($this->shop));
        $this->assertSame(PosAddonsLayout::BAR, PosAddonsLayout::for($this->shop->id));

        $this->actingAs($this->owner)->get(route('admin.products.create'))
            ->assertInertia(fn (Assert $p) => $p->where('addonsDisplay.layout', 'bar')->where('addonsDisplay.can_change', true));

        // والقراءةُ لا تكتب شيئًا
        $this->assertNull($this->layoutRow($this->shop));
    }

    /** ٢ و٣ — صاحبُ النشاط يختار القسم، ثمّ يعود إلى الشريط */
    public function test_the_owner_switches_to_section_and_back(): void
    {
        $this->save($this->owner, 'section')->assertOk()->assertJson(['ok' => true, 'layout' => 'section']);
        $this->assertSame('section', $this->layoutRow($this->shop));

        $this->save($this->owner, 'bar')->assertOk()->assertJson(['layout' => 'bar']);
        $this->assertSame('bar', $this->layoutRow($this->shop));
        $this->assertSame(1, Setting::where('business_id', $this->shop->id)->where('key', PosAddonsLayout::KEY)->count());
    }

    public static function outsiders(): array
    {
        return [
            'موظّفٌ بصلاحية المنتجات' => [['role' => 'staff', 'permissions' => ['products']]],
            'مديرُ فرع' => [['role' => 'manager']],
            'مديرٌ ضُيّقت صلاحياته' => [['role' => 'admin', 'permissions' => ['products']]],
        ];
    }

    /** ٤ — غيرُ صاحب النشاط يُردّ بـ403 قبل أيّ كتابة */
    #[DataProvider('outsiders')]
    public function test_anyone_but_the_owner_is_refused_before_writing(array $who): void
    {
        $user = User::create(array_merge([
            'business_id' => $this->shop->id, 'name' => 'موظّف', 'email' => uniqid().'@layout.test',
            'password' => bcrypt('x'), 'status' => 'نشط',
        ], $who));

        $this->save($user, 'section')->assertForbidden();
        $this->assertNull($this->layoutRow($this->shop));

        // ولا يرى المفتاحَ في الشاشة
        $this->actingAs($user)->get(route('admin.products.create'))
            ->assertInertia(fn (Assert $p) => $p->where('addonsDisplay.can_change', false));
    }

    /** ٥ — متجرٌ لا يكتب لمتجرٍ آخر — ولا يُقرأ `business_id` من الطلب */
    public function test_one_shop_cannot_write_another_shops_setting(): void
    {
        $this->actingAs($this->otherOwner)
            ->putJson(route('admin.products.addons.display'), ['layout' => 'section', 'business_id' => $this->shop->id])
            ->assertOk();

        $this->assertNull($this->layoutRow($this->shop));
        $this->assertSame('section', $this->layoutRow($this->other));
    }

    /** ٦ — قيمةٌ غيرُ الاثنتين تُردّ ولا تُكتب */
    public function test_an_unknown_value_is_refused_and_nothing_is_written(): void
    {
        foreach (['grid', '', null, 'BAR', ['section']] as $bad) {
            $this->save($this->owner, $bad)->assertUnprocessable()->assertJsonValidationErrors('layout');
        }

        $this->assertNull($this->layoutRow($this->shop));
    }

    /** ٧ — شاشتا الإضافة والتعديل تقرآن القيمة الحاليّة */
    public function test_create_and_edit_read_the_current_value(): void
    {
        $product = Product::create(['business_id' => $this->shop->id, 'name' => 'باقة', 'price' => 10, 'cost' => 4, 'quantity' => 5, 'alert_qty' => 1]);
        $this->save($this->owner, 'section')->assertOk();

        $this->actingAs($this->owner)->get(route('admin.products.create'))
            ->assertInertia(fn (Assert $p) => $p->where('addonsDisplay.layout', 'section'));
        $this->actingAs($this->owner)->get(route('admin.products.edit', $product->id))
            ->assertInertia(fn (Assert $p) => $p->where('addonsDisplay.layout', 'section'));
    }

    /** وتبديلُ العرض لا يمسّ منتجًا ولا إضافة */
    public function test_switching_touches_no_product_and_no_addon(): void
    {
        $product = Product::create(['business_id' => $this->shop->id, 'name' => 'باقة', 'price' => 10, 'cost' => 4, 'quantity' => 5, 'alert_qty' => 1]);
        $addon = Addon::create(['business_id' => $this->shop->id, 'name' => 'تغليف', 'price' => 1.5, 'icon' => '🎀', 'active' => true]);
        $before = [$product->fresh()->getAttributes(), $addon->fresh()->getAttributes()];

        $this->save($this->owner, 'section')->assertOk();

        $this->assertEquals($before, [$product->fresh()->getAttributes(), $addon->fresh()->getAttributes()]);
    }

    /* ═══════════════ نقطة البيع ═══════════════ */

    /** الصندوقُ يقرأ الطريقة — والإضافاتُ كما هي في الوضعين */
    public function test_the_pos_reads_the_layout_and_the_same_addons(): void
    {
        Addon::create(['business_id' => $this->shop->id, 'name' => 'تغليف', 'price' => 1.5, 'icon' => '🎀', 'active' => true]);
        Addon::create(['business_id' => $this->shop->id, 'name' => 'موقوفة', 'price' => 2, 'icon' => '🎁', 'active' => false]);

        $bar = $this->pos();
        $this->assertSame('bar', $bar['addonsLayout']);

        $this->save($this->owner, 'section')->assertOk();
        $section = $this->pos();

        $this->assertSame('section', $section['addonsLayout']);
        // والإضافاتُ نفسُها — والموقوفةُ ترشّحها الشاشة كما اليوم
        $this->assertSame($bar['addons'], $section['addons']);
    }

    /** وقيمةٌ فاسدةٌ مكتوبةٌ بيدٍ تُقرأ شريطًا — لا تكسر الصندوق */
    public function test_a_corrupt_stored_value_reads_bar(): void
    {
        Setting::create(['business_id' => $this->shop->id, 'key' => PosAddonsLayout::KEY, 'value' => 'grid']);

        $this->assertSame('bar', $this->pos()['addonsLayout']);
    }

    /** ١٢ — قسمٌ حقيقيٌّ اسمُه «الإضافات» يبقى قسمَ منتجات */
    public function test_a_real_category_named_addons_keeps_its_own_value(): void
    {
        Category::create(['business_id' => $this->shop->id, 'name' => 'الإضافات']);
        $this->save($this->owner, 'section')->assertOk();

        $cats = collect($this->pos()['categories']);

        // قيمتُه اسمُه كما كان — وتبويبُ الإضافات بقيمته الداخليّة `__addons__` لا يُرسَل من هنا
        $this->assertTrue($cats->contains('value', 'الإضافات'));
        $this->assertFalse($cats->contains('value', '__addons__'));
    }

    /* ═══════════════ زرُّ الموقع ═══════════════ */

    private function publish(): void
    {
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1', 'site_on' => '1', 'site_domain' => 'old-site.om']);
        $this->actingAs($this->owner);
    }

    /** A — منشورٌ والنطاقاتُ الفرعيّة مطفأة: `/s/{slug}` لا النطاقُ الفرعيّ */
    public function test_a_published_shop_without_subdomains_opens_the_served_path(): void
    {
        config(['storefront.subdomains' => false, 'storefront.custom_domains' => false]);
        $this->publish();

        $this->assertSame(url('/s/ward'), Demo::websiteUrl());
        $this->assertNotSame('https://ward.'.Storefront::domain(), Demo::websiteUrl());
    }

    /** B — والنطاقاتُ الفرعيّة مفعّلة: نطاقُ المنصّة الفرعيّ */
    public function test_a_published_shop_with_subdomains_opens_the_subdomain(): void
    {
        config(['storefront.subdomains' => true, 'storefront.custom_domains' => false]);
        $this->publish();

        $this->assertSame('https://ward.'.Storefront::domain(), Demo::websiteUrl());
    }

    /** C — ونطاقُ التاجر النشطُ المخدوم يتقدّمهما */
    public function test_an_active_served_custom_domain_comes_first(): void
    {
        config(['storefront.subdomains' => true, 'storefront.custom_domains' => true]);
        $this->app->bind(CustomDomainProvider::class, fn () => new class implements CustomDomainProvider
        {
            public function name(): string
            {
                return 'fake';
            }

            public function instructions(WebsiteDomain $domain): array
            {
                return [];
            }

            public function register(WebsiteDomain $domain): ?string
            {
                return 'p-1';
            }

            public function check(WebsiteDomain $domain): DomainCheck
            {
                return DomainCheck::active();
            }

            public function forget(WebsiteDomain $domain): void {}
        });
        Domains::attach($this->shop, 'wardshop.om');
        Domains::check(Domains::custom((int) $this->shop->id));
        $this->publish();

        $this->assertSame('https://wardshop.om', Demo::websiteUrl());
    }

    /** D و E — غيرُ المنشور يبقى على رابطه الخارجيّ، مكمَّلًا بـhttps */
    public function test_an_unpublished_shop_keeps_its_normalized_external_link(): void
    {
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '0', 'site_on' => '1', 'site_domain' => 'old-site.om']);
        $this->actingAs($this->owner);

        $this->assertSame('https://old-site.om', Demo::websiteUrl());
    }

    /** F — و`javascript:` تبقى مرفوضة */
    public function test_an_unsafe_external_value_stays_refused(): void
    {
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '0', 'site_on' => '1', 'site_domain' => 'javascript:alert(1)']);
        $this->actingAs($this->owner);

        $this->assertNull(Demo::websiteUrl());
    }

    /** والترويسةُ تحمل الرابطَ نفسَه — لا تبني عنوانًا بنفسها */
    public function test_the_topbar_context_carries_the_same_url(): void
    {
        config(['storefront.subdomains' => false, 'storefront.custom_domains' => false]);
        $this->publish();

        $this->get(route('admin.products.create'))
            ->assertInertia(fn (Assert $p) => $p->where('context.website', url('/s/ward')));
    }
}
