<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Store\StoreContent;
use App\Support\Store\StorePage;
use App\Support\Store\ThemePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * القسمُ المطفأ يحفظ موضعَه — ولو أُغلق المحرّرُ وفُتح بعدها.
 *
 * ═══ ما كان ═══
 *
 * `store_sections` قائمةُ المشغَّل بترتيبه، والمطفأُ ليس فيها. فإذا أطفأ صاحبُ
 * المتجر قسمًا وحفظ ثمّ أغلق المحرّر، ضاع موضعُه: يُفتح المحرّرُ فيجده بعد
 * الأقسام المشغَّلة كلِّها، ويشغّله فيقع في آخرها. (#139 حفظ الموضعَ في
 * ذاكرة الشاشة وحدها، فيضيع بإعادة التحميل.)
 *
 * ═══ ما صار ═══
 *
 * المطفأُ يبقى في القائمة نفسِها بعلامة `!` في موضعه: `cats,!best,new,about`.
 * والقارئُ القديم (`StorePage::order` — الواجهةُ والمعاينة) يتجاهل ما لا يعرفه
 * أصلًا، فلا يرى الزائرُ المطفأ، وقائمةٌ قديمةٌ بلا علامة تُقرأ كما كانت.
 *
 * ═══ ومثالُ المالك ═══
 *
 * «الهدايا، العطور، الفضة، الورد» — وفي القالب أقسامٌ بأسمائها:
 * `cats` (تسوّق حسب الفئة) ثمّ `best` ثمّ `new` ثمّ `about`. يُطفأ الثاني
 * فيُحفظ ويُغلق المحرّر ويُفتح ويُشغَّل — فيعود ثانيًا لا أخيرًا.
 */
