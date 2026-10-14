<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use App\Support\MarketingSettings;
use App\Support\Website\Blueprints;
use App\Support\Website\Builder;
use App\Support\Website\Domains;
use App\Support\Website\Publisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * تبويبُ النظام يحمل علامةَ أبعاد — ومتجرُ التاجر لا يحملها.
 *
 * ═══ العطب ═══
 *
 * `app.blade.php` لم يُعلن أيقونةً قطّ، فيطلب المتصفّحُ `/favicon.ico` من
 * الجذر — وهو ملفٌّ فارغ (صفر بايت). فيرسم Safari وChrome أيقونتَهما
 * العامّة، وiPhone/iPad لقطةً من الصفحة على الشاشة الرئيسيّة.
 *
 * ═══ ولماذا تحت /brand/abaad/ لا في الجذر ═══
 *
 * نطاقاتُ المتاجر (`/s/{slug}` و`{slug}.abaadapp.om` والنطاقاتُ الخاصّة)
 * تخدم `public/` نفسَه. والمتصفّحُ يطلب `/favicon.ico` و`/apple-touch-icon.png`
 * من الجذر لكلّ صفحةٍ لا تُعلن أيقونتها — وصفحةُ المتجر وموقعُ التاجر لا
 * يُعلنانها. فأيقونةُ أبعاد في الجذر تُلبِس متاجرَ التجّار شعارَ أبعاد.
 * فالروابطُ صريحةٌ في جذر النظام وحده، والملفّاتُ في مجلّدٍ لا يطلبه أحدٌ
 * من تلقاء نفسه.
 *
 * والعلامةُ هي مسارُ «العلامة» في `Components/Logo.tsx` بلون الشعار
 * (`#111`) على مربّع أيقونات أبعاد الفاتح (`#f9fafc`، كأيقونة abaadapp.om):
 * تُقرأ على شريط التبويبات الفاتح والداكن معًا.
 */
class TheSystemTabWearsTheAbaadMarkTest extends TestCase
{
    use RefreshDatabase;

    private const NOINDEX = '<meta name="robots" content="noindex, nofollow">';

    /** ما يُعلنه جذرُ النظام — بالحرف */
    private const LINKS = [
        '<link rel="icon" href="/brand/abaad/favicon.ico" sizes="any">',
        '<link rel="icon" type="image/png" sizes="32x32" href="/brand/abaad/favicon-32x32.png">',
        '<link rel="icon" type="image/png" sizes="16x16" href="/brand/abaad/favicon-16x16.png">',
        '<link rel="icon" type="image/png" sizes="192x192" href="/brand/abaad/favicon-192x192.png">',
        '<link rel="apple-touch-icon" sizes="180x180" href="/brand/abaad/apple-touch-icon.png">',
    ];

