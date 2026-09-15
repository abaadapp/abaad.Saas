<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Models\Website;
use App\Support\Storefront;
use App\Support\Website\Builder;
use App\Support\Website\Published;
use App\Support\Website\Publisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لكلّ صفحةٍ في الموقع عنوانٌ يُفتح — ولكلّ رابطٍ فيه وجهةٌ داخله.
 *
 * ═══ ما كان ═══
 *
 * أبعادُ تبني للتاجر موقعًا بأربع صفحات — الرئيسية والمتجر ومن نحن وتواصل
 * معنا — وتكتب في ترويسته وتذييله قائمةً تُشير إليها كلِّها (`Website\Nav`).
 * ثمّ **لا تخدم منها إلّا الرئيسية**: لا مسارَ لصفحةٍ داخليّة في النظام
 * أصلًا. فكلُّ رابطٍ في قائمة كلِّ موقعٍ منشور يردّ ٤٠٤.
 *
 * وطبقةُ الرسم تقبل الصفحة منذ بُنيت (`Site` لها خاصّية `page`) — الناقصُ
 * كان العنوانَ وحده، والمدخلَ الذي يقول للرسم أيَّ صفحةٍ يرسم.
 *
 * ═══ وأسوأ منه على المسار البديل ═══
 *
 * الروابطُ مكتوبةٌ من الجذر (`/shop`)، وهي صحيحةٌ على النطاق الفرعيّ وعلى
 * نطاق التاجر. أمّا على `app.abaadapp.om/s/متجري` — وهو الطريق العامل على
 * الإنتاج اليوم — فجذرُ المضيف جذرُ **أبعاد**: «الرئيسية» تُخرج زبونَ
 * التاجر إلى **صفحة دخول أبعاد**، وسائرُها إلى ٤٠٤.
 *
 * وتُحلّ في الخادم لا في طبقة الرسم: تلك مصدرُها `storefront/src/site`
 * وتُنسخ إلى هنا بأمرٍ واحد (انظر `RendererParityTest`)، فتعديلُها هنا
 * يُمحى عند أوّل مزامنة ويعود العطبُ صامتًا.
 */
class EveryPageOfTheSiteHasAnAddressTest extends TestCase
{
    use RefreshDatabase;

    /** ما بين علامتين — لقراءة جسد الخادم وحده */
    private function between(string $haystack, string $from, string $to): string
    {
        $start = strpos($haystack, $from);

        if ($start === false) {
            return '';
        }

        $end = strpos($haystack, $to, $start);

        return $end === false ? '' : substr($haystack, $start, $end - $start);
    }

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create([
            'name' => 'ورود مسقط', 'type' => 'محل ورود', 'status' => 'نشط',
            'site_slug' => 'wrood', 'phone' => '96890000000', 'city' => 'مسقط',
        ]);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function publish(): Website
    {
        $site = Builder::create($this->business, 'store', 'modern', $this->owner->id);
        Publisher::publish($site, $this->owner->id);

        return $site;
    }

    /** @return list<string> مساراتُ صفحات الموقع المنشورة */
    private function slugs(): array
    {
        $doc = Published::forBusiness((int) $this->business->id)['site'];

        return collect($doc['pages'])
            ->filter(fn ($p) => ($p['status'] ?? 'published') === 'published')
            ->pluck('slug')->map(fn ($s) => (string) $s)->values()->all();
    }

    /* ══════════════ ١ · لكلّ صفحةٍ عنوان ══════════════ */

    /**
     * كلُّ صفحةٍ يبنيها النظام تُفتح بعنوانها — لا الرئيسيةُ وحدها.
     *
     * والقالبُ يبني أربعًا، فلو خُدمت واحدةٌ لَسقط هذا الحارس بثلاثة.
     */
    public function test_every_page_the_builder_makes_has_an_address(): void
    {
        $this->publish();

        $slugs = $this->slugs();
        $this->assertGreaterThan(1, count($slugs), 'قالبٌ بصفحةٍ واحدة لا يُثبت شيئًا');

        foreach ($slugs as $slug) {
            $url = $slug === '/' ? '/s/wrood' : '/s/wrood'.$slug;

            $this->get($url)->assertOk();
        }
    }

