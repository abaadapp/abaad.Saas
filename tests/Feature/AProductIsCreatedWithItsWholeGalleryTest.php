<?php

namespace Tests\Feature;

use App\Models\BranchStock;
use App\Models\Business;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Support\Demo;
use App\Support\ImageVariants;
use App\Support\ProductImages;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RibbonShopAtCheckout;
use Tests\TestCase;

/**
 * الصنفُ يُضاف بصوره كلّها في حفظةٍ واحدة — حتى خمس، والرئيسيةُ ما اختاره صاحبُه.
 *
 * ═══ ما كان ═══
 *
 * شاشةُ الإضافة تقبل صورةً واحدة، وتقول «احفظ أوّلًا ثمّ أضف البقيّة»: خطوتان
 * وشاشتان لصنفٍ واحد. ومن نسي الثانية نُشر صنفُه بصورةٍ واحدة.
 *
 * ═══ ما يُحرس ═══
 *
 *   - صورةٌ واحدة، وخمسٌ في حفظةٍ واحدة: الرئيسيةُ في `products.image`،
 *     والبقيّةُ في `product_images` بترتيبها — القاعدةُ نفسُها التي يرفع بها
 *     المعرض.
 *   - السادسةُ تردّ الطلبَ كلَّه قبل أن يُكتب ملفّ — في الإضافة وفي المعرض.
 *   - ما أُرسل رئيسيةً يصير الرئيسية، وإن لم تُرسل رئيسيةٌ فأُولى البقيّة.
 *   - صنفٌ بخمسٍ لا يُضاف إليه، وصنفٌ قديمٌ بثمانٍ تبقى صورُه كلُّها: تُعرض
 *     وتُحذف وتُبدَّل رئيسيتُها، ولا يُضاف إليه حتى ينزل دون السقف.
 *   - الصندوقُ يعرض الرئيسية، وصفحةُ الصنف في RIBBON تعرض المعرضَ كلَّه.
 *   - صورُ متجرٍ لا تُبلَغ ولا تُعلَّق من متجرٍ آخر.
 *   - ما يسقط في منتصف الحفظ لا يترك صنفًا ولا ملفًّا.
 *   - الصورُ لا تمسّ السعر ولا الكمّيّة ولا الوصفين ولا قنوات البيع.
 *   - الترتيبُ يُحفظ كما رتّبه صاحبُه، وقائمةٌ قديمةٌ أو غريبةٌ تُردّ ولا يتحرّك شيء.
 *   - حدُّ الدفعة لا يَعِد بأكثر ممّا يحمله الطلب (`limitsFrom`).
 *
 * والضغطُ إلى WebP في `ANewProductPhotoIsSavedLightTest`، والشاشةُ في
 * `tests/js/a-product-is-created-with-its-gallery.test.tsx`.
 */
class AProductIsCreatedWithItsWholeGalleryTest extends TestCase
{
    use RefreshDatabase;
    use RibbonShopAtCheckout;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('public');

        $this->openRibbon();

