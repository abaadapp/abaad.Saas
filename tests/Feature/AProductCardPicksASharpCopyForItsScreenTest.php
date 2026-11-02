<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\ImageVariants;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Website\Builder;
use App\Support\Website\Preview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * بطاقةُ المنتج تعطي المتصفّحَ أحجامَ صورتها وعرضَها الحقيقيّ.
 *
 * قِيس قبل معالجة صورة المنتج 177 في RIBBON: بطاقتُه على الهاتف بعرض 342
 * نقطة، فتحتاج على شاشة 3x نحو ألفِ بكسل — والبطاقةُ كانت تُعطى المصغّرةَ
 * وحدها (640)، فتشوّش النصُّ المطبوع على شريط الباقة. فصارت البطاقةُ تكتب
 * `srcset` من الأصل لا من رابط المصغّرة، و`sizes` بحساب شبكتها — في RIBBON،
 * والمتجرِ العاديّ، وموقعِ المنشئ.
 *
 * ولا يُكتب حجمٌ لم يُصنع، ولا حجمٌ أكبرُ من الأصل: ما في `srcset` نسخٌ على
 * القرص أو الأصلُ نفسُه، وعرضُ كلٍّ من رأس ملفّه.
 */
class AProductCardPicksASharpCopyForItsScreenTest extends TestCase
{
    use RefreshDatabase;

    private const RIBBON_SIZES = '(min-width: 1280px) 290px, (min-width: 1040px) calc(25vw - 30px), (min-width: 786px) calc(33.34vw - 32px), (min-width: 532px) calc(50vw - 36px), calc(100vw - 48px)';

    private const CLASSIC_SIZES = '(min-width: 1100px) 257px, (min-width: 1000px) calc(25vw - 18px), (min-width: 700px) calc(33.34vw - 20px), calc(50vw - 23px)';

    private Business $shop;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('public');
        config(['images.pause_ms' => 0]);