    /** وكلُّ عنوانٍ يرسم صفحتَه هو — لا الرئيسيةَ بأربعة عناوين */
    public function test_each_address_draws_its_own_page(): void
    {
        $this->publish();

        foreach (['/' => '/', '/about' => '/about', '/contact' => '/contact'] as $path => $expected) {
            $url = $path === '/' ? '/s/wrood' : '/s/wrood'.$path;

            $this->get($url)->assertOk()->assertSee('data-page="'.$expected.'"', false);
        }
    }

    /**
     * ومسارٌ لا صفحةَ له ٤٠٤ — لا الرئيسيةُ مكانَه.
     *
     * صفحةٌ تُردّ بـ٢٠٠ عن عنوانٍ لا وجود له تُفهرَس مرّتين تحت عنوانين،
     * ويبقى من تبع رابطًا قديمًا يظنّ أنّه وصل.
     */
    public function test_an_address_with_no_page_is_not_the_home_page(): void
    {
        $this->publish();

        $this->get('/s/wrood/la-page')->assertNotFound();
    }

    /** ومتجرٌ لم يُنشر لا تُفتح صفحاتُه الداخليّة كما لا تُفتح رئيسيتُه */
    public function test_an_unpublished_site_has_no_inner_pages_either(): void
    {
        Builder::create($this->business, 'store', 'modern', $this->owner->id);

        $this->get('/s/wrood')->assertNotFound();
        $this->get('/s/wrood/about')->assertNotFound();
    }

    /** والصفحةُ المسوّدة ليست عنوانًا عامًّا — لم يرضَ عنها صاحبُها */
    public function test_a_draft_page_is_not_served(): void
    {
        $site = $this->publish();

        $site->pages()->where('slug', '/about')->update(['status' => 'draft']);
        Publisher::publish($site->fresh(), $this->owner->id);

        $this->get('/s/wrood')->assertOk();
        $this->get('/s/wrood/about')->assertNotFound();
    }

    /* ══════════════ ٢ · ولكلّ رابطٍ وجهةٌ داخل المتجر ══════════════ */

    /**
     * كلُّ رابطٍ داخليٍّ في المستند يُفتح — يُجرَّب واحدًا واحدًا.
     *
     * وهذا هو الحارسُ الذي يمنع عودة العطب: لا يكفي أن تُفتح الصفحاتُ
     * بعناوينها، بل أن تكون الوجهةُ المكتوبة في القائمة هي ذلك العنوان.
     */
    public function test_every_internal_link_in_the_document_opens(): void
    {
        $this->publish();

        $html = $this->get('/s/wrood')->assertOk()->getContent();
        $doc = json_decode(
            (string) preg_replace('#.*<script id="site-doc" type="application/json">(.*?)</script>.*#s', '$1', $html),
            true,
        );

        $this->assertIsArray($doc, 'لم تُقرأ لقطة الموقع من الصفحة');

        $links = [];
        array_walk_recursive($doc, function ($value, $key) use (&$links) {
            if (is_string($value) && ($key === 'href' || str_ends_with((string) $key, '_href'))
                && str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
                $links[] = $value;
            }
        });

        $links = array_values(array_unique($links));
        $this->assertGreaterThanOrEqual(4, count($links), 'مستندٌ بلا روابطَ داخليّة لا يُثبت شيئًا');

        foreach ($links as $href) {
            $this->get($href)->assertOk("رابطٌ في الموقع لا يُفتح: {$href}");
        }
    }

