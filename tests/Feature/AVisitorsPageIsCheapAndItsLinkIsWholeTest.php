<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * صفحةُ الزائر: ما تُكلّف القاعدةَ، وما تُظهر حين يُلصَق رابطُها.
 *
 * ═══ وهما عطبان قِيسا لا خُمِّنا ═══
 *
 * الأوّل: رفُّ متجرٍ بمئة صنفٍ كان يسأل القاعدةَ مئتي سؤالٍ زائدٍ عن عملةٍ
 * واحدةٍ لا تتبدّل — `Storefront::currency` داخل كلّ بطاقة. وفوقها
 * إعداداتُ الموقع تُقرأ سبعَ عشرةَ مرّةً في الصفحة الواحدة. أربعةٌ وخمسون
 * استعلامًا لصفحةٍ عامّةٍ يفتحها كلُّ زبون، وتكبر بكِبَر المحلّ.
 *
 * والثاني: بطاقةُ المشاركة. وزبونُ محلّ وردٍ في عُمان يصل من رسالةِ واتساب
 * لا من بحثٍ غالبًا، والبطاقةُ كانت تخرج بلا عنوانٍ ولا وصف — فتبدو رابطًا
 * مكسورًا، ولا يُضغط.
 *
 * ولا يُقاس هنا رقمٌ بعينه بل شكلُ المنحنى: صفحةٌ لا تكبر بكِبَر الرفّ.
 */
