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

    public function test_the_limit_and_the_server_gate_still_hold(): void
    {
        $first = $this->product($this->shop);
        $second = $this->product($this->shop);

        $this->artisan('images:optimize', ['--apply' => true, '--business' => $this->shop->id, '--path' => [$first, $second], '--limit' => 1])
            ->expectsOutputToContain('made=1')->assertSuccessful();
        Storage::disk('public')->assertExists((string) ImageVariants::display($first));
        Storage::disk('public')->assertMissing((string) ImageVariants::display($second));

        // ‏وحارسُ الخادم كما هو: ذاكرةٌ تحت الحدّ توقف المسمّاةَ أيضًا
        $meminfo = tempnam(sys_get_temp_dir(), 'mem');
        $loadavg = tempnam(sys_get_temp_dir(), 'load');
        file_put_contents($meminfo, "MemTotal: 984064 kB\nMemAvailable: ".(300 * 1024)." kB\n");
        file_put_contents($loadavg, "0.10 0.10 0.10 1/100 42\n");
        config(['images.batch_gate' => true, 'images.meminfo' => $meminfo, 'images.loadavg' => $loadavg, 'images.gate_min_disk_mb' => 1]);

        $this->artisan('images:optimize', ['--apply' => true, '--business' => $this->shop->id, '--path' => [$second]])
            ->expectsOutputToContain('low_memory')->assertSuccessful();
        Storage::disk('public')->assertMissing((string) ImageVariants::display($second));

        @unlink($meminfo);
        @unlink($loadavg);
    }
}
