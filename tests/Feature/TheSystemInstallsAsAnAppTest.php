<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * أبعاد تُثبَّت تطبيقًا على الهاتف والحاسوب — من النطاق نفسه.
 *
 * ═══ العطب ═══
 *
 * على iPhone تُضاف `app.abaadapp.om` إلى الشاشة الرئيسيّة، لكنّها تفتح
 * داخل إطار Safari: العنوانُ فوق، وأزرارُ الرجوع والمشاركة والتحديث تحت —
 * «شكلُ موقعٍ لا نظام». لأنّ الصفحة لا تُعلن ملفَّ تطبيق (manifest) ولا
 * `display: standalone`. وعلى Android وChrome/Edge في الحاسوب لا يظهر
 * «تثبيت التطبيق» أصلًا: شرطُه ملفُّ تطبيقٍ فيه اسمٌ وأيقونتا 192 و512.
 *
 * ═══ ولماذا على نطاق النظام وحده ═══
 *
 * التطبيقُ المثبَّت يفتح `start_url` (`/app`) من النطاق الذي ثُبِّت منه.
 * فلو أُعلن الملفُّ على نطاق متجرٍ لصار «تطبيقُ أبعاد» على شاشة زبونٍ
 * يفتح متجرَ التاجر. فالإعلانُ مشروطٌ بأن يكون المضيفُ مضيفَ `APP_URL`.
 * (ومتاجرُ التجّار لا تحمل `/brand/abaad/` أصلًا — يحرسه
 * `TheSystemTabWearsTheAbaadMarkTest`.)
 *
 * ═══ والأيقونات ═══
 *
 * علامةُ أبعاد بـ`#111` على مربّعها الفاتح `#f9fafc`، كأيقونة الشاشة
 * الرئيسيّة الموجودة. والمربّعُ معتمٌ حتى الزوايا: Android وiOS يملآن
 * الشفّافَ بالأسود. و«maskable» تُقصّ دائرةً أو شكلًا آخر، فالعلامةُ كلُّها
 * داخل الدائرة الآمنة (نصفُ قطرها 40٪ من الضلع) وإلّا قُطع رأسُ المثلّث.
 */
class TheSystemInstallsAsAnAppTest extends TestCase
{
    use RefreshDatabase;

    private const SYSTEM = 'https://app.abaadapp.om';

    private const MANIFEST = '/brand/abaad/manifest.webmanifest';

    /** ما يُعلنه رأسُ صفحة النظام — بالحرف */
    private const HEAD = [
        '<link rel="manifest" href="/brand/abaad/manifest.webmanifest">',
        '<meta name="theme-color" content="#ffffff">',
        '<meta name="mobile-web-app-capable" content="yes">',
        '<meta name="apple-mobile-web-app-capable" content="yes">',
        '<meta name="apple-mobile-web-app-title" content="Abaad">',
        '<meta name="apple-mobile-web-app-status-bar-style" content="default">',
    ];

