<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Product;
use App\Support\ImageState;
use App\Support\ImageVariants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * النسخةُ الوسطى (١٢٨٠) — تُصنع من الأصل حيث تُغني عن نسخة العرض، ولا تُصنع حيث لا تفيد.
 *
 * ═══ لمَ وُضعت ═══
 *
 * بطاقةُ القسم الطوليّة على هاتف 3x تحتاج ٤٨٣ بكسلًا ومصغّرتُها ٣٦٠ — فكانت تأخذ
 * نسخةَ العرض (١٤٤٠×٢٥٦٠): ربعُ ميغابايت لمربّعٍ بعرض ١٦١ نقطة. والوسطى ٧٢٠ عرضًا
 * بنحو ثمانين كيلوبايت ووضوحٍ لا يُفرَّق عن نسخة العرض.
 *
 * ═══ وما يُحرَس ═══
 *
 *   - تُصنع من الأصل بأبعادها الحقيقيّة: طوليّةٌ ومربّعةٌ وعريضة، JPEG وPNG بشفافيّته وWebP.
 *   - لا تكبير: صورةٌ لا يزيد ما يُعرض منها على ١٢٨٠ لا وسطى لها.
 *   - لا نسخةَ لا تنقص عمّا يُعرض بدلها عُشرًا — والحكمُ نفسُه في كلّ جولة، ويُحفظ فلا يُفكّ الأصلُ له ثانيةً.
 *   - النسخُ السليمةُ لا تُعاد، والناقصةُ وحدها تُصنع.
 *   - `srcset` بما على القرص وحده، والمفقودةُ يُرجع منها إلى أقرب ما فوقها.
 *   - أصلٌ مُحي وهي تُصنع: لا تبقى نسخٌ بعده.
 */