    /**
     * ولا رابطَ يخرج من المتجر إلى أبعاد.
     *
     * «الرئيسية» على المسار البديل كانت «/» — أي `app.abaadapp.om/`، صفحةُ
     * دخول أبعاد في يد زبون التاجر.
     */
    public function test_no_link_leaves_the_shop_for_abaad(): void
    {
        $this->publish();

        $html = $this->get('/s/wrood')->assertOk()->getContent();

        foreach (['"\/"', '"\/shop"', '"\/about"', '"\/contact"'] as $bare) {
            $this->assertStringNotContainsString(
                '"href":'.$bare,
                $html,
                'رابطٌ من جذر المضيف لا من جذر المتجر',
            );
        }

        $this->assertStringContainsString('"href":"\/s\/wrood\/shop"', $html);

        /*
         * و«الرئيسية» القاعدةُ نفسُها لا القاعدةُ وشرطةٌ معلّقة.
         *
         * `/s/wrood/` و`/s/wrood` عنوانان لصفحةٍ واحدة، وقائمةٌ تُشير إلى
         * أحدهما و`canonical` إلى الآخر تفرّق ما يجب أن يجتمع.
         */
        $this->assertStringContainsString('"href":"\/s\/wrood"', $html);
        $this->assertStringNotContainsString('"href":"\/s\/wrood\/"', $html);
    }

    /**
     * وعلى نطاقٍ جذرُه جذرُ المتجر تبقى الروابطُ كما كُتبت.
     *
     * والقاعدةُ تُضاف حيث تلزم وحدها: إضافتُها على النطاق الفرعيّ كانت
     * ستُخرج `متجري.abaadapp.om/s/متجري/shop` — عنوانًا لا وجود له.
     */
    public function test_on_a_subdomain_the_links_stay_bare(): void
    {
        config(['storefront.subdomains' => true]);
        $this->publish();

        $html = $this->get('http://wrood.abaadapp.om/')->assertOk()->getContent();

        $this->assertStringContainsString('"href":"\/shop"', $html);
        $this->assertStringNotContainsString('/s/wrood', $html);

        // وصفحاتُه الداخليّة تُفتح على نطاقه
        $this->get('http://wrood.abaadapp.om/about')->assertOk()->assertSee('data-page="/about"', false);
    }

    /* ══════════════ ٣ · ولكلّ عنوانٍ رأسُه ونصُّه ══════════════ */

    /** عنوانُ الصفحة الأصليُّ عنوانُها هي — لا جذرُ الموقع لأربع صفحات */
    public function test_each_page_points_at_itself(): void
    {
        $this->publish();

        $this->get('/s/wrood')->assertSee('rel="canonical" href="'.url('/s/wrood').'"', false);
        $this->get('/s/wrood/about')->assertSee('rel="canonical" href="'.url('/s/wrood').'/about"', false);
    }

    /**
     * ونصُّ الصفحة نصُّها — لا نصُّ الموقع كلِّه على كلّ عنوان.
     *
     * الجسدُ يُرسم في المتصفّح، والزاحفُ يقرأ ما يخرج من الخادم. وكان
     * يخرج نصُّ الصفحات الأربع على العنوان الواحد الذي كان يُخدَم.
     */
    public function test_each_address_carries_its_own_text(): void
    {
        $this->publish();

        /*
         * والمقروءُ هو جسدُ الخادم لا الصفحةُ كلُّها.
         *
         * اللقطةُ المنشورة تُمرَّر كاملةً في وسم `site-doc` — بصفحاتها
         * الأربع — لأنّها وثيقةٌ واحدة. والذي كان يفترق هو **النصُّ
         * المرسوم في الخادم**: هو ما يقرؤه الزاحف ومن لا JavaScript عنده.
         */
        $body = fn (string $url) => $this->between(
            (string) $this->get($url)->getContent(),
            '<div id="site"',
            '</div>',
        );

        $home = $body('/s/wrood');
        $about = $body('/s/wrood/about');

        $this->assertStringContainsString('لماذا نحن', $home);
        $this->assertStringNotContainsString('لماذا نحن', $about);

        $aboutLine = 'ورود مسقط نشاطٌ يخدم زبائنه';
        $this->assertStringContainsString($aboutLine, $about);
        $this->assertStringNotContainsString($aboutLine, $home);
    }

