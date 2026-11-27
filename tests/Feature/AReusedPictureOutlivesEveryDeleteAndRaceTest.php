<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use App\Support\ImageFingerprints;
use App\Support\ProductImages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RibbonShopAtCheckout;
use Tests\TestCase;

/**
 * صورةٌ يعرضها منتجان لا يفقدها أحدُهما — بالحذف، وسلّة المحذوفات، والمحو النهائيّ،
 * ورفعٍ وحذفٍ في اللحظة نفسها.
 *
 * ═══ ما يُحرس ═══
 *
 *   · الحذفُ إلى السلّة والاستعادةُ لا يمسّان ملفًّا. والمحوُ النهائيّ لمنتجٍ يُبقي
 *     ملفًّا يعرضه غيرُه، ومحوُ الأخير يمحوه.
 *   · صورةُ منتجٍ في السلّة لا تُعرض لرفعٍ جديد — ينتظر ملفُّها المحو.
 *   · رفعٌ يعيد استعمالَ ملفٍّ يحجزه حتى تُكتب صفوفُه: حذفُ آخر من يعرضه في
 *     تلك اللحظة لا يمحوه. وحجزٌ لم يُرفع (رفعٌ مات) ينتهي بعد عشر دقائق.
 *   · قفلٌ يمسكه طلبٌ آخر: لا يُعاد استعمالٌ ولا يُمحى شيء — يُحفظ للرفع ملفُّه.
 *   · فهرسٌ مزوَّرٌ يسمّي ملفَّ متجرٍ آخر لا يُعتمد.
 *   · خادمٌ مشغول: لا تُحسب بصمةُ البكسلات، وتبقى المطابقةُ التامّة.
 *   · ويُكتب ما حُفظ فعلًا: نسخةُ عرضٍ فاقدة، أو الملفُّ كما رُفع.
 */
class AReusedPictureOutlivesEveryDeleteAndRaceTest extends TestCase
{
    use RefreshDatabase;
    use RibbonShopAtCheckout;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('public');
        Storage::fake('local');

        $this->openRibbon();

