<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\MarketingSettings;
use App\Support\Store\PageEditor;
use App\Support\Store\RibbonPicks;
use App\Support\Store\RibbonUpsells;
use App\Support\Store\StorePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * «اختيارات RIBBON» و«أضف مع طلبك» — أصنافٌ يختارها صاحبُ المتجر بيده.
 *
 * ═══ ما يُحرس ═══
 *
 *   - «اختيارات RIBBON» مطفأٌ حتّى يُرفع: لا يتبدّل شيءٌ في صفحةٍ قائمة.
 *     ثمّ يعرض ما اختاره بترتيبه، من متجره المعروض وحده، وعنوانُه لكلّ لغة.
 *   - «أضف مع طلبك» يبقى على قسم «الاضافات» حتّى يختار، ثمّ يأخذ ما اختاره
 *     من أيّ قسم — والصنفُ يبقى في قسمه.
 *   - والحفظُ صارم: صنفُ متجرٍ آخر أو مطفأٌ أو مخفيٌّ أو قائمةٌ عابثةٌ أو
 *     فوق الحدّ يُردّ برسالة؛ ومن لم يُفتح له يُردّ بـ403.
 *   - ولا يُسأل قسمٌ من الأصناف للقسم الجديد، ولا يُنقل صنفٌ بين الأقسام.
 */
