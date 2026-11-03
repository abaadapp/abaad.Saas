<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Product;
use App\Support\ImageGate;
use App\Support\ImageState;
use App\Support\ImageVariants;
use App\Support\ProductImages;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * نسخُ صورةٍ مرفوعة لا تضيع مهمّتُها — ولو امتلأت القائمة، أو تلف ملفُّها، أو انقطعت
 * كتابتُه، أو طال دورُ الفكّ، أو سقط صنعُ النسخ باستثناء.
 *
 * ═══ لمَ ═══
 *
 *  - قائمةُ الانتظار الملأى كانت تُسقط الصورةَ الجديدة بصمت، وقفلُها إن طال كذلك.
 *  - ملفٌّ تالفٌ كان يُقرأ «لا شيء»، فتكتب الكتابةُ التالية فوقه وتضيع حالاتُه كلُّها.
 *  - الكتابةُ كانت باسم الملفّ مباشرةً: انقطاعٌ يترك نصفَ ملفٍّ تالف.
 *  - دورُ فكٍّ طال خمس عشرة ثانية كان يفكّ على أيّ حال — صورتان ثقيلتان معًا.
 *  - استثناءٌ عند الرفع كان يُبلَّغ به وحده، فلا تُصنع نسخُ الصورة حتى تُفتح الصورُ القديمة.
 *
 * ═══ ما يُحرس ═══
 *
 *   - رفعٌ لا يُكتب انتظارُه يُكتب أنّه فات ومنذ متى، وتستدركه الجولةُ القصيرة من القرص
 *     والصورُ القديمةُ مطفأة — ولا تمسّ صورةً أقدمَ منه.
 *   - الجولةُ القصيرة تستيقظ لما فات وحده.
 *   - ملفٌّ تالفٌ يُحفظ كما هو، ويبقى كلُّ سطرٍ سليمٍ فيه بعد الكتابة التالية.
 *   - كتابةٌ انقطعت تترك الملفَّ القديمَ كاملًا ولا ملفًّا مؤقّتًا.
 *   - دورُ فكٍّ لا يأتي: لا يُفكّ شيء — النسخُ تُؤجَّل، والرفعُ يحفظ أصلَه كما رُفع.
 *   - استثناءٌ عند الرفع: الأصلُ محفوظ والمهمّةُ تنتظر، وتُعاد ثلاثًا ثمّ تُترك فاشلة.
 */
class AnUploadsCopiesAreNeverLostTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('public');
        Storage::fake('local');
        config(['images.pause_ms' => 0, 'images.backfill' => false]);

        $this->shop = Business::create(['name' => 'ورد مسقط', 'type' => 'عام', 'status' => 'نشط']);
    }

    private function jpeg(int $w = 2400, int $h = 1800): string
    {
        $im = imagecreatetruecolor($w, $h);
        mt_srand($w + $h);

        for ($y = 0; $y < $h; $y += 3) {
            for ($x = 0; $x < $w; $x += 3) {
                $n = mt_rand(-20, 20);
                imagefilledrectangle($im, $x, $y, $x + 2, $y + 2, imagecolorallocate($im,
                    max(0, min(255, (int) (200 * $x / $w) + 30 + $n)),
                    max(0, min(255, (int) (160 * $y / $h) + 50 + $n)),
                    max(0, min(255, (int) (120 + 80 * sin(($x + $y) / 70)) + $n))));
            }
        }

        ob_start();
        imagejpeg($im, null, 95);

        return (string) ob_get_clean();
    }

    private function product(?string $image = null): Product
    {
        return Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة', 'price' => 5, 'cost' => 1,
            'quantity' => 3, 'alert_qty' => 1, 'active' => true, 'image' => $image,
        ]);
    }

    /** صورةُ منتجٍ رُفعت قبل أيّام ولم تُصنع نسخُها — تنتظر جولةَ الصور القديمة لا غيرها */
    private function oldPicture(): string
    {
        $file = 'products/'.Str::random(40).'.jpg';
        Storage::disk('public')->put($file, $this->jpeg());
        touch(Storage::disk('public')->path($file), time() - 2 * 86400);
        $this->product($file);

        return $file;
    }

    /** يرفع صورةً كما ترفعها شاشةُ المنتج — تُحفظ ثمّ تُطلب نسخُها — ويُربط بها منتج */
    private function upload(): string
    {
        $paths = ProductImages::putAll([UploadedFile::fake()->createWithContent('rose.jpg', $this->jpeg())]);
        ProductImages::attach($this->product(), $paths);

        return $paths[0];
    }

    /** صُنعت كلُّ نسخةٍ تستحقّ مكانها؟ */
    private function assertDone(string $path): void
    {
        $need = ImageVariants::needs($path);

        $this->assertNotNull($need, $path.' لا تُقرأ');
        $this->assertFalse($need['display'] || $need['mid'] || $need['thumb'], $path.': لم تُصنع نسخُها');
    }

    private function bytes(string $path): string
    {
        return (string) Storage::disk('public')->get($path);
    }

    public function test_a_full_list_does_not_drop_an_upload_and_the_short_round_brings_it_back_leaving_old_pictures_alone(): void
    {
        config(['images.backlog_max' => 2]);
        $old = $this->oldPicture();

        // ‏المعالجةُ موقوفة، والقائمةُ ملأى بصورتين مُحي أصلاهما
        Cache::forever(ImageGate::HALT, now()->toIso8601String());
        $this->assertTrue(ImageState::wait('products/'.Str::random(40).'.jpg', 'halted'));
        $this->assertTrue(ImageState::wait('products/'.Str::random(40).'.jpg', 'halted'));

        $new = $this->upload();
        $kept = md5($this->bytes($new));

        $this->assertArrayNotHasKey($new, ImageState::waiting());
        $missed = ImageState::missed();
        $this->assertNotNull($missed, 'سقط الرفعُ من قائمةٍ ملأى بصمت');
        $this->assertSame(['backlog_full' => 1], $missed['reasons']);
        $this->assertLessThanOrEqual(filemtime(Storage::disk('public')->path($new)), $missed['since'], 'العلَمُ بعد الصورة التي فاتت');
        $this->assertGreaterThan(filemtime(Storage::disk('public')->path($old)), $missed['since'], 'العلَمُ يشمل صورةً قديمة');

        Cache::forget(ImageGate::HALT);

        // ‏الجولةُ الأولى تُفرغ القائمة — وما زالت ملأى حين تستدرك فيبقى العلَم
        $this->artisan('images:optimize', ['--backlog' => true])->assertSuccessful();
        $this->assertSame([], ImageState::waiting());
        $this->assertNotNull(ImageState::missed(), 'مُحي العلَمُ ولم يُستدرك شيء');

        // ‏والثانية تستدركه من القرص وتصنع نسخه — والصورُ القديمةُ مطفأة
        $this->assertFalse((bool) config('images.backfill'));
        $this->artisan('images:optimize', ['--backlog' => true])->expectsOutputToContain('واستُدرك 1')->assertSuccessful();

        $this->assertNull(ImageState::missed());
        $this->assertSame([], ImageState::waiting());
        $this->assertDone($new);
        $this->assertSame($kept, md5($this->bytes($new)), 'تغيّر الأصل');

        // ‏والصورةُ القديمة كما كانت: لا نسخَ لها ولم تدخل القائمة
        $this->assertTrue(ImageVariants::needs($old)['thumb'], 'عولجت صورةٌ قديمة والصورُ القديمةُ مطفأة');
        $this->assertSame([], array_values(array_filter(Storage::disk('public')->allFiles('products'), fn ($f) => str_starts_with($f, $old.'.'))));
    }

    public function test_the_short_round_wakes_for_a_missed_upload_alone(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'images:optimize') && str_contains((string) $e->command, '--backlog'));

        $this->assertNotNull($event, 'لا جولةَ قصيرةً مجدولة');
        $this->assertFalse($event->filtersPass($this->app), 'تعمل ولا شيء ينتظر');

        // ‏قائمةٌ من سطرٍ واحد: الثاني يفوت، ثمّ يخرج الأوّل — فلا ينتظر شيء وقد فات رفع
        config(['images.backlog_max' => 1]);
        $this->assertTrue(ImageState::wait('products/a.jpg', 'halted'));
        $this->assertFalse(ImageState::wait('products/b.jpg', 'halted'));
        $this->assertTrue(ImageState::release('products/a.jpg'));
        $this->assertSame([], ImageState::waiting());

        $this->assertTrue($event->filtersPass($this->app), 'لا تستيقظ لما فات');

        config(['images.backlog' => false]);
        $this->assertFalse($event->filtersPass($this->app), 'مفتاحُها لا يطفئها');
    }

    public function test_a_corrupt_list_is_kept_aside_and_its_sound_rows_survive_the_next_write(): void
    {
        $row = fn (string $reason) => ['since' => '2026-11-03T09:00:00+04:00', 'reason' => $reason, 'attempts' => 0];
        $whole = json_encode(['products/a.jpg' => $row('busy_cpu'), 'products/b.jpg' => $row('halted'), 'products/c.jpg' => $row('low_memory')], JSON_UNESCAPED_SLASHES);
        $torn = substr($whole, 0, -25);
        Storage::disk('local')->put('image-variants/backlog.json', $torn);

        $this->assertSame(['products/a.jpg', 'products/b.jpg'], array_keys(ImageState::waiting()), 'قُرئ الملفُّ التالفُ قائمةً فارغة');

        $this->assertTrue(ImageState::wait('products/d.jpg', 'halted'));

        $this->assertSame(['products/a.jpg', 'products/b.jpg', 'products/d.jpg'], array_keys(ImageState::waiting()), 'ضاعت السطورُ السليمة');
        $this->assertIsArray(json_decode((string) Storage::disk('local')->get('image-variants/backlog.json'), true));

        $aside = array_values(array_filter(Storage::disk('local')->files('image-variants'), fn ($f) => str_contains($f, 'backlog.json.corrupt-')));
        $this->assertCount(1, $aside, 'لم يُحفظ الملفُّ التالف');
        $this->assertSame($torn, Storage::disk('local')->get($aside[0]), 'حُفظ التالفُ مغيَّرًا');

        // ‏وما لم يُستنقذ (الثالثة) لا يُعرف — فيُكتب أنّ رفعًا فات منذ أقدم ما سلم
        $missed = ImageState::missed();
        $this->assertSame(['backlog_corrupt' => 1], $missed['reasons']);
        $this->assertSame(strtotime('2026-11-03T09:00:00+04:00'), $missed['since']);

        // ‏وكتابةٌ أخرى لا تعزله مرّةً ثانية
        $this->assertTrue(ImageState::release('products/a.jpg'));
        $this->assertCount(1, array_filter(Storage::disk('local')->files('image-variants'), fn ($f) => str_contains($f, '.corrupt-')));
    }

    public function test_a_corrupt_state_keeps_every_sound_row_its_skips_and_its_failed_attempts(): void
    {
        $torn = '{"products/a.jpg":{"status":"skipped","reason":"too_large","at":"2026-11-01T10:00:00+04:00"},'
            .'"products/b.jpg":{"status":"failed","reason":"write","attempts":3,"at":"2026-11-01T10:00:00+04:00"},'
            .'"products/c.jpg":{"status":"made","skips":["thumb"],"at":"2026-11-01T10:00:00+04:00"},"products/e.jpg":{"sta';
        Storage::disk('local')->put('image-variants/state.json', $torn);

        $this->assertSame(['products/a.jpg', 'products/b.jpg', 'products/c.jpg'], array_keys(ImageState::all()));

        $this->assertTrue(ImageState::put('products/d.jpg', ['status' => 'made', 'skips' => ['mid']]));

        $all = ImageState::all();
        $this->assertSame(['products/a.jpg', 'products/b.jpg', 'products/c.jpg', 'products/d.jpg'], array_keys($all), 'كُتبت حالٌ واحدةٌ فوق التالف');
        $this->assertSame(3, $all['products/b.jpg']['attempts'], 'عادت المحاولاتُ من الصفر');
        $this->assertSame(['thumb'], $all['products/c.jpg']['skips']);

        $aside = array_values(array_filter(Storage::disk('local')->files('image-variants'), fn ($f) => str_contains($f, 'state.json.corrupt-')));
        $this->assertCount(1, $aside);
        $this->assertSame($torn, Storage::disk('local')->get($aside[0]));
        $this->assertNull(ImageState::missed(), 'الحالُ ليست قائمةَ انتظار');
    }

    public function test_a_write_cut_off_midway_leaves_the_old_list_whole_and_the_upload_is_recovered(): void
    {
        $this->assertTrue(ImageState::wait('products/'.Str::random(40).'.jpg', 'halted'));
        $before = Storage::disk('local')->get('image-variants/backlog.json');

        // ‏القرصُ يموت في منتصف كتابة القائمة: نصفُ الملفّ المؤقّت ثمّ انقطاع
        $fake = Storage::disk('local');
        Storage::set('local', new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig()) extends FilesystemAdapter
        {
            public function put($path, $contents, $options = [])
            {
                if (str_starts_with(basename($path), '.backlog.json.')) {
                    file_put_contents($this->path($path), substr($contents, 0, intdiv(strlen($contents), 2)));

                    throw new \RuntimeException('disk died');
                }

                return parent::put($path, $contents, $options);
            }
        });

        Cache::forever(ImageGate::HALT, now()->toIso8601String());
        $new = $this->upload();

        $this->assertSame($before, Storage::disk('local')->get('image-variants/backlog.json'), 'تغيّرت القائمةُ أو بقي نصفُها');
        $this->assertSame([], array_values(array_filter(Storage::disk('local')->allFiles('image-variants'), fn ($f) => str_starts_with(basename($f), '.backlog.json.'))), 'بقي ملفٌّ مؤقّت');
        $this->assertSame(['backlog_unwritten' => 1], ImageState::missed()['reasons'], 'ضاع الرفعُ بانقطاع الكتابة');
        $this->assertTrue(Storage::disk('public')->exists($new), 'لم يُحفظ الأصل');

        // ‏ويعود القرص: تستدركه الجولةُ القصيرة
        Storage::set('local', $fake);
        Cache::forget(ImageGate::HALT);
        $this->artisan('images:optimize', ['--backlog' => true])->expectsOutputToContain('واستُدرك 1')->assertSuccessful();

        $this->assertDone($new);
        $this->assertNull(ImageState::missed());
        $this->assertSame([], ImageState::waiting());
    }

    public function test_a_decode_turn_that_never_comes_defers_the_copies_and_keeps_the_upload_without_decoding(): void
    {
        Sleep::fake(syncWithCarbon: true);
        $old = $this->oldPicture();

        // ‏صورةٌ أخرى تُفكّ ولا تنتهي — والدورُ لا يأتي في خمس عشرة ثانية
        $held = Cache::lock('images:decode', 3600);
        $this->assertTrue($held->get());

        $r = ImageVariants::make($old);
        $this->assertSame(['deferred', 'decode_busy'], [$r['status'], $r['reason']], 'فُكّت والدورُ بيد غيرها');
        $this->assertSame([$old], Storage::disk('public')->allFiles('products'), 'كُتبت نسخةٌ بلا دور');

        // ‏والرفعُ ينجح بأصله كما رُفع — لم يُفكّ ليُحوَّل — وتنتظر نسخُه
        $bytes = $this->jpeg();
        $paths = ProductImages::putAll([UploadedFile::fake()->createWithContent('rose.jpg', $bytes)]);
        $this->assertCount(1, $paths, 'رُدّ الرفعُ لأنّ الدور طال');
        $this->assertStringEndsNotWith('.webp', $paths[0]);
        $this->assertSame(md5($bytes), md5($this->bytes($paths[0])), 'فُكّ الأصلُ والدورُ بيد غيره');
        $this->assertSame('decode_busy', ImageState::waiting()[$paths[0]]['reason'] ?? null, 'ضاعت مهمّةُ الصورة');
        ProductImages::attach($this->product(), $paths);

        // ‏وجولةُ الصور القديمة تقف قبل الفكّ ولا تعدّه فشلًا
        $this->artisan('images:optimize', ['--apply' => true, '--limit' => 5])->expectsOutputToContain('decode_busy')->assertSuccessful();
        $this->assertSame([], ImageState::all(), 'عُدّ الدورُ الطويلُ فشلًا');

        $held->release();
        $this->artisan('images:optimize', ['--backlog' => true])->assertSuccessful();

        $this->assertSame([], ImageState::waiting());
        $this->assertDone($paths[0]);
        $this->assertSame(md5($bytes), md5($this->bytes($paths[0])), 'تغيّر الأصل');
    }

    public function test_an_exception_while_an_upload_makes_its_copies_keeps_the_picture_and_its_task_and_stops_after_three_tries(): void
    {
        // ‏القرصُ يرمي حين يسأل صانعُ النسخ عن أصلٍ موجود — وحفظُ الأصل نفسِه يمرّ
        $fake = Storage::disk('public');
        Storage::set('public', new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig()) extends FilesystemAdapter
        {
            public function path($path)
            {
                $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? [];

                if (($caller['class'] ?? null) === ImageVariants::class && ($caller['function'] ?? null) === 'make' && is_file(parent::path($path))) {
                    throw new \RuntimeException('القرصُ لا يجيب');
                }

                return parent::path($path);
            }
        });

        $path = $this->upload();
        $kept = md5($this->bytes($path));

        $this->assertSame('exception', ImageState::waiting()[$path]['reason'] ?? null, 'ضاعت مهمّةُ الصورة');

        // ‏تُعاد مع كلّ جولةٍ قصيرة وتُعدّ محاولاتُها — ثمّ تُترك فاشلةً لا تدور بلا نهاية
        foreach ([1, 2] as $try) {
            $this->artisan('images:optimize', ['--backlog' => true])->assertFailed();
            $this->assertSame($try, ImageState::waiting()[$path]['attempts'] ?? null);
        }

        $this->artisan('images:optimize', ['--backlog' => true])->assertFailed();
        $this->assertArrayNotHasKey($path, ImageState::waiting(), 'تدور بلا نهاية');
        $this->assertSame(['failed', 3], [ImageState::all()[$path]['status'] ?? null, ImageState::all()[$path]['attempts'] ?? null]);

        $this->artisan('images:optimize', ['--backlog' => true])->assertSuccessful();

        // ‏ولا يعيدها الاستدراكُ — فشلت مرّاتها كلَّها
        config(['images.backlog_max' => 1]);
        $this->assertTrue(ImageState::wait('products/'.Str::random(40).'.jpg', 'halted'));
        $this->assertFalse(ImageState::wait($path, 'halted'));
        $this->artisan('images:optimize', ['--backlog' => true])->assertSuccessful();

        $this->assertNull(ImageState::missed());
        $this->assertArrayNotHasKey($path, ImageState::waiting(), 'أعادها الاستدراكُ بعد مرّاتها كلّها');
        $this->assertSame($kept, md5($this->bytes($path)), 'تغيّر الأصل');
        $this->assertSame($path, DB::table('products')->whereNotNull('image')->orderByDesc('id')->value('image'));

        // ‏وحين يعود القرص تعيدها الجولةُ العامّة إذا طُلب
        Storage::set('public', $fake);
        config(['images.backlog_max' => 10000]);
        $this->artisan('images:optimize', ['--apply' => true, '--retry-failed' => true])->expectsOutputToContain('made=1')->assertSuccessful();
        $this->assertDone($path);
    }
}
