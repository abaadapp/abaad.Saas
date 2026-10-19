<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\MarketingSettings;
use App\Support\Store\PageEditor;
use App\Support\Store\StoreCollections;
use App\Support\Store\StoreContent;
use App\Support\Store\StoreImageUpload;
use App\Support\Store\StoreVisualBar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * صورُ RIBBON كاملةً بلا قصّ — حين يختار صاحبُ المتجر ذلك — وصورٌ تُقبل من محتواها.
 *
 * ═══ وما يُحرَس ═══
 *
 *  • متجرٌ لم يختر شيئًا صورُه «ملء الإطار» كما كانت حرفًا بحرف.
 *  • و«الصورة كاملة» لصور المنتجات والفئات صنفٌ واحدٌ على الجذر، ولصورة الواجهة
 *    والشريط والقسم والمجموعة وبطاقة الشريط طريقتُها إلى جانبها.
 *  • والرفعُ يقرأ النوعَ من المحتوى: SVG وصفحةٌ تُسمّى `.jpg` تُردّان، وHEIC
 *    يُحوَّل أو يُقال كيف يُرسل. والصورةُ تُدار كما يقول رأسُها، وتُصغَّر بنسبتها
 *    ولا تُقصّ، وتبقى شفافيّتُها، وGIF كما هو.
 *
 * وقواعدُ الشاشة في `the-owner-chooses-how-images-show.test.tsx`.
 */
class RibbonImagesShowWholeWhenAskedTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Product $rose;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('public');

        $this->shop = Business::create([
            'name' => 'متجر ribbon', 'type' => 'محل ورد', 'status' => 'نشط', 'site_slug' => 'ribbon',
            'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@images.test', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1']);

        $cat = Category::create(['business_id' => $this->shop->id, 'name' => 'باقات']);
        $this->rose = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد', 'price' => 10, 'cost' => 2, 'quantity' => 20,
            'category_id' => $cat->id, 'active' => true, 'published' => true, 'image' => '/storage/products/rose.jpg',
        ]);
    }

    /* ═══════════════════════ أدوات ═══════════════════════ */

    private function mine(string $file): string
    {
        return Storage::disk('public')->url('website/'.$this->shop->id.'/'.$file);
    }

    private function html(string $path = '/'): string
    {
        return $this->get('/s/ribbon'.$path)->assertOk()->getContent();
    }

    private function save(array $values)
    {
        return $this->actingAs($this->owner)->post(route('admin.marketing.store.save'), $values);
    }

    private function upload(UploadedFile $file)
    {
        return $this->actingAs($this->owner)->post(route('admin.marketing.store.image'), ['image' => $file]);
    }

    /** صورةٌ بلونين — نصفُها الأيسر أحمر والأيمن أزرق — بصيغةٍ مختارة */
    private function painted(int $w, int $h, string $type = 'jpeg', bool $alpha = false): string
    {
        $im = imagecreatetruecolor($w, $h);

        if ($alpha) {
            imagealphablending($im, false);
            imagesavealpha($im, true);
            imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
            imagefilledrectangle($im, (int) ($w / 4), (int) ($h / 4), (int) ($w * 3 / 4), (int) ($h * 3 / 4), imagecolorallocatealpha($im, 255, 0, 0, 0));
        } else {
            imagefilledrectangle($im, 0, 0, (int) ($w / 2) - 1, $h - 1, imagecolorallocate($im, 255, 0, 0));
            imagefilledrectangle($im, (int) ($w / 2), 0, $w - 1, $h - 1, imagecolorallocate($im, 0, 0, 255));
        }

        ob_start();
        match ($type) {
            'jpeg' => imagejpeg($im, null, 95),
            'png' => imagepng($im),
            'webp' => imagewebp($im, null, 90),
            'gif' => imagegif($im),
        };

        return (string) ob_get_clean();
    }

    /** يُدخل في JPEG رأسَ EXIF فيه «الاتّجاه» — كما يكتبه الهاتف */
    private function withOrientation(string $jpeg, int $orientation): string
    {
        $tiff = 'II*'."\0".pack('V', 8).pack('v', 1).pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('v', $orientation)."\0\0".pack('V', 0);
        $payload = "Exif\0\0".$tiff;

        return "\xFF\xD8\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2);
    }

    private function file(string $name, string $bytes, string $mime): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'rbimg');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, $mime, null, true);
    }

    /** المحفوظُ في مجلّد المتجر — بأبعاده */
    private function stored(): array
    {
        $files = Storage::disk('public')->files('website/'.$this->shop->id);
        $this->assertCount(1, $files, 'ملفٌّ واحدٌ في مجلّد المتجر وحده');
        $bytes = Storage::disk('public')->get($files[0]);

        return [$files[0], $bytes, getimagesizefromstring($bytes)];
    }

    /** @return array{r: int, g: int, b: int, a: int} */
    private function pixel(string $bytes, int $x, int $y): array
    {
        $im = imagecreatefromstring($bytes);
        $c = imagecolorsforindex($im, imagecolorat($im, $x, $y));

        return ['r' => $c['red'], 'g' => $c['green'], 'b' => $c['blue'], 'a' => $c['alpha']];
    }

    /* ═══════════════════════ العرض ═══════════════════════ */

    public function test_a_shop_that_chose_nothing_keeps_every_image_filling_its_frame(): void
    {
        MarketingSettings::save($this->shop->id, 'website', ['store_hero_image' => $this->mine('hero.jpg'), 'store_sections' => 'banner,block', 'store_banner_image' => $this->mine('banner.jpg'), 'store_block_on' => '1', 'store_block_title' => 'قسمي', 'store_block_text' => 'نص', 'store_block_image' => $this->mine('block.jpg')]);
        $html = $this->html();

        $this->assertStringContainsString('<html lang="ar" dir="rtl">', $html, 'لا صنفَ «كاملة» على الجذر');
        $this->assertMatchesRegularExpression('#object-fit:cover;display:block" data-testid="rb-hero-image" data-fit="cover"#', $html);
        $this->assertMatchesRegularExpression('#object-fit:cover;display:block" data-testid="rb-banner-image" data-fit="cover"#', $html);
        $this->assertMatchesRegularExpression('#object-fit:cover;display:block" data-testid="rb-block-image" data-fit="cover"#', $html);
        $this->assertStringContainsString('class="rb-pfit"', $this->html('/p/'.$this->rose->id));
    }

    public function test_product_and_category_images_show_whole_with_one_switch(): void
    {
        $this->save(['store_catalog_fit' => 'contain'])->assertSessionHasNoErrors();

        foreach (['/', '/shop', '/p/'.$this->rose->id, '/cart'] as $path) {
            $html = $this->html($path);
            $this->assertStringContainsString('<html lang="ar" dir="rtl" class="rb-catalog-contain">', $html, $path);
            $this->assertStringContainsString('html.rb-catalog-contain .rb-pfit, html.rb-catalog-contain .rb-card .rb-img img', $html, $path);
        }

        // وصورةُ الصنف البديلةُ في الواجهة تتبع صورَ المنتجات
        $this->assertMatchesRegularExpression('#data-testid="rb-hero-image" data-fit="contain"#', $this->html());
    }

    public function test_the_hero_banner_and_block_each_keep_their_own_choice(): void
    {
        MarketingSettings::save($this->shop->id, 'website', ['store_sections' => 'banner,block', 'store_block_on' => '1', 'store_block_title' => 'قسمي', 'store_block_text' => 'نص']);
        $this->save([
            'store_hero_image' => $this->mine('hero.jpg'), 'store_hero_image_fit' => 'contain',
            'store_banner_image' => $this->mine('banner.jpg'), 'store_banner_image_fit' => 'contain',
            'store_block_image' => $this->mine('block.jpg'), 'store_block_image_fit' => 'cover',
        ])->assertSessionHasNoErrors();
        $html = $this->html();

        $this->assertMatchesRegularExpression('#background:var\(--rb-cream\);min-height:240px">\s*<img[^>]+object-fit:contain;display:block" data-testid="rb-hero-image"#', $html, 'وحولها لونٌ هادئ — لا خطوط');
        $this->assertMatchesRegularExpression('#object-fit:contain;display:block" data-testid="rb-banner-image" data-fit="contain"#', $html);
        $this->assertMatchesRegularExpression('#object-fit:cover;display:block" data-testid="rb-block-image" data-fit="cover"#', $html);
    }

    public function test_a_collection_shows_its_image_whole_at_the_head_of_its_shelf(): void
    {
        $im = imagecreatetruecolor(400, 1200);
        ob_start();
        imagepng($im);
        Storage::disk('public')->put('website/'.$this->shop->id.'/tall.png', (string) ob_get_clean());

        MarketingSettings::save($this->shop->id, 'website', [StoreCollections::KEY => json_encode([
            ['slug' => 'eid', 'title_ar' => 'العيد', 'image' => $this->mine('tall.png'), 'fit' => 'contain', 'visible' => true, 'products' => [$this->rose->id]],
            ['slug' => 'wide', 'title_ar' => 'عريضة', 'image' => $this->mine('tall.png'), 'fit' => 'cover', 'visible' => true, 'products' => [$this->rose->id]],
            ['slug' => 'plain', 'title_ar' => 'بلا صورة', 'visible' => true, 'products' => [$this->rose->id]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);

        $eid = $this->html('/shop?collection=eid');
        $this->assertMatchesRegularExpression('#data-testid="rb-collection-image" data-fit="contain"#', $eid);
        $this->assertStringContainsString('width="400" height="1200"', $eid, 'أبعادُها من الملفّ — لا قفزَ في الصفحة');
        $this->assertStringContainsString('max-height:min(42vh,340px);object-fit:contain', $eid, 'والطويلةُ لا تدفع البضاعةَ تحت الشاشة');

        $this->assertStringContainsString('aspect-ratio:21/8', $this->html('/shop?collection=wide'));
        $this->assertStringNotContainsString('rb-collection-image', $this->html('/shop?collection=plain'));
    }

    public function test_a_bar_card_shows_its_own_choice_or_its_collections(): void
    {
        MarketingSettings::save($this->shop->id, 'website', [
            StoreCollections::KEY => json_encode([['slug' => 'eid', 'title_ar' => 'العيد', 'image' => $this->mine('eid.jpg'), 'fit' => 'contain', 'visible' => true, 'products' => [$this->rose->id]]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            StoreVisualBar::KEY => json_encode(['on' => true, 'pages' => 'home', 'cards' => [
                ['image' => $this->mine('own.jpg'), 'fit' => 'contain', 'title_ar' => 'كاملة', 'target' => ['type' => 'shop', 'value' => ''], 'visible' => true],
                ['image' => $this->mine('own.jpg'), 'title_ar' => 'قديمة', 'target' => ['type' => 'shop', 'value' => ''], 'visible' => true],
                ['image' => '', 'title_ar' => 'مستعارة', 'target' => ['type' => 'collection', 'value' => 'eid'], 'visible' => true],
            ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        preg_match_all('#<img src="[^"]+" alt="" width="164" height="205"[^>]*data-fit="([a-z]+)">#', $this->html(), $m);
        $this->assertSame(['contain', 'cover', 'contain'], $m[1]);
    }

    /* ═══════════════════════ الحفظ ═══════════════════════ */

    public function test_a_fit_is_one_of_two_and_belongs_to_a_themed_shop(): void
    {
        $this->save(['store_catalog_fit' => 'stretch'])->assertSessionHasErrors('store_catalog_fit');
        $this->save(['store_hero_image_fit' => 'fill'])->assertSessionHasErrors('store_hero_image_fit');

        $this->save(['store_catalog_fit' => 'contain', 'store_hero_image_fit' => 'cover'])->assertSessionHasNoErrors();
        $this->assertSame('contain', MarketingSettings::group($this->shop->id, 'website')['store_catalog_fit']);

        $this->shop->forceFill(['storefront_theme' => null])->save();
        $this->save(['store_catalog_fit' => 'contain'])->assertForbidden();
    }

    public function test_every_fit_waits_for_publishing_and_sits_beside_its_image_in_the_editor(): void
    {
        foreach (['store_catalog_fit', 'store_hero_image_fit', 'store_banner_image_fit', 'store_block_image_fit'] as $key) {
            $this->assertContains($key, StoreContent::VERSIONED);
            $this->assertContains($key, PageEditor::omitted(), $key.' يُكتب في المحرّر لا في بطاقة الإعدادات');
            $this->assertArrayHasKey($key, PageEditor::values($this->shop->id));
        }

        $hero = collect(PageEditor::FIELDS[PageEditor::HERO])->firstWhere('key', 'store_hero_image');
        $this->assertSame(['store_hero_image_fit', '5/4'], [$hero['fit'], $hero['ratio']]);
        $this->assertSame('catalogfit', PageEditor::FIELDS[PageEditor::IMAGES][0]['kind']);
    }

    /* ═══════════════════════ الرفع ═══════════════════════ */

    public function test_a_phone_photo_is_turned_upright_and_its_header_is_dropped(): void
    {
        $sideways = $this->withOrientation($this->painted(40, 20), 6);
        $path = tempnam(sys_get_temp_dir(), 'rbo');
        file_put_contents($path, $sideways);
        $this->assertSame(6, StoreImageUpload::orientation($path), 'الرأسُ يُقرأ بلا امتداد exif');

        $this->upload($this->file('phone.jpg', $sideways, 'image/jpeg'))->assertSessionHasNoErrors()->assertSessionHas('uploaded_meta', ['w' => 20, 'h' => 40]);

        [$file, $bytes, $info] = $this->stored();
        $this->assertSame([20, 40, IMAGETYPE_JPEG], [$info[0], $info[1], $info[2]], 'دُوّرت ربعًا مع عقارب الساعة');
        // والنصفُ الأيسرُ الأحمر صار أعلاها
        $this->assertGreaterThan(150, $this->pixel($bytes, 10, 5)['r']);
        $this->assertGreaterThan(150, $this->pixel($bytes, 10, 35)['b']);

        file_put_contents($path, $bytes);
        $this->assertSame(1, StoreImageUpload::orientation($path), 'والرأسُ مُحي — لا يُدار مرّتين');
        $this->assertStringStartsWith('website/'.$this->shop->id.'/', $file);
    }

    public function test_a_huge_image_is_shrunk_to_its_proportions_and_keeps_its_transparency(): void
    {
        $this->upload($this->file('wide.png', $this->painted(3000, 1500, 'png', alpha: true), 'image/png'))->assertSessionHasNoErrors();

        [, $bytes, $info] = $this->stored();
        $this->assertSame([StoreImageUpload::MAX_SIDE, 1280, IMAGETYPE_PNG], [$info[0], $info[1], $info[2]], 'بنسبتها — لا قصّ');
        $this->assertSame(127, $this->pixel($bytes, 2, 2)['a'], 'والشفافيّةُ باقية');
    }

    public function test_square_tall_and_wide_images_keep_their_shape(): void
    {
        foreach ([[800, 800, 800, 800], [1000, 3000, 853, 2560], [4000, 1000, 2560, 640]] as [$w, $h, $ew, $eh]) {
            Storage::fake('public');
            $this->upload($this->file('shape.jpg', $this->painted($w, $h), 'image/jpeg'))->assertSessionHasNoErrors()->assertSessionHas('uploaded_meta', ['w' => $ew, 'h' => $eh]);
            [, , $info] = $this->stored();
            $this->assertSame([$ew, $eh], [$info[0], $info[1]], "{$w}×{$h}");
        }
    }

    public function test_webp_is_accepted_and_gif_is_kept_as_it_came(): void
    {
        if (function_exists('imagewebp')) {
            $this->upload($this->file('a.webp', $this->painted(300, 200, 'webp'), 'image/webp'))->assertSessionHasNoErrors();
            [, , $info] = $this->stored();
            $this->assertSame(IMAGETYPE_WEBP, $info[2]);
            Storage::fake('public');
        }

        $gif = $this->painted(30, 20, 'gif');
        $this->upload($this->file('a.gif', $gif, 'image/gif'))->assertSessionHasNoErrors();
        [, $bytes] = $this->stored();
        $this->assertSame($gif, $bytes, 'GIF كما رُفع — قد يتحرّك');
    }

    public function test_what_only_looks_like_an_image_is_refused_and_nothing_is_stored(): void
    {
        $forged = [
            ['x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml'],
            ['page.jpg', '<html><script>alert(1)</script></html>', 'image/jpeg'],
            ['notes.png', 'just text', 'image/png'],
        ];

        foreach ($forged as [$name, $bytes, $mime]) {
            $this->upload($this->file($name, $bytes, $mime))->assertSessionHasErrors('image');
        }

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_heic_is_converted_or_its_owner_is_told_how_to_send_it(): void
    {
        $heic = "\0\0\0\x18ftypheic\0\0\0\0mif1heic".str_repeat("\0", 64);
        $res = $this->upload($this->file('IMG_0001.HEIC', $heic, 'image/heic'));

        if (! StoreImageUpload::readsHeif()) {
            $res->assertSessionHasErrors('image');
            $this->assertStringContainsString('HEIC', (string) session('errors')->first('image'));
            $this->assertSame([], Storage::disk('public')->allFiles());
        } else {
            // وخادمٌ يقرؤها يردّ ملفًّا تالفًا كهذا برسالتها — ولا يحفظه
            $res->assertSessionHasErrors('image');
        }
    }

    public function test_an_image_with_too_many_pixels_is_refused_before_it_is_opened(): void
    {
        $ihdr = pack('N', 9000).pack('N', 9000)."\x08\x02\0\0\0";
        $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));

        $this->upload($this->file('huge.png', $png, 'image/png'))->assertSessionHasErrors('image');
        $this->assertStringContainsString('ميغابكسل', (string) session('errors')->first('image'));
    }

    public function test_a_file_over_the_limit_is_refused_with_the_limit(): void
    {
        $this->upload(UploadedFile::fake()->create('big.jpg', (int) (StoreImageUpload::maxBytes() / 1024) + 10, 'image/jpeg'))
            ->assertSessionHasErrors('image');

        $this->assertStringContainsString('ميغابايت', (string) session('errors')->first('image'));
    }
}
