<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\PosDevice;
use App\Models\User;
use App\Support\PosTerminal;
use App\Support\RouteGroups;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tighten\Ziggy\BladeRouteGenerator;
use Tighten\Ziggy\Ziggy;

/**
 * كلُّ مسارٍ تنادي به شاشةٌ في جدول مجموعتها — والمجموعةُ ما تأخذه صفحاتُها.
 *
 * الصفحةُ تحمل جدولَ مجموعتها وحده (`config/ziggy.php`). واسمٌ تناديه شاشةٌ وليس في
 * جدولها يُسقطها في المتصفّح (Ziggy يرمي). فيقرأ هذا الحارسُ كلَّ ملفٍّ تصل إليه
 * صفحاتُ كلِّ مجلّدٍ باستيراداتها، ويجمع كلَّ نصٍّ هو اسمُ مسار، وكلَّ قالبٍ يُمرَّر
 * إلى `route()` — ويطلبها في المجموعة. والاسمُ المبنيُّ وقتَ التشغيل في الصندوق
 * والدخول (`route(item.route)`) لا يُقرأ هكذا، فلا يُقبل إلّا من مواضعَ فُحص
 * مصدرُها — وموضعٌ جديد يُسقط الحارس حتى يُفحص.
 */
class EveryRouteAScreenCallsIsInItsGroupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * مواضعُ `route(<متغيّر>)` في ما تصل إليه صفحاتُ الدخول والصندوق — ومن أين اسمُها.
     *
     * كلُّها أسماءٌ مكتوبةٌ نصًّا في ملفّاتٍ يصلها الحارس فيقرؤها: عناصرُ شريط
     * الصندوق (`navItems`)، ومن ينادي `useReceiptPrint` باسمه، وثنائيّةُ `MovementForm`.
     */
    private const DYNAMIC_ON_TILL_AND_LOGIN = [
        'Layouts/PosLayout.tsx' => 'route(item.route)',
        'hooks/useReceiptPrint.ts' => 'route(routeName, invoice)',
        'Components/MovementForm.tsx' => "route(pos ? 'pos.movements.store' : 'admin.finance.store')",
    ];

    /** @var array<string, array{0: list<string>, 1: list<string>, 2: list<string>}> */
    private array $scanned = [];

    private function js(): string
    {
        return base_path('resources/js').'/';
    }

    /** @return list<string> */
    private function routeNames(): array
    {
        return array_keys((new Ziggy)->toArray()['routes']);
    }

    private function resolve(string $from, string $spec): ?string
    {
        if (str_starts_with($spec, '@/')) {
            $base = $this->js().substr($spec, 2);
        } elseif (str_starts_with($spec, '.')) {
            $base = dirname($from).'/'.$spec;
        } else {
            return null;
        }

        foreach (['', '.tsx', '.ts', '/index.tsx', '/index.ts'] as $ext) {
            $path = realpath($base.$ext);
            if ($path && is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * ما في الملفّ: استيراداتُه (لا `import type` — تُمحى من الحزمة)، وأسماءُ المسارات
     * فيه، ونداءاتُ `route()` بمتغيّر.
     *
     * @return array{0: list<string>, 1: list<string>, 2: list<string>}
     */
    private function scan(string $file, array $names): array
    {
        if (isset($this->scanned[$file])) {
            return $this->scanned[$file];
        }

        $src = (string) file_get_contents($file);
        $code = preg_replace('/^\s*(?:import|export)\s+type\s[^;]*;/m', '', $src);
        preg_match_all('/(?:import|export)\s[^;]*?from\s+[\'"]([^\'"]+)[\'"]|import\(\s*[\'"]([^\'"]+)[\'"]\s*\)|^\s*import\s+[\'"]([^\'"]+)[\'"]/m', (string) $code, $m);

        $deps = [];
        foreach (array_merge($m[1], $m[2], $m[3]) as $spec) {
            if ($spec !== '' && ($path = $this->resolve($file, $spec))) {
                $deps[] = $path;
            }
        }

        $set = array_flip($names);
        $found = [];
        preg_match_all('/[\'"]([A-Za-z0-9_.\-]+)[\'"]/', $src, $s);
        foreach ($s[1] as $str) {
            if (isset($set[$str])) {
                $found[$str] = true;
            }
        }

        // ‏وقالبٌ يُمرَّر إلى route() — `admin.${type}s.restore` — يُطلب كلُّ اسمٍ يطابقه
        preg_match_all('/\broute\(\s*`([^`]*)`/', $src, $t);
        foreach ($t[1] as $tpl) {
            $re = '/^'.preg_replace('/\\\\\$\\\\\{[^}]*\\\\\}/', '[A-Za-z0-9_.\-]+', preg_quote($tpl, '/')).'$/';
            foreach ($names as $name) {
                if (preg_match($re, $name)) {
                    $found[$name] = true;
                }
            }
        }

        $dynamic = [];
        foreach (explode("\n", $src) as $line) {
            $trim = ltrim($line);
            if (str_starts_with($trim, '*') || str_starts_with($trim, '//') || str_starts_with($trim, '/*')) {
                continue;
            }
            if (preg_match('/\broute\(\s*(?![\'"`)\s])[^;]*/', $line, $d)) {
                $dynamic[] = trim($d[0]);
            }
        }

        return $this->scanned[$file] = [array_values(array_unique($deps)), array_keys($found), $dynamic];
    }

    /**
     * ما تصل إليه صفحاتُ مجلّد: الأسماءُ، ونداءاتُ المتغيّر بملفّاتها.
     *
     * @return array{0: list<string>, 1: array<string, list<string>>}
     */
    private function reach(string $folder): array
    {
        $names = $this->routeNames();
        $stack = [realpath($this->js().'app.tsx')];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->js().'Pages/'.$folder)) as $f) {
            if ($f->isFile() && str_ends_with((string) $f, '.tsx')) {
                $stack[] = $f->getRealPath();
            }
        }

        $seen = [];
        $routes = [];
        $dynamic = [];
        while ($stack) {
            $file = array_pop($stack);
            if (isset($seen[$file])) {
                continue;
            }
            $seen[$file] = true;
            [$deps, $found, $calls] = $this->scan($file, $names);
            foreach ($found as $r) {
                $routes[$r] = true;
            }
            if ($calls) {
                $dynamic[str_replace($this->js(), '', $file)] = $calls;
            }
            array_push($stack, ...$deps);
        }

        return [array_keys($routes), $dynamic];
    }

    public function test_every_route_a_screen_reaches_is_in_its_group(): void
    {
        foreach (['Auth', 'Pos', 'Admin', 'Profile'] as $folder) {
            $group = RouteGroups::forComponent($folder.'/X');
            $this->assertNotSame(RouteGroups::ALL, $group, "مجلّد {$folder} بلا مجموعة");

            [$needed] = $this->reach($folder);
            $carried = array_keys(RouteGroups::routes($group));
            $missing = array_values(array_diff($needed, $carried));

            $this->assertSame([], $missing, "صفحات {$folder} تنادي مساراتٍ ليست في مجموعة «{$group}» — زِدها في config/ziggy.php: ".implode(', ', $missing));
        }
    }

    public function test_a_name_built_at_runtime_on_the_till_or_login_comes_from_a_checked_place(): void
    {
        foreach (['Auth', 'Pos'] as $folder) {
            [, $dynamic] = $this->reach($folder);

            foreach ($dynamic as $file => $calls) {
                foreach ($calls as $call) {
                    $known = self::DYNAMIC_ON_TILL_AND_LOGIN[$file] ?? null;
                    $this->assertTrue(
                        $known !== null && str_starts_with($call, $known),
                        "نداءٌ جديد لـroute() باسمٍ يُبنى وقتَ التشغيل في {$file}: «{$call}» — افحص من أين يأتي الاسم وأنّه في مجموعة {$folder}، ثمّ أضفه هنا",
                    );
                }
            }
        }
    }

    public function test_the_dashboard_group_carries_the_whole_till_group(): void
    {
        $merchant = array_keys(RouteGroups::routes(RouteGroups::MERCHANT));

        $this->assertSame([], array_values(array_diff(array_keys(RouteGroups::routes(RouteGroups::POS)), $merchant)),
            'اللوحةُ تنتقل إلى الصندوق بلا تحميل وتغطّيه (`COVERS`) — فمجموعتُه كلُّها فيها');
    }

    public function test_the_browser_and_the_server_read_the_same_folders(): void
    {
        $ts = (string) file_get_contents($this->js().'lib/route-groups.ts');

        preg_match('/FOLDERS[^=]*=\s*\{([^}]*)\}/s', $ts, $folders);
        preg_match_all('/(\w+):\s*\'(\w+)\'/', $folders[1], $pairs, PREG_SET_ORDER);
        $this->assertSame(RouteGroups::FOLDERS, collect($pairs)->mapWithKeys(fn ($p) => [$p[1] => $p[2]])->all());

        preg_match('/COVERS[^=]*=\s*\{(.*?)\};/s', $ts, $covers);
        preg_match_all('/(\w+):\s*\[([^\]]*)\]/', $covers[1], $rows, PREG_SET_ORDER);
        $this->assertSame(RouteGroups::COVERS, collect($rows)->mapWithKeys(fn ($r) => [$r[1] => array_map(fn ($v) => trim($v, " '"), explode(',', $r[2]))])->all());

        // ‏وكلُّ مجلّدٍ في الصفحات له مجموعة
        foreach (glob($this->js().'Pages/*', GLOB_ONLYDIR) as $dir) {
            $this->assertArrayHasKey(basename($dir), RouteGroups::FOLDERS, 'مجلّدُ صفحاتٍ جديد بلا مجموعة: '.basename($dir));
        }
    }

    public function test_the_till_and_login_carry_a_small_table(): void
    {
        $all = strlen(json_encode(RouteGroups::routes(RouteGroups::ALL)));

        foreach ([RouteGroups::POS => 0.15, RouteGroups::GUEST => 0.05] as $group => $share) {
            $size = strlen(json_encode(RouteGroups::routes($group)));
            $this->assertLessThan($all * $share, $size, "جدولُ «{$group}» {$size} بايت من {$all} — كبر حتى صار يحمل غيرَ مساراته");
        }
    }

    /* ═══════════ وما تكتبه الصفحةُ فعلًا ═══════════ */

    private function tableIn(string $html): array
    {
        $this->assertMatchesRegularExpression('/<meta name="route-group" content="(\w+)">/', $html);
        preg_match('/<meta name="route-group" content="(\w+)">/', $html, $g);
        preg_match('/(?:const Ziggy=|Object\.assign\(Ziggy\.routes,\s*)(\{.*?\})\)?;/s', $html, $z);

        $table = json_decode($z[1] ?? 'null', true);
        $routes = $table['routes'] ?? $table ?? [];

        return [$g[1], array_keys($routes)];
    }

    private function page(string $uri): string
    {
        BladeRouteGenerator::$generated = false;

        return (string) $this->get($uri)->assertOk()->getContent();
    }

    public function test_each_page_is_written_with_its_own_table(): void
    {
        [$group, $names] = $this->tableIn($this->page(route('login')));
        $this->assertSame(RouteGroups::GUEST, $group);
        $this->assertContains('login.attempt', $names);
        $this->assertNotContains('admin.dashboard', $names, 'صفحةُ الدخول تحمل مساراتِ اللوحة');

        $shop = Business::create(['name' => 'زهور', 'type' => 'عام', 'status' => 'نشط']);
        $branch = Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        $owner = User::create(['business_id' => $shop->id, 'name' => 'المالك', 'email' => 'o@groups.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $raw = str_repeat('g', 64);
        $device = PosDevice::create(['business_id' => $shop->id, 'branch_id' => $branch->id, 'name' => 'صندوق',
            'token_hash' => hash('sha256', $raw), 'status' => PosDevice::ACTIVE, 'activated_at' => now()]);

        $this->actingAs($owner)->withCookie(PosTerminal::COOKIE, $device->id.'|'.$raw);
        [$group, $names] = $this->tableIn($this->page(route('pos.index')));
        $this->assertSame(RouteGroups::POS, $group);
        $this->assertContains('pos.checkout', $names);
        $this->assertNotContains('admin.products.index', $names, 'الصندوقُ يحمل مساراتِ اللوحة');

        [$group, $names] = $this->tableIn($this->page(route('admin.products.index')));
        $this->assertSame(RouteGroups::MERCHANT, $group);
        $this->assertContains('admin.products.index', $names);
        $this->assertContains('pos.checkout', $names);
        $this->assertNotContains('super-admin.businesses.show', $names, 'اللوحةُ تحمل مساراتِ المنصّة لتاجر');
    }

    public function test_the_platform_takes_the_whole_table(): void
    {
        $super = User::create(['name' => 'المنصّة', 'email' => 's@groups.om', 'password' => bcrypt('x'), 'role' => 'super_admin', 'status' => 'نشط']);

        [$group, $names] = $this->tableIn($this->actingAs($super)->page(route('super-admin.dashboard')));
        $this->assertSame(RouteGroups::ALL, $group);
        $this->assertContains('super-admin.businesses.show', $names);
        $this->assertContains('admin.products.index', $names);
    }

    public function test_a_group_is_served_to_whom_its_pages_are_served(): void
    {
        $this->getJson(route('routeGroups.show', 'guest'))->assertOk()->assertJsonPath('group', 'guest');
        $this->getJson(route('routeGroups.show', 'pos'))->assertUnauthorized();
        $this->getJson(route('routeGroups.show', 'merchant'))->assertUnauthorized();
        $this->getJson(route('routeGroups.show', 'nothing'))->assertNotFound();

        $shop = Business::create(['name' => 'زهور', 'type' => 'عام', 'status' => 'نشط']);
        $owner = User::create(['business_id' => $shop->id, 'name' => 'المالك', 'email' => 'o2@groups.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        $merchant = $this->actingAs($owner)->getJson(route('routeGroups.show', 'merchant'))->assertOk()->json('routes');
        $this->assertArrayHasKey('admin.products.index', $merchant);
        $this->assertArrayNotHasKey('super-admin.businesses.show', $merchant);
        $this->actingAs($owner)->getJson(route('routeGroups.show', 'all'))->assertForbidden();

        $super = User::create(['name' => 'المنصّة', 'email' => 's2@groups.om', 'password' => bcrypt('x'), 'role' => 'super_admin', 'status' => 'نشط']);
        $this->actingAs($super)->getJson(route('routeGroups.show', 'all'))->assertOk()->assertJsonPath('group', 'all');
    }
}