class RibbonCuratesItsPicksAndAddOnsByHandTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    private User $owner;

    /** @var array<string, int> */
    private array $p = [];

    /** @var array<string, int> */
    private array $cat = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = $this->ribbon('ribbon');
        $this->other = $this->ribbon('other');
        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'سعود', 'email' => 'saud@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        $this->cat['bouquets'] = $this->category($this->shop, 'باقات');
        $this->cat['addons'] = $this->category($this->shop, 'الاضافات');

        $this->p['rose'] = $this->product($this->shop, $this->cat['bouquets'], 'باقة ورد', ['name_en' => 'Rose bouquet']);
        $this->p['tulip'] = $this->product($this->shop, $this->cat['bouquets'], 'باقة توليب', ['name_en' => 'Tulip bouquet']);
        $this->p['lily'] = $this->product($this->shop, $this->cat['bouquets'], 'باقة زنبق', ['name_en' => 'Lily bouquet']);
        $this->p['choc'] = $this->product($this->shop, $this->cat['addons'], 'شوكولاتة', ['name_en' => 'Chocolate']);
        $this->p['balloon'] = $this->product($this->shop, $this->cat['addons'], 'بالون', ['name_en' => 'Balloon']);
        $this->p['inactive'] = $this->product($this->shop, $this->cat['bouquets'], 'باقة موقوفة', ['active' => false]);
        $this->p['hidden'] = $this->product($this->shop, $this->cat['bouquets'], 'باقة مخفيّة', ['published' => false]);
        $this->p['foreign'] = $this->product($this->other, $this->category($this->other, 'باقات'), 'باقة متجرٍ آخر', []);

        config([
            'storefront.ribbon_picks_businesses' => [$this->shop->id],
            'storefront.ribbon_product_upsells' => [$this->shop->id => ['category' => 'الاضافات', 'limit' => 6]],
        ]);
    }

    private function ribbon(string $slug): Business
    {
        $shop = Business::create([
            'name' => 'متجر '.$slug, 'type' => 'محل ورد', 'status' => 'نشط',
            'site_slug' => $slug, 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        MarketingSettings::save($shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1', 'store_fulfil' => 'pickup']);

        return $shop;
    }

    private function category(Business $shop, string $name): int
    {
        return DB::table('categories')->insertGetId([
            'business_id' => $shop->id, 'name' => $name, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function product(Business $shop, int $category, string $name, array $attrs): int
    {
        return Product::create($attrs + [
            'business_id' => $shop->id, 'category_id' => $category, 'name' => $name,
            'price' => 5, 'cost' => 1, 'quantity' => 10, 'active' => true, 'published' => true,
        ])->id;
    }

    private function save(array $values)
    {
        return $this->actingAs($this->owner)->from(route('admin.website.design'))
            ->post(route('admin.marketing.store.save'), $values);
    }

    private function set(array $values): void
    {
        MarketingSettings::save($this->shop->id, 'website', $values);
    }

    private function home(string $lang = 'ar')
    {
        return $this->get('/s/ribbon?lang='.$lang)->assertOk();
    }

    /** @return list<int> */
    private function picksShown(string $lang = 'ar'): array
    {
        return collect($this->home($lang)->viewData('picks'))->pluck('id')->all();
    }

    /** @return list<int> */
    private function upsellIds(int $productId, string $lang = 'ar'): array
    {
        return collect($this->get("/s/ribbon/p/{$productId}?lang={$lang}")->assertOk()->viewData('upsells'))->pluck('id')->all();
    }

    /* ═══════════ «اختيارات RIBBON» ═══════════ */

    /** ١٨: مطفأٌ افتراضًا — ولا يتبدّل ترتيبُ صفحةٍ قائمة */
    public function test_picks_are_off_until_the_owner_shows_them(): void
    {
        $this->set(['store_picks' => $this->p['rose'].','.$this->p['tulip']]);

        $this->assertNotContains(RibbonPicks::SECTION, StorePage::order($this->shop->id));
        $this->assertStringNotContainsString('rb-sec-picks', $this->home()->getContent());

        // ومتجرٌ رتّب صفحتَه قبل اليوم يبقى على ترتيبه
        $this->set(['store_sections' => 'best,banner,cats,new,about']);
        $this->assertSame(['best', 'banner', 'cats', 'new', 'about'], StorePage::order($this->shop->id));

        // وفي المحرّر صفٌّ مطفأٌ يُرفع بالعين نفسِها
        $row = collect(PageEditor::rows($this->shop->id))->firstWhere('key', RibbonPicks::SECTION);
        $this->assertNotNull($row);
        $this->assertFalse($row['on']);
        $this->assertFalse($row['fixed']);
    }

    /** ١٩ و٢٠ و٢٨: رفعُه يعرض ما اختاره بترتيبه، في موضعه من ترتيب الأقسام */
    public function test_shown_picks_list_the_chosen_products_in_order_where_they_were_placed(): void
    {
        $this->save([
            'store_sections' => 'cats,picks,best',
            'store_picks' => implode(',', [$this->p['lily'], $this->p['rose'], $this->p['choc']]),
        ])->assertSessionHasNoErrors();

        $this->assertSame([$this->p['lily'], $this->p['rose'], $this->p['choc']], $this->picksShown());
        $this->assertSame(['cats', 'picks', 'best'], StorePage::order($this->shop->id));

        $html = (string) $this->home()->getContent();
        $this->assertLessThan(strpos($html, 'rb-sec-best'), strpos($html, 'rb-sec-picks'), 'القسمُ ليس في موضعه من الترتيب');
        $this->assertGreaterThan(strpos($html, 'rb-sec-cats'), strpos($html, 'rb-sec-picks'));

        // ونقلُه بعد «الأكثر مبيعًا» ينقله
        $this->save(['store_sections' => 'best,picks'])->assertSessionHasNoErrors();
        $html = (string) $this->home()->getContent();
        $this->assertGreaterThan(strpos($html, 'rb-sec-best'), strpos($html, 'rb-sec-picks'));
    }

    /** ٢١ و٢٤ و٢٥: صنفُ متجرٍ آخر وقائمةٌ عابثةٌ وما فوق الحدّ — يُردّ ولا يُكتب */
    public function test_a_crafted_or_oversized_picks_list_is_refused(): void
    {
        $this->set(['store_picks' => (string) $this->p['rose']]);

        foreach ([
            (string) $this->p['foreign'],
            $this->p['rose'].','.$this->p['foreign'],
            'abc',
            '1;DROP TABLE settings',
            '-3',
            '0',
            '1.5',
            implode(',', range(900001, 900000 + RibbonPicks::MAX + 1)),
        ] as $raw) {
            $this->save(['store_picks' => $raw])->assertSessionHasErrors('store_picks');
            $this->assertSame((string) $this->p['rose'], MarketingSettings::group($this->shop->id, 'website')['store_picks'], "حُفظ «{$raw}»");
        }

        $this->save(['store_picks' => ['1', '2']])->assertSessionHasErrors('store_picks');

        // وثمانيةٌ صالحةٌ تُحفظ — والمكرَّرُ يُطوى
        $ids = [$this->p['rose'], $this->p['tulip']];
        for ($i = 0; $i < RibbonPicks::MAX - 2; $i++) {
            $ids[] = $this->product($this->shop, $this->cat['bouquets'], 'باقة '.$i, []);
        }
        $this->save(['store_picks' => implode(',', $ids).','.$this->p['rose']])->assertSessionHasNoErrors();
        $this->assertSame(implode(',', $ids), MarketingSettings::group($this->shop->id, 'website')['store_picks']);
    }

    /** ٢٢ و٢٣: صنفٌ أُطفئ أو أُخفي بعد اختياره يسقط وحده — ولا يُحشى مكانَه شيء */
    public function test_a_pick_hidden_later_drops_out_alone(): void
    {
        $this->set(['store_sections' => 'picks', 'store_picks' => implode(',', [$this->p['rose'], $this->p['tulip'], $this->p['lily']])]);

        Product::whereKey($this->p['rose'])->update(['active' => false]);
        Product::whereKey($this->p['lily'])->update(['published' => false]);

        $this->assertSame([$this->p['tulip']], $this->picksShown());

        // ولا يُحفظ صنفٌ مطفأٌ أو مخفيٌّ اليوم
        $this->save(['store_picks' => (string) $this->p['inactive']])->assertSessionHasErrors('store_picks');
        $this->save(['store_picks' => (string) $this->p['hidden']])->assertSessionHasErrors('store_picks');

        // وإن سقطت كلُّها لم يُرسم القسم
        Product::whereKey($this->p['tulip'])->update(['published' => false]);
        $this->assertStringNotContainsString('rb-sec-picks', $this->home()->getContent());
    }

    /** ٢٦ و٢٧: لكلّ لغةٍ عنوانُها — وفارغُها اسمُ القسم في القالب للّغة نفسِها */
    public function test_the_picks_title_speaks_each_language(): void
    {
        $this->set(['store_sections' => 'picks', 'store_picks' => (string) $this->p['rose']]);

        $this->assertSame('اختيارات RIBBON', $this->home('ar')->viewData('picksTitle'));
        $this->assertSame('RIBBON picks', $this->home('en')->viewData('picksTitle'));

        $this->save(['store_picks_title' => 'اختياراتُ الأسبوع', 'store_picks_title_en' => "This week's picks"])->assertSessionHasNoErrors();

        $ar = (string) $this->home('ar')->getContent();
        $en = (string) $this->home('en')->getContent();
        $this->assertStringContainsString('اختياراتُ الأسبوع', $ar);
        $this->assertStringNotContainsString('اختياراتُ الأسبوع', $en);
        $this->assertStringContainsString('This week&#039;s picks', $en);
        $this->assertStringNotContainsString('This week&#039;s picks', $ar);

        // وحفظُ لغةٍ لا يمحو الأخرى، والحدُّ ثمانون
        $this->save(['store_picks_title_en' => 'Weekly'])->assertSessionHasNoErrors();
        $this->assertSame('اختياراتُ الأسبوع', MarketingSettings::group($this->shop->id, 'website')['store_picks_title']);
        $this->save(['store_picks_title' => str_repeat('ب', 81)])->assertSessionHasErrors('store_picks_title');
    }

    /** ومن لم يُفتح له لا يُحفظ له شيءٌ منه (403)، ولا يُضمّ القسمُ إلى صفحته ولو حُفظ */
    public function test_a_shop_outside_the_list_cannot_write_or_show_picks(): void
    {
        config(['storefront.ribbon_picks_businesses' => []]);

        $this->save(['store_picks' => (string) $this->p['rose']])->assertForbidden();
        $this->save(['store_picks_title' => 'عنوان'])->assertForbidden();
        $this->assertSame('', MarketingSettings::group($this->shop->id, 'website')['store_picks']);

        $this->set(['store_sections' => 'cats,picks', 'store_picks' => (string) $this->p['rose']]);
        $this->assertSame(['cats'], StorePage::order($this->shop->id));
        $this->assertStringNotContainsString('rb-sec-picks', $this->home()->getContent());
        $this->assertNotContains(RibbonPicks::SECTION, array_column(PageEditor::rows($this->shop->id), 'key'));
    }

    /** ولا يُسأل قسمٌ من الأصناف — والصنفُ المختار يبقى في قسمه ويظهر في غيره */
    public function test_picks_never_read_or_move_a_category(): void
    {
        $this->save(['store_sections' => 'picks,new', 'store_picks' => $this->p['choc'].','.$this->p['rose']])->assertSessionHasNoErrors();

        $this->assertSame([$this->p['choc'], $this->p['rose']], $this->picksShown());
        $this->assertSame($this->cat['addons'], (int) Product::find($this->p['choc'])->category_id);
        $this->assertSame($this->cat['bouquets'], (int) Product::find($this->p['rose'])->category_id);

        // وإعادةُ تسمية «الاضافات» لا تمسّ القسم
        DB::table('categories')->where('id', $this->cat['addons'])->update(['name' => 'هدايا صغيرة']);
        $this->assertSame('هدايا صغيرة', DB::table('categories')->where('id', $this->cat['addons'])->value('name'), 'لم تُبدَّل التسمية — الحارسُ يحرس لا شيء');
        $this->assertSame([$this->p['choc'], $this->p['rose']], $this->picksShown());

        $source = (string) file_get_contents(app_path('Support/Store/RibbonPicks.php'));
        $this->assertStringNotContainsString('Category', $source, '«اختيارات RIBBON» تسأل قسمًا من الأصناف');
    }

    /* ═══════════ «أضف مع طلبك» ═══════════ */

    /** ٢٩: بلا اختيارٍ يبقى على «الاضافات» كما كان */
    public function test_without_a_choice_the_add_ons_still_come_from_their_category(): void
    {
        $this->assertSame([$this->p['balloon'], $this->p['choc']], $this->upsellIds($this->p['rose']));
        $this->assertSame([], RibbonUpsells::manualIds($this->shop->id));
    }

    /** ٣٠ إلى ٣٣: ما اختاره يحلّ محلَّ القسم — بترتيبه، من أيّ قسم، والصنفُ في قسمه */
    public function test_a_hand_picked_list_overrides_the_category_in_its_order(): void
    {
        $this->save([RibbonUpsells::KEY => implode(',', [$this->p['lily'], $this->p['choc']])])->assertSessionHasNoErrors();

        $this->assertSame([$this->p['lily'], $this->p['choc']], $this->upsellIds($this->p['rose']));
        $this->assertNotContains($this->p['balloon'], $this->upsellIds($this->p['rose']), 'قسمُ «الاضافات» اختلط بالاختيار');

        // ليس من «الاضافات» ويبقى في قسمه
        $this->assertSame($this->cat['bouquets'], (int) Product::find($this->p['lily'])->category_id);

        $this->save([RibbonUpsells::KEY => implode(',', [$this->p['choc'], $this->p['lily']])])->assertSessionHasNoErrors();
        $this->assertSame([$this->p['choc'], $this->p['lily']], $this->upsellIds($this->p['rose']), 'الترتيبُ ليس ترتيبَه');

        // وتفريغُه يُعيده إلى القسم
        $this->save([RibbonUpsells::KEY => ''])->assertSessionHasNoErrors();
        $this->assertSame([$this->p['balloon'], $this->p['choc']], $this->upsellIds($this->p['rose']));
    }

    /** ٣٤ و٣٥: صنفُ متجرٍ آخر ومطفأٌ ومخفيٌّ وفوق الحدّ — يُردّ. وما أُخفي بعدُ يسقط وحده */
    public function test_the_hand_picked_list_is_kept_to_this_shops_shown_products(): void
    {
        foreach ([(string) $this->p['foreign'], (string) $this->p['inactive'], (string) $this->p['hidden'], 'x,1', implode(',', range(900001, 900007))] as $raw) {
            $this->save([RibbonUpsells::KEY => $raw])->assertSessionHasErrors(RibbonUpsells::KEY);
        }
        $this->assertSame('', MarketingSettings::group($this->shop->id, 'website')[RibbonUpsells::KEY]);

        $this->save([RibbonUpsells::KEY => implode(',', [$this->p['tulip'], $this->p['lily']])])->assertSessionHasNoErrors();
        Product::whereKey($this->p['tulip'])->update(['published' => false]);

        // يسقط وحده — ولا يُحشى مكانَه صنفٌ من «الاضافات»
        $this->assertSame([$this->p['lily']], $this->upsellIds($this->p['rose']));

        // ومتجرٌ لا قسمَ له يُردّ بـ403
        config(['storefront.ribbon_product_upsells' => []]);
        $this->save([RibbonUpsells::KEY => (string) $this->p['lily']])->assertForbidden();
        $this->assertNotContains(PageEditor::UPSELLS, array_column(PageEditor::rows($this->shop->id), 'key'));
    }

    /** ٣٦: صفحةُ صنفٍ مختارٍ لا تقترح نفسَها ولا أخواتِها — «إضافةٌ لا تقترح إضافة» */
    public function test_an_add_on_page_does_not_suggest_add_ons(): void
    {
        $this->set([RibbonUpsells::KEY => implode(',', [$this->p['lily'], $this->p['choc']])]);

        $this->assertSame([], $this->upsellIds($this->p['lily']));
        $this->assertSame([], $this->upsellIds($this->p['choc']));
        $this->assertSame([$this->p['lily'], $this->p['choc']], $this->upsellIds($this->p['tulip']));
    }

    /** ٣٧: والبنودُ بنودُ السلّة المعتادة — يسعّرها الإتمامُ من القاعدة كأيّ صنف */
    public function test_cart_and_checkout_are_unchanged(): void
    {
        $this->set([RibbonUpsells::KEY => (string) $this->p['lily']]);

        $this->postJson('/s/ribbon/checkout', [
            'items' => [['id' => $this->p['rose'], 'qty' => 1], ['id' => $this->p['lily'], 'qty' => 2]],
            'fulfil' => 'pickup', 'pay' => 'cod', 'name' => 'مريم', 'phone' => '96899110001',
            'date' => now()->addDays(2)->toDateString(),
        ])->assertOk();

        $order = Order::with('items')->sole();
        $this->assertSame(15.0, (float) $order->total);
        $this->assertSame([$this->p['rose'], $this->p['lily']], $order->items->pluck('product_id')->map(fn ($id) => (int) $id)->all());
    }

    /** ومحرّرُ صاحب القسم يعرض الصفَّ بحدّ القائمة نفسِها — لا رقمٌ ثانٍ */
    public function test_the_editor_row_carries_the_lists_own_limit(): void
    {
        $row = collect(PageEditor::rows($this->shop->id))->firstWhere('key', PageEditor::UPSELLS);

        $this->assertNotNull($row);
        $this->assertTrue($row['fixed']);
        $this->assertSame('products', $row['fields'][0]['kind']);
        $this->assertSame(6, $row['fields'][0]['max']);

        config(['storefront.ribbon_product_upsells' => [$this->shop->id => ['category' => 'الاضافات', 'limit' => 3]]]);
        $row = collect(PageEditor::rows($this->shop->id))->firstWhere('key', PageEditor::UPSELLS);
        $this->assertSame(3, $row['fields'][0]['max']);
        $this->save([RibbonUpsells::KEY => implode(',', [$this->p['rose'], $this->p['tulip'], $this->p['lily'], $this->p['choc']])])
            ->assertSessionHasErrors(RibbonUpsells::KEY);
    }

    /** وما عرضه الشاهدُ لسعود يومَ النشر: القائمتان له وحده */
    public function test_the_owners_lists_name_saud_alone(): void
    {
        $config = require base_path('config/storefront.php');

        $this->assertSame([5], $config['ribbon_picks_businesses']);
        $this->assertSame([5 => ['category' => 'الاضافات', 'limit' => 6]], $config['ribbon_product_upsells'], 'قسمُ «الاضافات» يبقى بديلًا لا يُرفع');
    }
}
