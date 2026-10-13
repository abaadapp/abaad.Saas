<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\PosDevice;
use App\Models\Product;
use App\Models\User;
use App\Support\MarketingSettings;
use App\Support\Permissions;
use App\Support\PosTerminal;
use App\Support\Website\Blueprints;
use App\Support\Website\Builder;
use App\Support\Website\Domains;
use App\Support\Website\Publisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * واجهةُ النظام على app.abaadapp.om لا تُفهرَس — ومتاجرُ التجّار تُفهرَس كما كانت.
 *
 * أبعاد في نتائج البحث هي موقعُها abaadapp.om، لا شاشةُ دخولٍ بعنوان «Abad POS».
 * فجذرُ Inertia (`app.blade.php`) وحده يحمل noindex، ومنه تمرّ صفحاتُ النظام كلُّها:
 * Admin وAuth وPlatform وPos وProfile.
 *
 * والمتجرُ والموقعُ المنشور والمستنداتُ العامّة قوالبُ Blade مستقلّة، يقرّر كلٌّ منها
 * فهرستَه بنفسه، ولا يمرّ من هنا. وrobots.txt يبقى مفتوحًا: محرّكٌ مُنع من الزحف
 * لا يرى noindex، فيبقى العنوانُ في نتائجه.
 */
class TheSystemIsNotIndexedButShopsAreTest extends TestCase
{
    use RefreshDatabase;

    private const NOINDEX = '<meta name="robots" content="noindex, nofollow">';

    private Business $business;