        /*
         * ومتجرُ سعود رقمُه ٥ في الإنتاج وله قواعدُ في الإعداد — ورقمُ الصفّ
         * هنا يتبع تسلسلَ القاعدة فقد يصيره. فتُفرَّغ قوائمُه كلُّها.
         */
        foreach ((array) config('storefront') as $key => $value) {
            if (str_starts_with((string) $key, 'ribbon_') && is_array($value)) {
                config(['storefront.'.$key => []]);
            }
        }
    }

    /** صورةٌ حقيقيّةٌ يرسمها GD — بأبعادٍ تُعرف بها بعد الحفظ */
    private function photo(string $name, int $w = 300, int $h = 200): UploadedFile
    {
        return UploadedFile::fake()->image($name, $w, $h);
    }

    /** ما ترسله شاشةُ الإضافة — بالصور التي اختيرت */
    private function create(?UploadedFile $main, array $others = [], array $over = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->post(route('admin.products.store'), $over + [
            'name' => 'باقة الربيع',
            'price' => 18.5, 'cost' => 6, 'quantity' => 7, 'alert_qty' => 2,
            'description' => 'ورد أبيض بلفّة كتّان', 'description_en' => 'White roses in linen wrap',
            'active' => true, 'published' => true, 'pos_enabled' => false,
            'description_pos_enabled' => true, 'description_web_enabled' => false,
        ] + array_filter(['image' => $main, 'images' => $others]));
    }

    private function product(string $name = 'باقة الربيع', ?Business $in = null): ?Product
    {
        return Product::where('business_id', ($in ?? $this->shop)->id)->where('name', $name)->first();
    }

    /** @return list<ProductImage> */
    private function extras(Product $product): array
    {
        return ProductImage::where('product_id', $product->id)->orderBy('sort_order')->orderBy('id')->get()->all();
    }

    /** أبعادُ الملفّ المحفوظ — ويُفكّ فعلًا، لا يُكتفى بوجوده */
    private function dimensions(string $path): array
    {
        Storage::disk('public')->assertExists($path);
        $info = getimagesize(Storage::disk('public')->path($path));

        $this->assertIsArray($info, "الملفّ المحفوظ لا يُقرأ صورةً: $path");

        return [$info[0], $info[1]];
    }

    /**
     * أصولُ الصور وحدها — ونسخُها الأخفّ (`ImageVariants`) تتبعها فلا تُعدّ.
     *
     * ونسخةٌ لا أصلَ إلى جوارها يتيمةٌ كملفٍّ لا صفَّ له — فتُردّ هنا.
     */
    private function filesOnDisk(): array
    {
        $files = Storage::disk('public')->allFiles('products');
        $copies = array_filter($files, fn ($f) => ImageVariants::isVariant($f));

        foreach ($copies as $copy) {
            $this->assertContains(preg_replace('/\.w\d+\.(webp|none)$/', '', $copy), $files, "نسخةٌ بقيت بلا أصل: $copy");
        }

        return array_values(array_diff($files, $copies));
    }

    /* ═══════════ صورةٌ واحدة، وخمسٌ في حفظةٍ واحدة ═══════════ */

    public function test_a_product_is_created_with_one_photo_as_its_main(): void
    {
        $this->create($this->photo('rose.jpg', 320, 240))->assertSessionHasNoErrors()->assertRedirect();

        $p = $this->product();
        $this->assertNotNull($p);
        $this->assertSame([320, 240], $this->dimensions($p->getRawOriginal('image')));
        $this->assertSame([], $this->extras($p), 'نزلت الصورةُ الوحيدة إلى المعرض بدل أن تكون الرئيسية');
        $this->assertSame(1, ProductImages::count($p));
        $this->assertCount(1, $this->filesOnDisk());
    }

    public function test_a_product_is_created_with_five_photos_in_one_save(): void
    {
        $this->create($this->photo('main.jpg', 400, 300), [
            $this->photo('side.jpg', 301, 200),
            $this->photo('box.png', 302, 200),
            $this->photo('card.jpg', 303, 200),
            $this->photo('hand.jpg', 304, 200),
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.products.index'));

        $p = $this->product();
        $this->assertSame(5, ProductImages::count($p));
        $this->assertSame([400, 300], $this->dimensions($p->getRawOriginal('image')));

        // والمعرضُ بترتيب الاختيار، ومن متجره
        $extras = $this->extras($p);
        $this->assertSame([301, 302, 303, 304], array_map(fn ($i) => $this->dimensions($i->path)[0], $extras));
        $this->assertSame([1, 2, 3, 4], array_map(fn ($i) => $i->sort_order, $extras));
        $this->assertSame([$this->shop->id], array_values(array_unique(array_map(fn ($i) => (int) $i->business_id, $extras))));

        $this->assertCount(5, $this->filesOnDisk(), 'ملفٌّ على القرص لا يشير إليه صفّ');
    }

    public function test_a_sixth_photo_is_refused_and_nothing_is_written(): void
    {
        $this->create($this->photo('1.jpg'), [
            $this->photo('2.jpg'), $this->photo('3.jpg'), $this->photo('4.jpg'), $this->photo('5.jpg'), $this->photo('6.jpg'),
        ])->assertSessionHasErrors(['images' => 'لا يحمل المنتج أكثر من 5 صور — اخترتَ 6.']);

        $this->assertNull($this->product(), 'أُنشئ الصنفُ وقد رُدّت صورُه');
        $this->assertSame([], $this->filesOnDisk(), 'حُفظت صورٌ لطلبٍ مردود');
        $this->assertSame(0, ProductImage::count());
    }

    /* ═══════════ الرئيسيةُ ما اختاره صاحبُه ═══════════ */

    public function test_the_photo_sent_as_main_becomes_the_main(): void
    {
        // اختار الثانيةَ رئيسيةً في الشاشة — فتصل في `image`، والأخريان بترتيبهما
        $this->create($this->photo('chosen.jpg', 200, 300), [
            $this->photo('first.jpg', 300, 200),
            $this->photo('third.jpg', 250, 250),
        ])->assertSessionHasNoErrors();

        $p = $this->product();
        $this->assertSame([200, 300], $this->dimensions($p->getRawOriginal('image')));
        $this->assertSame([[300, 200], [250, 250]], array_map(fn ($i) => $this->dimensions($i->path), $this->extras($p)));
    }

    public function test_without_a_main_the_first_of_the_others_leads(): void
    {
        // كما في المعرض: صنفٌ بلا رئيسيةٍ تصير أُولى المرفوعات رئيسيتَه
        $this->create(null, [$this->photo('a.jpg', 310, 200), $this->photo('b.jpg', 320, 200)])
            ->assertSessionHasNoErrors();

        $p = $this->product();
        $this->assertSame([310, 200], $this->dimensions($p->getRawOriginal('image')));
        $this->assertSame([[320, 200]], array_map(fn ($i) => $this->dimensions($i->path), $this->extras($p)));
    }

    /* ═══════════ والصورُ لا تمسّ غيرها ═══════════ */

    public function test_photos_change_neither_price_nor_stock_nor_descriptions_nor_channels(): void
    {
        $this->create(null, [], ['name' => 'باقة بلا صور'])->assertSessionHasNoErrors();
        $this->create($this->photo('m.jpg'), [$this->photo('x.jpg'), $this->photo('y.jpg')], ['name' => 'باقة مصوّرة'])
            ->assertSessionHasNoErrors();

        $columns = [
            'price', 'cost', 'quantity', 'alert_qty', 'tracks_stock', 'description', 'description_en',
            'active', 'published', 'pos_enabled', 'description_pos_enabled', 'description_web_enabled',
        ];
        $bare = $this->product('باقة بلا صور');
        $shot = $this->product('باقة مصوّرة');

        $this->assertSame($bare->only($columns), $shot->only($columns));
        $this->assertEquals(18.5, (float) $shot->price);
        $this->assertSame(7, (int) $shot->quantity);
        $this->assertSame('ورد أبيض بلفّة كتّان', $shot->description);
        $this->assertSame('White roses in linen wrap', $shot->description_en);
        $this->assertFalse((bool) $shot->pos_enabled);
        $this->assertTrue((bool) $shot->published);

        // والرصيدُ الافتتاحيّ وحركتُه كما هما بلا صور
        foreach ([$bare, $shot] as $p) {
            $this->assertSame(7, (int) BranchStock::where('product_id', $p->id)->sum('quantity'));
            $this->assertSame(1, InventoryMovement::where('product_id', $p->id)->count());
        }
    }

    /* ═══════════ السقفُ في التعديل ═══════════ */

    public function test_a_product_with_five_photos_takes_no_sixth_from_its_gallery(): void
    {
        $this->create($this->photo('1.jpg'), [$this->photo('2.jpg'), $this->photo('3.jpg'), $this->photo('4.jpg'), $this->photo('5.jpg')]);
        $p = $this->product();

        $this->get(route('admin.products.edit', $p->id))
            ->assertInertia(fn ($page) => $page->has('gallery', 5)->where('galleryMax', 5)->etc());

        $this->post(route('admin.products.images.store', $p->id), ['images' => [$this->photo('6.jpg')]])
            ->assertSessionHasErrors(['images' => 'لا يحمل المنتج أكثر من 5 صور — عنده 5 الآن.']);

        $this->assertSame(5, ProductImages::count($p->fresh()));
        $this->assertCount(5, $this->filesOnDisk(), 'كُتب على القرص ملفٌّ لرفعٍ مردود');
    }

    public function test_the_create_screen_is_told_the_cap_and_the_limits(): void
    {
        $this->actingAs($this->owner)->get(route('admin.products.create'))
            ->assertInertia(fn ($page) => $page
                ->where('galleryMax', ProductImages::MAX)
                ->where('galleryLimits', ProductImages::uploadLimits())
                ->etc());

        $this->assertSame(5, ProductImages::MAX);
    }

    /* ═══════════ الصنفُ القديمُ فوق السقف ═══════════ */

    /** صنفٌ رُفعت له ثماني صورٍ قبل السقف الجديد — رئيسيةٌ وسبعٌ معها */
    private function legacy(int $total = 8): Product
    {
        $p = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة قديمة', 'price' => 30, 'cost' => 10,
            'quantity' => 4, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);

        $paths = [];
        for ($i = 0; $i < $total; $i++) {
            // والملفُّ المؤقّت يُمحى مع كائنه — فيُمسك حتى يُقرأ
            $photo = $this->photo("l$i.jpg", 200 + $i, 200);
            $paths[] = $path = 'products/legacy-'.$i.'.jpg';
            Storage::disk('public')->put($path, (string) file_get_contents($photo->getRealPath()));
        }

        $p->forceFill(['image' => $paths[0]])->save();
        foreach (array_slice($paths, 1) as $i => $path) {
            ProductImage::create(['business_id' => $this->shop->id, 'product_id' => $p->id, 'path' => $path, 'sort_order' => $i + 1]);
        }

        return $p->fresh();
    }

    public function test_a_legacy_product_with_eight_photos_keeps_and_shows_them_all(): void
    {
        $p = $this->legacy();

        $this->actingAs($this->owner)->get(route('admin.products.edit', $p->id))
            ->assertInertia(fn ($page) => $page->has('gallery', 8)->where('galleryMax', 5)->etc());

        /*
         * وفتحُ الشاشة لا يمحو شيئًا، ولا حفظُ بيانات الصنف — ومعرضُه فوق
         * السقف لا يمنع تعديلَ اسمه وسعره ومخزونه: السقفُ للإضافة وحدها.
         */
        $this->put(route('admin.products.update', $p->id), [
            'name' => 'باقة قديمة مجدّدة', 'price' => 31, 'cost' => 10, 'quantity' => 9, 'alert_qty' => 1,
        ])->assertSessionHasNoErrors();

        $p->refresh();
        $this->assertSame('باقة قديمة مجدّدة', $p->name);
        $this->assertEquals(31, (float) $p->price);
        $this->assertSame(9, (int) $p->quantity);
        $this->assertSame(8, ProductImages::count($p));
        $this->assertCount(8, $this->filesOnDisk());
    }

    public function test_a_legacy_product_may_delete_and_promote_but_not_add(): void
    {
        $p = $this->legacy();
        $this->actingAs($this->owner);

        $this->post(route('admin.products.images.store', $p->id), ['images' => [$this->photo('new.jpg')]])
            ->assertSessionHasErrors(['images' => 'لا يحمل المنتج أكثر من 5 صور — عنده 8 الآن.']);
        $this->assertSame(8, ProductImages::count($p->fresh()), 'مسّ الرفضُ صورةً قائمة');

        $victim = $this->extras($p)[2];
        $this->delete(route('admin.products.images.destroy', [$p->id, $victim->id]))->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($victim->path);
        $this->assertSame(7, ProductImages::count($p->fresh()));

        $chosen = $this->extras($p)[0];
        $chosenPath = $chosen->path;
        $this->post(route('admin.products.images.promote', [$p->id, $chosen->id]))->assertSessionHasNoErrors();
        $this->assertSame($chosenPath, $p->fresh()->getRawOriginal('image'));
        $this->assertSame(7, ProductImages::count($p->fresh()), 'غيّر التبديلُ عددَ الصور');

        $this->delete(route('admin.products.images.destroyMain', $p->id))->assertSessionHasNoErrors();
        $this->assertSame(6, ProductImages::count($p->fresh()));

        // وما زال فوق السقف — فلا يُضاف
        $this->post(route('admin.products.images.store', $p->id), ['images' => [$this->photo('new.jpg')]])
            ->assertSessionHasErrors('images');
        $this->assertSame(6, ProductImages::count($p->fresh()));
    }

    public function test_once_under_the_cap_a_legacy_product_takes_photos_again(): void
    {
        $p = $this->legacy(5);
        $this->actingAs($this->owner);

        $this->delete(route('admin.products.images.destroy', [$p->id, $this->extras($p)[0]->id]));
        $this->post(route('admin.products.images.store', $p->id), ['images' => [$this->photo('back.jpg', 222, 111)]])
            ->assertSessionHasNoErrors();

        $this->assertSame(5, ProductImages::count($p->fresh()));
        $this->assertSame([222, 111], $this->dimensions(collect($this->extras($p))->last()->path));
    }

    /* ═══════════ الترتيب ═══════════ */

    public function test_the_gallery_is_reordered_as_the_merchant_arranged_it(): void
    {
        $this->create($this->photo('main.jpg', 400, 300), [
            $this->photo('a.jpg', 301, 200), $this->photo('b.jpg', 302, 200), $this->photo('c.jpg', 303, 200),
        ]);
        $p = $this->product();
        [$a, $b, $c] = $this->extras($p);
        $main = $p->getRawOriginal('image');

        $this->put(route('admin.products.images.order', $p->id), ['ids' => [$c->id, $a->id, $b->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame([303, 301, 302], array_map(fn ($i) => $this->dimensions($i->path)[0], $this->extras($p)));
        $this->assertSame($main, $p->fresh()->getRawOriginal('image'), 'غيّر الترتيبُ الرئيسية');

        // والمعرضُ يُقرأ بالترتيب الجديد في الشاشة وفي صفحة الصنف
        $this->get(route('admin.products.edit', $p->id))->assertInertia(fn ($page) => $page
            ->where('gallery.1.id', $c->id)->where('gallery.2.id', $a->id)->where('gallery.3.id', $b->id)->etc());
        $html = $this->get('/s/'.$this->shop->site_slug.'/p/'.$p->id)->assertOk()->getContent();
        preg_match_all('/<button[^>]*class="rb-thumb[^"]*" data-src="([^"]+)"/', $html, $m);
        $this->assertSame([Storage::url($main), $c->url, $a->url, $b->url], $m[1]);
    }

    public function test_a_stale_or_foreign_order_is_refused_and_nothing_moves(): void
    {
        $this->create($this->photo('main.jpg'), [$this->photo('a.jpg', 301, 200), $this->photo('b.jpg', 302, 200)]);
        $p = $this->product();
        [$a, $b] = $this->extras($p);

        $this->create($this->photo('o.jpg'), [$this->photo('o2.jpg')], ['name' => 'باقة أخرى']);
        $other = $this->extras($this->product('باقة أخرى'))[0];

        $stale = 'تغيّرت صور المنتج منذ فُتحت الصفحة — أعد فتحها ثمّ رتّبها.';
        // ناقصةٌ (حُذفت صورةٌ من تبويبٍ آخر)، وفيها صورةُ صنفٍ آخر، ومكرّرة
        $this->put(route('admin.products.images.order', $p->id), ['ids' => [$b->id]])->assertSessionHasErrors(['images' => $stale]);
        $this->put(route('admin.products.images.order', $p->id), ['ids' => [$b->id, $a->id, $other->id]])->assertSessionHasErrors(['images' => $stale]);
        $this->put(route('admin.products.images.order', $p->id), ['ids' => [$b->id, $other->id]])->assertSessionHasErrors(['images' => $stale]);
        $this->put(route('admin.products.images.order', $p->id), ['ids' => [$b->id, $b->id]])->assertSessionHasErrors('ids.0');

        $this->assertSame([301, 302], array_map(fn ($i) => $this->dimensions($i->path)[0], $this->extras($p)));
        $this->assertSame(1, (int) $other->fresh()->sort_order, 'تحرّكت صورةُ صنفٍ آخر');

        // ومتجرٌ آخر لا يبلغ الباب أصلًا
        $neighbour = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        $stranger = User::create([
            'business_id' => $neighbour->id, 'name' => 'الجار', 'email' => 'order@neighbour.local',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->actingAs($stranger)->put(route('admin.products.images.order', $p->id), ['ids' => [$b->id, $a->id]])->assertNotFound();
        $this->assertSame([301, 302], array_map(fn ($i) => $this->dimensions($i->path)[0], $this->extras($p)));
    }

    public function test_a_legacy_product_may_still_be_reordered(): void
    {
        $p = $this->legacy();
        $ids = array_map(fn ($i) => $i->id, $this->extras($p));

        $this->actingAs($this->owner)->put(route('admin.products.images.order', $p->id), ['ids' => array_reverse($ids)])
            ->assertSessionHasNoErrors();

        $this->assertSame(array_reverse($ids), array_map(fn ($i) => $i->id, $this->extras($p)));
        $this->assertSame(8, ProductImages::count($p->fresh()));
    }

    /* ═══════════ حدودُ الرفع ═══════════ */

    public function test_the_batch_never_promises_more_than_the_request_carries(): void
    {
        // حدُّ الإنتاج اليوم: ملفٌّ ٢ ميغا وطلبٌ ٨
        $this->assertSame(['perFile' => 2048, 'batch' => 7680], ProductImages::limitsFrom(2048, 8192));

        // وطلبٌ أضيقُ من الملفّ: لا يُوعد بملفٍّ لا يتّسع له الطلب
        $this->assertSame(['perFile' => 1536, 'batch' => 1536], ProductImages::limitsFrom(4096, 2048));

        // وطلبٌ أضيقُ من الهامش نفسه لا يصير صفرًا ولا سالبًا
        $this->assertSame(['perFile' => 1, 'batch' => 1], ProductImages::limitsFrom(4096, 256));

        // وبلا حدٍّ معروف: وعدُنا نحن
        $this->assertSame(['perFile' => ProductImages::MAX_KB, 'batch' => ProductImages::MAX_KB * 5], ProductImages::limitsFrom(0, 0));
        $this->assertSame(['perFile' => ProductImages::MAX_KB, 'batch' => 20480 - 512], ProductImages::limitsFrom(0, 20480));
    }

    /* ═══════════ الصندوق وصفحةُ الصنف ═══════════ */

    public function test_the_till_shows_the_main_photo(): void
    {
        $this->create($this->photo('main.jpg', 210, 210), [$this->photo('side.jpg'), $this->photo('box.jpg')], ['pos_enabled' => true]);
        $p = $this->product();

        $this->actingAs($this->owner);
        $row = collect(Demo::products())->firstWhere('id', $p->id);

        $this->assertSame(Storage::url($p->getRawOriginal('image')), $row['image']);
        foreach ($this->extras($p) as $extra) {
            $this->assertNotSame($extra->url, $row['image']);
        }
    }

    public function test_the_ribbon_product_page_shows_the_whole_gallery_in_order(): void
    {
        $this->create($this->photo('main.jpg'), [$this->photo('side.jpg'), $this->photo('box.jpg')]);
        $p = $this->product();

        $html = $this->get('/s/'.$this->shop->site_slug.'/p/'.$p->id)->assertOk()->getContent();

        preg_match_all('/<button[^>]*class="rb-thumb[^"]*" data-src="([^"]+)"/', $html, $m);
        $this->assertSame(
            [Storage::url($p->getRawOriginal('image')), ...array_map(fn ($i) => $i->url, $this->extras($p))],
            $m[1],
            'المعرضُ في صفحة الصنف ليس الرئيسيةَ ثمّ البقيّةَ بترتيبها',
        );
    }

    /* ═══════════ الجدارُ بين المتاجر ═══════════ */

    public function test_another_shop_can_neither_reach_nor_attach_these_photos(): void
    {
        $this->create($this->photo('main.jpg'), [$this->photo('side.jpg')]);
        $mine = $this->product();
        $myExtra = $this->extras($mine)[0];

        $neighbour = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        $stranger = User::create([
            'business_id' => $neighbour->id, 'name' => 'الجار', 'email' => 'n@neighbour.local',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $theirs = Product::create([
            'business_id' => $neighbour->id, 'name' => 'صنفهم', 'price' => 5, 'cost' => 1,
            'quantity' => 1, 'alert_qty' => 0, 'active' => true,
        ]);

        $this->actingAs($stranger);
        $this->get(route('admin.products.edit', $mine->id))->assertNotFound();
        $this->post(route('admin.products.images.store', $mine->id), ['images' => [$this->photo('x.jpg')]])->assertNotFound();
        $this->post(route('admin.products.images.promote', [$theirs->id, $myExtra->id]))->assertNotFound();
        $this->delete(route('admin.products.images.destroy', [$mine->id, $myExtra->id]))->assertNotFound();
        $this->delete(route('admin.products.images.destroyMain', $mine->id))->assertNotFound();

        $this->assertSame(2, ProductImages::count($mine->fresh()));
        $this->assertNull($theirs->fresh()->getRawOriginal('image'), 'صعدت صورةُ متجرٍ إلى صنف جاره');
        Storage::disk('public')->assertExists($myExtra->path);

        // وما يضيفه الجارُ بصوره يُكتب لمتجره هو
        $this->create($this->photo('n1.jpg'), [$this->photo('n2.jpg')], ['name' => 'باقتهم'], $stranger)->assertSessionHasNoErrors();
        $theirNew = $this->product('باقتهم', $neighbour);
        $this->assertSame([(int) $neighbour->id], array_map(fn ($i) => (int) $i->business_id, $this->extras($theirNew)));
        $this->assertNull($this->product('باقتهم'), 'كُتب صنفُ الجار في متجرٍ آخر');
    }

    /* ═══════════ ما يسقط في منتصف الحفظ ═══════════ */

    /** قرصٌ يقبل أوّلَ ما يُكتب ثمّ يتعذّر — كقرصٍ امتلأ في منتصف الدفعة */
    private function diskThatFailsAfter(int $writes): void
    {
        $fake = Storage::disk('public');

        Storage::set('public', new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig(), $writes) extends FilesystemAdapter
        {
            public function __construct($driver, $adapter, array $config, private int $left)
            {
                parent::__construct($driver, $adapter, $config);
            }

            public function put($path, $contents, $options = [])
            {
                return $this->left-- > 0 ? parent::put($path, $contents, $options) : false;
            }
        });
    }

    public function test_a_photo_that_cannot_be_saved_leaves_no_product_and_no_file(): void
    {
        $this->diskThatFailsAfter(2);

        $this->create($this->photo('a.jpg'), [$this->photo('b.jpg'), $this->photo('c.jpg'), $this->photo('d.jpg')])
            ->assertSessionHasErrors(['images' => 'تعذّر حفظ الصورة «c.jpg» — لم يُحفظ شيء، فحاول مرّةً أخرى.']);

        $this->assertNull($this->product(), 'أُنشئ الصنفُ بنصف صوره');
        $this->assertSame([], $this->filesOnDisk(), 'بقيت على القرص صورتان لا يشير إليهما صفّ');
        $this->assertSame(0, ProductImage::count());
    }

    public function test_a_failure_after_the_photos_are_saved_takes_them_back_off_the_disk(): void
    {
        // يسقط حفظُ المعرض بعد أن كُتب الصنفُ ورصيدُه وحُفظت الملفّات
        ProductImage::creating(fn () => throw new \RuntimeException('عطلٌ مصطنع في القاعدة'));

        $this->create($this->photo('a.jpg'), [$this->photo('b.jpg'), $this->photo('c.jpg')])->assertServerError();

        $this->assertNull($this->product(), 'بقي صنفٌ بلا معرضه بعد أن سقط الحفظ');
        $this->assertSame(0, InventoryMovement::where('product_name', 'باقة الربيع')->count(), 'بقي رصيدٌ افتتاحيٌّ لصنفٍ لم يُحفظ');
        $this->assertSame([], $this->filesOnDisk(), 'بقيت الملفّاتُ وقد مُحي صنفُها');
    }

    public function test_a_gallery_upload_whose_rows_fail_takes_its_files_back(): void
    {
        $this->create($this->photo('main.jpg'));
        $p = $this->product();
        ProductImage::creating(fn () => throw new \RuntimeException('عطلٌ مصطنع في القاعدة'));

        $this->post(route('admin.products.images.store', $p->id), ['images' => [$this->photo('x.jpg'), $this->photo('y.jpg')]])
            ->assertServerError();

        $this->assertSame(1, ProductImages::count($p->fresh()));
        $this->assertSame([$p->getRawOriginal('image')], $this->filesOnDisk(), 'بقيت ملفّاتُ رفعٍ لم تُكتب صفوفُه');
    }

    public function test_a_gallery_upload_that_fails_midway_keeps_nothing(): void
    {
        $this->create($this->photo('main.jpg'));
        $p = $this->product();
        $this->diskThatFailsAfter(1);

        $this->post(route('admin.products.images.store', $p->id), ['images' => [$this->photo('x.jpg'), $this->photo('y.jpg')]])
            ->assertSessionHasErrors(['images' => 'تعذّر حفظ الصورة «y.jpg» — لم يُحفظ شيء، فحاول مرّةً أخرى.']);

        $this->assertSame(1, ProductImages::count($p->fresh()));
        $this->assertCount(1, $this->filesOnDisk());
    }
}
