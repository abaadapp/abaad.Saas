<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Support\ImageVariants;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Store\StoreMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * صورُ RIBBON الكبيرة — الواجهة والشريط والقسمُ الحرّ و«عنّا» ورأسُ المجموعة وصورُ
 * الصفحات — تعطي المتصفّحَ أحجامها وعرضَها المرسوم.
 *
 * ═══ العطب الذي وُضعت له ═══
 *
 * كانت كلُّها تُعطى نسخةَ العرض (٢٥٦٠) أيًّا كانت الشاشة: هاتفٌ بعرض ٣٩٠ ينزل صورةَ
 * الواجهة بعرض ٢٥٦٠ وهي تُرسم بعرض ٣٤٢. وبلا صورةِ واجهةٍ كانت صورةُ الصنف البديلة
 * مصغّرتَه (٦٤٠) في إطارٍ بعرض الصفحة.
 *
 * ═══ وما يُحرَس ═══
 *
 *   - كلُّ مكانٍ يكتب `srcset` بما على القرص و`sizes` بقاعدته (`StoreMedia::HERO`…).
 *   - القواعدُ قيست في متصفّحٍ حقيقيّ على الصفحة الحيّة (٤٧٤ عرضًا لكلّ مكان، الفرقُ
 *     أقلُّ من بكسل) — وهنا تُحرس أرقامُ CSS التي بُنيت عليها: تغييرُها يُسقط هذا.
 *   - الهاتفُ يأخذ الوسطى حيث تكفي، والشاشةُ الكبيرة نسخةَ العرض حيث تحتاجها.
 *   - صورةٌ بلا نسخ، وشعارٌ، ورابطٌ خارجيّ: كما كانت بلا `srcset`.
 */