        foreach ((array) config('storefront') as $key => $value) {
            if (str_starts_with((string) $key, 'ribbon_') && is_array($value)) {
                config(['storefront.'.$key => []]);
            }
        }
    }

    private function picture(string $name, int $seed, int $level = 6): UploadedFile
    {
        $image = imagecreatetruecolor(420, 300);
        mt_srand($seed);

        for ($i = 0; $i < 80; $i++) {
            imagefilledellipse($image, mt_rand(0, 420), mt_rand(0, 300), mt_rand(10, 200), mt_rand(10, 200),
                imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
        }

        $file = tempnam(sys_get_temp_dir(), 'race-').'.png';
        imagepng($image, $file, $level);

        return new UploadedFile($file, $name, 'image/png', null, true);
    }

    /** صورةٌ كصورة بضاعة — تدرّجٌ وضجيج، فيصغر WebP عنها فتُحوَّل */
    private function photo(string $name): UploadedFile
    {
        $image = imagecreatetruecolor(1200, 900);
        mt_srand(77);

        for ($y = 0; $y < 900; $y += 2) {
            for ($x = 0; $x < 1200; $x += 2) {
                $n = mt_rand(-25, 25);
                imagefilledrectangle($image, $x, $y, $x + 1, $y + 1, imagecolorallocate($image,
                    max(0, min(255, (int) (200 * $x / 1200) + 30 + $n)), max(0, min(255, (int) (160 * $y / 900) + 50 + $n)), 140));
            }
        }

        $file = tempnam(sys_get_temp_dir(), 'race-').'.png';
        imagepng($image, $file, 9);

        return new UploadedFile($file, $name, 'image/png', null, true);
    }

    private function product(string $name, ?Business $in = null): Product
    {
        return Product::create([
            'business_id' => ($in ?? $this->shop)->id, 'name' => $name, 'price' => 10, 'cost' => 4,
            'quantity' => 3, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
    }

    private function upload(Product $to, UploadedFile $file, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->post(route('admin.products.images.store', $to->id), ['images' => [$file]]);
    }

    private function mainOf(Product $product): ?string
    {
        return DB::table('products')->where('id', $product->id)->value('image');
    }

    private function stored(): array
    {
        return array_values(array_filter(Storage::disk('public')->files('products'),
            fn ($p) => preg_match('/\.w\d+\.webp$/', $p) !== 1 && ! str_starts_with(basename($p), '.')));
    }

    /** وردةٌ وتوليبٌ يعرضان ملفًّا واحدًا */
    private function shared(): array
    {
        $rose = $this->product('Rose');
        $tulip = $this->product('Tulip');
        $this->upload($rose, $this->picture('a.png', 3))->assertSessionHasNoErrors();
        $this->upload($tulip, $this->picture('a.png', 3))->assertSessionHasNoErrors();
        $this->assertSame($this->mainOf($rose), $this->mainOf($tulip));

        return [$rose, $tulip, $this->mainOf($rose)];
    }

    public function test_the_trash_and_a_restore_touch_no_shared_file(): void
    {
        [$rose, $tulip, $path] = $this->shared();

        $this->actingAs($this->owner)->delete(route('admin.products.destroy', $rose->id), ['ack_stock' => 1]);
        $this->assertSoftDeleted('products', ['id' => $rose->id]);
        Storage::disk('public')->assertExists($path);

        $this->actingAs($this->owner)->post(route('admin.products.restore', $rose->id));
        $this->assertNotSoftDeleted('products', ['id' => $rose->id]);
        Storage::disk('public')->assertExists($path);
        $this->assertSame($path, $this->mainOf($rose));
        $this->assertSame($path, $this->mainOf($tulip));
    }

    public function test_purging_one_keeps_the_file_for_the_other_and_purging_the_last_removes_it(): void
    {
        [$rose, $tulip, $path] = $this->shared();

        $this->actingAs($this->owner)->delete(route('admin.products.destroy', $rose->id), ['ack_stock' => 1]);
        $this->actingAs($this->owner)->delete(route('admin.products.purge', $rose->id));
        $this->assertDatabaseMissing('products', ['id' => $rose->id]);
        Storage::disk('public')->assertExists($path);

        $this->actingAs($this->owner)->delete(route('admin.products.destroy', $tulip->id), ['ack_stock' => 1]);
        $this->actingAs($this->owner)->delete(route('admin.products.purge', $tulip->id));
        Storage::disk('public')->assertMissing($path);
    }

    public function test_a_picture_shown_only_by_a_trashed_product_is_not_offered_again(): void
    {
        $rose = $this->product('Rose');
        $tulip = $this->product('Tulip');
        $this->upload($rose, $this->picture('a.png', 4));
        $roses = $this->mainOf($rose);
        $this->actingAs($this->owner)->delete(route('admin.products.destroy', $rose->id), ['ack_stock' => 1]);

        $this->upload($tulip, $this->picture('a.png', 4));
        $this->assertNotSame($roses, $this->mainOf($tulip), 'ينتظر محوَه — فلا يُبنى عليه');
        $this->assertStringNotContainsString('من قبل', (string) (session('toast')['msg'] ?? ''));

        // ‏ومحوُه بعدُ لا يمسّ ملفَّ التوليب
        $this->actingAs($this->owner)->delete(route('admin.products.purge', $rose->id));
        Storage::disk('public')->assertMissing($roses);
        Storage::disk('public')->assertExists($this->mainOf($tulip));
    }

    public function test_a_file_claimed_by_an_upload_in_flight_survives_its_last_delete(): void
    {
        $rose = $this->product('Rose');
        $this->upload($rose, $this->picture('a.png', 5));
        $path = $this->mainOf($rose);

        // ‏رفعٌ آخر وجدها وحجزها ولم يكتب صفَّه بعد — والتاجرُ يحذفها من منتجها الوحيد
        $this->assertTrue(ImageFingerprints::claim($path));
        $this->actingAs($this->owner)->delete(route('admin.products.images.destroyMain', $rose->id));
        Storage::disk('public')->assertExists($path);

        // ‏وحين يُرفع الحجزُ ولا يعرضها شيء: تُمحى
        ImageFingerprints::settle([$path]);
        ProductImages::release($path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_a_claim_left_by_a_dead_upload_expires(): void
    {
        $rose = $this->product('Rose');
        $this->upload($rose, $this->picture('a.png', 6));
        $path = $this->mainOf($rose);

        $this->assertTrue(ImageFingerprints::claim($path));
        Storage::disk('local')->put('image-fingerprints/claims/'.sha1($path), (string) (time() - 700));

        $this->actingAs($this->owner)->delete(route('admin.products.images.destroyMain', $rose->id));
        Storage::disk('public')->assertMissing($path);
    }

    public function test_a_file_already_gone_cannot_be_claimed(): void
    {
        $this->assertFalse(ImageFingerprints::claim('products/'.str_repeat('x', 40).'.webp'));
    }

    public function test_a_delete_asks_again_under_the_lock(): void
    {
        $rose = $this->product('Rose');
        $this->upload($rose, $this->picture('a.png', 7));
        $path = $this->mainOf($rose);

        // ‏صفٌّ كُتب بين السؤال الأوّل والقفل: السؤالُ الثاني يُبقي الملفّ
        ProductImages::forgetFile($path, fn () => false);
        Storage::disk('public')->assertExists($path);
    }

    public function test_a_lock_held_by_another_request_means_its_own_copy_and_no_delete(): void
    {
        [$rose, , $path] = $this->shared();
        $lily = $this->product('Lily');

        config(['images.fingerprint_lock_ms' => 0]);
        $lock = fopen(Storage::disk('local')->path('image-fingerprints/claims/.lock-'.(crc32($path) % 64)), 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        try {
            $this->upload($lily, $this->picture('a.png', 3))->assertSessionHasNoErrors();
            $this->assertNotSame($path, $this->mainOf($lily), 'لم يُحجز — فيُحفظ له ملفُّه');

            ProductImages::forgetFile($path);
            Storage::disk('public')->assertExists($path);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->assertSame($path, $this->mainOf($rose));
    }

    public function test_a_forged_index_naming_another_shops_file_is_not_trusted(): void
    {
        $rose = $this->product('Rose');
        $this->upload($rose, $this->picture('a.png', 8));
        $theirs = $this->mainOf($rose);

        $other = Business::create(['name' => 'Other', 'type' => 'محل ورد', 'status' => 'نشط']);
        $stranger = User::create(['business_id' => $other->id, 'name' => 'Other', 'email' => 'forge@shop.local', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        $mine = $this->product('Mine', $other);

        // ‏فهرسُ المتجر الآخر يُكتب بيده (تالفًا أو مزوّرًا) ليسمّي ملفَّ الوردة ببصمة الصورة نفسها
        $copy = json_decode((string) Storage::disk('local')->get('image-fingerprints/'.$this->shop->id.'/index.json'), true);
        Storage::disk('local')->put('image-fingerprints/'.$other->id.'/index.json', json_encode($copy));

        $this->upload($mine, $this->picture('a.png', 8), $stranger)->assertSessionHasNoErrors();
        $this->assertNotSame($theirs, $this->mainOf($mine), 'ملفُّ متجرٍ آخر لا يُعرض هنا');
        $this->assertCount(2, $this->stored());
    }

    public function test_a_busy_server_skips_the_pixel_fingerprint_but_keeps_the_exact_one(): void
    {
        $load = tempnam(sys_get_temp_dir(), 'load');
        $memory = tempnam(sys_get_temp_dir(), 'mem');
        file_put_contents($load, "3.50 2.00 1.00 1/180 4242\n");
        file_put_contents($memory, "MemTotal: 984064 kB\nMemAvailable: 921600 kB\n");
        config(['images.batch_gate' => true, 'images.loadavg' => $load, 'images.meminfo' => $memory, 'images.gate_min_disk_mb' => 1]);

        $rose = $this->product('Rose');
        $tulip = $this->product('Tulip');
        $lily = $this->product('Lily');

        $this->upload($rose, $this->picture('a.png', 9, 9));
        $this->upload($tulip, $this->picture('a.png', 9, 0));
        $this->assertNotSame($this->mainOf($rose), $this->mainOf($tulip), 'بصمةُ البكسلات لا تُحسب والخادمُ مشغول');

        $this->upload($lily, $this->picture('a.png', 9, 9));
        $this->assertSame($this->mainOf($rose), $this->mainOf($lily), 'والمطابقةُ التامّةُ باقية');
    }

    public function test_the_index_says_what_was_really_stored(): void
    {
        $rose = $this->product('Rose');
        $tulip = $this->product('Tulip');
        $this->upload($rose, $this->photo('a.png'));

        // ‏وصورةٌ لا تُضغط (WebP مطفأ) تُحفظ كما رُفعت بايتًا ببايت
        config(['images.product_webp' => false]);
        $raw = $this->picture('b.png', 11);
        $bytes = hash_file('sha256', $raw->getRealPath());
        $this->upload($tulip, $raw);

        $rows = json_decode((string) Storage::disk('local')->get('image-fingerprints/'.$this->shop->id.'/index.json'), true);
        $converted = $rows[$this->mainOf($rose)];
        $kept = $rows[$this->mainOf($tulip)];

        $this->assertStringEndsWith('.webp', $this->mainOf($rose));
        $this->assertSame('converted', $converted['stored']);
        $this->assertNotSame($converted['sha'], hash('sha256', (string) Storage::disk('public')->get($this->mainOf($rose))), 'بصمةُ ما رُفع لا بصمةُ النسخة المضغوطة');

        $this->assertSame('as_uploaded', $kept['stored']);
        $this->assertSame($bytes, hash('sha256', (string) Storage::disk('public')->get($this->mainOf($tulip))), 'المحفوظُ هو ما رُفع بايتًا ببايت');
    }
}
