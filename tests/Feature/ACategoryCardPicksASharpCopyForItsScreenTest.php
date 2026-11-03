<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
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
 * بطاقةُ القسم في رئيسيّة RIBBON تعطي المتصفّحَ أحجامَ صورتها وعرضَها المرسوم.
 *
 * ═══ العطب الذي وُضعت له ═══
 *
 * بعد صنع نسخ صور RIBBON صارت بطاقةُ القسم تُعطى مصغّرتَها وحدها. وأقسامُ سعود
 * الأربعة بصورٍ طوليّة (2268×4032): مصغّرتُها بعرض 360، والبطاقةُ على الهاتف
 * بعرض 161 — فتحتاج على شاشة 3x نحو 483 بكسلًا. فشوّشت الصورةُ وكلمةُ «Ribbon»
 * على شريط الباقة (27.7–30.9 dB مقابل الأصل في مكانه).
 *
 * ═══ وما يُحرَس ═══
 *
 *   - `srcset` من النسخ الموجودة على القرص بعرضها الحقيقيّ (`ImageVariants::srcset`).
 *   - `sizes` عرضُ الصورة المرسوم في شبكتها: بعدد البطاقات، ونسبةِ الصورة، وطريقةِ
 *     العرض (`StoreMedia::cardSizes`) — ويطابق CSS الشبكة في كلّ عرضٍ للشاشة.
 *   - كلُّ شاشةٍ تأخذ أصغرَ نسخةٍ تكفيها: المصغّرةُ حيث تكفي، ولا تُعطى صورةٌ أكبرُ من أصلها.
 *   - شكلُ البطاقة كما كان، والمصغّرةُ في `src` لمتصفّحٍ لا يقرأ `srcset`.
 */