    private Branch $branch;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'ورد الخوير', 'type' => 'محل ورود', 'status' => 'نشط', 'phone' => '91234567',
        ]);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'صاحب النشاط', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        Product::create([
            'business_id' => $this->business->id, 'name' => 'بوكيه ورد',
            'price' => 12.5, 'cost' => 5, 'quantity' => 10, 'alert_qty' => 2, 'active' => true,
        ]);
    }

    private function publish(): void
    {
        $this->business->forceFill(['site_slug' => 'ward-alkhuwair'])->save();
        MarketingSettings::save($this->business->id, 'website', ['store_on' => '1']);
    }

    /** صفحةٌ مفتوحةٌ للفهرسة: لا وسمَ robots يمنعها، ولا ترويسةَ تمنعها */
    private function assertIndexable(TestResponse $response, string $where): void
    {
        $response->assertOk();
        $this->assertDoesNotMatchRegularExpression(
            '/<meta[^>]+name="robots"[^>]+noindex/i',
            $response->getContent(),
            "{$where}: صار عليها noindex",
        );
        $this->assertNull($response->headers->get('X-Robots-Tag'), "{$where}: ترويسةُ X-Robots-Tag");
    }

    /* ═════════════════════ النظام: لا يُفهرَس ═════════════════════ */

    public function test_the_login_page_asks_search_engines_not_to_index_it(): void
    {
        foreach (['/', '/login', 'https://app.abaadapp.om/', 'https://app.abaadapp.om/login'] as $url) {
            $this->get($url)->assertOk()->assertSee(self::NOINDEX, false);
        }
    }

    public function test_the_register_page_asks_search_engines_not_to_index_it(): void
    {
        $this->get('/register')->assertOk()->assertSee(self::NOINDEX, false);
        $this->get('https://app.abaadapp.om/register')->assertOk()->assertSee(self::NOINDEX, false);
    }

    /** واللوحةُ ونقطةُ البيع والملفُّ الشخصيّ للتاجر، ولوحةُ المنصّة لمديرها — كلُّها من الجذر نفسه */
    public function test_every_part_of_the_system_asks_not_to_be_indexed(): void
    {
        foreach (['admin.dashboard', 'profile.edit'] as $name) {
            $this->actingAs($this->owner)->get(route($name))
                ->assertOk()->assertSee(self::NOINDEX, false);
        }

        // ونقطةُ البيع على جهازٍ مفعَّل — كما يفتحها الكاشير
        $raw = str_repeat('k', 64);
        $device = PosDevice::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id, 'name' => 'صندوق',
            'token_hash' => hash('sha256', $raw), 'status' => PosDevice::ACTIVE, 'activated_at' => now(),
        ]);
        $this->withCookie(PosTerminal::COOKIE, $device->id.'|'.$raw)->actingAs($this->owner)
            ->get(route('pos.index'))->assertOk()->assertSee(self::NOINDEX, false);

        $platform = User::create([
            'name' => 'مدير المنصّة', 'email' => 'super@abaad.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
        $this->actingAs($platform)->get(route('super-admin.dashboard'))
            ->assertOk()->assertSee(self::NOINDEX, false);
    }

    /** والوسمُ في الرأس وحده — لا يمسّ الدخولَ ولا وجهتَه */
    public function test_logging_in_and_its_redirects_are_unchanged(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->get(route('login'))->assertInertia(fn ($page) => $page->component('Auth/Login'));

        $this->post(route('login.attempt'), ['email' => 'o@abaad.om', 'password' => 'password'])
            ->assertRedirect(Permissions::homeFor($this->owner));
        $this->assertAuthenticatedAs($this->owner);
    }

    /** robots.txt يبقى مفتوحًا للنظام: الزاحفُ يجب أن يصل إلى الصفحة ليقرأ noindex */
    public function test_robots_txt_still_lets_search_engines_reach_the_system(): void
    {
        $robots = File::get(public_path('robots.txt'));

        $this->assertMatchesRegularExpression('/^User-agent: \*$/m', $robots);
        $this->assertDoesNotMatchRegularExpression('/^Disallow:\s*\/\S*/m', $robots, 'robots.txt يمنع الزحف');
    }

    /* ═════════════════════ المتاجر: تُفهرَس كما كانت ═════════════════════ */

    public function test_a_published_shop_stays_open_to_search_engines(): void
    {
        $this->publish();

        $this->assertIndexable($this->get(route('store.show', 'ward-alkhuwair')), '/s/ward-alkhuwair');
        $this->assertIndexable($this->get('https://ward-alkhuwair.abaadapp.om/'), 'النطاق الفرعيّ');
    }

    /** وواجهةُ RIBBON كذلك — ما لم يطفئ التاجرُ الفهرسةَ بنفسه، وذاك قرارُه لا قرارُنا */
    public function test_a_shop_wearing_ribbon_stays_open_to_search_engines(): void
    {
        $this->business->forceFill(['tier' => 'gold', 'storefront_theme' => 'ribbon'])->save();
        $this->publish();

        $this->assertIndexable($this->get(route('store.show', 'ward-alkhuwair')), 'متجر RIBBON');
    }

    public function test_a_merchant_site_on_its_own_domain_stays_open_to_search_engines(): void
    {
        config(['storefront.custom_domains' => true]);
        $this->business->forceFill(['site_slug' => 'ward-alkhuwair'])->save();

        Publisher::publish(
            Builder::create($this->business, Blueprints::STORE, 'modern', $this->owner->id),
            $this->owner->id,
        );
        Domains::attach($this->business->refresh(), 'mystore.om');

        $response = $this->get('https://mystore.om/');
        $this->assertIndexable($response, 'نطاق التاجر الخاصّ');
        $response->assertSee('<meta name="robots" content="index, follow">', false);
    }

    /* ═════════════════════ كلُّ جذر HTML: داخليٌّ أم عامّ ═════════════════════ */

    /**
     * كلُّ قالبٍ يبدأ صفحةً كاملة مصنَّفٌ هنا، فجذرٌ جديد يُسقط الاختبار حتى يُقرَّر مكانُه.
     *
     * والداخليُّ وحده يحمل noindex النظام، ولا قالبَ عامًّا يستعير منه رأسَه.
     */
    public function test_every_html_root_is_known_and_only_the_system_root_carries_the_system_noindex(): void
    {
        $roots = [
            // واجهةُ النظام — جذرُ Inertia: noindex
            'app' => 'internal',
            // متاجرُ التجّار ومواقعُهم — تُفهرَس، أو يطفئها التاجر من إعداداته
            'store/show' => 'public', 'store/ribbon/layout' => 'public', 'site/show' => 'public',
            // عامّةٌ لكنّها لا تُفهرَس بقرارها هي، من قبل هذا العمل
            'site/maintenance' => 'own-noindex', 'public/paper' => 'own-noindex',
            'public/review' => 'own-noindex', 'public/verify' => 'own-noindex',
            // ليست صفحاتٍ على الويب: رسائلُ بريد، وصفحةٌ داخل ملفّ تصدير، وفهرسُ أرشيفٍ مضغوط
            'exports/pdf-too-large' => 'not-a-page', 'documents/archive/readme' => 'not-a-page',
        ];

        $found = collect(File::allFiles(resource_path('views')))
            ->filter(fn ($f) => str_ends_with($f->getFilename(), '.blade.php') && str_contains($f->getContents(), '<html'))
            ->map(fn ($f) => str_replace(['\\', '.blade.php'], ['/', ''], $f->getRelativePathname()))
            ->reject(fn ($view) => str_starts_with($view, 'emails/'))
            ->sort()->values()->all();

        $this->assertSame(collect(array_keys($roots))->sort()->values()->all(), $found, 'جذرُ HTML غيرُ مصنَّف');

        $this->assertStringContainsString(self::NOINDEX, File::get(resource_path('views/app.blade.php')));
        foreach (array_keys($roots) as $view) {
            if ($view === 'app') {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression(
                "/@(extends|include)\\(\\s*'app'/",
                File::get(resource_path("views/{$view}.blade.php")),
                "{$view} يستعير جذرَ النظام",
            );
        }
    }
}
