<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\DemoStore;
use App\Support\ProductImages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * منتجٌ بلا صورةٍ يقول «بلا صورة» — ولا يُعطى صورةَ شيءٍ آخر.
 *
 * ═══ ما كان ═══
 *
 * `Product::getImageAttribute` كانت تردّ رابط `picsum.photos` — خدمةَ صورٍ
 * عشوائيّة على الإنترنت — لكلّ منتجٍ لم تُرفع له صورة. و`ProductController`
 * كانت **تكتب** ذلك الرابط في العمود عند الإضافة، والاستيرادُ مثلُها.
 *
 * فيُعرض للتاجر ولزبونه صورةُ شيءٍ لا صلة له بالمنتج: سيّارةٌ أو شاطئٌ أو
 * وجهُ إنسان مكان «لانيارد». ووقع على متجرٍ حقيقيّ.
 *
 * والمكتوبُ في العمود أسوأ من المحسوب: `ProductImages::hasRealMain` تقرأ
 * العمود الخام فتقول «له صورةٌ رفعها صاحبُه» — فيُنشر بها في متجره على
 * الإنترنت (وهناك حارسٌ يمنع البديل المحسوب ولا يمنع المكتوب)، وتُعدّ في
 * سقف الصور، ويُرسم لها زرُّ حذفٍ لملفٍّ لا وجود له.
 *
 * ═══ وما صار ═══
 *
 * الفراغُ يبقى فراغًا في العمود وفي المقروء. والشاشاتُ كلُّها كتبت حالتَها
 * الفارغة أصلًا — مربّعٌ رماديّ في القائمة و«📦» في نقطة البيع — ولم تقع
 * مرّةً لأنّ المقروء لم يكن فارغًا قطّ.
 *
 * والبديلُ المصنوع بقي للمتجر التجريبيّ وحده: يُكتب في بياناته عند بذره.
 */
class AProductWithoutAPhotoSaysSoTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->category = Category::create(['business_id' => $this->business->id, 'name' => 'ورد']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function add(array $over = []): Product
    {
        $this->actingAs($this->owner)
            ->post(route('admin.products.store'), $over + [
                'name' => 'لانيارد',
                'category_id' => $this->category->id,
                'price' => 5,
            ])
            ->assertSessionHasNoErrors();

        return Product::latest('id')->firstOrFail();
    }

    /* ==================== الإضافة ==================== */

    public function test_a_product_added_without_a_photo_keeps_its_column_empty(): void
    {
        $product = $this->add();

        $this->assertNull($product->getRawOriginal('image'), 'كُتبت في العمود صورةٌ لم يرفعها أحد');
    }

    public function test_and_reads_back_as_nothing_so_the_screen_shows_its_empty_state(): void
    {
        $this->assertSame('', $this->add()->image);
    }

    public function test_and_the_system_does_not_call_it_photographed(): void
    {
        /*
         * وهذا هو الفارق الذي يصل الزبون: `Storefront` تعرض الصورة لمن
         * `hasRealMain` وحده — وكان المكتوبُ في العمود يتخطّى ذلك الحارس.
         */
        $this->assertFalse(ProductImages::hasRealMain($this->add()));
    }

    public function test_a_photo_that_was_uploaded_is_kept_and_read_as_a_link(): void
    {
        $product = $this->add(['image' => UploadedFile::fake()->image('rose.jpg')]);

        $this->assertNotNull($product->getRawOriginal('image'));
        $this->assertStringContainsString('/storage/products/', $product->image);
        $this->assertTrue(ProductImages::hasRealMain($product));
    }

    public function test_the_gallery_calls_it_a_placeholder_and_does_not_count_it(): void
    {
        $product = $this->add();
        $gallery = ProductImages::gallery($product);

        $this->assertTrue($gallery[0]['placeholder']);
        $this->assertSame(0, ProductImages::count($product));
    }

    /* ==================== ولا يعود من بابٍ آخر ==================== */

    public function test_no_product_door_invents_a_photo(): void
    {
        /*
         * حارسُ مصدر: الاختراعُ يعود بسطرٍ واحد في أيّ بابٍ يُضيف منتجًا،
         * ولا يُكتشف إلّا حين يرى تاجرٌ صورةَ شيءٍ لا يبيعه.
         */
        foreach ([
            'app/Http/Controllers/Admin/ProductController.php',
            'app/Http/Controllers/Admin/ProductImportExportController.php',
        ] as $file) {
            $this->assertStringNotContainsString(
                'Demo::image',
                (string) file_get_contents(base_path($file)),
                "«{$file}» ما زال يخترع صورةً لمنتج",
            );
        }
    }

    public function test_the_demo_store_still_carries_its_own_photos(): void
    {
        /*
         * والبديلُ يبقى حيث يصحّ: كتالوجُ عرضٍ بلا صورٍ لا يُعرض. لكنّه
         * يُكتب في بيانات المتجر التجريبيّ لا يُخترع لكلّ متجر.
         */
        $demo = DemoStore::create('متجر العرض', 'صغير');

        $withPhotos = Product::where('business_id', $demo->id)
            ->whereNotNull('image')->count();

        $this->assertGreaterThan(0, $withPhotos);
        $this->assertSame(Product::where('business_id', $demo->id)->count(), $withPhotos);
    }

    /* ==================== وما كُتب قبل الإغلاق ==================== */

    public function test_the_migration_empties_a_photo_no_one_uploaded(): void
    {
        $invented = Product::create([
            'business_id' => $this->business->id, 'name' => 'لانيارد',
            'price' => 5, 'cost' => 0, 'quantity' => 0,
            'image' => 'https://picsum.photos/seed/prod6a96b636b9466/400/400',
        ]);
        $real = Product::create([
            'business_id' => $this->business->id, 'name' => 'وردة',
            'price' => 5, 'cost' => 0, 'quantity' => 0,
            'image' => 'products/rose.jpg',
        ]);

        (require base_path('database/migrations/2026_09_09_010000_a_product_without_a_photo_says_so.php'))->up();

        $this->assertNull($invented->fresh()->getRawOriginal('image'));
        // ‏ولا تمسّ صورةً رفعها التاجر
        $this->assertSame('products/rose.jpg', $real->fresh()->getRawOriginal('image'));
    }
}