class AMiddleCopyIsMadeOnlyWhereItHelpsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        config(['images.pause_ms' => 0]);
    }

    /**
     * صورةٌ بتفاصيلَ دقيقةٍ ونصٍّ صغير — أو ناعمةٌ كصورة هاتف (`smooth`: تدرّجٌ ودوائرُ ونصّ)،
     * فضغطُها يتبع عددَ بكسلاتها لا ضجيجَها.
     */
    private function picture(string $folder, int $w, int $h, string $type = 'jpeg', bool $alpha = false, bool $smooth = false): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 250, 248, 240, $alpha ? 127 : 0));
        mt_srand($w * 13 + $h);

        if ($smooth) {
            for ($y = 0; $y < $h; $y += 2) {
                imagefilledrectangle($im, 0, $y, $w, $y + 1, imagecolorallocate($im, (int) (90 + 120 * $y / $h), 120, (int) (200 - 100 * $y / $h)));
            }

            for ($i = 0; $i < 40; $i++) {
                imagefilledellipse($im, mt_rand(0, $w), mt_rand(0, $h), $r = mt_rand(20, (int) ($w / 6)), $r, imagecolorallocate($im, mt_rand(40, 230), mt_rand(40, 230), mt_rand(40, 230)));
            }
        } else {
            for ($y = $alpha ? (int) ($h / 4) : 0; $y < ($alpha ? $h * 3 / 4 : $h); $y += 3) {
                for ($x = $alpha ? (int) ($w / 4) : 0; $x < ($alpha ? $w * 3 / 4 : $w); $x += 3) {
                    $n = mt_rand(-40, 40);
                    imagefilledrectangle($im, $x, $y, $x + 1, $y + 1, imagecolorallocatealpha($im,
                        max(0, min(255, (int) (200 * $x / $w) + 30 + $n)), max(0, min(255, (int) (160 * $y / $h) + 50 + $n)), max(0, min(255, 150 + $n)), 0));
                }
            }
        }

        imagealphablending($im, true);
        imagestring($im, 3, (int) ($w / 3), (int) ($h / 2), 'Ribbon Flower Lounge', imagecolorallocate($im, 240, 240, 240));

        $path = $folder.'/'.Str::random(40).'.'.['jpeg' => 'jpg', 'png' => 'png', 'webp' => 'webp'][$type];
        ob_start();
        match ($type) {
            'png' => imagepng($im),
            'webp' => imagewebp($im, null, 80),
            default => imagejpeg($im, null, 95),
        };
        Storage::disk('public')->put($path, (string) ob_get_clean());

        return $path;
    }

    /** @return array{0: int, 1: int}|null */
    private function dims(string $path): ?array
    {
        $file = Storage::disk('public')->path($path);
        $info = is_file($file) ? getimagesize($file) : false;

        return is_array($info) ? [$info[0], $info[1]] : null;
    }

    /** الوسطى مقابل الأصل مصغّرًا إلى أبعادها — من الأصل صُنعت لا من نسخةٍ أصغر */
    private function psnr(string $copy, string $original): float
    {
        $a = imagecreatefromwebp(Storage::disk('public')->path($copy));
        $o = imagecreatefromstring((string) Storage::disk('public')->get($original));
        $b = imagescale($o, imagesx($a), imagesy($a), IMG_BICUBIC);
        $se = 0;
        $n = 0;

        for ($y = 0; $y < imagesy($a); $y += 2) {
            for ($x = 0; $x < imagesx($a); $x += 2) {
                [$p, $q] = [imagecolorat($a, $x, $y), imagecolorat($b, $x, $y)];

                foreach ([16, 8, 0] as $s) {
                    $d = (($p >> $s) & 255) - (($q >> $s) & 255);
                    $se += $d * $d;
                    $n++;
                }
            }
        }

        return $se === 0 ? 99.0 : 10 * log10(255 * 255 / ($se / $n));
    }

    /** المصغّرةُ مكبّرةً إلى أبعاد الوسطى مقابل الأصل — ما كانت ستبدو لو صُنعت الوسطى منها */
    private function psnrOfThumbAt(string $path, int $side): float
    {
        $mid = imagecreatefromwebp(Storage::disk('public')->path(ImageVariants::path($path, ImageVariants::MID)));
        $thumb = imagecreatefromwebp(Storage::disk('public')->path(ImageVariants::path($path, ImageVariants::THUMB)));
        $up = imagescale($thumb, imagesx($mid), imagesy($mid), IMG_BICUBIC);
        $tmp = 'products/'.Str::random(20).'.tmp.webp';
        ob_start();
        imagewebp($up, null, 100);
        Storage::disk('public')->put($tmp, (string) ob_get_clean());

        try {
            return $this->psnr($tmp, $path);
        } finally {
            Storage::disk('public')->delete($tmp);
        }
    }

    public function test_every_shape_gets_its_three_copies_at_their_true_sizes_from_the_original(): void
    {
        $cases = [
            ['products', 2400, 1800, [1600, 1200], [1280, 960], [640, 480]],
            ['products', 1800, 2400, [1200, 1600], [960, 1280], [480, 640]],
            ['website', 900, 1600, [900, 1600], [720, 1280], [360, 640]],
            ['website', 2560, 1440, [2560, 1440], [1280, 720], [640, 360]],
            ['website', 1800, 1800, [1800, 1800], [1280, 1280], [640, 640]],
        ];

        foreach ($cases as [$folder, $w, $h, $display, $mid, $thumb]) {
            $path = $this->picture($folder, $w, $h, smooth: true);
            $before = md5((string) Storage::disk('public')->get($path));

            $r = ImageVariants::make($path);

            $this->assertSame('made', $r['status'], "{$w}×{$h}");
            $this->assertSame([], $r['skips'], "{$w}×{$h}: تُركت نسخةٌ تستحقّ مكانها");
            $this->assertSame($display, $this->dims((string) ImageVariants::display($path)), "{$w}×{$h}: نسخةُ العرض");
            $this->assertSame($mid, $this->dims(ImageVariants::path($path, ImageVariants::MID)), "{$w}×{$h}: الوسطى");
            $this->assertSame($thumb, $this->dims(ImageVariants::path($path, ImageVariants::THUMB)), "{$w}×{$h}: المصغّرة");
            // ‏من الأصل: أقربُ إليه بكثيرٍ من مصغّرةٍ مكبّرة إلى أبعادها
            $mid = $this->psnr(ImageVariants::path($path, ImageVariants::MID), $path);
            $this->assertGreaterThan(30.0, $mid, "{$w}×{$h}: الوسطى ليست الأصلَ مصغّرًا");
            $this->assertGreaterThan($this->psnrOfThumbAt($path, $mid > 0 ? ImageVariants::MID : 0) + 3, $mid, "{$w}×{$h}: الوسطى بوضوح مصغّرةٍ مكبّرة");
            $this->assertSame($before, md5((string) Storage::disk('public')->get($path)), 'تغيّر الأصل');
            $this->assertLessThan($r['display'], $r['mid'], "{$w}×{$h}: الوسطى ليست أخفَّ من نسخة العرض");
        }
    }

    /** ولا تكبير: ما يُعرض منه ١٢٨٠ أو أقلّ لا وسطى له — ولا مصغّرةَ لما هو أصغرُ منها */
    public function test_a_picture_no_wider_than_the_middle_copy_gets_none(): void
    {
        $small = $this->picture('products', 1200, 900);
        $tiny = $this->picture('products', 500, 400);

        ImageVariants::make($small);
        ImageVariants::make($tiny);

        Storage::disk('public')->assertMissing(ImageVariants::path($small, ImageVariants::MID));
        $this->assertSame([640, 480], $this->dims(ImageVariants::path($small, ImageVariants::THUMB)));
        Storage::disk('public')->assertMissing(ImageVariants::path($tiny, ImageVariants::MID));
        Storage::disk('public')->assertMissing(ImageVariants::path($tiny, ImageVariants::THUMB));
        $this->assertSame([500, 400], $this->dims((string) ImageVariants::display($tiny)), 'كُبّرت صورةٌ صغيرة');
        $this->assertFalse(ImageVariants::needs($small)['mid']);
    }

    /** وصورةُ منتجٍ رُفعت WebP بعرض ١٦٠٠ (كما يحفظها الرفعُ اليوم): لا نسخةَ عرض، ووسطى من الأصل */
    public function test_an_uploaded_webp_gets_its_middle_copy_measured_against_the_original(): void
    {
        $path = $this->picture('products', 1600, 1600, 'webp');

        $r = ImageVariants::make($path);

        $this->assertSame('made', $r['status']);
        $this->assertSame(0, $r['display'], 'صُنعت نسخةُ عرضٍ لصورةٍ هي بحدّها');
        Storage::disk('public')->assertMissing((string) ImageVariants::display($path));
        $this->assertSame([1280, 1280], $this->dims(ImageVariants::path($path, ImageVariants::MID)));
        $this->assertLessThan($r['before'] * 0.9, $r['mid']);
    }

    public function test_a_transparent_picture_keeps_its_transparency_in_the_middle_copy(): void
    {
        $path = $this->picture('website', 2000, 2000, 'png', alpha: true);

        ImageVariants::make($path);

        foreach ([ImageVariants::MID, ImageVariants::THUMB, ImageVariants::SIDES['website']] as $side) {
            $im = imagecreatefromwebp(Storage::disk('public')->path(ImageVariants::path($path, $side)));
            $this->assertSame(127, (imagecolorat($im, 2, 2) >> 24) & 127, "w$side: الحافّةُ الشفّافة صارت مصمتة");
        }
    }

    /**
     * رسمٌ مسطّحٌ بكتابةٍ كبيرة محفوظٌ JPEG بأعلى جودة — كشعارٍ رُفع صورةَ صنف: أصلُه
     * عشرون كيلوبايت، ونسخةُ عرضه أقلُّ من اثنين، ومصغّرتُه أثقلُ من نسخة العرض.
     */
    private function flatGraphic(): string
    {
        $im = imagecreatetruecolor(1006, 357);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        $text = imagecreatetruecolor(170, 16);
        imagefill($text, 0, 0, imagecolorallocate($text, 255, 255, 255));
        imagestring($text, 5, 2, 0, 'RIBBON FLOWERS', imagecolorallocate($text, 20, 60, 40));
        imagecopyresampled($im, $text, 40, 80, 0, 0, 680, 64, 170, 16);

        $path = 'products/'.Str::random(40).'.jpg';
        ob_start();
        imagejpeg($im, null, 100);
        Storage::disk('public')->put($path, (string) ob_get_clean());

        return $path;
    }

    /**
     * والحكمُ واحدٌ في كلّ جولة: نسخةٌ لا تنقص عمّا يُعرض بدلها عُشرًا تُترك — الآن وغدًا.
     *
     * كانت المصغّرةُ تُقاس بنسخة العرض إن صُنعت في الجولة نفسها، وبالأصل إن صُنعت
     * قبلها: فتُترك اليوم (أثقلُ من نسخة العرض) وتُصنع غدًا (أخفُّ من الأصل)، والعدّادُ
     * يقول «تنتظر» أبدًا — كما في صورةٍ مسطّحةٍ من RIBBON.
     */
    public function test_a_copy_not_worth_its_place_is_left_the_same_way_every_time_and_remembered(): void
    {
        $path = $this->flatGraphic();
        Product::create(['business_id' => Business::create(['name' => 'ورد', 'type' => 'عام', 'status' => 'نشط'])->id,
            'name' => 'شعار', 'price' => 1, 'cost' => 1, 'quantity' => 1, 'alert_qty' => 1, 'active' => true, 'image' => $path]);

        $first = ImageVariants::make($path);
        $this->assertSame('made', $first['status']);
        $this->assertSame(['thumb'], $first['skips'], 'مصغّرةٌ أخفُّ من نسخة العرض بعُشرٍ في هذا الرسم؟ — غيّر الرسم');
        Storage::disk('public')->assertMissing(ImageVariants::path($path, ImageVariants::THUMB));

        // ‏وجولةٌ ثانيةٌ تقيسها بنسخة العرض التي على القرص — لا بالأصل — فتتركها كذلك
        $again = ImageVariants::make($path);
        $this->assertSame(['thumb'], $again['skips']);
        Storage::disk('public')->assertMissing(ImageVariants::path($path, ImageVariants::THUMB));

        // ‏والجولةُ تحفظ ما تُرك — فلا تعود إليها، والعدّادُ لا يقول «تنتظر»
        $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('بقي 0 صورةً تنتظر')->assertSuccessful();
        $this->assertSame(['thumb'], ImageState::all()[$path]['skips']);
        $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('عولجت 0 صورة')->assertSuccessful();
    }

    /** وجولةٌ صنعت بعضَ نسخ صورةٍ وتركت غيرَها تحفظ ما تركت — فلا تفكّها التاليةُ لتتركه */
    public function test_a_batch_that_makes_some_copies_and_leaves_one_remembers_it(): void
    {
        $path = $this->flatGraphic();
        Product::create(['business_id' => Business::create(['name' => 'ورد', 'type' => 'عام', 'status' => 'نشط'])->id,
            'name' => 'شعار', 'price' => 1, 'cost' => 1, 'quantity' => 1, 'alert_qty' => 1, 'active' => true, 'image' => $path]);

        $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('made=1')->assertSuccessful();

        Storage::disk('public')->assertExists((string) ImageVariants::display($path));
        $this->assertSame(['made', ['thumb']], [ImageState::all()[$path]['status'], ImageState::all()[$path]['skips']]);
        $this->artisan('images:optimize', ['--apply' => true])->expectsOutputToContain('عولجت 0 صورة')->assertSuccessful();
    }

    /** وصورةٌ أعرضُ من ١٢٨٠ بقليل لا وسطى لها — تكاد تكون نسختَها */
    public function test_a_picture_just_above_the_middle_copy_gets_none(): void
    {
        $near = $this->picture('website', 1500, 1125, smooth: true);
        $far = $this->picture('website', 1600, 1200, smooth: true);

        ImageVariants::make($near);
        ImageVariants::make($far);

        Storage::disk('public')->assertMissing(ImageVariants::path($near, ImageVariants::MID));
        $this->assertSame([1280, 960], $this->dims(ImageVariants::path($far, ImageVariants::MID)));
    }

    /** وصورةٌ قديمةٌ بنسختين سليمتين: تُصنع الوسطى وحدها، ولا تُمسّ الأخريان */
    public function test_an_old_picture_gets_only_its_missing_middle_copy(): void
    {
        $path = $this->picture('products', 2400, 1800);
        ImageVariants::make($path);
        Storage::disk('public')->delete(ImageVariants::path($path, ImageVariants::MID));
        $kept = [
            md5((string) Storage::disk('public')->get((string) ImageVariants::display($path))),
            md5((string) Storage::disk('public')->get(ImageVariants::path($path, ImageVariants::THUMB))),
        ];

        $this->assertSame([false, true, false], [ImageVariants::needs($path)['display'], ImageVariants::needs($path)['mid'], ImageVariants::needs($path)['thumb']]);
        $r = ImageVariants::make($path);

        $this->assertSame([0, 0], [$r['display'], $r['thumb']], 'أُعيدت نسخةٌ سليمة');
        $this->assertGreaterThan(0, $r['mid']);
        $this->assertSame($kept, [
            md5((string) Storage::disk('public')->get((string) ImageVariants::display($path))),
            md5((string) Storage::disk('public')->get(ImageVariants::path($path, ImageVariants::THUMB))),
        ]);
    }

    /** والمتصفّحُ يُعطى ما على القرص وحده — والمفقودةُ يُرجع منها إلى أقرب ما فوقها */
    public function test_the_page_offers_only_the_copies_on_disk_and_falls_back_upwards(): void
    {
        $path = $this->picture('products', 2400, 1800);
        ImageVariants::make($path);
        $url = fn ($side) => Storage::url(ImageVariants::path($path, $side));

        $this->assertSame($url(640).' 640w, '.$url(1280).' 1280w, '.$url(1600).' 1600w', ImageVariants::srcset($path));

        Storage::disk('public')->delete(ImageVariants::path($path, ImageVariants::MID));
        $this->assertSame($url(640).' 640w, '.$url(1600).' 1600w', ImageVariants::srcset($path));

        ImageVariants::make($path);
        Storage::disk('public')->delete(ImageVariants::path($path, ImageVariants::THUMB));
        $this->assertSame($url(1280), ImageVariants::url($path, ImageVariants::THUMB), 'بلا مصغّرة: الوسطى قبل نسخة العرض');
        $this->assertSame($url(1280).' 1280w, '.$url(1600).' 1600w', ImageVariants::srcset($path));

        Storage::disk('public')->delete([ImageVariants::path($path, ImageVariants::MID), (string) ImageVariants::display($path)]);
        $this->assertSame(Storage::url($path), ImageVariants::url($path, ImageVariants::THUMB), 'بلا نسخ: الأصلُ كما كان');
        $this->assertNull(ImageVariants::srcset($path));
    }

    /** قرصٌ يُنادي `$hook` بعد كلّ كتابةٍ لملفٍّ مؤقّت — كتابةُ نسخةٍ قبل أن تُنشر باسمها */
    private function afterStagedWrite(callable $hook): void
    {
        $fake = Storage::disk('public');

        Storage::set('public', new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig(), $hook) extends \Illuminate\Filesystem\FilesystemAdapter
        {
            /** @var callable */
            private $hook;

            public function __construct($driver, $adapter, array $config, callable $hook)
            {
                parent::__construct($driver, $adapter, $config);
                $this->hook = $hook;
            }

            public function put($path, $contents, $options = [])
            {
                $done = parent::put($path, $contents, $options);

                if (str_starts_with(basename($path), '.')) {
                    ($this->hook)($this, $path);
                }

                return $done;
            }
        });
    }

    /** وصاحبُ الصورة حذفها وهي تُصنع — حذفُه سبق نشرَها فلم يجد ما يمحوه: تُمحى هنا */
    public function test_a_picture_deleted_while_its_copies_are_made_leaves_none_behind(): void
    {
        $path = $this->picture('products', 2400, 1800);
        $deleted = false;
        $this->afterStagedWrite(function ($disk) use (&$deleted, $path) {
            if (! $deleted) {
                $deleted = true;
                @unlink($disk->path($path));
            }
        });

        $r = ImageVariants::make($path);

        $this->assertTrue($deleted);
        $this->assertSame(['missing', 'deleted_while_made'], [$r['status'], $r['reason']]);
        $this->assertSame([], Storage::disk('public')->allFiles('products'), 'بقيت نسخةٌ بعد أصلها');
    }

    /** وحذفٌ يقع على الكتابة نفسها (يمحو النسخَ والمؤقّتَ معًا): تفشل الجولةُ ولا يبقى شيء */
    public function test_a_delete_that_hits_the_write_itself_leaves_nothing_either(): void
    {
        $path = $this->picture('products', 2400, 1800);
        $deleted = false;
        $this->afterStagedWrite(function ($disk) use (&$deleted, $path) {
            if (! $deleted) {
                $deleted = true;
                @unlink($disk->path($path));
                ImageVariants::forget($path);
            }
        });

        $r = ImageVariants::make($path);

        $this->assertTrue($deleted);
        $this->assertContains($r['status'], ['missing', 'failed']);
        $this->assertSame([], Storage::disk('public')->allFiles('products'), 'بقيت نسخةٌ أو ملفٌّ مؤقّت بعد أصلها');
    }

    /** وما يمحو صورةً يمحو نسخها الثلاث */
    public function test_forgetting_a_picture_removes_its_middle_copy_too(): void
    {
        $path = $this->picture('products', 2400, 1800);
        ImageVariants::make($path);

        ImageVariants::forget($path);

        $this->assertSame([$path], Storage::disk('public')->allFiles('products'));
    }
}