class AVisitorsPageIsCheapAndItsLinkIsWholeTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Category $cat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        $this->shop = Business::create([
            'name' => 'ريبون', 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'address' => 'الخوير', 'email' => 'hi@ribbon.om',
            'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);

        MarketingSettings::save($this->shop->id, 'website', [
            'store_on' => '1', 'store_pay_cod' => '1',
            'store_about' => 'محلُّ وردٍ في الخوير.',
        ]);

        $this->cat = Category::create(['business_id' => $this->shop->id, 'name' => 'باقات']);
    }

    private function stock(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Product::create([
                'business_id' => $this->shop->id, 'name' => 'باقة '.$i, 'price' => 10,
                'category_id' => $this->cat->id, 'cost' => 4, 'quantity' => 10,
                'active' => true, 'published' => true, 'image' => '/storage/p'.$i.'.jpg',
            ]);
        }
    }

    /**
     * كم استعلامًا كلّفت هذه الصفحة؟
     *
     * وتُنسى الذاكرةُ قبل القياس: كلُّ طلبٍ عند الزائر يبدأ باردًا، فالقياسُ
     * الصادق يبدأ باردًا مثلَه.
     */
    private function cost(string $path): int
    {
        MarketingSettings::forget();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/s/ribbon'.$path)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /* ═══════════ ما تُكلّف ═══════════ */

    /**
     * رفٌّ بستّين صنفًا ككشٍّ باثني عشر — والفرقُ صفر.
     *
     * وهو الحارسُ الذي يموت لو عادت العملةُ تُقرأ داخل البطاقة: الفرقُ حينها
     * ستٌّ وتسعون استعلامًا لا صفر.
     */
    public function test_the_shelf_does_not_grow_more_expensive_as_the_shop_fills(): void
    {
        $this->stock(12);
        $small = $this->cost('/shop');

        $this->stock(48);
        $big = $this->cost('/shop');

        $this->assertSame(
            $small, $big,
            'رفُّ ستّين صنفًا كلّف '.$big.' استعلامًا ورفُّ اثني عشر كلّف '.$small.
            ' — الصفحةُ تكبر بكِبَر المحلّ، وذلك ما لا يُرى إلّا عند أكبر الزبائن.',
        );
    }

    /**
     * وسقفٌ مطلقٌ فوقه: صفحةٌ عامّةٌ يفتحها كلُّ زبون لا تسأل القاعدةَ
     * ثلاثين سؤالًا. وكانت أربعةً وخمسين.
     *
     * والرقمُ فسيحٌ عن قصد — هو سقفٌ يمنع الانحدار، لا هدفٌ يُلاحَق.
     */
    public function test_the_landing_page_stays_under_its_ceiling(): void
    {
        $this->stock(12);

        $this->assertLessThanOrEqual(30, $this->cost(''), 'الرئيسيةُ تجاوزت سقفَ الاستعلامات');
        $this->assertLessThanOrEqual(30, $this->cost('/shop'), 'الرفُّ تجاوز سقفَ الاستعلامات');
        $this->assertLessThanOrEqual(30, $this->cost('/about'), '«من نحن» تجاوزت سقفَ الاستعلامات');
    }

    /**
     * وما حُفظ في الذاكرة يُبطَل بالكتابة — أيًّا كان البابُ الذي كتب.
     *
     * والكتابةُ هنا بالنموذج مباشرةً لا بـ`MarketingSettings::save`: تسعةٌ
     * وثمانون موضعًا في النظام تكتب هذا الجدول هكذا، ولو عُلِّق الإبطالُ على
     * ذلك الباب وحده لَقرأ موضعٌ كتب بالنموذج قيمتَه القديمة في الطلب نفسِه.
     */
    public function test_a_setting_written_is_read_back_at_once(): void
    {
        $this->assertSame('', MarketingSettings::group($this->shop->id, 'website')['store_seo_title'] ?? 'x');

        Setting::updateOrCreate(
            ['business_id' => $this->shop->id, 'key' => 'store_seo_title'],
            ['value' => 'وردُ الخوير'],
        );

        $this->assertSame(
            'وردُ الخوير',
            MarketingSettings::group($this->shop->id, 'website')['store_seo_title'] ?? '',
            'الذاكرةُ أجابت بما كان قبل الكتابة — والتاجرُ يحفظ فلا يرى حفظَه.',
        );
    }

    /** كم استعلامًا سألت القاعدةَ هذه الجملة؟ */
    private function queriesWhile(callable $what): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $what();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * وذاكرةُ متجرٍ ليست ذاكرةَ جاره: إبطالُ الأوّل لا يمسّ الثاني.
     *
     * ولا يُقاس ذلك بالقيمة — فالقيمةُ صحيحةٌ في الحالين، تُقرأ من الذاكرة
     * أو من الجدول. إنّما يُقاس بعدد الاستعلامات: من نُسي سأل، ومن بقي سكت.
     */
    public function test_forgetting_one_shop_does_not_forget_the_others(): void
    {
        $other = Business::create(['name' => 'جار', 'type' => 'محل', 'status' => 'نشط', 'site_slug' => 'jar', 'phone' => '968']);
        MarketingSettings::save($other->id, 'website', ['store_seo_title' => 'عنوانُ الجار']);

        MarketingSettings::group($this->shop->id, 'website');
        MarketingSettings::group($other->id, 'website');

        MarketingSettings::forget($this->shop->id);

        $asked = $this->queriesWhile(fn () => MarketingSettings::group($other->id, 'website'));

        $this->assertSame(0, $asked, 'إبطالُ ذاكرةِ متجرٍ محا ذاكرةَ جاره — فكلُّ حفظٍ يُكلّف النظامَ كلَّه.');
        $this->assertSame('عنوانُ الجار', MarketingSettings::group($other->id, 'website')['store_seo_title'] ?? '');
    }

    /** ومن نُسي يسأل الجدولَ من جديدٍ — وإلّا فالإبطالُ اسمٌ بلا فعل */
    public function test_a_forgotten_shop_reads_the_table_again(): void
    {
        MarketingSettings::group($this->shop->id, 'website');
        $this->assertSame(0, $this->queriesWhile(fn () => MarketingSettings::group($this->shop->id, 'website')));

        MarketingSettings::forget($this->shop->id);

        $this->assertGreaterThan(
            0, $this->queriesWhile(fn () => MarketingSettings::group($this->shop->id, 'website')),
            'أُبطلت الذاكرةُ ولم يُسأل الجدول — فالإبطالُ لم يقع.',
        );
    }

    /* ═══════════ ما تُظهر حين تُشارَك ═══════════ */

    /** بطاقةُ المشاركة كاملةٌ — عنوانٌ ووصفٌ ورابطٌ ونوعٌ واسمُ المحلّ */
    public function test_a_shared_link_carries_a_whole_card(): void
    {
        $this->stock(3);
        $html = (string) $this->get('/s/ribbon')->assertOk()->getContent();

        foreach (['og:type', 'og:site_name', 'og:title', 'og:description', 'og:url', 'twitter:card'] as $tag) {
            $this->assertStringContainsString(
                'property="'.$tag.'"', str_replace('name="twitter:card"', 'property="twitter:card"', $html),
                'لا '.$tag.' — الرابطُ يخرج في واتساب كأنّه مكسور.',
            );
        }

        $this->assertStringContainsString('content="ريبون"', $html, 'اسمُ المحلّ ليس في بطاقة المشاركة');
    }

    /**
     * وصورةُ المشاركة مطلقةٌ لا نسبيّة.
     *
     * واتساب يقرؤها من خادمه لا من متصفّح الزائر، فلا مضيفَ يُكمل به
     * `‎/storage/…‎`. ورابطٌ نسبيٌّ هناك لا صورةَ له ولا خطأ — تُعرض البطاقةُ
     * بلا صورةٍ ولا يُعرف لماذا.
     */
    public function test_the_share_image_is_absolute(): void
    {
        $this->stock(3);
        MarketingSettings::save($this->shop->id, 'website', ['store_hero_image' => '/storage/hero.jpg']);

        $html = (string) $this->get('/s/ribbon')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '~<meta property="og:image" content="https?://[^"]+/storage/hero\.jpg"~',
            $html,
            'صورةُ المشاركة نسبيّةٌ — فلا تُعرض في المحادثة.',
        );
    }

    /* ═══════════ وبابٌ مفتوحٌ بعدّاد ═══════════ */

    /**
     * بابُ الإتمام يكتب طلبًا حقيقيًّا في الدفتر — فلا يُترك بلا حدّ.
     *
     * وبرنامجٌ صغير كان يُغرق تاجرًا بألف طلبٍ في دقيقة: قيودٌ لا بضاعةَ
     * خلفها، وإشعاراتٌ لا آخرَ لها، ولا حسابَ يُوقَف لأنّ لا حسابَ هناك.
     *
     * ولا يُقاس هنا نجاحُ الطلب — بل أنّ المحاولةَ الحاديةَ عشرة تُردّ
     * بـ٤٢٩ لا بـ٤٢٢. فالرفضُ لسببٍ في البيانات جوابٌ، والإغراقُ لا جواب.
     */
    public function test_the_checkout_door_counts_its_visitors(): void
    {
        $codes = [];

        for ($i = 0; $i < 12; $i++) {
            $codes[] = $this->postJson('/s/ribbon/checkout', [])->getStatusCode();
        }

        $this->assertContains(429, $codes, 'بابُ الإتمام يقبل اثنتي عشرة محاولةً في الدقيقة بلا حدّ — وهو يكتب في الدفتر.');
    }

    /** وبابُ التسعير معه — أفسحَ حدًّا لأنّه يُنادى مع كلّ تبديل */
    public function test_the_quote_door_counts_its_visitors(): void
    {
        $limited = false;

        for ($i = 0; $i < 70; $i++) {
            if ($this->postJson('/s/ribbon/quote', [])->getStatusCode() === 429) {
                $limited = true;
                break;
            }
        }

        $this->assertTrue($limited, 'بابُ التسعير بلا عدّاد');
    }

    /**
     * والطرقُ الثلاثُ إلى المتجر بابٌ واحد — فلا يُحرس أحدُها ويُترك أخواه.
     *
     * ومن أُغلق عليه `‎/s/ribbon/checkout‎` يجرّب `متجري.abaadapp.om/checkout`
     * ويصل. فيُسأل التسجيلُ نفسُه لا الطلبُ الواحد.
     */
    public function test_all_three_roads_to_the_shop_are_counted(): void
    {
        foreach (['store.checkout', 'store.show.checkout', 'store.custom.checkout'] as $name) {
            $this->assertContains(
                'throttle:10,1',
                \Illuminate\Support\Facades\Route::getRoutes()->getByName($name)->gatherMiddleware(),
                'مسارُ «'.$name.'» بلا عدّاد — وهو بابٌ يكتب في الدفتر.',
            );
        }

        foreach (['store.quote', 'store.show.quote', 'store.custom.quote'] as $name) {
            $this->assertContains(
                'throttle:60,1',
                \Illuminate\Support\Facades\Route::getRoutes()->getByName($name)->gatherMiddleware(),
                'مسارُ «'.$name.'» بلا عدّاد',
            );
        }
    }

    /* ═══════════ ولا بابَ يُغلق بلا كلمة ═══════════ */

    /**
     * كلُّ حالةٍ تُردّ يُقال للزبون فيها شيء — ولكلِّ سببٍ كلمتُه.
     *
     * ═══ والعطبُ الذي وُضع لأجله ═══
     *
     * صفحةُ الإتمام كانت تقرأ `errors` وحدَها، فكلُّ جوابٍ ليس ٤٢٢ يخرج
     * **صامتًا**: يضغط الزبون «تأكيد الطلب»، يعود الزرُّ قابلًا للضغط، ولا
     * تظهر كلمة. ومن ملأ نموذجًا كاملًا ثمّ لم يُجَب يترك السلّة ولا يعود.
     *
     * وأكثرُها وقوعًا ٤١٩ — جلسةٌ انتهت على صفحةٍ بقيت مفتوحة.
     */
    public function test_every_shut_door_says_why(): void
    {
        $says = \App\Support\Store\RibbonTexts::doorSays('ar');

        foreach (['429', '419', '401', '403', '_'] as $status) {
            $this->assertArrayHasKey($status, $says, 'حالةُ «'.$status.'» تُردّ بلا كلمة');
            $this->assertNotSame('', trim($says[$status]));
        }
    }

    /**
     * وثلاثُ كلماتٍ لا كلمةٌ واحدة: لكلِّ سببٍ علاجُه.
     *
     * ومن انتهت جلستُه لا يُقال له «أعد المحاولة» — تُعاد ألفًا ولا تنجح.
     * ومن ضغط كثيرًا لا يُقال له «أعد تحميل الصفحة» — التحميلُ لا يُنقص
     * عدّادَه. والفرقُ بين الكلمتين هو الفرقُ بين زبونٍ يُتمّ وزبونٍ ينصرف.
     */
    public function test_a_counted_door_and_a_stale_session_do_not_say_the_same_thing(): void
    {
        $says = \App\Support\Store\RibbonTexts::doorSays('ar');

        $this->assertNotSame($says['429'], $says['419'], 'الانتظارُ وإعادةُ التحميل علاجان — لا كلمةٌ واحدة');
        $this->assertNotSame($says['429'], $says['_']);
        $this->assertNotSame($says['419'], $says['_']);
    }

    /** ويُقال بلغة الزبون — لا بلغة النظام */
    public function test_the_shut_door_speaks_the_visitors_tongue(): void
    {
        $ar = \App\Support\Store\RibbonTexts::doorSays('ar');
        $en = \App\Support\Store\RibbonTexts::doorSays('en');

        foreach (array_keys($ar) as $k) {
            $this->assertNotSame($ar[$k], $en[$k], 'حالةُ «'.$k.'» تخرج بالعربية للزبون الإنجليزيّ');
            $this->assertDoesNotMatchRegularExpression('/\p{Arabic}/u', $en[$k]);
        }
    }

    /** وتبلغ الصفحةَ نفسَها — لا تبقى في الخادم */
    public function test_the_page_carries_what_it_will_say(): void
    {
        $this->stock(3);

        // و`@json` يُهرّب العربيةَ إلى `\uXXXX` — فيُقارَن بما يخرج لا بما كُتب
        $asWritten = fn (string $m) => trim((string) json_encode($m), '"');

        /*
         * ويُبحث عن **الزوج** لا عن النصّ وحدَه: كلُّ نصوص الواجهة تُرسَل
         * إلى صفحة الإتمام في `T` العامّة، فوجودُ الجملة في الصفحة لا يدلّ
         * على أنّ الخريطةَ وصلت. ومن أفرغ `SAYS` بقي الحارسُ راضيًا.
         */
        $pair = fn (array $map, string $k) => '"'.$k.'":"'.$asWritten($map[$k]).'"';

        $ar = (string) $this->get('/s/ribbon/checkout')->assertOk()->getContent();
        $this->assertStringContainsString(
            $pair(\App\Support\Store\RibbonTexts::doorSays('ar'), '429'), $ar,
            'الصفحةُ لا تحمل خريطةَ ما ستقوله حين يُردّ بابُها.',
        );
        $this->assertStringContainsString($pair(\App\Support\Store\RibbonTexts::doorSays('ar'), '_'), $ar);

        $en = (string) $this->get('/s/ribbon/checkout?lang=en')->assertOk()->getContent();
        $this->assertStringContainsString($pair(\App\Support\Store\RibbonTexts::doorSays('en'), '419'), $en);
        $this->assertStringNotContainsString(
            $asWritten(\App\Support\Store\RibbonTexts::doorSays('ar')['419']), $en,
            'الزبونُ الإنجليزيُّ يحمل رسالةً عربيّة',
        );

        // والسلّةُ تنادي البابَ نفسَه — فتحمل خريطتَه
        $this->assertStringContainsString(
            $pair(\App\Support\Store\RibbonTexts::doorSays('ar'), '429'),
            (string) $this->get('/s/ribbon/cart')->assertOk()->getContent(),
        );
    }

    /* ═══════════ وما لا يُفهرَس ═══════════ */

    /**
     * صفحةُ التأكيد لا تدخل نتائجَ البحث — وفيها طلبُ زبونٍ بعينه.
     *
     * رقمُ طلبه وأصنافُه ومبلغُه وحسابُ التحويل. والرابطُ لا يُخمَّن، لكنّ
     * محرّك بحثٍ يزحف إليه من مشاركةٍ عابرة يجعله مفهرسًا للجميع — وهي
     * حجّةُ `public/paper.blade.php` نفسُها.
     */
    public function test_the_private_pages_are_never_indexed(): void
    {
        $t = \App\Support\Store\RibbonTexts::for('ar');
        $identity = \App\Support\Website\MerchantData::identity((int) $this->shop->id);

        foreach (\App\Support\Store\StoreSeo::PRIVATE_PAGES as $page) {
            $this->assertFalse(
                \App\Support\Store\StoreSeo::head($this->shop, $page, $t, $identity)['index'],
                'صفحةُ «'.$page.'» تدخل نتائجَ البحث — وليست صفحةً تُبحَث.',
            );
        }
    }

    /** ويصل المنعُ إلى الصفحة نفسِها لا إلى الحساب وحدَه */
    public function test_the_cart_says_noindex_in_its_head(): void
    {
        $this->stock(3);

        $this->get('/s/ribbon/cart')->assertOk()->assertSee('data-testid="rb-noindex"', false);
        $this->get('/s/ribbon/checkout')->assertOk()->assertSee('data-testid="rb-noindex"', false);
    }

    /** وصفحاتُ المتجر العامّة تبقى مفهرَسةً — وإلّا فالمنعُ شمل ما لا يخصّه */
    public function test_the_public_pages_are_still_indexed(): void
    {
        $this->stock(3);

        $this->get('/s/ribbon')->assertOk()->assertDontSee('data-testid="rb-noindex"', false);
        $this->get('/s/ribbon/shop')->assertOk()->assertDontSee('data-testid="rb-noindex"', false);
        $this->get('/s/ribbon/about')->assertOk()->assertDontSee('data-testid="rb-noindex"', false);
    }

    /** ومن أرسل رابطَ باقةٍ أراده يرى الباقةَ لا واجهةَ المحلّ */
    public function test_a_shared_product_shows_the_product_not_the_storefront(): void
    {
        $this->stock(3);
        MarketingSettings::save($this->shop->id, 'website', ['store_hero_image' => '/storage/hero.jpg']);

        $id = (int) Product::where('business_id', $this->shop->id)->orderBy('id')->value('id');
        $html = (string) $this->get('/s/ribbon/p/'.$id)->assertOk()->getContent();

        $this->assertStringContainsString('/storage/p0.jpg"', $html);
        $this->assertDoesNotMatchRegularExpression(
            '~<meta property="og:image" content="[^"]*hero\.jpg"~',
            $html,
            'صفحةُ الصنف تُشارَك بصورة الواجهة — فكلُّ الباقات رابطٌ واحدٌ في المحادثة.',
        );
    }
}
