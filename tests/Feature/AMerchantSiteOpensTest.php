<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use App\Support\Website\Builder;
use App\Support\Website\Published;
use App\Support\Website\Publisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Support\Website\Domains;

/**
 * موقعُ التاجر يُفتح — بابٌ يصله زبونٌ، لا مستندٌ ينتظر عارضًا.
 *
 * وكان يُبنى ويُنشر ولا يُفتح: اللقطة تخرج من `/site/{host}` سليمةً، والذي
 * يحوّلها صفحةً تطبيقٌ في مستودعٍ آخر لم يُنشر على خادمٍ قطّ. فالتاجر يضغط
 * «انشر» ويقرأ في لوحته عنوانًا — ويفتحه فلا يجد شيئًا.
 *
 * فصارت أبعاد ترسمه بنفسها. وما يحرسه هذا الملفّ:
 *
 * ١) **البانِي يتقدّم على المتجر البسيط.** من بنى موقعه ونشره يجب أن يرى
 *    ما بناه، لا شبكةَ صورٍ لم يصنعها.
 * ٢) **الصفحة تُقرأ بلا JavaScript.** ما يُرسم في المتصفّح لا يقرؤه زاحفٌ
 *    ولا من انقطعت عنه الحزمة — فنصُّ الموقع يخرج من الخادم.
 * ٣) **بيانات التاجر لا تصير شيفرة.** اللقطة تُمرَّر في وسمٍ، وحرفٌ فيها
 *    لا يُغلقه.
 */
class AMerchantSiteOpensTest extends TestCase
{
    use RefreshDatabase;

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

    /** يبني موقعًا وينشره — ويردّ اللقطة كما يقرؤها الزائر */
    private function publish(): array
    {
        $site = Builder::create($this->business, 'store', 'modern', $this->owner->id);
        Publisher::publish($site, $this->owner->id);

        return Published::forBusiness((int) $this->business->id);
    }

    /* ------------------------------- البـاب ------------------------------- */

    public function test_a_published_site_opens_as_a_page(): void
    {
        $this->publish();

        $this->get('/s/wrood')
            ->assertOk()
            ->assertSee('<div id="site">', false)
            ->assertSee('id="site-doc"', false);
    }

    /** ولا يُفتح ما لم يُنشر: ٤٠٤ تقول «لا عنوان هنا»، والفراغُ يقول «مغلق» */
    public function test_a_site_that_was_never_published_is_not_a_page(): void
    {
        Builder::create($this->business, 'store', 'modern', $this->owner->id);

        $this->get('/s/wrood')->assertNotFound();
    }

    /** ومتجرٌ موقوف لا يُفتح موقعه — الحارس واحدٌ للبابين */
    public function test_a_suspended_shop_closes_its_site(): void
    {
        $this->publish();
        $this->business->update(['status' => 'موقوف']);

        $this->get('/s/wrood')->assertNotFound();
    }

    /* ------------------------ البانِي يتقدّم ------------------------ */

    /**
     * من بنى موقعًا ونشره يرى ما بناه — لا شبكةَ صورٍ لم يصنعها.
     *
     * وفي النظام طريقان إلى «موقع التاجر» بُنيا في وقتين، وعنوانُهما واحد.
     * فلو تقدّمت الصفحةُ البسيطة لَبنى التاجر موقعَه ونشره ثمّ فتح عنوانه
     * فوجد غيرَه — ولا شيء في لوحته يقول لماذا.
     */
    public function test_the_builder_site_wins_over_the_simple_storefront(): void
    {
        Setting::create(['business_id' => $this->business->id, 'key' => 'store_on', 'value' => '1']);
        $this->publish();

        $page = $this->get('/s/wrood')->assertOk();

        $page->assertSee('id="site-doc"', false);
        // وقالبُ المتجر البسيط لا يُرسم: علامتُه متغيّرُ ثيمته
        $page->assertDontSee('--accent:', false);
    }

    /** ومن لم يبنِ موقعًا تبقى له صفحتُه البسيطة — لا يُغلق بابٌ كان مفتوحًا */
    public function test_a_shop_without_a_built_site_keeps_the_simple_storefront(): void
    {
        Setting::create(['business_id' => $this->business->id, 'key' => 'store_on', 'value' => '1']);

        $this->get('/s/wrood')->assertOk()->assertSee('--accent:', false);
    }

    /* --------------------- ما يُقرأ بلا JavaScript --------------------- */

    /**
     * نصُّ الموقع يخرج من الخادم — وإلّا فالصفحة صندوقٌ أبيض.
     *
     * والزاحفُ يقرأ ما في HTML، ومن انقطعت عنه الحزمة يرى ما فيها. فلو
     * كان الجسد كلُّه في JavaScript لَقُرئت الصفحةُ «لا شيء هنا».
     */
    public function test_the_page_carries_its_text_without_javascript(): void
    {
        $found = $this->publish();
        $outline = Published::outline($found['site']);

        $this->assertNotEmpty($outline, 'لقطةٌ منشورة بلا سطرِ نصٍّ واحد');

        $page = $this->get('/s/wrood')->assertOk();

        foreach (array_slice($outline, 0, 3) as $line) {
            $page->assertSee($line, false);
        }
    }

    /** والعنوانُ والوصفُ في الرأس — فبطاقةُ الرابط في واتساب تُرسم بلا JavaScript */
    public function test_the_head_carries_title_and_share_card(): void
    {
        $found = $this->publish();
        $head = Published::head($found['site']);

        $this->get('/s/wrood')
            ->assertOk()
            ->assertSee('<title>'.e($head['title']).'</title>', false)
            ->assertSee('og:title', false)
            ->assertSee('rel="canonical"', false);
    }

