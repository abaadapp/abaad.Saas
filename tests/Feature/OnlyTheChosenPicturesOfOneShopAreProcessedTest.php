<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Support\ImageVariants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * صورٌ بعينها من متجرٍ واحد — بمساراتها وبترتيبها، ولا شيءَ سواها.
 *
 * قِيس على الإنتاج: رئيسيّةُ RIBBON تنقل نحو 24 ميغابايت من الصور في أوّل
 * فتحٍ على الهاتف، وأثقلُها صورةُ الواجهة وبطاقتا صنفين. و`--limit` يأخذ
 * المرشّحين بترتيب الجداول — أقدمَ الأصناف أوّلًا — فلا يبلغ صورَ الرئيسيّة.
 * فتُسمّى الصورُ بمساراتها (`--path`)، ولا يُقبل منها إلّا ما هو من صور ذلك
 * المتجر الحيّة نفسها: ما سواه يردّ الدفعةَ كلَّها قبل أن تُفكّ صورة.
 */
class OnlyTheChosenPicturesOfOneShopAreProcessedTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('public');
        Storage::fake('local');
        config(['images.pause_ms' => 0]);

        $this->shop = Business::create(['name' => 'ورد مسقط', 'type' => 'عام', 'status' => 'نشط']);
        $this->other = Business::create(['name' => 'متجرٌ آخر', 'type' => 'عام', 'status' => 'نشط']);
    }

    private function photo(string $folder): string
    {
        $path = $folder.'/'.Str::random(40).'.jpg';
        $im = imagecreatetruecolor(1600, 1200);
        mt_srand(crc32($path));

        for ($y = 0; $y < 1200; $y += 4) {
            for ($x = 0; $x < 1600; $x += 4) {
                imagefilledrectangle($im, $x, $y, $x + 3, $y + 3, imagecolorallocate($im, mt_rand(60, 220), mt_rand(60, 220), (int) (255 * $x / 1600)));
            }
        }

        ob_start();
        imagejpeg($im, null, 95);
        Storage::disk('public')->put($path, (string) ob_get_clean());

        return $path;
    }

    private function product(Business $shop): string
    {
        $path = $this->photo('products');
        Product::create(['business_id' => $shop->id, 'name' => 'باقة', 'price' => 5, 'cost' => 1, 'quantity' => 3, 'alert_qty' => 1, 'active' => true, 'image' => $path]);

        return $path;
    }

    /** @return list<string> */
    private function copies(): array
    {
        return array_values(array_filter(Storage::disk('public')->allFiles(), fn ($f) => ImageVariants::isVariant($f)));
    }

    /** @return array<string, string> كلُّ أصلٍ وبصمتُه، وكلُّ مرجعٍ في القاعدة */
    private function snapshot(): array
    {
        $out = [];

        foreach (Storage::disk('public')->allFiles() as $f) {
            if (! ImageVariants::isVariant($f)) {
                $out[$f] = md5((string) Storage::disk('public')->get($f));
            }
        }

        $out['#products'] = md5(json_encode(DB::table('products')->orderBy('id')->pluck('image')));
        $out['#categories'] = md5(json_encode(DB::table('categories')->orderBy('id')->pluck('card_image')));
        $out['#settings'] = md5(json_encode(DB::table('settings')->orderBy('id')->pluck('value')));

        return $out;
    }

    public function test_only_the_named_pictures_are_processed_in_the_order_given(): void
    {
        $old = $this->product($this->shop);
        $card = $this->product($this->shop);
        $hero = $this->photo('website/'.$this->shop->id);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'store_hero_image', 'value' => '/storage/'.$hero]);
        $cat = $this->photo('website/'.$this->shop->id);
        Category::create(['business_id' => $this->shop->id, 'name' => 'ورد', 'card_image' => '/storage/'.$cat]);
        $stranger = $this->product($this->other);
        $before = $this->snapshot();

        // ‏الواجهةُ ثمّ بطاقةُ الصنف — لا أقدمُ الأصناف كما يأخذها --limit
        $this->artisan('images:optimize', ['--apply' => true, '--business' => $this->shop->id, '--path' => [$hero, $card], '--limit' => 10])
            ->expectsOutputToContain('عولجت 2 صورة: made=2')->assertSuccessful();

        $made = $this->copies();
        sort($made);
        $want = [ImageVariants::display($hero), ImageVariants::path($hero, ImageVariants::THUMB), ImageVariants::display($card), ImageVariants::path($card, ImageVariants::THUMB)];
        sort($want);
        $this->assertSame($want, $made, 'صُنعت نسخةٌ لصورةٍ لم تُسمَّ');

        foreach ([$old, $cat, $stranger] as $untouched) {
            Storage::disk('public')->assertMissing((string) ImageVariants::display($untouched));
        }

        $this->assertSame($before, $this->snapshot(), 'تغيّر أصلٌ أو مرجع');
    }

    public function test_a_picture_of_another_shop_refuses_the_whole_batch(): void
    {
        $mine = $this->product($this->shop);
        $theirs = $this->product($this->other);

        $this->artisan('images:optimize', ['--apply' => true, '--business' => $this->shop->id, '--path' => [$mine, $theirs]])
            ->expectsOutputToContain('ليست من صور المتجر '.$this->shop->id.' الحيّة: '.$theirs)->assertFailed();

        $this->assertSame([], $this->copies(), 'فُكّت صورةٌ والدفعةُ مردودة');
    }

    public function test_an_unreferenced_missing_traversing_or_copy_path_refuses_the_batch(): void
    {
        $mine = $this->product($this->shop);
        $orphan = $this->photo('website/'.$this->shop->id);
        ImageVariants::make($mine);
        $copy = (string) ImageVariants::display($mine);
        Storage::disk('public')->delete($this->copies());

        foreach ([$orphan, 'products/'.Str::random(40).'.jpg', 'website/'.$this->shop->id.'/../'.$this->other->id.'/x.jpg', $copy, 'logos/'.Str::random(40).'.png'] as $bad) {
            $this->artisan('images:optimize', ['--apply' => true, '--business' => $this->shop->id, '--path' => [$mine, $bad]])
                ->expectsOutputToContain('لم تُعالج صورة')->assertFailed();
            $this->assertSame([], $this->copies(), $bad);
        }
    }

    public function test_named_pictures_need_apply_and_one_shop_and_never_move_references(): void
    {
        $mine = $this->product($this->shop);

        foreach ([
            ['--apply' => true, '--path' => [$mine]],
            ['--business' => $this->shop->id, '--path' => [$mine]],
            ['--apply' => true, '--business' => $this->shop->id, '--swap' => true, '--path' => [$mine]],
            ['--business' => $this->shop->id, '--retire-plan' => true, '--path' => [$mine]],
        ] as $options) {
            $this->artisan('images:optimize', $options)->expectsOutputToContain('--path يُستعمل مع --apply و--business وحدهما')->assertFailed();
        }

        $this->assertSame([], $this->copies());
        $this->assertSame([], Storage::disk('local')->allFiles('image-swaps'), 'نُقل مرجع');
    }

    /*
     * ‏`--path` فارغٌ كان يُسقَط بصمت — فتصير الدفعةُ عامّةً بحدّها (خمسون
     * صورةً من صور المتجر). فارغًا أو مسافاتٍ أو بلا قيمة، وحدَه أو مع صورةٍ
     * صالحة: يُردّ الأمرُ كلُّه. والسطرُ كما يكتبه المشغّل في الطرفيّة.
     */
    public function test_an_empty_or_blank_path_refuses_everything_rather_than_becoming_a_general_batch(): void
    {
        $mine = $this->product($this->shop);
        $this->product($this->shop);
        $run = 'images:optimize --apply --business='.$this->shop->id.' ';

        foreach (['--path=', '--path="   "', '--path', '--path='.$mine.' --path=', '--path='.$mine.' --path=" "'] as $given) {
            $this->artisan($run.$given)->expectsOutputToContain('--path فارغ')->assertFailed();
            $this->assertSame([], $this->copies(), $given);
        }

        // ‏ومسارٌ حوله مسافةٌ غيرُ مسارِ المتجر: يُردّ ولا يُصحَّح
        $this->artisan($run.'--path=" '.$mine.'"')->expectsOutputToContain('ليست من صور المتجر')->assertFailed();
        $this->assertSame([], $this->copies());
    }

    public function test_a_picture_named_twice_is_processed_once_in_its_first_place(): void
    {
        $card = $this->product($this->shop);
        $hero = $this->photo('website/'.$this->shop->id);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'store_hero_image', 'value' => '/storage/'.$hero]);

        $this->artisan('images:optimize', ['--apply' => true, '--business' => $this->shop->id, '--path' => [$hero, $card, $hero]])
            ->expectsOutputToContain('عولجت 2 صورة: made=2')->assertSuccessful();

        $this->assertCount(4, $this->copies());
        Storage::disk('public')->assertExists((string) ImageVariants::display($card));
    }

    public function test_a_named_picture_missing_or_unreadable_on_disk_refuses_the_batch(): void
    {
        $mine = $this->product($this->shop);
        $gone = $this->product($this->shop);
        Storage::disk('public')->delete($gone);
        $broken = $this->product($this->shop);
        Storage::disk('public')->put($broken, 'ليست صورة');

        foreach ([$gone, $broken] as $bad) {
            $this->artisan('images:optimize', ['--apply' => true, '--business' => $this->shop->id, '--path' => [$mine, $bad]])
                ->expectsOutputToContain('لا تُقرأ صورةً على القرص: '.$bad)->assertFailed();
            $this->assertSame([], $this->copies(), $bad);
        }
    }

    /*
     * ‏ونسخةُ صورةٍ يعرضها متجرٌ آخر تُخدم هناك أيضًا — فتجربةُ متجرٍ لا
     * تُسمّيها، بصفّ منتجٍ كانت أو بإعداد واجهةٍ أو بطاقةِ صنف.
     */
    public function test_a_picture_another_shop_also_shows_refuses_the_batch(): void
    {
        $mine = $this->product($this->shop);
        $product = $this->product($this->shop);
        Product::create(['business_id' => $this->other->id, 'name' => 'باقة', 'price' => 5, 'cost' => 1, 'quantity' => 3, 'alert_qty' => 1, 'active' => true, 'image' => $product]);
        $hero = $this->photo('website/'.$this->shop->id);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'store_hero_image', 'value' => '/storage/'.$hero]);
        Category::create(['business_id' => $this->other->id, 'name' => 'ورد', 'card_image' => '/storage/'.$hero]);
        $before = $this->snapshot();

        foreach ([$product, $hero] as $shared) {
            $this->artisan('images:optimize', ['--apply' => true, '--business' => $this->shop->id, '--path' => [$mine, $shared]])
                ->expectsOutputToContain('يعرضها متجرٌ آخر أيضًا: '.$shared)->assertFailed();
            $this->assertSame([], $this->copies(), $shared);
        }

        // ‏وصورةُ المتجر وحده تُعالج كما هي
        $this->artisan('images:optimize', ['--apply' => true, '--business' => $this->shop->id, '--path' => [$mine]])
            ->expectsOutputToContain('made=1')->assertSuccessful();
        $this->assertSame($before, $this->snapshot(), 'تغيّر أصلٌ أو مرجع');
    }

    public function test_the_limit_and_every_server_check_still_hold_before_each_named_picture(): void
    {
        $first = $this->product($this->shop);
        $second = $this->product($this->shop);
        $third = $this->product($this->shop);

        $this->artisan('images:optimize', ['--apply' => true, '--business' => $this->shop->id, '--path' => [$first, $second], '--limit' => 1])
            ->expectsOutputToContain('made=1')->assertSuccessful();
        Storage::disk('public')->assertExists((string) ImageVariants::display($first));
        Storage::disk('public')->assertMissing((string) ImageVariants::display($second));

        // ‏وحارسُ الخادم كما هو: الذاكرةُ والحِملُ والقرصُ والطابور — كلٌّ يوقف المسمّاةَ قبل أن تُفكّ
        $meminfo = tempnam(sys_get_temp_dir(), 'mem');
        $loadavg = tempnam(sys_get_temp_dir(), 'load');
        $quiet = function () use ($meminfo, $loadavg) {
            file_put_contents($meminfo, "MemTotal: 984064 kB\nMemAvailable: ".(900 * 1024)." kB\n");
            file_put_contents($loadavg, "0.10 0.10 0.10 1/100 42\n");
            config(['images.gate_min_disk_mb' => 1]);
            DB::table('jobs')->delete();
        };
        $job = fn (int $available) => ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => $available, 'created_at' => time()];
        config(['images.batch_gate' => true, 'images.meminfo' => $meminfo, 'images.loadavg' => $loadavg, 'queue.default' => 'database', 'images.gate_max_queue' => 0]);

        foreach ([
            'low_memory' => fn () => file_put_contents($meminfo, "MemTotal: 984064 kB\nMemAvailable: ".(300 * 1024)." kB\n"),
            'busy_cpu' => fn () => file_put_contents($loadavg, "0.95 0.10 0.10 1/100 42\n"),
            'low_disk' => fn () => config(['images.gate_min_disk_mb' => PHP_INT_MAX >> 21]),
            'queue_busy' => fn () => DB::table('jobs')->insert($job(time() - 1)),
        ] as $reason => $busy) {
            $quiet();
            $busy();
            $this->artisan('images:optimize', ['--apply' => true, '--business' => $this->shop->id, '--path' => [$second]])
                ->expectsOutputToContain('وقفت الدفعة قبل '.$second.': '.$reason)->assertSuccessful();
            Storage::disk('public')->assertMissing((string) ImageVariants::display($second));
        }

        // ‏وقبل كلِّ صورةٍ لا قبل الأولى وحدها: عملٌ يحين في الطابور أثناء الدفعة يوقف الثانية
        $quiet();
        config(['images.pause_ms' => 2500]);
        DB::table('jobs')->insert($job(time() + 2));

        $this->artisan('images:optimize', ['--apply' => true, '--business' => $this->shop->id, '--path' => [$second, $third]])
            ->expectsOutputToContain('وقفت الدفعة قبل '.$third.': queue_busy')->assertSuccessful();
        Storage::disk('public')->assertExists((string) ImageVariants::display($second));
        Storage::disk('public')->assertMissing((string) ImageVariants::display($third));

        @unlink($meminfo);
        @unlink($loadavg);
    }
}
