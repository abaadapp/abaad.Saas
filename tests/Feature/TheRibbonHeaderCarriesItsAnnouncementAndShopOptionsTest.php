<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Store\PageEditor;
use App\Support\Store\StoreHeader;
use App\Support\Store\ThemePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * رأسُ متجر RIBBON — شريطُ إعلانٍ أعلاه، وصفُّ خيارات «المتجر» أسفلَه.
 *
 * ═══ ما يُحرس هنا ═══
 *
 * ١) الإعلانُ بلغة الصفحة وحدها: لا يقع العربيُّ على الإنجليزيّة ولا العكس،
 *    وفارغُ اللغة لا يُرسم شريطًا. والمحاذاةُ مكانٌ (يسار/وسط/يمين) في
 *    الصفحتين، من ثلاثٍ مغلقة.
 * ٢) والصفوفُ الأربعةُ داخل ترويسةٍ لاصقةٍ واحدة، بلون الترويسة نفسِه.
 * ٣) وصفُّ المتجر: «كل المنتجات» و«الأكثر مبيعًا» ثمّ فئاتُه بترتيبه —
 *    بمعرّفاتها، باسمها بلغة الصفحة، ومن متجره وحده، ولا زرَّ إلى رفٍّ خالٍ.
 *    ولا صفَّ ثانيًا للفئات في جسم الصفحة.
 * ٤) و«الأكثر مبيعًا» ما بيع فعلًا بقاعدة `Order::sold` — لا ملغى ولا معلّق،
 *    ولا بيعُ متجرٍ آخر، ولا يُملأ بغير المبيع.
 * ٥) والمفاتيحُ في عقد المسوّدة والنشر — يُحفظ فلا يراه الزائرُ حتّى يُنشر.
 */