        $this->shop = $this->business('ribbon', 'ribbon');
    }

    private function business(string $slug, ?string $theme): Business
    {
        $shop = Business::create([
            'name' => 'متجر '.$slug, 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => $slug, 'tier' => $theme === null ? null : 'gold', 'storefront_theme' => $theme,
        ]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        Branch::create(['business_id' => $shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        MarketingSettings::save($shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1']);

        return $shop;
    }

    /** صورةٌ بتفاصيلَ دقيقة — وشفّافةُ الحوافّ إن طُلب */
    private function stored(int $w, int $h, string $type = 'jpeg', bool $alpha = false): string
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

        $path = 'products/'.Str::random(40).($type === 'png' ? '.png' : '.jpg');
        ob_start();
        $type === 'png' ? imagepng($im) : imagejpeg($im, null, 95);
        Storage::disk('public')->put($path, (string) ob_get_clean());

        return $path;
    }

    private function product(Business $shop, string $image): Product
    {
        return Product::create([
            'business_id' => $shop->id, 'name' => 'باقة '.Str::random(4), 'name_en' => 'Bouquet', 'price' => 20, 'cost' => 8,
            'quantity' => 10, 'alert_qty' => 1, 'active' => true, 'published' => true, 'image' => $image,
        ]);
    }

    /** وسمُ صورة البطاقة لهذا الأصل في الصفحة — وخصائصُه */
    private function img(string $html, string $raw): array
    {
        $this->assertSame(1, preg_match('/<img src="[^"]*'.preg_quote(basename($raw), '/').'[^"]*"[^>]*>/u', $html, $m), 'لا بطاقةَ لـ'.$raw);
        preg_match_all('/\s([a-z]+)="([^"]*)"/', $m[0], $attrs, PREG_SET_ORDER);

        return collect($attrs)->mapWithKeys(fn ($a) => [$a[1] => html_entity_decode($a[2])])->all();
    }

    private function url(string $path): string
    {
        return Storage::url($path);
    }

    /** العرضُ الأكبرُ في `srcset` */
    private function widest(string $srcset): int
    {
        preg_match_all('/ (\d+)w(?:,|$)/', $srcset, $m);

        return max(array_map('intval', $m[1]));
    }

    public function test_a_ribbon_card_offers_the_thumbnail_and_the_display_copy_with_its_true_width_in_both_languages(): void
    {
        $raw = $this->stored(2400, 1800);
        $this->assertSame('made', ImageVariants::make($raw)['status']);
        $this->product($this->shop, $raw);

        foreach (['/s/ribbon/shop', '/s/ribbon/shop?lang=en'] as $page) {
            $img = $this->img($this->get($page)->assertOk()->getContent(), $raw);

            $this->assertSame($this->url($raw.'.w640.webp'), $img['src'], $page.': المصغّرةُ لمتصفّحٍ لا يقرأ srcset');
            $this->assertSame($this->url($raw.'.w640.webp').' 640w, '.$this->url($raw.'.w1600.webp').' 1600w', $img['srcset'] ?? null, $page);
            $this->assertSame(self::RIBBON_SIZES, $img['sizes'] ?? null, $page.': عرضُ البطاقة من شبكة .rb-grid');
            $this->assertSame('lazy', $img['loading']);
        }
    }

    /*
     * ‏وما في `srcset` هو ما على القرص — لا أكثر:
     *   مصغّرةٌ وحدها: الأكبرُ هو الأصل؛ نسخةُ عرضٍ وحدها أو لا نسخة: صورةٌ واحدة
     *   بلا `srcset`؛ وصورةٌ صغيرة أو طوليّة لا تُعطى عرضًا أكبرَ ممّا صُوِّر.
     */
    public function test_a_card_offers_only_what_exists_and_never_more_than_the_original(): void
    {
        $thumbOnly = $this->stored(2400, 1800);
        ImageVariants::make($thumbOnly);
        Storage::disk('public')->delete($thumbOnly.'.w1600.webp');

        $displayOnly = $this->stored(2400, 1800);
        ImageVariants::make($displayOnly);
        Storage::disk('public')->delete($displayOnly.'.w640.webp');

        $none = $this->stored(2400, 1800);
        $small = $this->stored(500, 400);
        ImageVariants::make($small);
        $tall = $this->stored(736, 1104);
        ImageVariants::make($tall);

        foreach ([$thumbOnly, $displayOnly, $none, $small, $tall] as $raw) {
            $this->product($this->shop, $raw);
        }

        $html = $this->get('/s/ribbon/shop')->assertOk()->getContent();

        $img = $this->img($html, $thumbOnly);
        $this->assertSame($this->url($thumbOnly.'.w640.webp'), $img['src']);
        $this->assertSame($this->url($thumbOnly.'.w640.webp').' 640w, '.$this->url($thumbOnly).' 2400w', $img['srcset'] ?? null, 'بلا نسخة عرض: الأصلُ هو الأكبر');

        $img = $this->img($html, $displayOnly);
        $this->assertSame($this->url($displayOnly.'.w1600.webp'), $img['src'], 'بلا مصغّرة: نسخةُ العرض');
        $this->assertArrayNotHasKey('srcset', $img);
        $this->assertArrayNotHasKey('sizes', $img);

        $img = $this->img($html, $none);
        $this->assertSame($this->url($none), $img['src'], 'بلا نسخ: الأصلُ كما كان');
        $this->assertArrayNotHasKey('srcset', $img);

        $img = $this->img($html, $small);
        $this->assertArrayNotHasKey('srcset', $img, 'صورةٌ أصغرُ من المصغّرة: نسخةٌ واحدة');
        $this->assertSame([500, 400], array_slice(getimagesize(Storage::disk('public')->path(ltrim(str_replace('/storage/', '', $img['src']), '/'))), 0, 2), 'لا تكبيرَ اصطناعيّ');

        $img = $this->img($html, $tall);
        $this->assertSame($this->url($tall.'.w640.webp').' 427w, '.$this->url($tall.'.w1600.webp').' 736w', $img['srcset'] ?? null, 'العرضُ من رأس الملفّ: الطوليّةُ أضيقُ من ضلعها');
        $this->assertLessThanOrEqual(736, $this->widest($img['srcset']));
    }

    public function test_a_transparent_picture_keeps_its_transparency_in_every_size_it_offers(): void
    {
        $raw = $this->stored(1800, 1800, 'png', alpha: true);
        $this->assertSame('made', ImageVariants::make($raw)['status']);
        $this->product($this->shop, $raw);

        $img = $this->img($this->get('/s/ribbon/shop')->assertOk()->getContent(), $raw);
        $this->assertSame($this->url($raw.'.w640.webp').' 640w, '.$this->url($raw.'.w1600.webp').' 1600w', $img['srcset'] ?? null);

        foreach ([$raw.'.w640.webp', $raw.'.w1600.webp'] as $copy) {
            $im = imagecreatefromwebp(Storage::disk('public')->path($copy));
            $this->assertSame(127, (imagecolorat($im, 2, 2) >> 24) & 127, $copy.': الحافّةُ الشفّافة بقيت شفّافة');
        }
    }

    public function test_the_plain_shop_card_offers_both_copies_with_its_own_grid_width(): void
    {
        $plain = $this->business('plain', null);
        $raw = $this->stored(2400, 1800);
        ImageVariants::make($raw);
        $this->product($plain, $raw);
        $bare = $this->stored(2400, 1800);
        $this->product($plain, $bare);

        $html = $this->get('/s/plain')->assertOk()->getContent();
        $img = $this->img($html, $raw);
        $this->assertSame($this->url($raw.'.w640.webp'), $img['src']);
        $this->assertSame($this->url($raw.'.w640.webp').' 640w, '.$this->url($raw.'.w1600.webp').' 1600w', $img['srcset'] ?? null);
        $this->assertSame(self::CLASSIC_SIZES, $img['sizes'] ?? null, 'عرضُ البطاقة من شبكة .grid');
        $this->assertArrayNotHasKey('srcset', $this->img($html, $bare), 'بلا نسخ: الأصلُ وحده');
    }

    public function test_a_builder_site_card_carries_every_size_with_the_shops_full_address(): void
    {
        $owner = User::create(['business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner'.Str::random(4).'@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $both = $this->product($this->shop, $raw = $this->stored(2400, 1800));
        ImageVariants::make($raw);
        $bare = $this->product($this->shop, $this->stored(2400, 1800));
        $site = Builder::create($this->shop, 'store', 'modern', $owner->id);
        $app = rtrim((string) config('app.url'), '/');

        $products = collect(Preview::document($site->refresh())['data']['products'])->keyBy('id');

        $this->assertSame($app.$this->url($raw.'.w640.webp').' 640w, '.$app.$this->url($raw.'.w1600.webp').' 1600w', $products[$both->id]['srcset']);
        $this->assertSame($app.$this->url($raw.'.w640.webp'), $products[$both->id]['thumb'], 'المصغّرةُ لمتصفّحٍ لا يقرأ srcset');
        $this->assertNull($products[$bare->id]['srcset'], 'بلا نسخ: لا أحجام');
    }
}