    private const TILE = [249, 250, 252];

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => self::SYSTEM]);
        URL::forceRootUrl(self::SYSTEM);

        $business = Business::create([
            'name' => 'ورد الخوير', 'type' => 'محل ورود', 'status' => 'نشط', 'phone' => '91234567',
        ]);
        Branch::create(['business_id' => $business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $business->id, 'name' => 'صاحب النشاط', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $path = public_path(ltrim(self::MANIFEST, '/'));
        $this->assertFileExists($path);

        $json = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($json);

        return $json;
    }

    /* ═════════════════════ الإعلان ═════════════════════ */

    public function test_every_system_page_offers_itself_as_an_app(): void
    {
        $pages = [
            'الدخول' => $this->get(route('login')),
            'اللوحة' => $this->actingAs($this->owner)->get(route('admin.dashboard')),
        ];

        foreach ($pages as $where => $response) {
            $response->assertOk();
            $html = $response->getContent();

            foreach (self::HEAD as $tag) {
                $this->assertStringContainsString($tag, $html, "{$where}: {$tag}");
            }
            $this->assertSame(1, substr_count($html, 'rel="manifest"'), "{$where}: ملفُّ تطبيقٍ واحد");
        }
    }

    /** صفحةُ النظام نفسُها على مضيفٍ غير مضيف النظام: لا تُعرض للتثبيت */
    public function test_a_system_page_on_another_host_is_not_offered_as_the_abaad_app(): void
    {
        URL::forceRootUrl(null);

        $html = $this->get('http://localhost/')->assertOk()->getContent();

        $this->assertStringNotContainsString('rel="manifest"', $html);
        $this->assertStringNotContainsString('apple-mobile-web-app-capable', $html);
        // وأيقوناتُ التبويب باقيةٌ كما كانت
        $this->assertStringContainsString('<link rel="apple-touch-icon" sizes="180x180" href="/brand/abaad/apple-touch-icon.png">', $html);
    }

    /* ═════════════════════ ملفُّ التطبيق ═════════════════════ */

    public function test_the_manifest_opens_the_system_root_without_browser_chrome(): void
    {
        $m = $this->manifest();

        $this->assertSame('standalone', $m['display'] ?? null, 'بلا شريط المتصفّح');
        $this->assertSame('/app', $m['start_url'] ?? null, 'يفتح شاشةَ التطبيق البسيطة — والضيفُ يُحوَّل إلى الدخول ثمّ يعود إليها');
        $this->assertSame('/', $m['scope'] ?? null, 'كلُّ صفحات النظام داخل التطبيق: «النظام كاملًا» لا يخرج إلى المتصفّح');
        $this->assertSame('/', $m['id'] ?? null, 'هويّةٌ ثابتة لا تتبدّل بتغيير start_url');
        $this->assertSame('Abaad', $m['name'] ?? null);
        $this->assertSame('Abaad', $m['short_name'] ?? null);
        $this->assertSame('#ffffff', $m['theme_color'] ?? null, 'كـtheme-color في الصفحة');
        $this->assertArrayNotHasKey('prefer_related_applications', $m, 'وإلّا أخفى Chrome زرَّ التثبيت');
    }

    public function test_every_manifest_icon_is_a_real_png_of_its_declared_size(): void
    {
        $icons = $this->manifest()['icons'] ?? [];
        $this->assertNotEmpty($icons);

        $have = [];
        foreach ($icons as $icon) {
            $this->assertStringStartsWith('/brand/abaad/', $icon['src'], 'لا في الجذر: كلُّ نطاقٍ يرثه');
            $path = public_path(ltrim($icon['src'], '/'));
            $this->assertFileExists($path, $icon['src']);

            $info = getimagesize($path);
            $this->assertNotFalse($info, "{$icon['src']}: لا تُقرأ صورةً");
            $this->assertSame(IMAGETYPE_PNG, $info[2], "{$icon['src']}: ليست PNG");
            $this->assertSame('image/png', $icon['type']);
            $this->assertSame("{$info[0]}x{$info[1]}", $icon['sizes'], "{$icon['src']}: مقاسُها غيرُ ما أُعلن");

            $have[] = "{$icon['sizes']} {$icon['purpose']}";
        }

        // شرطُ «تثبيت» في Chrome وAndroid: 192 و512 — ومقصوصةٌ لأيقونة Android التكيّفيّة
        sort($have);
        $this->assertSame(['192x192 any', '512x512 any', '512x512 maskable'], $have);
    }

    public function test_each_app_icon_is_the_abaad_mark_on_its_opaque_light_tile(): void
    {
        foreach ($this->manifest()['icons'] as $icon) {
            $image = imagecreatefrompng(public_path(ltrim($icon['src'], '/')));
            $n = imagesx($image);

            foreach ([[0, 0], [$n - 1, 0], [0, $n - 1], [$n - 1, $n - 1]] as [$x, $y]) {
                $c = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                $this->assertSame([...self::TILE, 0], [$c['red'], $c['green'], $c['blue'], $c['alpha']], "{$icon['src']}: الزاوية ({$x},{$y})");
            }

            $ink = 0;
            for ($y = 0; $y < $n; $y++) {
                $c = imagecolorsforindex($image, imagecolorat($image, intdiv($n, 2), $y));
                $ink += $c['red'] + $c['green'] + $c['blue'] < 3 * 80 ? 1 : 0;
            }
            $this->assertGreaterThan(0, $ink, "{$icon['src']}: لا علامةَ فيها");
        }
    }

    /** المقصوصةُ تُقصّ دائرة — فلا حبرَ خارج نصف قطرٍ قدرُه 40٪ من الضلع */
    public function test_the_maskable_icon_keeps_the_whole_mark_inside_the_safe_circle(): void
    {
        $maskable = array_values(array_filter($this->manifest()['icons'], fn ($i) => $i['purpose'] === 'maskable'));
        $this->assertCount(1, $maskable);

        $image = imagecreatefrompng(public_path(ltrim($maskable[0]['src'], '/')));
        $n = imagesx($image);
        $safe = 0.4 * $n;

        $outside = 0;
        $inked = 0;
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                $c = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                if ($c['red'] + $c['green'] + $c['blue'] >= 3 * 200) {
                    continue;
                }
                $inked++;
                $outside += hypot($x + 0.5 - $n / 2, $y + 0.5 - $n / 2) > $safe ? 1 : 0;
            }
        }

        $this->assertGreaterThan(0, $inked, 'لا علامةَ فيها');
        $this->assertSame(0, $outside, 'حبرٌ خارج الدائرة الآمنة: يُقصّ على Android');
    }
}