    /**
     * والنصُّ يُستخرج من الكتالوج لا من أسماءٍ مكتوبة.
     *
     * قائمةٌ مكتوبة تفترق عن الكتالوج عند أوّل قسمٍ يُضاف: يُبنى ويُنشر
     * ويظهر في المتصفّح، ولا يظهر حرفٌ منه لمن لا JavaScript عنده.
     */
    public function test_the_outline_follows_the_catalogue_not_a_written_list(): void
    {
        $site = Builder::create($this->business, 'store', 'modern', $this->owner->id);

        $hero = $site->pages()->where('key', 'home')->first()
            ->sections()->where('type', 'hero')->first();

        $hero->update(['data' => array_merge($hero->data, [
            'title' => 'عنوانٌ كتبه التاجر',
            'subtitle' => 'وجملةٌ تحته',
        ])]);

        Publisher::publish($site->refresh(), $this->owner->id);

        $page = $this->get('/s/wrood')->assertOk();
        $page->assertSee('عنوانٌ كتبه التاجر', false);
        $page->assertSee('وجملةٌ تحته', false);
    }

    /* --------------------------- الحراسة --------------------------- */

    /**
     * حرفٌ في بيانات التاجر لا يُغلق وسمَ اللقطة.
     *
     * ولا نظريّةَ في هذا: تاجرٌ يكتب في نبذته وسمًا مغلقًا، فينتهي الوسم من
     * داخله ويصير ما بعده — وهو بياناتُه — شيفرةً تُنفَّذ في متصفّح كلّ زائر.
     */
    public function test_merchant_text_cannot_close_the_document_tag(): void
    {
        $site = Builder::create($this->business, 'store', 'modern', $this->owner->id);

        $hero = $site->pages()->where('key', 'home')->first()
            ->sections()->where('type', 'hero')->first();

        $hero->update(['data' => array_merge($hero->data, [
            'title' => '</script><script>alert(1)</script>',
        ])]);

        Publisher::publish($site->refresh(), $this->owner->id);

        $html = $this->get('/s/wrood')->assertOk()->getContent();
        $json = $this->between($html, '<script id="site-doc" type="application/json">', '</script>');

        $this->assertStringNotContainsString('<script>alert(1)</script>', $json);
        $this->assertIsArray(json_decode($json, true), 'اللقطة لم تعد JSON صالحًا');
    }

    /** والصيانةُ تردّ ٥٠٣ لا ٢٠٠ — فلا يحفظ محرّكُ البحث «نعود قريبًا» مكانَ المتجر */
    public function test_maintenance_answers_503_with_the_shops_own_name(): void
    {
        $site = Builder::create($this->business, 'store', 'modern', $this->owner->id);
        Publisher::publish($site, $this->owner->id);
        $site->update(['maintenance' => true, 'maintenance_message' => 'نعود بعد العيد']);

        $this->get('/s/wrood')
            ->assertStatus(503)
            ->assertSee('نعود بعد العيد', false)
            ->assertSee('noindex', false);
    }

    /* ------------------- والمستندُ لم يتبدّل عقدُه ------------------- */

    /**
     * وبابُ العارض الخارجيّ يبقى كما كان.
     *
     * القراءةُ انتقلت إلى `Published` ليقرأها البابان، وهذا يحرس أنّ
     * الانتقال لم يبدّل ما يردّه — فالعقدُ مع مستودعٍ آخر.
     */
    public function test_the_external_document_endpoint_keeps_its_shape(): void
    {
        Domains::attach($this->business, 'wrood.om');
        $this->publish();

        $this->getJson('/site/wrood.om')
            ->assertOk()
            ->assertJsonStructure(['published_at', 'version', 'site' => ['pages', 'brand', 'currency', 'locale', 'dir']]);
    }

    public function test_an_unknown_host_is_not_found(): void
    {
        $this->getJson('/site/nobody.om')->assertNotFound()->assertJson(['error' => 'not_found']);
    }

    /* ------------------------- العنوانُ الأصل ------------------------- */

    /**
     * و`canonical` يشير إلى ما يُخدَم لا إلى ما يُتمنّى.
     *
     * النطاقُ الفرعيّ يلزمه سجلُّ DNS بالحرف البدل وشهادةٌ مثله. وقبلهما
     * إشارةُ `canonical` إليه تدلّ محرّكَ البحث على عنوانٍ لا يُحلّ، وتترك
     * الصفحةَ الحيّة بلا فهرسة — فتضرّ حيث يُراد بها النفع.
     */
    public function test_canonical_points_at_the_address_the_server_actually_serves(): void
    {
        $this->publish();

        config(['storefront.subdomains' => false]);
        $this->get('/s/wrood')->assertOk()->assertSee('rel="canonical" href="'.url('/s/wrood').'"', false);

        config(['storefront.subdomains' => true]);
        $this->get('/s/wrood')->assertOk()->assertSee('rel="canonical" href="https://wrood.abaadapp.om"', false);
    }

    /** ولا يتكرّر سطرٌ قيل في العنوان أو الوصف */
    public function test_the_fallback_text_says_nothing_twice(): void
    {
        $this->publish();

        $html = $this->get('/s/wrood')->assertOk()->getContent();
        $body = $this->between($html, '<div id="site">', '</div>');

        $this->assertSame(1, substr_count($body, '<h1>'), 'أكثر من عنوانٍ أوّل');
    }

    private function between(string $haystack, string $start, string $end): string
    {
        $from = strpos($haystack, $start);
        $this->assertNotFalse($from, 'وسمُ اللقطة غير موجود في الصفحة');
        $from += strlen($start);

        return substr($haystack, $from, strpos($haystack, $end, $from) - $from);
    }
}