class TheRibbonHeaderCarriesItsAnnouncementAndShopOptionsTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Category $bouquets;

    private Category $gifts;

    private Category $addons;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-04-01 10:00:00');
        app()->setLocale('ar');

        [$this->shop, $this->owner] = $this->ribbon('RIBBON', 'ribbon', 'owner-a@abaad.test');

        $this->bouquets = Category::create(['business_id' => $this->shop->id, 'name' => 'باقات', 'name_en' => 'Bouquets']);
        $this->gifts = Category::create(['business_id' => $this->shop->id, 'name' => 'هدايا', 'name_en' => 'Gifts']);
        $this->addons = Category::create(['business_id' => $this->shop->id, 'name' => 'إضافات', 'name_en' => 'Add-ons']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ------------------------------ أدوات ------------------------------ */

    /** @return array{0: Business, 1: User} */
    private function ribbon(string $name, string $slug, string $email): array
    {
        $shop = Business::create([
            'name' => $name, 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96890000000',
            'city' => 'مسقط', 'site_slug' => $slug, 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $owner = User::create(['business_id' => $shop->id, 'name' => 'المالك', 'email' => $email, 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        MarketingSettings::save($shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1']);

        return [$shop, $owner];
    }

    private function product(Business $shop, string $name, ?string $nameEn, ?Category $cat): Product
    {
        return Product::create([
            'business_id' => $shop->id, 'name' => $name, 'name_en' => $nameEn, 'price' => 20,
            'category_id' => $cat?->id, 'cost' => 8, 'quantity' => 50, 'alert_qty' => 1,
            'active' => true, 'published' => true,
        ]);
    }

    private int $orders = 0;

    /** طلبٌ ببندٍ واحد — مكتملٌ ما لم يُقل غيرُه */
    private function sell(Business $shop, Product $p, float $qty, string $status = 'مكتمل', bool $held = false): void
    {
        $order = Order::create([
            'business_id' => $shop->id,
            'branch_id' => Branch::where('business_id', $shop->id)->value('id'),
            'number' => 'T-'.$shop->id.'-'.(++$this->orders),
            'status' => $status, 'is_held' => $held,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 20 * $qty, 'total' => 20 * $qty,
        ]);

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $p->id, 'name' => $p->name,
            'price' => 20, 'quantity' => $qty, 'total' => 20 * $qty,
        ]);
    }

    private function settings(array $values, ?Business $shop = null): void
    {
        MarketingSettings::save(($shop ?? $this->shop)->id, 'website', $values);
    }

    private function page(string $path = '/', string $lang = 'ar', string $slug = 'ribbon'): string
    {
        $sep = str_contains($path, '?') ? '&' : '?';

        return $this->get('/s/'.$slug.$path.$sep.'lang='.$lang)->assertOk()->getContent();
    }

    /** الترويسةُ وحدها — من فتحها إلى إغلاقها */
    private function header(string $html): string
    {
        $this->assertSame(1, preg_match('#<header class="rb-head">(.*?)</header>#s', $html, $m), 'لا ترويسة في الصفحة');

        return $m[1];
    }

    /** الجسمُ وحده — ما بين `<main>` و`</main>` */
    private function body(string $html): string
    {
        $this->assertSame(1, preg_match('#<main>(.*?)</main>#s', $html, $m));

        return $m[1];
    }

    /** أزرارُ صفّ المتجر بترتيبها: [مفتاح، رابط، اسم، مختار؟] */
    private function buttons(string $html): array
    {
        preg_match_all('#<a href="([^"]*)"\s+class="rb-opt ?( is-on)?\s*"\s+data-testid="rb-opt-([^"]+)"[^>]*>([^<]*)</a>#u', $this->header($html), $m, PREG_SET_ORDER);

        return array_map(fn ($x) => [$x[3], html_entity_decode($x[1]), html_entity_decode($x[4]), $x[2] !== ''], $m);
    }

    /** معرّفاتُ الأصناف المعروضة في الرفّ بترتيبها */
    private function shelfIds(string $query, string $lang = 'ar'): array
    {
        // ويُرمَّز الرابطُ كما يرمّزه المتصفّح — العربيُّ فيه لا يُرسَل خامًا
        parse_str($query, $params);
        $res = $this->get('/s/ribbon/shop?'.http_build_query($params + ['lang' => $lang]))->assertOk();

        return array_column($res->original->getData()['products'], 'id');
    }

    /* ═══════════════ ١ · الإعلان بلغة الصفحة ═══════════════ */

    public function test_the_arabic_announcement_shows_on_the_arabic_page(): void
    {
        $this->settings(['store_announcement_ar' => 'توصيلٌ مجّانيّ داخل مسقط', 'store_announcement_en' => 'Free delivery in Muscat']);

        $head = $this->header($this->page('/', 'ar'));

        $this->assertStringContainsString('data-testid="rb-announcement"', $head);
        $this->assertStringContainsString('توصيلٌ مجّانيّ داخل مسقط', $head);
        $this->assertStringNotContainsString('Free delivery in Muscat', $head);
    }

    public function test_the_english_announcement_shows_on_the_english_page(): void
    {
        $this->settings(['store_announcement_ar' => 'توصيلٌ مجّانيّ داخل مسقط', 'store_announcement_en' => 'Free delivery in Muscat']);

        $head = $this->header($this->page('/', 'en'));

        $this->assertStringContainsString('Free delivery in Muscat', $head);
        $this->assertStringNotContainsString('توصيلٌ مجّانيّ داخل مسقط', $head);
    }

    /** وفراغُ الإنجليزيّ لا يُملأ بالعربيّ — ولا شريطَ يأخذ ارتفاعًا */
    public function test_arabic_never_fills_a_blank_english_bar(): void
    {
        $this->settings(['store_announcement_ar' => 'عرضُ العيد', 'store_announcement_en' => '']);

        $en = $this->page('/', 'en');

        $this->assertStringNotContainsString('عرضُ العيد', $en);
        $this->assertStringNotContainsString('data-testid="rb-announcement"', $en);
        $this->assertStringContainsString('data-testid="rb-announcement"', $this->page('/', 'ar'));
    }

    public function test_english_never_fills_a_blank_arabic_bar(): void
    {
        $this->settings(['store_announcement_ar' => '   ', 'store_announcement_en' => 'Eid offer']);

        $ar = $this->page('/', 'ar');

        $this->assertStringNotContainsString('Eid offer', $ar);
        $this->assertStringNotContainsString('data-testid="rb-announcement"', $ar);
    }

    public function test_no_text_in_either_language_draws_no_bar_anywhere(): void
    {
        foreach (['/', '/shop', '/cart'] as $path) {
            foreach (['ar', 'en'] as $lang) {
                $this->assertStringNotContainsString('rb-announcement', $this->body($this->page($path, $lang)).$this->header($this->page($path, $lang)));
            }
        }
    }

    /** والشريطُ في كلّ صفحةٍ لا في الرئيسية وحدها */
    public function test_the_bar_rides_on_every_page(): void
    {
        $this->settings(['store_announcement_ar' => 'نوصّل اليوم']);
        $this->product($this->shop, 'باقة', 'Bouquet', $this->bouquets);

        foreach (['/', '/shop', '/cart', '/checkout'] as $path) {
            $this->assertStringContainsString('نوصّل اليوم', $this->header($this->page($path)), $path);
        }
    }

    public static function aligns(): array
    {
        return ['left' => ['left'], 'center' => ['center'], 'right' => ['right']];
    }

    /**
     * والمحاذاةُ مكانٌ في الشاشة — نفسُها في الصفحتين، لا تنقلب بالاتّجاه.
     */
    #[DataProvider('aligns')]
    public function test_the_alignment_is_the_same_place_on_both_pages(string $align): void
    {
        $this->settings(['store_announcement_ar' => 'نصّ', 'store_announcement_en' => 'Text', 'store_announcement_align' => $align]);

        foreach (['ar', 'en'] as $lang) {
            $head = $this->header($this->page('/', $lang));
            $this->assertStringContainsString('data-align="'.$align.'"', $head, $lang);
            $this->assertStringContainsString('rb-ann-in rb-ann-'.$align.'"', $head, $lang);
        }
    }

    /** والمحاذاةُ صنفُ CSS لا قيمةٌ حرّة — وكلُّ صنفٍ يقول مكانَه الحرفيّ */
    public function test_each_alignment_class_is_its_physical_side(): void
    {
        $html = $this->page('/');

        foreach (['left', 'center', 'right'] as $side) {
            $this->assertStringContainsString('.rb-ann-'.$side.' { text-align: '.$side.'; }', $html);
        }
    }

    public function test_an_unknown_alignment_is_refused_on_save(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.marketing.store.save'), ['store_announcement_align' => 'justify; color:red'])
            ->assertSessionHasErrors('store_announcement_align');

        $this->assertSame('', MarketingSettings::group($this->shop->id, 'website')['store_announcement_align']);
    }

    /** وقيمةٌ غريبةٌ وصلت القاعدةَ بطريقٍ آخر تُقرأ الوسطَ — لا تبلغ الصفحة */
    public function test_an_unknown_stored_alignment_reads_as_center(): void
    {
        $this->settings(['store_announcement_ar' => 'نصّ', 'store_announcement_align' => 'evil"><script>']);

        $head = $this->header($this->page('/'));

        $this->assertStringContainsString('data-align="center"', $head);
        $this->assertStringNotContainsString('evil', $head);
        $this->assertSame('center', StoreHeader::align('justify'));
        $this->assertSame('center', StoreHeader::align(null));
    }

    /** والنصُّ يُهرَّب — ما يكتبه صاحبُ المتجر نصٌّ لا وسم */
    public function test_the_announcement_is_text_not_markup(): void
    {
        $this->settings(['store_announcement_ar' => '<b>عرض</b><script>x</script>']);

        $head = $this->header($this->page('/'));

        $this->assertStringNotContainsString('<script>x</script>', $head);
        $this->assertStringContainsString('&lt;b&gt;عرض&lt;/b&gt;', $head);
    }

    /* ═══════════════ ٢ · لكلّ متجرٍ إعلانُه ═══════════════ */

    public function test_another_shops_announcement_never_reaches_this_shop(): void
    {
        [$other, $otherOwner] = $this->ribbon('ورد B', 'ward-b', 'owner-b@abaad.test');

        $this->actingAs($otherOwner)
            ->post(route('admin.marketing.store.save'), ['store_announcement_ar' => 'سرّ متجر B', 'store_announcement_en' => 'Shop B secret'])
            ->assertSessionHasNoErrors();

        foreach (['ar', 'en'] as $lang) {
            $html = $this->page('/', $lang);
            $this->assertStringNotContainsString('سرّ متجر B', $html);
            $this->assertStringNotContainsString('Shop B secret', $html);
        }

        $this->assertStringContainsString('سرّ متجر B', $this->page('/', 'ar', 'ward-b'));
        $this->assertSame('', MarketingSettings::group($this->shop->id, 'website')['store_announcement_ar']);
    }

    /** ومحرّرُ متجرٍ آخر لا يقرأ إعلانَ هذا المتجر ولا فئاتِه */
    public function test_another_owners_editor_reads_only_his_own_header(): void
    {
        $this->settings(['store_announcement_ar' => 'إعلانُ سعود', 'store_shop_nav_categories' => (string) $this->gifts->id]);
        [, $otherOwner] = $this->ribbon('ورد B', 'ward-b', 'owner-b@abaad.test');

        $this->actingAs($otherOwner)
            ->get(route('admin.website.editor'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Admin/Website/ThemeEditor')
                ->where('values.store_announcement_ar', '')
                ->where('values.store_shop_nav_categories', '')
                ->where('shortcutCategories', []));
    }

    /* ═══════════════ ٣ · ترويسةٌ لاصقةٌ واحدة ═══════════════ */

    /**
     * والصفوفُ الأربعة داخل `header.rb-head` بترتيبها، ولا يُثبَّت منها شيءٌ وحدَه.
     */
    public function test_all_four_rows_sit_in_one_sticky_header_in_order(): void
    {
        $this->settings(['store_announcement_ar' => 'شريط']);
        $this->product($this->shop, 'باقة', 'Bouquet', $this->bouquets);

        $html = $this->page('/shop');
        $head = $this->header($html);

        $at = fn (string $needle) => strpos($head, $needle);

        $this->assertNotFalse($at('data-testid="rb-announcement"'));
        $this->assertLessThan($at('class="rb-head-in"'), $at('data-testid="rb-announcement"'), 'الإعلانُ ليس أوّلَ الصفوف');
        $this->assertLessThan($at('data-testid="rb-nav"'), $at('class="rb-head-in"'));
        $this->assertLessThan($at('data-testid="rb-shop-options"'), $at('data-testid="rb-nav"'), 'صفُّ المتجر ليس تحت قائمة الصفحات');

        // اللاصقُ عنصرٌ واحد — ولا `position: fixed` لشريطٍ منها
        $this->assertStringContainsString('header.rb-head { background: var(--rb-olive); position: sticky; top: 0;', $html);
        $this->assertDoesNotMatchRegularExpression('/\.rb-(ann|opts|nav)[^{]*\{[^}]*position:\s*(fixed|sticky)/', $html);
    }

    /** ولونُ الشريط لونُ الترويسة نفسُه — بلا خلفيّةٍ له تفترق عنها */
    public function test_the_bar_wears_the_header_colour_itself(): void
    {
        $html = $this->page('/');

        $this->assertSame(1, preg_match('/\.rb-ann \{([^}]*)\}/', $html, $rule));
        $this->assertStringNotContainsString('background', $rule[1], 'الشريطُ لونٌ كُتب تقريبًا لا لونُ الترويسة');
        $this->assertStringNotContainsString('#', $rule[1]);
    }

    /* ═══════════════ ٤ · صفُّ خيارات المتجر ═══════════════ */

    public function test_the_shop_row_starts_with_all_products_and_best_sellers(): void
    {
        $this->product($this->shop, 'باقة', 'Bouquet', $this->bouquets);

        $ar = $this->buttons($this->page('/shop', 'ar'));
        $en = $this->buttons($this->page('/shop', 'en'));

        $this->assertSame(['all', '/s/ribbon/shop', 'كل المنتجات', true], $ar[0]);
        $this->assertSame(['best', '/s/ribbon/shop?view=best', 'الأكثر مبيعًا', false], $ar[1]);
        $this->assertSame('All Products', $en[0][2]);
        $this->assertSame('Best Sellers', $en[1][2]);
    }

    /** وهو في «المتجر» وحدها — لا في الرئيسية ولا السلّة */
    public function test_the_shop_row_lives_on_the_shop_page_only(): void
    {
        $this->product($this->shop, 'باقة', 'Bouquet', $this->bouquets);

        $this->assertStringContainsString('data-testid="rb-shop-options"', $this->page('/shop'));

        foreach (['/', '/cart', '/checkout'] as $path) {
            $this->assertStringNotContainsString('data-testid="rb-shop-options"', $this->page($path), $path);
        }
    }

    public function test_chosen_categories_follow_in_the_saved_order_by_id(): void
    {
        $this->product($this->shop, 'باقة', 'Bouquet', $this->bouquets);
        $this->product($this->shop, 'هدية', 'Gift', $this->gifts);
        $this->product($this->shop, 'بالون', 'Balloon', $this->addons);

        // الإضافاتُ ثمّ الباقات — والهدايا لم تُختر
        $this->settings(['store_shop_nav_categories' => $this->addons->id.','.$this->bouquets->id]);

        $ar = $this->buttons($this->page('/shop', 'ar'));
        $en = $this->buttons($this->page('/shop', 'en'));

        $this->assertSame(['all', 'best', 'cat-'.$this->addons->id, 'cat-'.$this->bouquets->id], array_column($ar, 0));
        $this->assertSame('/s/ribbon/shop?cat='.$this->addons->id, $ar[2][1]);
        $this->assertSame(['إضافات', 'باقات'], [$ar[2][2], $ar[3][2]]);
        $this->assertSame(['Add-ons', 'Bouquets'], [$en[2][2], $en[3][2]]);

        // وما لم يُختر لا يُرسم في الصفّ
        $this->assertNotContains('cat-'.$this->gifts->id, array_column($ar, 0));
        $this->assertStringNotContainsString('هدايا</a>', $this->header($this->page('/shop', 'ar')));
    }

    /** والزرُّ يرشّح بالمعرّف — واسمُ الفئة يتبدّل ولا يتبدّل ما تحته */
    public function test_a_shortcut_filters_by_id_whatever_its_label(): void
    {
        $b = $this->product($this->shop, 'باقة', 'Bouquet', $this->bouquets);
        $this->product($this->shop, 'هدية', 'Gift', $this->gifts);
        $this->settings(['store_shop_nav_categories' => (string) $this->bouquets->id]);

        $this->assertSame([$b->id], $this->shelfIds('cat='.$this->bouquets->id));

        $this->bouquets->update(['name' => 'باقات الموسم', 'name_en' => 'Seasonal']);

        $this->assertSame([$b->id], $this->shelfIds('cat='.$this->bouquets->id, 'en'));
        $this->assertSame('Seasonal', $this->buttons($this->page('/shop', 'en'))[2][2]);
    }

    /** ولا زرَّ إلى رفٍّ خالٍ أو فئةٍ محذوفة أو فئةِ متجرٍ آخر — ولو بقي المعرّفُ مخزّنًا */
    public function test_dead_shortcuts_never_render(): void
    {
        [$other] = $this->ribbon('ورد B', 'ward-b', 'owner-b@abaad.test');
        $foreign = Category::create(['business_id' => $other->id, 'name' => 'فئةُ B', 'name_en' => 'B only']);
        $this->product($other, 'صنفُ B', 'B item', $foreign);

        $this->product($this->shop, 'باقة', 'Bouquet', $this->bouquets);
        $gone = Category::create(['business_id' => $this->shop->id, 'name' => 'ستُحذف']);
        $this->product($this->shop, 'صنفٌ يتيم', null, $gone);
        $goneId = $gone->id;
        Product::where('category_id', $goneId)->update(['category_id' => null]);
        $gone->delete();

        // الهدايا فارغة، و«ستُحذف» حُذفت، و`$foreign` لمتجرٍ آخر، و٩٩٩٩ لا وجود له
        $this->settings(['store_shop_nav_categories' => implode(',', [$this->gifts->id, $goneId, $foreign->id, 9999, $this->bouquets->id])]);

        $keys = array_column($this->buttons($this->page('/shop')), 0);

        $this->assertSame(['all', 'best', 'cat-'.$this->bouquets->id], $keys);
        $this->assertStringNotContainsString('فئةُ B', $this->page('/shop'));
        $this->assertStringNotContainsString('B only', $this->page('/shop', 'en'));
    }

    /** والصفُّ القديم في جسم الصفحة رُفع — موضعٌ واحدٌ للترشيح */
    public function test_the_old_body_pills_are_gone(): void
    {
        $this->product($this->shop, 'باقة', 'Bouquet', $this->bouquets);
        $this->product($this->shop, 'هدية', 'Gift', $this->gifts);

        $body = $this->body($this->page('/shop'));

        $this->assertStringNotContainsString('data-testid="rb-cats"', $body);
        $this->assertStringNotContainsString('rb-pill', $body);
        $this->assertStringNotContainsString('?cat=', $body);
        // والرفُّ نفسُه باقٍ
        $this->assertStringContainsString('class="rb-grid"', $body);
    }

    /** والمختارُ مضاءٌ — واحدٌ لا غير */
    public function test_the_current_option_is_lit(): void
    {
        $this->product($this->shop, 'باقة', 'Bouquet', $this->bouquets);
        $this->settings(['store_shop_nav_categories' => (string) $this->bouquets->id]);

        $lit = fn (string $q) => array_values(array_map(
            fn ($o) => $o[0],
            array_filter($this->buttons($this->page('/shop'.$q)), fn ($o) => $o[3]),
        ));

        $this->assertSame(['all'], $lit(''));
        $this->assertSame(['best'], $lit('?view=best'));
        $this->assertSame(['cat-'.$this->bouquets->id], $lit('?cat='.$this->bouquets->id));
        // فئةٌ ليست في الصفّ: لا يُضاء شيء، والعنوانُ يقول أين هو
        $this->assertSame([], $lit('?cat='.$this->gifts->id));
        $this->assertMatchesRegularExpression('#aria-current="page"\s*>الأكثر مبيعًا</a>#u', $this->header($this->page('/shop?view=best')));
    }

    /** والعنوانُ يقول ما يُعرض — اسمُ الفئة لمن وصل من خارج الصفّ */
    public function test_the_title_names_what_is_shown(): void
    {
        $this->product($this->shop, 'هدية', 'Gift', $this->gifts);

        $this->assertStringContainsString('data-testid="rb-shop-title">هدايا</h1>', $this->page('/shop?cat='.$this->gifts->id));
        $this->assertStringContainsString('data-testid="rb-shop-title">Gifts</h1>', $this->page('/shop?cat='.$this->gifts->id, 'en'));
        $this->assertStringContainsString('data-testid="rb-shop-title">Best Sellers</h1>', $this->page('/shop?view=best', 'en'));
    }

    /* ═══════════════ ٥ · الأكثر مبيعًا = ما بيع فعلًا ═══════════════ */

    public function test_best_sellers_are_what_actually_sold_most_first(): void
    {
        $a = $this->product($this->shop, 'أ', 'A', $this->bouquets);
        $b = $this->product($this->shop, 'ب', 'B', $this->bouquets);
        $c = $this->product($this->shop, 'ج', 'C', $this->bouquets);

        $this->sell($this->shop, $b, 2);
        $this->sell($this->shop, $a, 3);
        $this->sell($this->shop, $a, 2);

        $this->assertSame([$a->id, $b->id], $this->shelfIds('view=best'), 'الترتيبُ ليس بالمبيع، أو دخل ما لم يُبع');
        $this->assertNotContains($c->id, $this->shelfIds('view=best'));
    }

    /** والملغى والمعلّق ليسا بيعًا — قاعدةُ `Order::sold` */
    public function test_cancelled_and_held_orders_are_not_sales(): void
    {
        $a = $this->product($this->shop, 'أ', 'A', $this->bouquets);
        $b = $this->product($this->shop, 'ب', 'B', $this->bouquets);
        $c = $this->product($this->shop, 'ج', 'C', $this->bouquets);

        $this->sell($this->shop, $a, 1);
        $this->sell($this->shop, $b, 50, Order::CANCELLED);
        $this->sell($this->shop, $c, 50, 'مكتمل', held: true);

        $this->assertSame([$a->id], $this->shelfIds('view=best'));
    }

    /** وبيعُ متجرٍ آخر لا يدخل — ولو كان أكثر، ولو أشار بندُه إلى صنفِ هذا المتجر */
    public function test_another_shops_sales_never_enter(): void
    {
        $a = $this->product($this->shop, 'أ', 'A', $this->bouquets);
        $b = $this->product($this->shop, 'ب', 'B', $this->bouquets);
        $this->sell($this->shop, $a, 5);
        $this->sell($this->shop, $b, 2);

        [$other] = $this->ribbon('ورد B', 'ward-b', 'owner-b@abaad.test');
        $theirs = $this->product($other, 'الأكثر عندهم', 'Their top', null);
        $this->sell($other, $theirs, 500);
        // بندٌ في طلبِ متجرٍ آخر يحمل معرّفَ صنفِ هذا المتجر — لا يُعدّ له
        $this->sell($other, $b, 900);

        $ids = $this->shelfIds('view=best');

        $this->assertSame([$a->id, $b->id], $ids, 'بيعُ متجرٍ آخر قلب الترتيب أو دخل القائمة');
        $this->assertNotContains($theirs->id, $ids);
        $this->assertStringNotContainsString('الأكثر عندهم', $this->page('/shop?view=best'));
    }

    /** ومتجرٌ لم يبع يُقال له ذلك — لا تُملأ القائمةُ بأحدث الأصناف */
    public function test_no_sales_says_so_instead_of_inventing_best_sellers(): void
    {
        $this->product($this->shop, 'أ', 'A', $this->bouquets);

        $this->assertSame([], $this->shelfIds('view=best'));
        $this->assertStringContainsString('data-testid="rb-empty">لا توجد منتجات مباعة بعد.</p>', $this->page('/shop?view=best'));
        $this->assertStringContainsString('data-testid="rb-empty">No best sellers yet.</p>', $this->page('/shop?view=best', 'en'));
    }

    /**
     * ═══ وعقدُ الرابط — حتميٌّ ═══
     *
     * `view=best` يغلب `cat`، والبحثُ يقع داخل ما اختير، وقيمةٌ غريبةٌ لـ`view`
     * تُقرأ «الكلّ».
     */
    public function test_the_shop_link_contract_is_deterministic(): void
    {
        $rose = $this->product($this->shop, 'باقة ورد', 'Rose bouquet', $this->bouquets);
        $lily = $this->product($this->shop, 'باقة زنبق', 'Lily bouquet', $this->bouquets);
        $gift = $this->product($this->shop, 'علبة ورد', 'Rose box', $this->gifts);
        $this->sell($this->shop, $gift, 4);
        $this->sell($this->shop, $rose, 1);

        // البحثُ داخل «الأكثر مبيعًا» بترتيبه
        $this->assertSame([$gift->id, $rose->id], $this->shelfIds('view=best&q=Rose'));
        // و`view=best` يغلب `cat` — لا رفَّ متناقض
        $this->assertSame([$gift->id, $rose->id], $this->shelfIds('view=best&cat='.$this->bouquets->id));
        // والبحثُ داخل الفئة
        $this->assertSame([$rose->id], $this->shelfIds('cat='.$this->bouquets->id.'&q=Rose'));
        // وقيمةٌ غريبة تُقرأ «الكلّ»
        $this->assertEqualsCanonicalizing([$rose->id, $lily->id, $gift->id], $this->shelfIds('view=whatever'));
        // والبحثُ وحده ما زال يعمل
        $this->assertSame([$lily->id], $this->shelfIds('q=زنبق'));
    }

    /** و«مختاراتنا» في الرئيسية باقيةٌ كما هي — مفهومٌ آخر غيرُ «الأكثر مبيعًا» في الرفّ */
    public function test_the_homepage_picks_stay_a_separate_concept(): void
    {
        $sold = $this->product($this->shop, 'المبيع', 'Sold', $this->bouquets);
        $picked = $this->product($this->shop, 'المختار', 'Picked', $this->bouquets);
        $this->sell($this->shop, $sold, 9);
        $this->settings(['store_featured' => (string) $picked->id]);

        $home = $this->get('/s/ribbon')->assertOk()->original->getData();

        $this->assertSame([$picked->id], array_column($home['best'], 'id'), 'المختاراتُ اليدويّة لم تعد تتصدّر الرئيسية');
        $this->assertTrue($home['bestPicked']);
        // والرفُّ يبقى على المبيع وحده
        $this->assertSame([$sold->id], $this->shelfIds('view=best'));
    }

    /* ═══════════════ ٦ · الحفظ ═══════════════ */

    public function test_the_owner_saves_his_header_and_it_shows(): void
    {
        $this->product($this->shop, 'باقة', 'Bouquet', $this->bouquets);
        $this->product($this->shop, 'هدية', 'Gift', $this->gifts);

        $this->actingAs($this->owner)->post(route('admin.marketing.store.save'), [
            'store_announcement_ar' => 'توصيلٌ اليوم',
            'store_announcement_en' => 'Same-day delivery',
            'store_announcement_align' => 'right',
            'store_shop_nav_categories' => $this->gifts->id.','.$this->bouquets->id.','.$this->gifts->id,
        ])->assertSessionHasNoErrors();

        $saved = MarketingSettings::group($this->shop->id, 'website');

        $this->assertSame('right', $saved['store_announcement_align']);
        // والمكرّرُ يُحفظ مرّةً بموضعه الأوّل
        $this->assertSame($this->gifts->id.','.$this->bouquets->id, $saved['store_shop_nav_categories']);
        $this->assertSame(['all', 'best', 'cat-'.$this->gifts->id, 'cat-'.$this->bouquets->id], array_column($this->buttons($this->page('/shop')), 0));
    }

    public function test_another_shops_category_cannot_be_saved(): void
    {
        [$other] = $this->ribbon('ورد B', 'ward-b', 'owner-b@abaad.test');
        $foreign = Category::create(['business_id' => $other->id, 'name' => 'فئةُ B']);

        $this->actingAs($this->owner)
            ->post(route('admin.marketing.store.save'), ['store_shop_nav_categories' => $this->gifts->id.','.$foreign->id])
            ->assertSessionHasErrors('store_shop_nav_categories');

        $this->assertSame('', MarketingSettings::group($this->shop->id, 'website')['store_shop_nav_categories']);
    }

    public static function badShortcuts(): array
    {
        return [
            'سالب' => ['-3'],
            'صفر' => ['0'],
            'نصّ' => ['abc'],
            'كسر' => ['1.5'],
            'رابط' => ['/shop?cat=1'],
            'لا وجود له' => ['987654'],
        ];
    }

    #[DataProvider('badShortcuts')]
    public function test_a_bad_shortcut_list_is_refused(string $raw): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.marketing.store.save'), ['store_shop_nav_categories' => $raw])
            ->assertSessionHasErrors('store_shop_nav_categories');
    }

    /** ستٌّ لا أكثر — والسابعةُ يُقال لها، لا تُقصّ بصمت */
    public function test_more_than_six_shortcuts_are_refused(): void
    {
        $ids = [];
        for ($i = 1; $i <= 7; $i++) {
            $ids[] = Category::create(['business_id' => $this->shop->id, 'name' => 'فئة '.$i])->id;
        }

        $this->actingAs($this->owner)
            ->post(route('admin.marketing.store.save'), ['store_shop_nav_categories' => implode(',', $ids)])
            ->assertSessionHasErrors('store_shop_nav_categories');

        $this->actingAs($this->owner)
            ->post(route('admin.marketing.store.save'), ['store_shop_nav_categories' => implode(',', array_slice($ids, 0, 6))])
            ->assertSessionHasNoErrors();

        $this->assertSame(6, StoreHeader::MAX_SHORTCUTS);
    }

    /** وتفريغُ القائمة يُحفظ تفريغًا */
    public function test_clearing_the_shortcuts_clears_them(): void
    {
        $this->settings(['store_shop_nav_categories' => (string) $this->gifts->id]);

        $this->actingAs($this->owner)
            ->post(route('admin.marketing.store.save'), ['store_shop_nav_categories' => ''])
            ->assertSessionHasNoErrors();

        $this->assertSame('', MarketingSettings::group($this->shop->id, 'website')['store_shop_nav_categories']);
    }

    public function test_an_announcement_is_a_line_not_a_page(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.marketing.store.save'), ['store_announcement_en' => str_repeat('x', StoreHeader::ANNOUNCEMENT_MAX + 1)])
            ->assertSessionHasErrors('store_announcement_en');
    }

    /* ═══════════════ ٧ · المسوّدة ثمّ النشر ═══════════════ */

    public function test_saving_drafts_and_only_publishing_shows_it(): void
    {
        $this->product($this->shop, 'باقة', 'Bouquet', $this->bouquets);
        ThemePublisher::enable($this->shop, (int) $this->owner->id);

        $this->actingAs($this->owner)->post(route('admin.marketing.store.save'), [
            'store_announcement_ar' => 'إعلانٌ لم يُنشر',
            'store_announcement_en' => 'Unpublished',
            'store_announcement_align' => 'left',
            'store_shop_nav_categories' => (string) $this->bouquets->id,
        ])->assertSessionHasNoErrors();

        // الزائرُ لا يرى المسوّدة
        auth()->logout();
        $this->assertStringNotContainsString('إعلانٌ لم يُنشر', $this->page('/'));
        $this->assertSame(['all', 'best'], array_column($this->buttons($this->page('/shop')), 0));

        // والمعاينةُ تراها — لصاحبها
        $this->actingAs($this->owner)
            ->get(route('admin.store.preview'))
            ->assertOk()
            ->assertSee('إعلانٌ لم يُنشر');

        ThemePublisher::publish($this->shop, (int) $this->owner->id, 'رأس المتجر');
        auth()->logout();

        $this->assertStringContainsString('إعلانٌ لم يُنشر', $this->header($this->page('/')));
        $this->assertStringContainsString('data-align="left"', $this->header($this->page('/')));
        $this->assertStringContainsString('Unpublished', $this->header($this->page('/', 'en')));
        $this->assertSame(['all', 'best', 'cat-'.$this->bouquets->id], array_column($this->buttons($this->page('/shop')), 0));
    }

    /* ═══════════════ ٨ · موضعُ التحرير ═══════════════ */

    /** صفٌّ واحدٌ «رأس المتجر» فيه الحقولُ الأربعة — لا مقابضُ مبعثرة */
    public function test_one_store_header_row_holds_all_four_fields(): void
    {
        $this->assertSame(
            ['store_announcement_ar', 'store_announcement_en', 'store_announcement_align', 'store_shop_nav_categories'],
            array_column(PageEditor::FIELDS[PageEditor::HEAD], 'key'),
        );
        $this->assertSame('رأس المتجر', PageEditor::ROWS[PageEditor::HEAD]['label']);
        $this->assertSame(
            ['نص الشريط بالعربية', 'Announcement text in English', 'محاذاة النص'],
            array_slice(array_column(PageEditor::FIELDS[PageEditor::HEAD], 'label'), 0, 3),
        );

        $this->product($this->shop, 'باقة', 'Bouquet', $this->bouquets);

        $this->actingAs($this->owner)
            ->get(route('admin.website.editor'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Admin/Website/ThemeEditor')
                ->where('rows.0.key', PageEditor::HEAD)
                ->where('maxShortcuts', StoreHeader::MAX_SHORTCUTS)
                ->where('shortcutCategories', [
                    ['id' => $this->addons->id, 'name' => 'إضافات', 'shown' => 0],
                    ['id' => $this->bouquets->id, 'name' => 'باقات', 'shown' => 1],
                    ['id' => $this->gifts->id, 'name' => 'هدايا', 'shown' => 0],
                ]));
    }
}
