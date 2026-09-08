<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\TrashController;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * النسخةُ تملك صورتَها — ولا يمحو محوُ منتجٍ صورةَ منتجٍ آخر.
 *
 * ═══ ما كان ═══
 *
 * «نسخ منتج» يستعمل `replicate`، وهي تنسخ عمود الصورة كما هو. فيتقاسم
 * الأصلُ ونسختُه **ملفًّا واحدًا على القرص**.
 *
 * ثمّ يُحذف أحدهما — والحذفُ إخفاءٌ لا محو — وتمرّ عليه سلّةُ المحذوفات بعد
 * تسعين يومًا (أو يفرغها التاجر بيده) فيُمحى الملفّ. فيفقد **الآخر، وهو حيٌّ
 * معروضٌ في المتجر على الإنترنت**، صورتَه.
 *
 * ولا يُعرف السبب: لا أحد لمس ذلك المنتج، وعمودُه ما زال يحمل مسارًا —
 * فتُعرض صورةٌ مكسورة لا الحالةُ الفارغة المكتوبة في الشاشة.
 *
 * ووقع على متجرٍ حقيقيّ: «سماء زرقاء» ونسختُها تتقاسمان ملفًّا واحدًا،
 * والنسخةُ في السلّة منذ ٢ سبتمبر.
 *
 * ═══ وما صار ═══
 *
 * النسخُ يملك ملفَّه. وما نُسخ قبل ذلك يحرسه المحو نفسُه: ملفٌّ يشير إليه
 * منتجٌ آخر لا يُمحى مع صاحبه.
 */
class ACopiedProductOwnsItsPhotoTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Category::create(['business_id' => $this->business->id, 'name' => 'ورد']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'سماء زرقاء',
            'price' => 10, 'cost' => 4, 'quantity' => 5,
            'image' => UploadedFile::fake()->image('sky.jpg')->store('products', 'public'),
        ]);
    }

    private function copy(): Product
    {
        $this->actingAs($this->owner)
            ->post(route('admin.products.duplicate', $this->product->id))
            ->assertRedirect();

        return Product::latest('id')->firstOrFail();
    }

    /* ==================== النسخة ==================== */

    public function test_a_copy_gets_a_file_of_its_own(): void
    {
        $copy = $this->copy();

        $this->assertNotNull($copy->getRawOriginal('image'));
        $this->assertNotSame(
            $this->product->getRawOriginal('image'),
            $copy->getRawOriginal('image'),
            'النسخةُ تتقاسم ملفَّ أصلها',
        );
    }

    public function test_and_the_file_is_really_there(): void
    {
        Storage::disk('public')->assertExists($this->copy()->getRawOriginal('image'));
    }

    public function test_and_it_looks_like_its_source(): void
    {
        $copy = $this->copy();

        $this->assertSame(
            Storage::disk('public')->get($this->product->getRawOriginal('image')),
            Storage::disk('public')->get($copy->getRawOriginal('image')),
        );
    }

    public function test_purging_the_copy_leaves_the_original_its_photo(): void
    {
        $copy = $this->copy();
        $was = $this->product->getRawOriginal('image');

        $copy->delete();
        TrashController::purgeRow('product', $copy->fresh() ?? $copy);

        Storage::disk('public')->assertExists($was);
        $this->assertSame($was, $this->product->fresh()->getRawOriginal('image'));
    }

    /* ==================== وما نُسخ قبل الإغلاق ==================== */

    public function test_a_file_another_product_points_at_is_not_purged(): void
    {
        // ‏نسخةٌ قديمة: المساران واحد، كما كُتبت في القاعدة قبل الإصلاح
        $old = Product::create([
            'business_id' => $this->business->id, 'name' => 'سماء زرقاء — نسخة',
            'price' => 10, 'cost' => 4, 'quantity' => 0,
            'image' => $this->product->getRawOriginal('image'),
        ]);
        $shared = $old->getRawOriginal('image');

        $old->delete();
        TrashController::purgeRow('product', $old);

        Storage::disk('public')->assertExists($shared);
    }

    public function test_a_gallery_file_of_another_product_is_not_purged_either(): void
    {
        $other = Product::create([
            'business_id' => $this->business->id, 'name' => 'وردة',
            'price' => 3, 'cost' => 1, 'quantity' => 0,
        ]);
        $shared = $this->product->getRawOriginal('image');
        ProductImage::create([
            'business_id' => $this->business->id, 'product_id' => $other->id,
            'path' => $shared, 'sort_order' => 1,
        ]);

        $this->product->delete();
        TrashController::purgeRow('product', $this->product);

        Storage::disk('public')->assertExists($shared);
    }

    public function test_but_a_file_no_one_else_points_at_is_purged(): void
    {
        // ‏والحارسُ لا يعطّل التنظيف: ملفٌّ لا يشير إليه غيرُه يمضي مع صاحبه
        $was = $this->product->getRawOriginal('image');

        $this->product->delete();
        TrashController::purgeRow('product', $this->product);

        Storage::disk('public')->assertMissing($was);
    }
}
