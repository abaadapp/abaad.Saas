<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Setting;
use App\Models\User;
use App\Support\Demo;
use App\Support\ImageVariants;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Store\StoreImageUpload;
use App\Support\Website\Builder;
use App\Support\Website\Preview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الصفحةُ تطلب أخفَّ نسخةٍ موجودة — والأصلُ وما في القاعدة لا يتغيّران.
 *
 * قِيس على الإنتاج: رئيسيّةُ RIBBON تطلب ثماني عشرة صورةً بأربعةٍ وثلاثين
 * ميغابايت، ولا واحدةَ منها نسخةٌ مصغّرة. ومواقعُ المنشئ ترسل الأصلَ نفسه في
 * بطاقةٍ بعرض مئتين، والصندوقُ يأخذ نسخةَ العرض لشبكته. فالنسخةُ تُختار
 * لحظةَ الرسم (`ImageVariants::link` و`url`): المصغّرةُ للبطاقة، ونسخةُ العرض
 * للإطار العريض، والأصلُ حيث لم تُصنع — والرابطُ المحفوظُ في الإعدادات
 * والأعمدة يبقى على الأصل كما كان.
 */
class AStorePageAsksForTheLightestCopyTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('public');
        config(['images.pause_ms' => 0]);

        $this->shop = Business::create([
            'name' => 'متجر ورد', 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1']);
    }

    /** صورةُ بضاعةٍ مصنوعة — تدرّجٌ وحبيبات، وبخلفيّةٍ شفّافة إن طُلبت */
    private function photo(int $w, int $h, string $type = 'jpeg', bool $alpha = false): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, $alpha ? 127 : 0));
        mt_srand(7);

        for ($y = $alpha ? (int) ($h / 4) : 0; $y < ($alpha ? $h * 3 / 4 : $h); $y += 3) {
            for ($x = $alpha ? (int) ($w / 4) : 0; $x < ($alpha ? $w * 3 / 4 : $w); $x += 3) {
                $n = mt_rand(-20, 20);
                $c = imagecolorallocatealpha($im,
                    max(0, min(255, (int) (200 * $x / $w) + 30 + $n)),
                    max(0, min(255, (int) (160 * $y / $h) + 50 + $n)),
                    max(0, min(255, (int) (120 + 80 * sin(($x + $y) / 70)) + $n)), 0);
                imagefilledrectangle($im, $x, $y, $x + 2, $y + 2, $c);
            }
        }

        ob_start();
        $type === 'png' ? imagepng($im) : imagejpeg($im, null, 95);

        return (string) ob_get_clean();
    }

    /** ملفٌّ على القرص العامّ باسمٍ كما يعطيه الرفع — ونسخُه إن طُلبت */
    private function stored(string $folder, int $w, int $h, bool $copies = true): string
    {
        $path = $folder.'/'.Str::random(40).'.jpg';
        Storage::disk('public')->put($path, $this->photo($w, $h));

        if ($copies) {
            $this->assertSame('made', ImageVariants::make($path)['status'], $path);
        }

        return $path;
    }

    private function product(string $image, ?int $category = null, string $name = 'باقة ورد'): Product
    {
        return Product::create([
            'business_id' => $this->shop->id, 'name' => $name, 'name_en' => 'Rose bouquet', 'price' => 20, 'cost' => 8,
            'quantity' => 10, 'alert_qty' => 1, 'active' => true, 'published' => true, 'image' => $image,
            'category_id' => $category,
        ]);
    }

    /** @return array<string, string> كلُّ ملفٍّ على القرص العامّ وبصمتُه */
    private function disk(): array
    {
        $out = [];

        foreach (Storage::disk('public')->allFiles() as $f) {
            $out[$f] = md5((string) Storage::disk('public')->get($f));
        }

        ksort($out);

        return $out;
    }

    /** مسارُ الرابط وحده — الشكلُ الذي يُحفظ به في الإعدادات */
    private function rel(string $path): string
    {
        return (string) parse_url(Storage::url($path), PHP_URL_PATH);
    }

    /* ═══════════ الرابطُ المحفوظ ونسختُه ═══════════ */

    public function test_a_stored_link_gets_its_copy_in_the_form_it_came_in_and_nothing_else_moves(): void
    {
        $hero = $this->stored('website/'.$this->shop->id, 2000, 1000, copies: false);
        $rel = $this->rel($hero);
        $abs = rtrim((string) config('app.url'), '/').$rel;

        // ‏بلا نسخةٍ بعد: الرابطُ كما هو، بأيّ شكلٍ جاء
        foreach ([$rel, $abs, Storage::url($hero)] as $v) {
            $this->assertSame($v, ImageVariants::link($v, ImageVariants::DISPLAY));
        }

        $this->assertSame('made', ImageVariants::make($hero)['status']);
        $before = $this->disk();

        $this->assertSame($rel.'.w2560.webp', ImageVariants::link($rel, ImageVariants::DISPLAY));
        $this->assertSame($rel.'.w640.webp', ImageVariants::link($rel, ImageVariants::THUMB), 'صورةُ الموقع لها مصغّرة لبطاقتها');
        $this->assertSame($abs.'.w2560.webp', ImageVariants::link($abs, ImageVariants::DISPLAY), 'المطلقُ يبقى مطلقًا بنطاقه');

        // ‏وما ليس صورةً على قرصنا يمرّ كما هو
        foreach ([
            'https://cdn.example.test'.$rel,
            $rel.'?v=2',
            '/storage/website/'.$this->shop->id.'/../'.$this->shop->id.'/'.basename($hero),
            'data:image/png;base64,iVBORw0KGgo=',
            '/storage/logos/'.Str::random(40).'.png',
            $rel.'.w640.webp',
            '',
        ] as $v) {
            $this->assertSame($v, ImageVariants::link($v, ImageVariants::DISPLAY), $v);
        }

        $this->assertSame($before, $this->disk(), 'اختيارُ النسخة كتب على القرص');
    }

    /* ═══════════ RIBBON ═══════════ */

    public function test_the_ribbon_home_asks_for_copies_and_puts_only_its_hero_first(): void
    {
        $folder = 'website/'.$this->shop->id;
        $hero = $this->stored($folder, 2000, 1600, copies: false);
        $banner = $this->stored($folder, 2400, 900, copies: false);
        $card = $this->stored($folder, 1600, 1200, copies: false);
        $category = Category::create(['business_id' => $this->shop->id, 'name' => 'ورد', 'card_image' => $this->rel($card)]);
        $this->product($this->stored('products', 2400, 1800, copies: false), $category->id);

        MarketingSettings::save($this->shop->id, 'website', [
            'store_hero_image' => $this->rel($hero), 'store_banner_image' => $this->rel($banner),
        ]);
        $settings = DB::table('settings')->where('business_id', $this->shop->id)->orderBy('id')->pluck('value', 'key')->all();
        $card0 = DB::table('categories')->where('id', $category->id)->value('card_image');

        // ‏قبل النسخ: الأصولُ كما كانت، والتحميلُ نفسُه أخفّ
        $html = $this->get('/s/ribbon')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<img src="'.preg_quote($this->rel($hero), '/').'" alt="" fetchpriority="high"/', $html);
        $this->assertMatchesRegularExpression('/<img src="'.preg_quote($this->rel($banner), '/').'" alt="" loading="lazy"/', $html, 'البنرُ تحت الواجهة يُطلب حين يُبلغ');
        $this->assertSame(1, substr_count($html, 'fetchpriority="high"'), 'الأولويّةُ لصورةٍ واحدة');

        foreach ([$hero, $banner, $card] as $path) {
            ImageVariants::make($path);
        }

        foreach (DB::table('products')->where('business_id', $this->shop->id)->pluck('image') as $raw) {
            ImageVariants::make($raw);
        }

        $html = $this->get('/s/ribbon')->assertOk()->getContent();
        $this->assertStringContainsString('src="'.$this->rel($hero).'.w2560.webp"', $html, 'الواجهةُ بنسخة العرض');
        $this->assertStringContainsString('src="'.$this->rel($banner).'.w2560.webp"', $html, 'البنرُ بنسخة العرض');
        $this->assertStringContainsString('src="'.$this->rel($card).'.w640.webp"', $html, 'بطاقةُ القسم بمصغّرتها');
        $this->assertStringNotContainsString('src="'.$this->rel($card).'"', $html);
        $this->assertSame(1, substr_count($html, 'fetchpriority="high"'));

        // ‏والإعداداتُ والعمودُ على الأصل — لا شيء كُتب
        $this->assertSame($settings, DB::table('settings')->where('business_id', $this->shop->id)->orderBy('id')->pluck('value', 'key')->all());
        $this->assertSame($card0, DB::table('categories')->where('id', $category->id)->value('card_image'));
    }

    public function test_the_product_page_offers_both_sizes_and_its_strip_draws_thumbnails(): void
    {
        $main = $this->stored('products', 2400, 1800);
        $side = $this->stored('products', 1800, 2400);
        $rose = $this->product($main);
        ProductImage::create(['business_id' => $this->shop->id, 'product_id' => $rose->id, 'path' => $side, 'sort_order' => 1]);

        $html = $this->get('/s/ribbon/p/'.$rose->id)->assertOk()->getContent();
        $display = Storage::url($main.'.w1600.webp');

        $this->assertMatchesRegularExpression('/<img src="'.preg_quote($display, '/').'"\s+srcset="'.preg_quote(Storage::url($main.'.w640.webp'), '/').' 640w, '.preg_quote($display, '/').' 1600w" sizes="[^"]+"[^>]*fetchpriority="high"[^>]*data-rb-main/', $html);

        // ‏والشريطُ مصغّراتٌ، وكلُّ زرٍّ يفتح الصورةَ بحجمها وأحجامِها — والطوليّةُ بعرضها لا بضلعها
        preg_match_all('/<button[^>]*class="rb-thumb[^"]*" data-src="([^"]+)"[^>]*data-srcset="([^"]+)"[^>]*>\s*(?:\{\{--.*?--\}\}\s*)?<img src="([^"]+)"/s', $html, $m);
        $this->assertSame([$display, Storage::url($side.'.w1600.webp')], $m[1]);
        $this->assertStringContainsString(Storage::url($side.'.w640.webp').' 480w', $m[2][1]);
        $this->assertSame([Storage::url($main.'.w640.webp'), Storage::url($side.'.w640.webp')], $m[3]);
        $this->assertSame(1, substr_count($html, 'fetchpriority="high"'));

        // ‏وصنفٌ لم تُصنع نسخُه: أصلُه كما كان، بلا srcset
        $plain = $this->product($this->stored('products', 1200, 900, copies: false), name: 'زنبق');
        $html = $this->get('/s/ribbon/p/'.$plain->id)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<img src="'.preg_quote(Storage::url($plain->getRawOriginal('image')), '/').'"\s+alt=[^>]*data-rb-main/', $html);
        $this->assertStringNotContainsString('srcset=', $html);
    }

    public function test_the_cart_draws_its_lines_with_thumbnails(): void
    {
        $rose = $this->product($this->stored('products', 2400, 1800));

        $lines = $this->postJson('/s/ribbon/quote', ['items' => [['id' => $rose->id, 'qty' => 1]]])->assertOk()->json('lines');

        $this->assertSame(Storage::url($rose->getRawOriginal('image').'.w640.webp'), $lines[0]['image']);
    }

    /* ═══════════ مواقع المنشئ ═══════════ */

    public function test_a_builder_site_sends_thumbnails_to_cards_and_copies_to_wide_images(): void
    {
        $owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->actingAs($owner);
        // ‏متجرُ منشئٍ بلا فرعٍ كما يُنشأ — رفُّه من كمّيّة الصنف (`Shelf`)
        $this->shop->update(['storefront_theme' => null]);
        Branch::where('business_id', $this->shop->id)->delete();
        $category = Category::create(['business_id' => $this->shop->id, 'name' => 'باقات']);
        // ‏والغلافُ صورةُ أحدث صنفٍ في التصنيف — فالمنسوخُ آخرُهما
        $lily = $this->product($this->stored('products', 1200, 900, copies: false), $category->id, 'زنبق');
        $rose = $this->product($this->stored('products', 2400, 1800), $category->id);
        $hero = $this->stored('website/'.$this->shop->id, 2000, 1200);

        $site = Builder::create($this->shop, 'store', 'modern', $owner->id);
        $section = $site->pages()->where('key', 'home')->first()->sections()->where('type', 'hero')->first();
        $section->update(['data' => array_merge($section->data, ['image' => $hero])]);
        $app = rtrim((string) config('app.url'), '/');

        foreach (['ar', 'en'] as $locale) {
            Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'locale'], ['value' => $locale]);
            $doc = Preview::document($site->refresh());
            $products = collect($doc['data']['products'])->keyBy('id');
            $this->assertSame($locale, $doc['locale']);

            $this->assertSame($app.$this->rel($rose->getRawOriginal('image').'.w1600.webp'), $products[$rose->id]['image'], 'صفحةُ المنتج بنسخة العرض');
            $this->assertSame($app.$this->rel($rose->getRawOriginal('image').'.w640.webp'), $products[$rose->id]['thumb'], 'البطاقةُ بمصغّرتها');
            $this->assertSame($app.$this->rel($lily->getRawOriginal('image')), $products[$lily->id]['thumb'], 'بلا نسخة: الأصلُ كما كان');

            $covers = collect($doc['data']['categories'])->keyBy('id');
            $this->assertSame($products[$rose->id]['thumb'], $covers[$category->id]['image'], 'غلافُ التصنيف مصغّرة');

            $images = collect($doc['pages'])->flatMap(fn ($p) => $p['sections'])->where('type', 'hero')->pluck('data.image')->filter()->values();
            $this->assertContains($app.$this->rel($hero.'.w2560.webp'), $images->all(), 'صورةُ القسم بنسخة العرض، مطلقةً لنطاق التاجر');
        }

        // ‏ورابطٌ خارجيّ في قسمٍ يبقى كما كتبه التاجر
        $section->update(['data' => array_merge($section->data, ['image' => 'https://cdn.example.test/hero.jpg'])]);
        $images = collect(Preview::document($site->refresh())['pages'])->flatMap(fn ($p) => $p['sections'])->where('type', 'hero')->pluck('data.image')->filter();
        $this->assertContains('https://cdn.example.test/hero.jpg', $images->all());
    }

    /* ═══════════ الصندوق ═══════════ */

    public function test_the_till_grid_gets_a_thumbnail_and_every_other_screen_keeps_its_image(): void
    {
        $owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->actingAs($owner);
        $rose = $this->product($this->stored('products', 2400, 1800));
        $plain = $this->product($this->stored('products', 1200, 900, copies: false), name: 'زنبق');

        $rows = collect(Demo::products())->keyBy('id');

        $this->assertSame(Storage::url($rose->getRawOriginal('image').'.w640.webp'), $rows[$rose->id]['thumb']);
        $this->assertSame($rose->fresh()->image, $rows[$rose->id]['image'], 'الصورةُ نفسُها لمن يقرأ image — الملفُّ والتصدير والمشتريات');
        $this->assertSame(Storage::url($plain->getRawOriginal('image')), $rows[$plain->id]['thumb']);
    }

    /* ═══════════ الرفع ═══════════ */

    public function test_a_transparent_png_banner_gets_its_copies_on_upload_and_keeps_its_transparency(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($file, $this->photo(1600, 1000, 'png', alpha: true));
        $url = StoreImageUpload::store($this->shop->id, new UploadedFile($file, 'banner.png', 'image/png', null, true))['url'];

        $path = ltrim(substr($url, strpos($url, '/storage/') + strlen('/storage/')), '/');
        $this->assertStringEndsWith('.png', $path, 'الأصلُ بنوعه كما كان');

        foreach ([$path, $path.'.w2560.webp', $path.'.w640.webp'] as $f) {
            Storage::disk('public')->assertExists($f);
            $im = imagecreatefromstring((string) Storage::disk('public')->get($f));
            $this->assertSame(127, (imagecolorat($im, 2, 2) >> 24) & 0x7F, $f.': الزاويةُ الشفّافة صارت لونًا');
            $this->assertSame(0, (imagecolorat($im, (int) (imagesx($im) / 2), (int) (imagesy($im) / 2)) >> 24) & 0x7F, $f.': الوسطُ صار شفّافًا');
        }

        $this->assertLessThan(Storage::disk('public')->size($path), Storage::disk('public')->size($path.'.w2560.webp'));
    }

    public function test_a_sideways_phone_photo_gets_upright_copies_and_a_tight_server_still_takes_the_upload(): void
    {
        $sideways = $this->photo(2000, 1000);
        $tiff = 'II*'."\0".pack('V', 8).pack('v', 1).pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('v', 6)."\0\0".pack('V', 0);
        $payload = "Exif\0\0".$tiff;
        $file = tempnam(sys_get_temp_dir(), 'jpg');
        file_put_contents($file, "\xFF\xD8\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($sideways, 2));

        $url = StoreImageUpload::store($this->shop->id, new UploadedFile($file, 'phone.jpg', 'image/jpeg', null, true))['url'];
        $path = ltrim(substr($url, strpos($url, '/storage/') + strlen('/storage/')), '/');

        $this->assertSame([1000, 2000], array_slice(getimagesize(Storage::disk('public')->path($path)), 0, 2));
        $this->assertSame([1000, 2000], array_slice(getimagesize(Storage::disk('public')->path($path.'.w2560.webp')), 0, 2), 'النسخةُ قائمةٌ كأصلها');
        $this->assertSame([320, 640], array_slice(getimagesize(Storage::disk('public')->path($path.'.w640.webp')), 0, 2));

        // ‏وخادمٌ لا تتّسع ذاكرتُه الآن: الرفعُ ينجح بأصله، والنسخُ تُصنع لاحقًا
        $meminfo = tempnam(sys_get_temp_dir(), 'mem');
        file_put_contents($meminfo, "MemTotal: 1000000 kB\nMemAvailable: 150000 kB\n");
        config(['images.meminfo' => $meminfo]);
        file_put_contents($file, $this->photo(1800, 1200));

        $url = StoreImageUpload::store($this->shop->id, new UploadedFile($file, 'tight.jpg', 'image/jpeg', null, true))['url'];
        $path = ltrim(substr($url, strpos($url, '/storage/') + strlen('/storage/')), '/');
        Storage::disk('public')->assertExists($path);
        Storage::disk('public')->assertMissing($path.'.w2560.webp');
        Storage::disk('public')->assertMissing($path.'.w640.webp');
    }
}