class AHiddenHomeSectionKeepsItsPlaceTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    /** الترتيبُ بعد أن رتّبه — وما لم يُذكر (`banner` و`block` و`reviews`) مطفأ */
    private const ARRANGED = ['about', 'cats', 'best', 'new'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        [$this->shop, $this->owner] = $this->openShop('ribbon', 'owner@ribbon.test');
    }

    /** @return array{0: Business, 1: User} */
    private function openShop(string $slug, string $email): array
    {
        $shop = Business::create([
            'name' => 'ريبون '.$slug, 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => $slug, 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        Branch::create(['business_id' => $shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        MarketingSettings::save($shop->id, 'website', ['store_on' => '1']);

        $owner = User::create([
            'business_id' => $shop->id, 'name' => 'المالك', 'email' => $email,
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $cat = Category::create(['business_id' => $shop->id, 'name' => 'باقات']);
        Product::create([
            'business_id' => $shop->id, 'name' => 'باقة ورد', 'price' => 12, 'category_id' => $cat->id,
            'cost' => 4, 'quantity' => 10, 'active' => true, 'published' => true,
        ]);

        return [$shop, $owner];
    }

    private function save(array $payload, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->from(route('admin.website.editor'))
            ->post(route('admin.marketing.store.save'), $payload);
    }

    /** @return array<string, mixed> خصائصُ المحرّر كما تصل الشاشة — «فتحُ المحرّر» */
    private function editor(?User $as = null): array
    {
        return $this->actingAs($as ?? $this->owner)->get(route('admin.website.editor'))->assertOk()->viewData('page')['props'];
    }

    /** @return list<string> أقسامُ الرئيسية في صفوف المحرّر بترتيبها — المطفأُ بعلامته */
    private function rowsOf(array $props): array
    {
        return array_values(array_map(
            fn ($r) => ($r['on'] ? '' : '!').$r['key'],
            array_filter($props['rows'], fn ($r) => in_array($r['key'], StorePage::SECTIONS, true)),
        ));
    }

    /** @return list<string> أقسامُ الرئيسية كما يراها الزائر */
    private function visitorSees(string $slug = 'ribbon'): array
    {
        auth()->logout();

        return $this->get('/s/'.$slug)->assertOk()->viewData('sections');
    }

    private function publish(): void
    {
        $this->actingAs($this->owner)->from(route('admin.website.editor'))
            ->post(route('admin.website.store.publish'))->assertRedirect()->assertSessionHasNoErrors();
    }

    /* ═════════════ التسلسلُ كلُّه ═════════════ */

    public function test_a_hidden_section_comes_back_to_its_place_after_the_editor_is_closed_and_reopened(): void
    {
        ThemePublisher::enable($this->shop, (int) $this->owner->id);

        // ١. إعادةُ الترتيب — «من نحن» أوّلًا
        $this->save(['store_sections' => implode(',', self::ARRANGED)])->assertSessionHasNoErrors();

        // ٢–٣. يُطفأ «تسوّق حسب الفئة» (الثاني) ويُحفظ — بموضعه في القائمة
        $this->save(['store_sections' => 'about,!cats,best,new'])->assertSessionHasNoErrors();

        // ٤–٥. يُغلق المحرّرُ ويُفتح: المطفأُ ثانيًا في الصفوف، والقائمةُ كاملةً بعلاماتها
        $reopened = $this->editor();
        $this->assertSame(
            ['about', '!cats', 'best', 'new', '!banner', '!block', '!reviews'],
            $this->rowsOf($reopened),
            'وقع القسمُ المطفأ بعد المشغَّلة حين أُعيد فتحُ المحرّر',
        );
        $this->assertSame(['about', '!cats', 'best', 'new', '!banner', '!block', '!reviews'], $reopened['layout']);
        $this->assertSame(['about', 'best', 'new'], $reopened['order'], 'المشغَّلُ وحده بترتيبه — كما كان');

        // والموقعُ العامّ لم يتغيّر قبل النشر
        $this->assertSame(StorePage::DEFAULT_ORDER, $this->visitorSees());

        // ٦–٧. يُشغَّل ثانيةً ويُحفظ — الشاشةُ ترسل القائمةَ كما فُتحت بعد قلب علامته
        $this->save(['store_sections' => 'about,cats,best,new,!banner,!block,!reviews'])->assertSessionHasNoErrors();
        $this->assertSame(['about', 'cats', 'best', 'new'], $this->editor()['order'], 'لم يعد القسمُ إلى موضعه');

        // ٨–٩. يُنشر ويُحدَّث الموقع: الترتيبُ الجديد كما رتّبه، والقسمُ في موضعه
        $this->publish();
        $this->assertSame(self::ARRANGED, $this->visitorSees());
    }

    public function test_the_visitor_never_reads_a_hidden_marker(): void
    {
        // متجرٌ بلا مسوّدات: الحفظُ نشر
        $this->save(['store_sections' => 'cats,!best,new'])->assertSessionHasNoErrors();

        $this->assertSame(['cats', 'new'], StorePage::order($this->shop->id));
        $this->assertFalse(StorePage::shows($this->shop->id, 'best'));
        $this->assertSame(['cats', 'new'], $this->visitorSees());
    }

    /* ═════════════ وما كان يبقى كما كان ═════════════ */

    public function test_a_list_saved_before_the_marker_reads_as_it_always_did(): void
    {
        // كما يُحفظ اليوم في متاجر قائمة: المشغَّلُ وحده
        MarketingSettings::save($this->shop->id, 'website', ['store_sections' => 'best,cats,new']);

        $props = $this->editor();
        $this->assertSame(['best', 'cats', 'new'], $props['order']);
        $this->assertSame(
            ['best', 'cats', 'new', '!banner', '!block', '!about', '!reviews'],
            $this->rowsOf($props),
            'تبدّلت صفوفُ قائمةٍ قديمة — المطفأُ بعد المشغَّل بترتيبه الأصليّ كما كان',
        );
        $this->assertSame(['best', 'cats', 'new'], $this->visitorSees());

        // والفارغُ «ما كان»: الأقسامُ السبعة مشغَّلةً بترتيبها
        MarketingSettings::save($this->shop->id, 'website', ['store_sections' => '']);
        $this->assertSame(StorePage::DEFAULT_ORDER, $this->rowsOf($this->editor()));
    }

    public function test_a_list_with_every_section_hidden_reads_as_empty_and_shows_them_all(): void
    {
        // كالفراغ في `order` — لا صفحةَ عاريةً بلا قسم، ولا صفوفٌ تقول غيرَ ما يُرى
        MarketingSettings::save($this->shop->id, 'website', ['store_sections' => '!cats,!best']);

        $this->assertSame(StorePage::DEFAULT_ORDER, StorePage::order($this->shop->id));
        $this->assertSame(StorePage::DEFAULT_ORDER, $this->rowsOf($this->editor()));
    }

    public function test_unknown_repeated_and_unallowed_tokens_are_ignored(): void
    {
        // «اختيارات RIBBON» ليست لهذا المتجر — فلا تُضمّ ولو حُفظت مطفأةً أو مشغَّلة
        MarketingSettings::save($this->shop->id, 'website', ['store_sections' => '!evil,picks,!picks,!new,best,!best,cats,new']);

        $this->assertSame(['best', 'cats', 'new'], StorePage::order($this->shop->id));
        // والقسمُ المشغَّل في موضع ظهوره المشغَّل — لا تسبقه علامةُ إطفاءٍ مكرّرةٌ له
        $this->assertSame(['best', 'cats', 'new', '!banner', '!block', '!about', '!reviews'], $this->editor()['layout']);
    }

    public function test_hiding_in_one_shop_moves_nothing_in_another(): void
    {
        [$other, $otherOwner] = $this->openShop('other', 'owner@other.test');
        ThemePublisher::enable($this->shop, (int) $this->owner->id);

        $this->save(['store_sections' => 'about,!cats,best,new'])->assertSessionHasNoErrors();

        $this->assertSame(StorePage::DEFAULT_ORDER, $this->rowsOf($this->editor($otherOwner)));
        $this->assertSame(StorePage::DEFAULT_ORDER, $this->visitorSees('other'));
        $this->assertSame('', (string) (StoreContent::draft($other->id)['store_sections'] ?? ''));
    }
}