class ACategoryCardPicksASharpCopyForItsScreenTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['images.pause_ms' => 0]);

        $this->shop = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1']);
    }

    /**
     * صورةٌ في مجلّد المتجر كما يحفظها رفعُ بطاقة القسم — رابطًا لا مسارًا.
     * بتفاصيلَ دقيقةٍ ونصٍّ صغير، وشفّافةُ الحوافّ إن طُلب.
     */
    private function picture(int $w, int $h, string $type = 'jpeg', bool $alpha = false, bool $copies = true): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 255, 255, 255, $alpha ? 127 : 0));
        mt_srand($w * 7 + $h);

        for ($y = $alpha ? (int) ($h / 4) : 0; $y < ($alpha ? $h * 3 / 4 : $h); $y += 3) {
            for ($x = $alpha ? (int) ($w / 4) : 0; $x < ($alpha ? $w * 3 / 4 : $w); $x += 3) {
                $n = mt_rand(-40, 40);
                imagefilledrectangle($im, $x, $y, $x + 1, $y + 1, imagecolorallocatealpha($im,
                    max(0, min(255, (int) (200 * $x / $w) + 30 + $n)), max(0, min(255, (int) (160 * $y / $h) + 50 + $n)), max(0, min(255, 150 + $n)), 0));
            }
        }

        imagealphablending($im, true);
        imagestring($im, 2, (int) ($w / 3), (int) ($h / 2), 'Ribbon Flower Lounge', imagecolorallocate($im, 20, 20, 20));

        $path = 'website/'.$this->shop->id.'/'.Str::random(40).($type === 'png' ? '.png' : '.jpg');
        ob_start();
        $type === 'png' ? imagepng($im) : imagejpeg($im, null, 95);
        Storage::disk('public')->put($path, (string) ob_get_clean());

        if ($copies) {
            $this->assertSame('made', ImageVariants::make($path)['status'], $path);
        }

        return Storage::url($path);
    }

    /** قسمٌ بصنفٍ معروض — فبطاقتُه في الرئيسيّة */
    private function category(?string $image): Category
    {
        $category = Category::create(['business_id' => $this->shop->id, 'name' => 'قسم '.Str::random(4), 'card_image' => $image]);
        Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة '.Str::random(4), 'price' => 10, 'category_id' => $category->id,
            'cost' => 4, 'quantity' => 5, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);

        return $category;
    }

    /** وسمُ صورة بطاقة القسم لهذا الرابط — وخصائصُه */
    private function img(string $html, string $url): array
    {
        $this->assertSame(1, preg_match('/<img src="[^"]*'.preg_quote(basename($url), '/').'[^"]*"[^>]*data-testid="rb-cat-image">/u', $html, $m), 'لا بطاقةَ لـ'.$url);
        preg_match_all('/\s([a-z-]+)="([^"]*)"/', $m[0], $attrs, PREG_SET_ORDER);

        return collect($attrs)->mapWithKeys(fn ($a) => [$a[1] => html_entity_decode($a[2])])->all() + ['tag' => $m[0]];
    }

    /** عرضُ `sizes` عند عرض شاشةٍ بالنقاط — كما يقرؤه المتصفّح: أوّلُ شرطٍ يصدق */
    private static function slot(string $sizes, float $viewport): float
    {
        static $read = [];

        // ‏كلُّ شرطٍ: [أقلُّ عرض، نسبةُ vw، ما يُطرح] — والعرضُ الثابت نسبتُه صفر
        $read[$sizes] ??= array_map(function ($entry) {
            $from = preg_match('/^\(min-width: ([\d.]+)px\) (.+)$/', $entry, $m) ? (float) $m[1] : 0.0;
            $value = $m[2] ?? $entry;

            if (preg_match('/^([\d.]+)px$/', $value, $v)) {
                return [$from, 0.0, -(float) $v[1]];
            }

            preg_match('/^calc\(([\d.]+)vw - ([\d.]+)px\)$/', $value, $v) || self::fail('شكلٌ لا يُقرأ: '.$value);

            return [$from, (float) $v[1], (float) $v[2]];
        }, array_map('trim', explode(', ', $sizes)));

        foreach ($read[$sizes] as [$from, $vw, $minus]) {
            if ($viewport >= $from) {
                return $vw * $viewport / 100 - $minus;
            }
        }

        self::fail('لا عرضَ افتراضيًّا في sizes');
    }

    /** ما يختاره المتصفّح: أصغرُ عرضٍ يكفي البكسلات، وإلّا الأكبر */
    private function pick(array $img, float $viewport, float $dpr): int
    {
        preg_match_all('/ (\d+)w(?:,|$)/', $img['srcset'], $m);
        $widths = array_map('intval', $m[1]);
        sort($widths);
        $need = self::slot($img['sizes'], $viewport) * $dpr;

        foreach ($widths as $w) {
            if ($w >= $need) {
                return $w;
            }
        }

        return max($widths);
    }

    public function test_a_tall_picture_offers_both_copies_with_their_true_widths_in_both_languages(): void
    {
        $tall = $this->picture(900, 1600);
        $this->category($tall);
        $this->category(null);

        foreach (['/s/ribbon', '/s/ribbon?lang=en'] as $page) {
            $img = $this->img($this->get($page)->assertOk()->getContent(), $tall);

            $this->assertSame($tall.'.w640.webp', $img['src'], $page.': المصغّرةُ لمتصفّحٍ لا يقرأ srcset');
            $this->assertSame($tall.'.w640.webp 360w, '.$tall.'.w1280.webp 720w, '.$tall.'.w2560.webp 900w', $img['srcset'] ?? null, $page.': العرضُ من رأس الملفّ — الطوليّةُ أضيقُ من ضلعها');
            $this->assertSame(StoreMedia::cardSizes(2, ['w' => 900, 'h' => 1600], 'cover'), $img['sizes'] ?? null, $page);
            $this->assertSame('calc(50vw - 34px)', trim(explode(',', explode('(min-width: 384px)', $img['sizes'])[1])[0]), $page.': بطاقتان على الهاتف: (390 − 48 − 16) ÷ 2 − 2 = 161');
        }
    }

    /*
     * ‏وكلُّ شاشةٍ أصغرَ نسخةٍ تكفيها — كما في صفحة سعود (تسعُ بطاقات):
     *   الطوليّة: المصغّرةُ (360) لـ1x و2x، والوسطى (720) لـ3x (تحتاج 483) — لا نسخةُ العرض.
     *   المربّعة (1200): المصغّرةُ (640) تكفي الهاتفَ بكثافاته كلِّها، ولا وسطى لها.
     *   العريضة 16:9: تُرسم بارتفاع الإطار فتفيض — 3x تحتاج 644 فتأخذ الوسطى (1280).
     */
    public function test_each_screen_takes_the_smallest_copy_that_keeps_the_picture_sharp(): void
    {
        $tall = $this->picture(900, 1600);
        $square = $this->picture(1200, 1200);
        $wide = $this->picture(1920, 1080);
        $this->category($tall);
        $this->category($square);
        $this->category($wide);

        foreach (range(1, 6) as $i) {
            $this->category(null);
        }

        $html = $this->get('/s/ribbon')->assertOk()->getContent();
        $expect = [
            $tall => ['phone1x' => 360, 'phone2x' => 360, 'phone3x' => 720, 'desktop1x' => 360, 'desktop2x' => 360],
            $square => ['phone1x' => 640, 'phone2x' => 640, 'phone3x' => 640, 'desktop1x' => 640, 'desktop2x' => 640],
            $wide => ['phone1x' => 640, 'phone2x' => 640, 'phone3x' => 1280, 'desktop1x' => 640, 'desktop2x' => 640],
        ];
        $screens = ['phone1x' => [390, 1], 'phone2x' => [390, 2], 'phone3x' => [390, 3], 'desktop1x' => [1366, 1], 'desktop2x' => [1440, 2]];

        foreach ($expect as $url => $want) {
            $img = $this->img($html, $url);

            foreach ($screens as $screen => [$viewport, $dpr]) {
                $this->assertSame($want[$screen], $this->pick($img, $viewport, $dpr), basename($url).' على '.$screen);
            }
        }
    }

    /** «كاملة» ترسم الطوليّةَ أضيقَ من إطارها — فلا تطلب ما لا يُرسم */
    public function test_a_whole_picture_asks_only_for_the_width_it_draws(): void
    {
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1', 'store_catalog_fit' => 'contain']);
        $tall = $this->picture(900, 1600);
        $this->category($tall);
        $this->category(null);

        $img = $this->img($this->get('/s/ribbon')->assertOk()->getContent(), $tall);

        $this->assertSame(StoreMedia::cardSizes(2, ['w' => 900, 'h' => 1600], 'contain'), $img['sizes']);
        $this->assertSame(360, $this->pick($img, 390, 3), 'تُرسم بعرض 68 نقطة: المصغّرةُ تكفي 3x');
    }

    /*
     * ‏وما في `srcset` هو ما على القرص — لا أكثر:
     *   مصغّرةٌ وحدها: الأكبرُ هو الأصل؛ نسخةُ عرضٍ وحدها أو لا نسخة: صورةٌ واحدة
     *   بلا `srcset`؛ وصورةٌ أصغرُ من المصغّرة لا تُكبَّر؛ والرابطُ الخارجيّ كما هو.
     */
    public function test_a_card_offers_only_what_exists_and_never_more_than_the_original(): void
    {
        $thumbOnly = $this->picture(900, 1600);
        Storage::disk('public')->delete(ltrim(substr($thumbOnly, strlen('/storage/')), '/').'.w2560.webp');

        $displayOnly = $this->picture(900, 1600);
        Storage::disk('public')->delete(ltrim(substr($displayOnly, strlen('/storage/')), '/').'.w640.webp');

        $none = $this->picture(900, 1600, copies: false);
        $small = $this->picture(500, 400);
        $external = 'https://cdn.example.com/flowers/'.Str::random(20).'.jpg';

        foreach ([$thumbOnly, $displayOnly, $none, $small, $external] as $url) {
            $this->category($url);
        }

        $html = $this->get('/s/ribbon')->assertOk()->getContent();

        $img = $this->img($html, $thumbOnly);
        $this->assertSame($thumbOnly.'.w640.webp', $img['src']);
        $this->assertSame($thumbOnly.'.w640.webp 360w, '.$thumbOnly.'.w1280.webp 720w, '.$thumbOnly.' 900w', $img['srcset'] ?? null, 'بلا نسخة عرض: الأصلُ هو الأكبر');

        $img = $this->img($html, $displayOnly);
        $this->assertSame($displayOnly.'.w1280.webp', $img['src'], 'بلا مصغّرة: الوسطى قبل نسخة العرض');
        $this->assertSame($displayOnly.'.w1280.webp 720w, '.$displayOnly.'.w2560.webp 900w', $img['srcset'] ?? null, 'ما بقي على القرص وحده');

        $img = $this->img($html, $none);
        $this->assertSame($none, $img['src'], 'بلا نسخ: الأصلُ كما كان');
        $this->assertArrayNotHasKey('srcset', $img);

        $img = $this->img($html, $small);
        $this->assertArrayNotHasKey('srcset', $img, 'صورةٌ أصغرُ من المصغّرة: نسخةٌ واحدة');
        $this->assertSame([500, 400], array_slice(getimagesize(Storage::disk('public')->path(substr($img['src'], strlen('/storage/')))), 0, 2), 'لا تكبيرَ اصطناعيّ');

        $img = $this->img($html, $external);
        $this->assertSame($external, $img['src'], 'رابطٌ خارجيّ يمرّ كما هو');
        $this->assertArrayNotHasKey('srcset', $img, 'ولا أحجامَ لما ليس على قرص أبعاد');
    }

    public function test_a_transparent_picture_keeps_its_transparency_in_every_size_it_offers(): void
    {
        $png = $this->picture(1800, 1800, 'png', alpha: true);
        $this->category($png);

        $img = $this->img($this->get('/s/ribbon')->assertOk()->getContent(), $png);
        $this->assertSame($png.'.w640.webp 640w, '.$png.'.w1280.webp 1280w, '.$png.'.w2560.webp 1800w', $img['srcset'] ?? null);

        foreach ([$png.'.w640.webp', $png.'.w1280.webp', $png.'.w2560.webp'] as $copy) {
            $im = imagecreatefromwebp(Storage::disk('public')->path(substr($copy, strlen('/storage/'))));
            $this->assertSame(127, (imagecolorat($im, 2, 2) >> 24) & 127, $copy.': الحافّةُ الشفّافة بقيت شفّافة');
        }
    }

    /** شكلُ البطاقة كما كان حرفًا بحرف — لم يُضف إلّا `srcset` و`sizes` */
    public function test_the_card_keeps_its_shape(): void
    {
        $tall = $this->picture(900, 1600);
        $this->category($tall);
        $this->category(null);

        $html = $this->get('/s/ribbon')->assertOk()->getContent();
        $img = $this->img($html, $tall);
        $before = preg_replace('/ srcset="[^"]*" sizes="[^"]*"/', '', $img['tag']);

        $this->assertSame('<img src="'.$tall.'.w640.webp" alt="" loading="lazy" decoding="async" class="rb-pfit" style="display:block;width:100%;aspect-ratio:4/3" data-testid="rb-cat-image">', $before);
        $this->assertStringContainsString('<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,160px),1fr));gap:16px">', $html);
        $this->assertSame(1, substr_count($html, '<div style="aspect-ratio:4/3;background:repeating-linear-gradient('), 'البطاقةُ بلا صورة بقيت بخطوطها');
    }

    /*
     * ‏و`sizes` يطابق الشبكةَ في كلّ عرضٍ للشاشة — لا تقديرٌ.
     *
     * أرقامُ الشبكة تُقرأ من CSS نفسه (`sections/cats` و`.rb-section`)، فتغييرُها
     * دون `StoreMedia::cardSizes` يُسقط هذا. والشبكةُ: أعمدةٌ بأقلّ عرضٍ وفاصل،
     * و`auto-fit` يطوي ما زاد عن البطاقات، وحدُّ البطاقة من جانبيها، والصورةُ في
     * إطار 4:3 تُرسم «ملء الإطار» أو «كاملة» بنسبتها.
     *
     * والمقبول: لا أقلّ من المرسوم أبدًا (وإلّا شوّشت)، ولا أكثرَ منه ببكسل.
     */
    public function test_the_sizes_follow_the_grid_at_every_screen_width(): void
    {
        $cats = (string) file_get_contents(resource_path('views/store/ribbon/sections/cats.blade.php'));
        $layout = (string) file_get_contents(resource_path('views/store/ribbon/layout.blade.php'));
        $this->assertSame(1, preg_match('/minmax\(min\(100%,(\d+)px\),1fr\)\);gap:(\d+)px/', $cats, $grid));
        $this->assertSame(1, preg_match('/<a [^>]*style="display:block;background:#fff;border:(\d+)px solid var\(--rb-line\)/', $cats, $edge));
        $this->assertSame(1, preg_match('/aspect-ratio:(\d+)\/(\d+)" data-testid="rb-cat-image"/', $cats, $box));
        $this->assertSame(1, preg_match('/\.rb-section \{ max-width: (\d+)px;[^}]*padding: clamp\([^)]*\) (\d+)px 0;/', $layout, $section));
        [$min, $gap, $border, $boxW, $boxH, $wide, $pad] = [(int) $grid[1], (int) $grid[2], (int) $edge[1], (int) $box[1], (int) $box[2], (int) $section[1], (int) $section[2]];

        $wrong = [];

        foreach (range(1, 12) as $cards) {
            foreach ([[2268, 4032], [900, 1200], [1000, 1000], [1200, 900], [1920, 1080], [3000, 1000]] as [$w, $h]) {
                foreach (['cover', 'contain'] as $fit) {
                    $sizes = StoreMedia::cardSizes($cards, ['w' => $w, 'h' => $h], $fit);
                    $ratio = ($w / $h) / ($boxW / $boxH);
                    $f = $fit === 'contain' ? min(1, $ratio) : max(1, $ratio);

                    for ($v = 280; $v <= 1700; $v += 0.5) {
                        $inner = min($v, $wide) - 2 * $pad;
                        $columns = $inner < $min ? 1 : max(1, (int) floor(($inner + $gap) / ($min + $gap)));
                        $k = min($columns, $cards);
                        $drawn = (($inner - ($k - 1) * $gap) / $k - 2 * $border) * $f;
                        $said = self::slot($sizes, $v);

                        if ($said < $drawn - 1e-6 || $said > $drawn + 1) {
                            $wrong[] = "{$cards} بطاقة، {$w}×{$h} {$fit}، شاشة {$v}: sizes {$said} والمرسوم ".round($drawn, 2);
                        }
                    }
                }
            }
        }

        $this->assertSame([], array_slice($wrong, 0, 10), count($wrong).' عرضًا لا يطابق الشبكة');
    }
}