    /* ══════════════ ٤ · ولا يبتلع مسارُ المتاجر عناوينَ أبعاد ══════════════ */

    /**
     * نمطُ «مضيفٍ ليس لنا» يستثني مضيفات أبعاد — **في القراءتين**.
     *
     * ═══ وهذا الحارسُ مكتوبٌ بدمٍ ═══
     *
     * الاستثناء كان `(?!.*abaadapp\.om$)`. وبلا `route:cache` يُطابَق نمطُ
     * المضيف على المضيف وحدَه، فـ`$` نهايتُه و`app.abaadapp.om` يُستثنى.
     * ومع التخزين يدمج المُطابِقُ المُصرَّف المضيفَ والمسارَ في سلسلةٍ واحدة
     * بفاصلٍ **نقطة** — `app.abaadapp.om./login` — فتصير `$` نهايةَ الاثنين:
     * والسلسلةُ تنتهي بـ`/login` لا بـ`abaadapp.om`، فيمرّ الاستثناء ويلتقط
     * المسارُ الجامعُ كلَّ عنوانٍ في النظام.
     *
     * ووقع على الإنتاج: `/login` و`/health` و`/s/{slug}` كلُّها ٤٠٤. ولم
     * تره السويتةُ كلُّها لأنّ الاختبارات لا تُخزّن المسارات ولا تُنادى على
     * مضيف الإنتاج — فيُقاس هنا الشكلان اللذان يُطابَق عليهما فعلًا.
     */
    public function test_the_foreign_host_pattern_excludes_abaad_in_both_readings(): void
    {
        $regex = '#^(?:'.Storefront::foreignHost().')$#Diu';
        $merged = '#^(?:'.Storefront::foreignHost().')\.#Diu';

        // القراءةُ الأولى: المضيفُ وحدَه (بلا تخزين المسارات)
        foreach (['app.abaadapp.om', 'abaadapp.om', 'wrood.abaadapp.om'] as $ours) {
            $this->assertDoesNotMatchRegularExpression($regex, $ours, "مضيفُ أبعاد «{$ours}» لم يُستثنَ");
        }

        // والقراءةُ الثانية: المضيفُ والمسارُ مدموجين بنقطة (مع التخزين)
        foreach (['app.abaadapp.om./login', 'app.abaadapp.om./health', 'app.abaadapp.om./s/saad'] as $subject) {
            $this->assertDoesNotMatchRegularExpression($merged, $subject, "«{$subject}» التقطه مسارُ المتاجر");
        }

        // ونطاقُ تاجرٍ حقيقيّ يُلتقط — الحدُّ يستثني ولا يُقفل
        $this->assertMatchesRegularExpression($regex, 'wrood.om');
        $this->assertMatchesRegularExpression($merged, 'wrood.om./about');
    }

    /**
     * ونمطُ النطاق الفرعيّ يحمل الحدَّ نفسَه — وهو الذي نجا لأنّه يحمله.
     *
     * `Storefront::pattern` تستثني المحجوز بـ`(?:\.|$)` منذ بُنيت، وتعليقُها
     * يقول لمَ. وهذا يمنع سقوطَه يوم يُبدَّل.
     */
    public function test_the_subdomain_pattern_keeps_the_same_boundary(): void
    {
        $merged = '#^(?:'.Storefront::pattern().')\.abaadapp\.om\.#Diu';

        $this->assertDoesNotMatchRegularExpression($merged, 'app.abaadapp.om./login');
        $this->assertMatchesRegularExpression($merged, 'wrood.abaadapp.om./about');
    }
}