    /** مربّعُ أيقونات أبعاد الفاتح */
    private const TILE = [249, 250, 252];

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'ورد الخوير', 'type' => 'محل ورود', 'status' => 'نشط', 'phone' => '91234567',
        ]);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'صاحب النشاط', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        Product::create([
            'business_id' => $this->business->id, 'name' => 'بوكيه ورد',
            'price' => 12.5, 'cost' => 5, 'quantity' => 10, 'alert_qty' => 2, 'active' => true,
        ]);
    }

    /** روابطُ الأيقونة في صفحةٍ — كلُّ `<link rel="…icon…">` فيها */
    private function iconLinks(string $html): array
    {
        preg_match_all('/<link\b[^>]*\brel="[^"]*icon[^"]*"[^>]*>/i', $html, $m);

        return $m[0];
    }

    private function hrefOf(string $link): string
    {
        preg_match('/\bhref="([^"]+)"/', $link, $m);

        return $m[1];
    }

    /* ═════════════════════ النظام ═════════════════════ */

    public function test_every_system_page_declares_the_abaad_icons_and_stays_noindex(): void
    {
        $pages = [
            'الدخول' => $this->get(route('login')),
            'التسجيل' => $this->get(route('register')),
            'اللوحة' => $this->actingAs($this->owner)->get(route('admin.dashboard')),
        ];

        foreach ($pages as $where => $response) {
            $response->assertOk();
            $html = $response->getContent();

            $this->assertSame(self::LINKS, $this->iconLinks($html), "{$where}: روابطُ الأيقونة");
            $this->assertStringContainsString(self::NOINDEX, $html, "{$where}: noindex النظام");
        }
    }

    public function test_every_declared_icon_is_a_real_image_of_its_declared_size(): void
    {
        foreach (self::LINKS as $link) {
            $path = public_path(ltrim($this->hrefOf($link), '/'));
            $this->assertFileExists($path, $link);
            $this->assertGreaterThan(0, filesize($path), "{$link}: ملفٌّ فارغ");

            if (str_ends_with($path, '.ico')) {
                $this->assertSame([16, 32, 48], $this->icoFrames($path), 'favicon.ico: أحجامُه');

                continue;
            }

            preg_match('/sizes="(\d+)x(\d+)"/', $link, $declared);
            $info = getimagesize($path);
            $this->assertNotFalse($info, "{$link}: لا تُقرأ صورةً");
            $this->assertSame(IMAGETYPE_PNG, $info[2], "{$link}: ليست PNG");
            $this->assertSame([(int) $declared[1], (int) $declared[2]], [$info[0], $info[1]], "{$link}: مقاسُها غيرُ ما أُعلن");
        }
    }

    /**
     * أحجامُ إطارات ICO — بعد التحقّق أنّ كلَّ إطارٍ صورةٌ تُفتح.
     *
     * @return list<int>
     */
    private function icoFrames(string $path): array
    {
        $bytes = File::get($path);
        $header = unpack('vreserved/vtype/vcount', substr($bytes, 0, 6));
        $this->assertSame(['reserved' => 0, 'type' => 1], ['reserved' => $header['reserved'], 'type' => $header['type']], 'ترويسةُ ICO');

        $sizes = [];
        for ($i = 0; $i < $header['count']; $i++) {
            $entry = unpack('Cw/Ch/Ccolors/Creserved/vplanes/vbpp/Vlength/Voffset', substr($bytes, 6 + 16 * $i, 16));
            $this->assertLessThanOrEqual(strlen($bytes), $entry['offset'] + $entry['length'], 'إطارٌ يتجاوز الملفّ');

            $frame = imagecreatefromstring(substr($bytes, $entry['offset'], $entry['length']));
            $this->assertNotFalse($frame, "إطارُ {$entry['w']}px لا يُفتح");
            $this->assertSame([$entry['w'], $entry['h']], [imagesx($frame), imagesy($frame)]);

            $sizes[] = $entry['w'];
        }
        sort($sizes);

        return $sizes;
    }

    /** وهي علامةُ أبعاد على مربّعها الفاتح — لا صورةٌ فارغة، ولا شفّافةٌ تختفي على الشريط الداكن */
    public function test_each_icon_is_the_abaad_mark_on_its_light_tile(): void
    {
        foreach (['favicon-16x16.png', 'favicon-32x32.png', 'favicon-192x192.png', 'apple-touch-icon.png'] as $file) {
            $image = imagecreatefrompng(public_path("brand/abaad/{$file}"));
            $n = imagesx($image);

            // الزوايا: المربّعُ الفاتح، معتمًا — iOS يملأ الشفّافَ بالأسود
            foreach ([[0, 0], [$n - 1, 0], [0, $n - 1], [$n - 1, $n - 1]] as [$x, $y]) {
                $c = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                $this->assertSame([...self::TILE, 0], [$c['red'], $c['green'], $c['blue'], $c['alpha']], "{$file}: الزاوية ({$x},{$y})");
            }

            // والعلامةُ في الوسط: حبرٌ داكن في عمود المنتصف
            $ink = 0;
            for ($y = 0; $y < $n; $y++) {
                $c = imagecolorsforindex($image, imagecolorat($image, intdiv($n, 2), $y));
                $ink += $c['red'] + $c['green'] + $c['blue'] < 3 * 80 ? 1 : 0;
            }
            $this->assertGreaterThan(0, $ink, "{$file}: لا علامةَ فيها");
        }
    }

    /* ═════════════════════ المتاجر: كما كانت ═════════════════════ */

    private function publish(): void
    {
        $this->business->forceFill(['site_slug' => 'ward-alkhuwair'])->save();
        MarketingSettings::save($this->business->id, 'website', ['store_on' => '1']);
    }

    public function test_a_merchant_shop_does_not_wear_the_abaad_mark(): void
    {
        $this->publish();

        foreach (['/s/ward-alkhuwair' => route('store.show', 'ward-alkhuwair'), 'النطاق الفرعيّ' => 'https://ward-alkhuwair.abaadapp.om/'] as $where => $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('/brand/abaad/', $html, $where);
            $this->assertSame([], $this->iconLinks($html), "{$where}: صار يُعلن أيقونة");
        }
    }

    /** وRIBBON على أيقونته هو */
    public function test_a_shop_wearing_ribbon_keeps_its_own_icons(): void
    {
        $this->business->forceFill(['tier' => 'gold', 'storefront_theme' => 'ribbon'])->save();
        $this->publish();

        $html = $this->get(route('store.show', 'ward-alkhuwair'))->assertOk()->getContent();
        $this->assertStringNotContainsString('/brand/abaad/', $html, 'RIBBON');
        $this->assertStringContainsString('/brand/ribbon/favicon.svg', $html, 'RIBBON: أيقونتُه');
        $this->assertStringContainsString('/brand/ribbon/apple-touch-icon.png', $html, 'RIBBON: أيقونةُ الشاشة الرئيسيّة');
    }

    public function test_a_merchant_site_on_its_own_domain_does_not_wear_the_abaad_mark(): void
    {
        config(['storefront.custom_domains' => true]);
        $this->business->forceFill(['site_slug' => 'ward-alkhuwair'])->save();
        Publisher::publish(
            Builder::create($this->business, Blueprints::STORE, 'modern', $this->owner->id),
            $this->owner->id,
        );
        Domains::attach($this->business->refresh(), 'mystore.om');

        $html = $this->get('https://mystore.om/')->assertOk()->getContent();
        $this->assertStringNotContainsString('/brand/abaad/', $html);
        $this->assertSame([], $this->iconLinks($html));
    }

    /**
     * وما يطلبه المتصفّحُ من الجذر بلا إعلان لا يحمل علامةَ أبعاد.
     *
     * كلُّ نطاقٍ يخدمه `public/` يرث هذه الملفّات — والمتجرُ الذي لا يُعلن
     * أيقونتَه (أو يُعلنها SVG فيتخطّاها Safari) يأخذ ما فيها.
     */
    public function test_the_root_files_every_shop_host_inherits_do_not_carry_the_abaad_mark(): void
    {
        $brand = File::get(public_path('brand/abaad/favicon.ico'));

        $this->assertNotSame($brand, File::get(public_path('favicon.ico')), '/favicon.ico صار أيقونةَ أبعاد');
        foreach (['apple-touch-icon.png', 'apple-touch-icon-precomposed.png'] as $file) {
            $this->assertFileDoesNotExist(public_path($file), "/{$file} في الجذر يرثه كلُّ متجر");
        }
    }
}
