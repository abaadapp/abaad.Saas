<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Support\ProductImages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * معرض صور المنتج — رئيسيةٌ واحدة وما بعدها.
 *
 * والقاعدة كلّها جملةٌ واحدة تحرسها هذه الاختبارات: **الرئيسية في
 * `products.image`، وما سواها في `product_images`** — فلا صورةَ في موضعين،
 * ولا منتجَ بلا رئيسيةٍ ومعرضُه ممتلئ.
 *
 * والثابت الثاني هو سببُ وجود هذا الباب أصلًا: **لا يمسّ فعلٌ على الصور
 * عمودًا آخر في المنتج.** كان الرفع يمرّ بنموذج المنتج، وهو يكتب الكمية
 * مطلقةً ويُزيح رصيد الفرع بفارقها — فمن بدّل صورةً أعاد الكمية إلى ما كانت
 * عليه قبل أيّ بيعةٍ وقعت بينهما.
 */
class ProductGalleryTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('public');

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'مسقط']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'وردة',
            'price' => 10, 'cost' => 4, 'quantity' => 25, 'alert_qty' => 5, 'active' => true,
        ]);

        $this->actingAs($this->owner);
    }

    /** يرفع صورًا إلى المعرض */
    private function upload(int $n = 1, ?Product $on = null)
    {
        $files = [];
        for ($i = 0; $i < $n; $i++) {
            $files[] = UploadedFile::fake()->image('img'.uniqid().'.jpg');
        }

        return $this->post(route('admin.products.images.store', ($on ?? $this->product)->id), ['images' => $files]);
    }

    private function main(): ?string
    {
        return $this->product->fresh()->getRawOriginal('image');
    }

    private function extras(): Collection
    {
        return ProductImage::where('product_id', $this->product->id)
            ->orderBy('sort_order')->orderBy('id')->get();
    }

    /* ==================== الرفع، والرئيسية الأولى ==================== */

    public function test_the_first_upload_becomes_the_main_image(): void
    {
        /*
         * وإلّا رفع التاجر صورةً فبقيت بطاقتُه تعرض بديل النظام وصورتُه في
         * معرضٍ لا يفتحه أحد — فيظنّ أنّ الرفع لم ينجح ويعيده.
         */
        $this->upload()->assertSessionHasNoErrors();

        $this->assertNotNull($this->main());
        Storage::disk('public')->assertExists($this->main());
        $this->assertCount(0, $this->extras(), 'أُنزلت أوّلُ صورةٍ إلى المعرض بدل أن تكون الرئيسية');
    }

    public function test_the_rest_land_in_the_gallery_beside_it(): void
    {
        $this->upload(3)->assertSessionHasNoErrors();

        $this->assertNotNull($this->main());
        $this->assertCount(2, $this->extras());

        foreach ($this->extras() as $image) {
            Storage::disk('public')->assertExists($image->path);
        }
    }

    public function test_the_gallery_reads_main_first(): void
    {
        // بابٌ واحد تقرأ منه الشاشات كلّها — فلا تُعرض الرئيسية ثانيةً في آخر الصفّ
        $this->upload(3);

        $gallery = ProductImages::gallery($this->product->fresh()->load('images'));

        $this->assertCount(3, $gallery);
        $this->assertTrue($gallery[0]['main']);
        $this->assertNull($gallery[0]['id'], 'الرئيسية ليست صفًّا في جدول الصور');
        $this->assertSame([false, false], [$gallery[1]['main'], $gallery[2]['main']]);
    }

    /* ==================== ولا يُمسّ شيءٌ سوى الصور ==================== */

    public function test_uploading_touches_nothing_else_in_the_product(): void
    {
        /*
         * هذا هو سببُ وجود هذا الباب: نموذج المنتج يكتب الكمية مطلقةً ويُزيح
         * رصيد الفرع بفارقها. فمن فتح الشاشة ثمّ باع صنفًا على الصندوق ثمّ
         * بدّل الصورة، كان يُعيد الكمية إلى ما قبل البيعة — بضاعةٌ تعود إلى
         * الرفّ لأنّ أحدًا غيّر صورة.
         */
        $before = $this->product->only(['name', 'price', 'cost', 'quantity', 'alert_qty', 'sku', 'barcode', 'active']);

        $this->upload(2)->assertSessionHasNoErrors();

        $this->assertSame($before, $this->product->fresh()->only(array_keys($before)));
    }

    public function test_neither_does_promoting_nor_deleting(): void
    {
        $this->upload(3);
        $before = $this->product->only(['name', 'price', 'quantity', 'alert_qty']);

        $this->post(route('admin.products.images.promote', [$this->product->id, $this->extras()->first()->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame($before, $this->product->fresh()->only(array_keys($before)));

        $this->delete(route('admin.products.images.destroy', [$this->product->id, $this->extras()->first()->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame($before, $this->product->fresh()->only(array_keys($before)));

        $this->delete(route('admin.products.images.destroyMain', $this->product->id))
            ->assertSessionHasNoErrors();
        $this->assertSame($before, $this->product->fresh()->only(array_keys($before)));
    }

    /* ======================== تغيير الرئيسية ======================== */

    public function test_promoting_swaps_the_two_and_loses_neither(): void
    {
        /*
         * تبديلٌ لا استبدال: من رفع صورًا ثمّ بدّل الرئيسية لا يقصد أن يفقد
         * واحدةً منها. فيبقى العدد كما هو وتتبادل الصورتان موضعيهما.
         */
        $this->upload(3);

        $wasMain = $this->main();
        $chosen = $this->extras()->last();
        $chosenPath = $chosen->path;

        $this->post(route('admin.products.images.promote', [$this->product->id, $chosen->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame($chosenPath, $this->main(), 'لم تصعد المختارة');
        $this->assertCount(2, $this->extras(), 'تغيّر عدد الصور بالتبديل');
        $this->assertContains($wasMain, $this->extras()->pluck('path')->all(), 'ضاعت الرئيسية القديمة');

        Storage::disk('public')->assertExists($wasMain);
        Storage::disk('public')->assertExists($chosenPath);
    }

    public function test_the_promoted_image_keeps_its_slot_in_the_gallery(): void
    {
        // وإلّا قفزت الصور في الشاشة عند كل ترقية بلا سبب
        $this->upload(4);

        $second = $this->extras()[1];
        $slot = $second->sort_order;

        $this->post(route('admin.products.images.promote', [$this->product->id, $second->id]));

        $demoted = $this->extras()->firstWhere('id', $second->id);
        $this->assertSame($slot, $demoted->sort_order);
    }

    public function test_a_system_placeholder_is_not_demoted_into_the_gallery(): void
    {
        /*
         * منتجٌ لا صورةَ له يحمل رابط `picsum` في عموده، وإنزالُه إلى المعرض
         * يملؤه بصورٍ لم يرفعها أحد.
         */
        $this->product->forceFill(['image' => 'https://picsum.photos/seed/x/400/400'])->save();
        ProductImages::add($this->product, UploadedFile::fake()->image('real.jpg'));

        $only = $this->extras()->first();
        $this->post(route('admin.products.images.promote', [$this->product->id, $only->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame($only->path, $this->main());
        $this->assertCount(0, $this->extras(), 'نزل بديلُ النظام إلى المعرض');
    }

    /* ========================= الحذف والخلافة ========================= */

    public function test_deleting_an_extra_removes_its_row_and_its_file(): void
    {
        $this->upload(3);
        $victim = $this->extras()->first();

        $this->delete(route('admin.products.images.destroy', [$this->product->id, $victim->id]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('product_images', ['id' => $victim->id]);
        Storage::disk('public')->assertMissing($victim->path);
        $this->assertCount(1, $this->extras());
        $this->assertNotNull($this->main(), 'مُسّت الرئيسية بحذف غيرها');
    }

    public function test_deleting_the_main_promotes_the_next_one(): void
    {
        /*
         * ومنتجٌ يُترك بلا رئيسيةٍ ومعرضُه ممتلئ يُعرض بلا صورةٍ في شاشة البيع
         * بينما صورُه محفوظة — عطبٌ يراه التاجر ولا يفهم سببه.
         */
        $this->upload(3);

        $wasMain = $this->main();
        $heir = $this->extras()->first()->path;

        $this->delete(route('admin.products.images.destroyMain', $this->product->id))
            ->assertSessionHasNoErrors();

        $this->assertSame($heir, $this->main(), 'لم تخلُفها التالية');
        $this->assertCount(1, $this->extras());
        Storage::disk('public')->assertMissing($wasMain);
        Storage::disk('public')->assertExists($heir);
    }

    public function test_deleting_the_last_image_leaves_the_product_without_one(): void
    {
        // والإفراغ إلى بديل النظام لا يقع إلّا حين لا يبقى شيء
        $this->upload(1);
        $wasMain = $this->main();

        $this->delete(route('admin.products.images.destroyMain', $this->product->id))
            ->assertSessionHasNoErrors();

        $this->assertNull($this->main());
        Storage::disk('public')->assertMissing($wasMain);
        // والمقروء يبقى رابطًا صالحًا للعرض لا فراغًا يكسر الشاشة
        $this->assertNotEmpty($this->product->fresh()->image);
    }

    /* ============================== السقف ============================== */

    public function test_the_cap_is_measured_on_what_it_would_become(): void
    {
        /*
         * لا أن تُقبل بعضُها وتُطرح بعضُها بلا أن يُقال أيّها: من رفع أربعًا
         * فقُبلت اثنتان لا يعرف أيّ اثنتين وصلتا.
         */
        $this->upload(ProductImages::MAX);
        $this->assertSame(ProductImages::MAX, ProductImages::count($this->product->fresh()));

        $this->upload(1)->assertSessionHasErrors('images');
        $this->assertSame(ProductImages::MAX, ProductImages::count($this->product->fresh()));
    }

    public function test_a_batch_that_would_overflow_is_refused_whole(): void
    {
        $this->upload(ProductImages::MAX - 1);
        $count = ProductImages::count($this->product->fresh());

        $this->upload(3)->assertSessionHasErrors('images');

        $this->assertSame($count, ProductImages::count($this->product->fresh()), 'قُبلت بعضُ الدفعة وطُرح باقيها');
    }

    public function test_a_file_that_is_not_an_image_is_refused(): void
    {
        $this->post(route('admin.products.images.store', $this->product->id), [
            'images' => [UploadedFile::fake()->create('sheet.pdf', 100, 'application/pdf')],
        ])->assertSessionHasErrors('images.0');

        $this->assertNull($this->main());
    }

    /* ========================= الجدار بين المتاجر ========================= */

    private function neighboursProduct(): Product
    {
        $neighbour = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);

        return Product::create([
            'business_id' => $neighbour->id, 'name' => 'صنفهم',
            'price' => 5, 'cost' => 2, 'quantity' => 3, 'alert_qty' => 1, 'active' => true,
        ]);
    }

    public function test_a_neighbours_product_takes_no_images_from_me(): void
    {
        $theirs = $this->neighboursProduct();

        $this->upload(1, $theirs)->assertNotFound();

        $this->assertNull($theirs->fresh()->getRawOriginal('image'));
    }

    public function test_a_neighbours_image_cannot_be_promoted_or_deleted(): void
    {
        $theirs = $this->neighboursProduct();
        $theirImage = ProductImages::add($theirs, UploadedFile::fake()->image('theirs.jpg'));

        $this->post(route('admin.products.images.promote', [$this->product->id, $theirImage->id]))->assertNotFound();
        $this->delete(route('admin.products.images.destroy', [$this->product->id, $theirImage->id]))->assertNotFound();

        $this->assertDatabaseHas('product_images', ['id' => $theirImage->id]);
        Storage::disk('public')->assertExists($theirImage->path);
    }

    public function test_an_image_of_another_product_in_my_own_shop_is_out_of_reach_too(): void
    {
        /*
         * والشرطان معًا: `business_id` يمنع صورة الجار، و`product_id` يمنع
         * نقل صورةِ منتجٍ إلى منتجٍ آخر بتخمين معرّفها — وكلاهما داخل المتجر
         * الواحد فلا يكفي الأوّل وحده.
         */
        $other = Product::create([
            'business_id' => $this->business->id, 'name' => 'صنفٌ آخر',
            'price' => 5, 'cost' => 2, 'quantity' => 3, 'alert_qty' => 1, 'active' => true,
        ]);
        $its = ProductImages::add($other, UploadedFile::fake()->image('other.jpg'));

        $this->post(route('admin.products.images.promote', [$this->product->id, $its->id]))->assertNotFound();

        $this->assertNull($this->main(), 'صعدت صورةُ منتجٍ آخر إلى رئيسيّة هذا');
    }

    public function test_the_screen_is_closed_to_whoever_lacks_the_products_section(): void
    {
        $cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->actingAs($cashier)
            ->post(route('admin.products.images.store', $this->product->id), [
                'images' => [UploadedFile::fake()->image('x.jpg')],
            ])->assertForbidden();

        $this->assertNull($this->main());
    }

    /* ====================== ما تحمله الشاشات ====================== */

    public function test_the_edit_screen_carries_the_gallery(): void
    {
        $this->upload(3);

        $this->get(route('admin.products.edit', $this->product->id))
            ->assertInertia(fn ($p) => $p->has('gallery', 3)->where('galleryMax', ProductImages::MAX)->etc());
    }

    public function test_the_product_page_shows_every_image_not_just_the_main(): void
    {
        // كان سطرًا يبني قائمةً من صورةٍ واحدة، فالمصغّرات لا تظهر أبدًا
        $this->upload(3);

        $this->get(route('admin.products.show', $this->product->id))
            ->assertInertia(fn ($p) => $p->has('thumbs', 3)->etc());
    }

    /* ================= الحدُّ الذي تقرؤه الشاشة ================= */

    public function test_the_size_limit_is_one_number_the_screen_can_read(): void
    {
        /*
         * ورقمان يقولان الشيء نفسه يفترقان يومًا: لو قاست الشاشةُ بحدٍّ
         * والخادمُ بآخر، رُفعت أربعةُ ميغابايت على شبكةِ هاتفٍ ثمّ رُدّت —
         * أو منعت الشاشةُ ما كان الخادم ليقبله.
         */
        $this->upload(1);

        $this->get(route('admin.products.edit', $this->product->id))
            ->assertInertia(fn ($p) => $p->where('galleryMaxKb', ProductImages::MAX_KB)->etc());

        // والمُدقّق يقيس بالرقم نفسه لا برقمٍ مكتوبٍ في مكانه
        $this->post(route('admin.products.images.store', $this->product->id), [
            'images' => [UploadedFile::fake()->create('big.jpg', ProductImages::MAX_KB + 1, 'image/jpeg')],
        ])->assertSessionHasErrors('images.0');

        /*
         * والطرفُ الآخر لازم: مُدقّقٌ أضيقُ ممّا وعدت به الشاشة يردّ ملفًّا
         * سمحت الشاشةُ برفعه — فينتظر التاجر رفعَ أربعةِ ميغابايت ثمّ يُردّ.
         */
        $this->post(route('admin.products.images.store', $this->product->id), [
            'images' => [UploadedFile::fake()->create('just-under.jpg', ProductImages::MAX_KB - 1, 'image/jpeg')],
        ])->assertSessionHasNoErrors();
    }

    /* =================== بديلُ النظام ليس صورةً =================== */

    public function test_the_system_placeholder_is_marked_as_one(): void
    {
        /*
         * ولولا العَلَم لَعدّته الشاشةُ صورةً: «١ / ٨» لمنتجٍ لا صورةَ له،
         * وزرُّ حذفٍ لملفٍّ لا وجود له، وسقفٌ يُبلَغ عند سبعٍ لا ثمانٍ.
         */
        $empty = ProductImages::gallery($this->product->fresh()->load('images'));

        $this->assertCount(1, $empty);
        $this->assertTrue($empty[0]['placeholder'], 'بديلُ النظام يُعرض كأنّه صورةٌ رفعها التاجر');

        $this->upload(2);
        $filled = ProductImages::gallery($this->product->fresh()->load('images'));

        $this->assertSame([false, false], array_column($filled, 'placeholder'));
    }

    /* ================= المحو النهائي لا يترك ملفًّا ================= */

    public function test_purging_a_product_takes_every_image_off_the_disk(): void
    {
        /*
         * صفوفُ المعرض يأخذها قيدُ المفتاح، وملفّاتُها لا يأخذها شيء —
         * فتبقى على القرص بلا صفٍّ يشير إليها، ولا يظهر ذلك إلا بعدّ ما عليه.
         */
        $this->upload(3);

        $paths = ProductImages::files($this->product->fresh());
        $this->assertCount(3, $paths);

        $this->delete(route('admin.products.destroy', $this->product->id));
        $this->delete(route('admin.products.purge', $this->product->id))
            ->assertSessionHasNoErrors();

        $this->assertNull(Product::withTrashed()->find($this->product->id));

        foreach ($paths as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }
}