class ADisplayPictureAsksForItsDrawnWidthTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('public');
        Storage::fake('local');
        config(['images.pause_ms' => 0]);

        $this->shop = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
    }

    /** صورةٌ رفعها المتجر لموقعه — بنسخها الثلاث إن طُلب */
    private function picture(int $w, int $h, bool $copies = true): string
    {
        $im = imagecreatetruecolor($w, $h);
        mt_srand($w + $h);

        for ($y = 0; $y < $h; $y += 2) {
            imagefilledrectangle($im, 0, $y, $w, $y + 1, imagecolorallocate($im, (int) (90 + 120 * $y / $h), 120, (int) (200 - 100 * $y / $h)));
        }

        for ($i = 0; $i < 25; $i++) {
            imagefilledellipse($im, mt_rand(0, $w), mt_rand(0, $h), $r = mt_rand(20, (int) ($w / 6)), $r, imagecolorallocate($im, mt_rand(40, 230), mt_rand(40, 230), mt_rand(40, 230)));
        }

        $path = 'website/'.$this->shop->id.'/'.Str::random(40).'.jpg';
        ob_start();
        imagejpeg($im, null, 92);
        Storage::disk('public')->put($path, (string) ob_get_clean());

        if ($copies) {
            $this->assertSame('made', ImageVariants::make($path)['status']);
        }

        return Storage::url($path);
    }

    /** صنفٌ منشور — الشريطُ لا يُرسم على رفٍّ خالٍ */
    private function shelf(): void
    {
        Product::create(['business_id' => $this->shop->id, 'name' => 'باقة', 'price' => 10, 'cost' => 4, 'quantity' => 5, 'alert_qty' => 1, 'active' => true, 'published' => true]);
    }

    /** خصائصُ الصورة بهذا المعرّف في الصفحة */
    private function img(string $html, string $testid): array
    {
        $this->assertSame(1, preg_match('/<img [^>]*data-testid="'.preg_quote($testid, '/').'"[^>]*>/u', $html, $m), 'لا صورةَ '.$testid);
        preg_match_all('/\s([a-z-]+)="([^"]*)"/', $m[0], $attrs, PREG_SET_ORDER);

        return collect($attrs)->mapWithKeys(fn ($a) => [$a[1] => html_entity_decode($a[2])])->all();
    }

    /** عرضُ `sizes` عند عرض شاشةٍ بالنقاط — كما يقرؤه المتصفّح: أوّلُ شرطٍ يصدق */
    private static function width(string $sizes, float $viewport): float
    {
        foreach (array_map('trim', explode(', ', $sizes)) as $entry) {
            $from = preg_match('/^\(min-width: ([\d.]+)px\) (.+)$/', $entry, $x) ? (float) $x[1] : 0.0;
            $value = $x[2] ?? $entry;

            if ($viewport >= $from) {
                return preg_match('/^([\d.]+)px$/', $value, $v) ? (float) $v[1]
                    : (preg_match('/^calc\(([\d.]+)vw ([-+]) ([\d.]+)px\)$/', $value, $v) ? $v[1] * $viewport / 100 + ($v[2] === '-' ? -1 : 1) * $v[3] : 0.0);
            }
        }

        return 0.0;
    }

    /** ما يختاره المتصفّح: أصغرُ عرضٍ يكفي بكسلاتِ مكانه، وإلّا الأكبر */
    private function pick(array $img, float $viewport, float $dpr): int
    {
        preg_match_all('/ (\d+)w(?:,|$)/', $img['srcset'], $m);
        $widths = array_map('intval', $m[1]);
        sort($widths);
        $need = self::width($img['sizes'], $viewport);

        foreach ($widths as $w) {
            if ($w >= $need * $dpr) {
                return $w;
            }
        }

        return max($widths);
    }

    public function test_the_home_hero_banner_and_block_offer_every_size_with_their_drawn_width(): void
    {
        $hero = $this->picture(2870, 2870);
        $banner = $this->picture(2400, 1600);
        $block = $this->picture(1600, 2400);
        MarketingSettings::save($this->shop->id, 'website', [
            'store_on' => '1', 'store_hero_image' => $hero, 'store_banner_image' => $banner,
            'store_block_on' => '1', 'store_block_title' => 'باقات المناسبات', 'store_block_text' => 'لكلّ مناسبة',
            'store_block_title_en' => 'Occasions', 'store_block_text_en' => 'For every occasion', 'store_block_image' => $block,
        ]);
        $this->shelf();
        $raw = fn (string $url) => substr($url, strlen('/storage/'));

        foreach (['/s/ribbon', '/s/ribbon?lang=en'] as $page) {
            $html = $this->get($page)->assertOk()->getContent();
            $h = $this->img($html, 'rb-hero-image');
            $b = $this->img($html, 'rb-banner-image');
            $k = $this->img($html, 'rb-block-image');

            $this->assertSame(Storage::url(ImageVariants::display($raw($hero))), $h['src'], 'المتصفّحُ الذي لا يقرأ srcset يبقى على نسخة العرض');
            $this->assertSame($hero.'.w640.webp 640w, '.$hero.'.w1280.webp 1280w, '.$hero.'.w2560.webp 2560w', $h['srcset'] ?? null, $page);
            $this->assertSame(StoreMedia::sizes(StoreMedia::HERO), $h['sizes'], 'مربّعةٌ في إطار ٥:٤ تُرسم بعرضه');
            $this->assertSame(StoreMedia::sizes(StoreMedia::BANNER, 2400 / 1600 / (4 / 3)), $b['sizes'], 'عريضةٌ في إطار ٤:٣ تفيض بعرضها');
            $this->assertSame(StoreMedia::sizes(StoreMedia::BLOCK), $k['sizes'], 'طوليّةٌ في ٤:٣ بعرض إطارها');

            // ‏الهاتفُ يأخذ الوسطى، والحاسوبُ الكثيف كذلك (الواجهةُ بعرض ٥٩١)، وأضيقُ الهواتف المصغّرة
            $this->assertSame([1280, 1280, 1280, 640], [$this->pick($h, 390, 3), $this->pick($h, 430, 3), $this->pick($h, 1440, 2), $this->pick($h, 390, 1)]);
            // ‏والشريطُ العريض (٦١٨ نقطةً على الحاسوب) تكفيه الوسطى حتى على 2x
            $this->assertSame([1280, 1280], [$this->pick($b, 390, 3), $this->pick($b, 1440, 2)]);
        }

        // ‏وما يُرسم بعرض الإطار كلِّه (١٢٣٢) يحتاج نسخةَ العرض على حاسوبٍ كثيف — والوسطى تكفي الحاسوبَ العاديّ
        $wide = ['srcset' => 'a 640w, b 1280w, c 2560w', 'sizes' => StoreMedia::sizes(StoreMedia::WIDE)];
        $this->assertSame([2560, 1280, 1280], [$this->pick($wide, 1440, 2), $this->pick($wide, 1366, 1), $this->pick($wide, 390, 3)]);
    }

    /** وبلا صورةِ واجهة: صورةُ الصنف البديلة بأحجامها — لا مصغّرتُها وحدها في إطارٍ بعرض الصفحة */
    public function test_the_hero_falls_back_to_the_best_product_with_every_size(): void
    {
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1']);
        $path = 'products/'.Str::random(40).'.jpg';
        $im = imagecreatetruecolor(2000, 2000);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 90, 120));
        ob_start();
        imagejpeg($im, null, 92);
        Storage::disk('public')->put($path, (string) ob_get_clean());
        ImageVariants::make($path);
        Product::create(['business_id' => $this->shop->id, 'name' => 'باقة', 'price' => 10, 'cost' => 4, 'quantity' => 5, 'alert_qty' => 1, 'active' => true, 'published' => true, 'image' => $path]);

        $h = $this->img($this->get('/s/ribbon')->assertOk()->getContent(), 'rb-hero-image');

        $this->assertSame(Storage::url($path.'.w640.webp'), $h['src']);
        $this->assertSame(Storage::url($path.'.w640.webp').' 640w, '.Storage::url($path.'.w1280.webp').' 1280w, '.Storage::url($path.'.w1600.webp').' 1600w', $h['srcset'] ?? null);
        $this->assertSame(StoreMedia::sizes(StoreMedia::HERO), $h['sizes']);
        $this->assertSame(1280, $this->pick($h, 390, 3), 'مصغّرةٌ في إطار الواجهة على هاتف 3x');
    }

    public function test_a_picture_without_copies_a_logo_and_an_outside_link_stay_as_they_were(): void
    {
        $bare = $this->picture(2400, 1600, copies: false);
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1', 'store_hero_image' => $bare, 'store_banner_image' => 'https://cdn.example.com/b.jpg']);
        $this->shelf();

        $html = $this->get('/s/ribbon')->assertOk()->getContent();

        $h = $this->img($html, 'rb-hero-image');
        $this->assertSame($bare, $h['src'], 'بلا نسخ: الأصلُ كما كان');
        $this->assertArrayNotHasKey('srcset', $h);
        $b = $this->img($html, 'rb-banner-image');
        $this->assertSame('https://cdn.example.com/b.jpg', $b['src']);
        $this->assertArrayNotHasKey('srcset', $b);
        $this->assertSame('', (string) StoreMedia::sized($this->shop->id, '/storage/logos/'.Str::random(40).'.png', StoreMedia::ABOUT), 'الشعارُ لا نسخَ له');
    }

    /** و«كاملة» لا تطلب أعرضَ ممّا تُرسم، و«ملء الإطار» يطلب ما يفيض — والإطارُ بلا نسبةٍ بعرضه */
    public function test_the_drawn_width_follows_the_picture_and_its_frame(): void
    {
        $this->assertSame(1.0, StoreMedia::factor(['w' => 1000, 'h' => 2000], StoreMedia::COVER, 4 / 3), 'طوليّةٌ تملأ الإطار بعرضه');
        $this->assertEqualsWithDelta(16 / 9 / (4 / 3), StoreMedia::factor(['w' => 1600, 'h' => 900], StoreMedia::COVER, 4 / 3), 1e-9);
        $this->assertEqualsWithDelta(0.5 / (4 / 3), StoreMedia::factor(['w' => 1000, 'h' => 2000], StoreMedia::CONTAIN, 4 / 3), 1e-9);
        $this->assertSame(1.0, StoreMedia::factor(['w' => 1600, 'h' => 900], StoreMedia::CONTAIN, 4 / 3));
        $this->assertSame(1.0, StoreMedia::factor(['w' => 1600, 'h' => 900], StoreMedia::COVER, null), 'بلا نسبةٍ ثابتة: بعرض مكانها');
        $this->assertSame(1.0, StoreMedia::factor(null, StoreMedia::COVER, 4 / 3), 'بلا أبعاد: بعرض مكانها');
        $this->assertSame('(min-width: 1280px) 1232px, calc(100vw - 48px)', StoreMedia::sizes(StoreMedia::WIDE));
        $this->assertSame('(min-width: 1280px) 296px, (min-width: 1136px) calc(25vw - 24px), (min-width: 860px) calc(33.34vw - 26.66px), (min-width: 584px) calc(50vw - 32px), calc(100vw - 48px)', StoreMedia::sizes(StoreMedia::GALLERY));
    }

    /**
     * وكلُّ قاعدةٍ بما رسمه متصفّحٌ حقيقيّ على صفحة RIBBON الحيّة (Chromium، ٢٠٢٦-١١-٠٣):
     * [عرضُ الشاشة، عرضُ المكان مرسومًا]. لا تقلّ عنه أبدًا (وإلّا شوّشت)، ولا تزيد بأكثر من ستّ نقاط.
     * وصورُ الصفحة ومعرضُها وعرضُها قيست على CSS الصفحة نفسه في صفحةٍ مصنوعة.
     */
    public function test_every_rule_matches_widths_measured_in_a_real_browser(): void
    {
        $measured = [
            'HERO' => [[301, 300], [343, 300], [391, 343], [433, 385], [601, 553], [718, 670], [742, 694], [760, 340.81], [862, 389.77], [1003, 457.45], [1138, 522.25], [1201, 552.48], [1366, 588.69], [1600, 588]],
            'BANNER' => [[301, 205], [343, 247], [391, 295], [433, 337], [601, 504.94], [718, 612.56], [742, 301.33], [760, 309.61], [862, 356.53], [1003, 421.39], [1138, 483.48], [1201, 512.47], [1366, 545.38], [1600, 544]],
            'BLOCK' => [[301, 253], [343, 295], [391, 343], [433, 385], [601, 553], [718, 670], [742, 327], [760, 336], [862, 387], [1003, 457.5], [1138, 525], [1201, 556.5], [1366, 596], [1600, 596]],
            'ABOUT' => [[301, 253], [343, 295], [391, 343], [433, 385], [601, 553], [718, 320.64], [742, 332.17], [760, 340.81], [862, 389.77], [1003, 457.45], [1138, 522.25], [1201, 552.48], [1366, 590], [1600, 590]],
            'WIDE' => [[301, 253], [343, 295], [391, 343], [433, 385], [601, 553], [718, 670], [742, 694], [760, 712], [862, 814], [1003, 955], [1138, 1090], [1201, 1153], [1366, 1232], [1600, 1232]],
            'SECTION' => [[301, 253], [343, 295], [391, 343], [433, 385], [601, 553], [718, 320.64], [742, 332.16], [760, 340.8], [862, 389.77], [1003, 457.44], [1138, 522.23], [1201, 552.5], [1366, 592], [1600, 592]],
            'GALLERY' => [[301, 253], [343, 295], [391, 343], [433, 385], [601, 268.5], [718, 327], [742, 339], [760, 348], [862, 260.66], [1003, 307.66], [1138, 260.5], [1201, 276.25], [1366, 296], [1600, 296]],
            'PROMO' => [[301, 205], [343, 247], [391, 295], [433, 337], [601, 504.94], [718, 612.56], [742, 302.48], [760, 310.41], [862, 355.3], [1003, 417.39], [1138, 479.48], [1201, 508.5], [1366, 548], [1600, 548]],
        ];

        foreach ($measured as $rule => $points) {
            $sizes = StoreMedia::sizes(constant(StoreMedia::class.'::'.$rule));

            foreach ($points as [$viewport, $drawn]) {
                $said = self::width($sizes, $viewport);
                $this->assertGreaterThanOrEqual($drawn - 0.05, $said, "$rule على $viewport: sizes أضيقُ ممّا يُرسم");
                $this->assertLessThanOrEqual($drawn + 6, $said, "$rule على $viewport: sizes أعرضُ ممّا يُرسم بكثير");
            }
        }
    }

    /**
     * أرقامُ CSS التي بُنيت عليها القواعد — قيست القواعدُ عليها في متصفّحٍ حقيقيّ.
     * تغييرُ واحدٍ منها دون قاعدته في `StoreMedia` يُرسل صورةً أصغرَ من مكانها.
     */
    public function test_the_rules_still_match_the_css_they_were_measured_on(): void
    {
        $view = fn (string $name) => (string) file_get_contents(resource_path('views/store/ribbon/'.$name.'.blade.php'));
        $squash = fn (string $css) => preg_replace('/\s+/', '', $css);

        $expect = [
            'layout' => ['.rb-wrap{max-width:1280px;margin:0auto;padding:024px;', '.rb-section{max-width:1280px;margin:0auto;padding:clamp(48px,6vw,80px)24px0;'],
            'home' => ['grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr));gap:clamp(28px,4vw,56px)', 'aspect-ratio:5/4;', 'min-height:240px'],
            'sections/banner' => ['padding:clamp(32px,4vw,56px)clamp(24px,4vw,56px);display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:32px', 'aspect-ratio:4/3'],
            'sections/block' => ['grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:40px', 'aspect-ratio:4/3'],
            'about' => ['grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:clamp(28px,4vw,52px)', 'width:100%;height:auto'],
            'shop' => ['aspect-ratio:21/8'],
            'page' => [
                '.rb-cp-figure.is-cover{aspect-ratio:16/9;}',
                '.rb-cp-sec{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:clamp(20px,4vw,48px);',
                '.rb-cp-gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,260px),1fr));gap:16px;',
                '.rb-cp-gallery.rb-cp-figure.is-cover{aspect-ratio:1/1;}',
                '.rb-cp-promo{background:var(--rb-soft);border-radius:var(--rb-r-lg);padding:clamp(24px,4vw,48px);display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:clamp(20px,4vw,40px);',
            ],
        ];

        foreach ($expect as $name => $needles) {
            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $squash($view($name)), $name.': تغيّر CSS الذي قيست عليه قاعدةُ `sizes` — قِس من جديد وغيّر `StoreMedia`');
            }
        }
    }
}
