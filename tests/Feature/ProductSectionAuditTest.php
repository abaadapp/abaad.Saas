<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RecipeItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * قسم المنتجات — المفاتيح الموصولة، والرموز التي لا تتكرّر، والنسخة التي
 * تشبه أصلها فعلًا.
 *
 * وكلّ ما هنا يمرّ في الشاشة بلا خطأ: مفتاحٌ يُضغط فلا يفعل شيئًا، ونسخةٌ
 * تبدو طبق الأصل وتسلك سلوكًا آخر، وقسمٌ من متجر الجار يُقرأ اسمُه هنا.
 */
class ProductSectionAuditTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    private function product(array $over = []): Product
    {
        return Product::create(array_merge([
            'business_id' => $this->business->id, 'name' => 'قميص', 'price' => 10, 'cost' => 6,
            'quantity' => 20, 'alert_qty' => 5, 'active' => true,
            'sku' => 'SH-'.uniqid(), 'barcode' => '628'.random_int(1000000000, 9999999999),
        ], $over));
    }

    private function neighbour(): Business
    {
        return Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
    }

    /* --------------------- القسم من أقسام هذا المتجر --------------------- */

    public function test_adding_a_product_refuses_a_category_of_another_store(): void
    {
        $theirs = Category::create(['business_id' => $this->neighbour()->id, 'name' => 'أقسامهم']);

        $this->post(route('admin.products.store'), [
            'name' => 'وردة', 'price' => 5, 'category_id' => $theirs->id,
        ])->assertSessionHasErrors('category_id');

        $this->assertSame(0, Product::where('business_id', $this->business->id)->count());
    }

    public function test_editing_a_product_refuses_a_category_of_another_store(): void
    {
        $theirs = Category::create(['business_id' => $this->neighbour()->id, 'name' => 'أقسامهم']);
        $p = $this->product();

        $this->put(route('admin.products.update', $p->id), [
            'name' => 'قميص', 'price' => 10, 'category_id' => $theirs->id,
        ])->assertSessionHasErrors('category_id');

        $this->assertNull($p->fresh()->category_id);
    }

    public function test_our_own_category_is_accepted(): void
    {
        $mine = Category::create(['business_id' => $this->business->id, 'name' => 'ورود']);

        $this->post(route('admin.products.store'), [
            'name' => 'وردة', 'price' => 5, 'category_id' => $mine->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($mine->id, Product::firstOrFail()->category_id);
    }

    public function test_the_bulk_action_still_refuses_a_foreign_category(): void
    {
        $theirs = Category::create(['business_id' => $this->neighbour()->id, 'name' => 'أقسامهم']);
        $p = $this->product();

        $this->post(route('admin.products.bulk'), [
            'action' => 'category', 'ids' => [$p->id], 'category_id' => $theirs->id,
        ]);

        $this->assertNull($p->fresh()->category_id);
    }

    /* ------------------- الموقوف لا يُباع ------------------- */

    private function sell(Product $p)
    {
        session(['current_branch' => $this->branch->id]);

        return $this->postJson(route('pos.checkout'), [
            'items' => [['id' => $p->id, 'name' => $p->name, 'qty' => 1]],
            'payment_method' => 'نقدي',
        ]);
    }

    public function test_a_stopped_product_cannot_be_sold(): void
    {
        $p = $this->product(['active' => false]);

        // ماسحٌ يقرأ الباركود لا يسأل الشاشة، وسلّةٌ عُلّقت قبل الإيقاف تُستأنف بعده
        $this->sell($p)->assertStatus(422);

        $this->assertSame(0, Order::where('is_held', false)->count());
    }

    public function test_a_live_product_still_sells(): void
    {
        $this->sell($this->product())->assertOk();

        $this->assertSame(1, Order::where('is_held', false)->count());
    }

    public function test_the_till_screen_hides_what_was_stopped(): void
    {
        $source = file_get_contents(base_path('resources/js/Pages/Pos/Index.tsx'));

        // لا اختبار يشغّل الشاشة هنا، وحارسُ الخادم وحده يترك الصنف معروضًا
        // ثمّ يُردّ عند الدفع — والعميل واقف
        $this->assertStringContainsString('p.active === false', $source);
        $this->assertStringContainsString(
            'x.active !== false',
            file_get_contents(base_path('resources/js/hooks/usePosCart.ts')),
        );
    }

    /* ------------------- النسخة تشبه أصلها ------------------- */

    public function test_a_copy_carries_the_recipe_and_the_sizes(): void
    {
        $stem = $this->product(['name' => 'ساق', 'quantity' => 100]);
        $bouquet = $this->product(['name' => 'باقة']);
        $variant = ProductVariant::create([
            'business_id' => $this->business->id, 'product_id' => $bouquet->id,
            'name' => 'كبير', 'price' => 30, 'active' => true,
        ]);
        RecipeItem::create([
            'business_id' => $this->business->id, 'product_id' => $bouquet->id,
            'variant_id' => $variant->id, 'component_product_id' => $stem->id, 'quantity' => 12,
        ]);

        $this->post(route('admin.products.duplicate', $bouquet->id));
        $copy = Product::where('name', 'like', '%نسخة%')->firstOrFail();

        // ولو نُسخ الصفّ وحده لبيعت النسخة بلا أن تُنقص ساقًا واحدة
        $this->assertSame(1, RecipeItem::where('product_id', $copy->id)->count());
        $this->assertSame(1, ProductVariant::where('product_id', $copy->id)->count());
    }

    public function test_the_copied_recipe_points_at_the_copies_own_size(): void
    {
        $stem = $this->product(['name' => 'ساق', 'quantity' => 100]);
        $bouquet = $this->product(['name' => 'باقة']);
        $variant = ProductVariant::create([
            'business_id' => $this->business->id, 'product_id' => $bouquet->id,
            'name' => 'كبير', 'price' => 30, 'active' => true,
        ]);
        RecipeItem::create([
            'business_id' => $this->business->id, 'product_id' => $bouquet->id,
            'variant_id' => $variant->id, 'component_product_id' => $stem->id, 'quantity' => 12,
        ]);

        $this->post(route('admin.products.duplicate', $bouquet->id));
        $copy = Product::where('name', 'like', '%نسخة%')->firstOrFail();
        $copiedItem = RecipeItem::where('product_id', $copy->id)->firstOrFail();
        $copiedVariant = ProductVariant::where('product_id', $copy->id)->firstOrFail();

        // وإلّا أشارت وصفةُ النسخة إلى مقاسٍ في الأصل فلم تُقرأ أبدًا
        $this->assertSame($copiedVariant->id, $copiedItem->variant_id);
        $this->assertNotSame($variant->id, $copiedItem->variant_id);
    }

    public function test_a_copy_carries_the_allowed_addons(): void
    {
        $addon = Addon::create([
            'business_id' => $this->business->id, 'name' => 'شريط', 'price' => 1, 'active' => true,
        ]);
        $p = $this->product();
        \DB::table('product_addons')->insert([
            'business_id' => $this->business->id, 'product_id' => $p->id,
            'addon_id' => $addon->id, 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->post(route('admin.products.duplicate', $p->id));
        $copy = Product::where('name', 'like', '%نسخة%')->firstOrFail();

        // والفارغة تعني «كلّ الإضافات» لا «لا شيء» — فإسقاط الربط يوسّع النسخة
        $this->assertSame([$addon->id], $copy->allowedAddons()->pluck('addons.id')->all());
    }

    public function test_a_copy_still_starts_with_no_stock_and_new_codes(): void
    {
        $p = $this->product(['quantity' => 40]);

        $this->post(route('admin.products.duplicate', $p->id));
        $copy = Product::where('name', 'like', '%نسخة%')->firstOrFail();

        $this->assertSame(0, (int) $copy->quantity);
        $this->assertNotSame($p->sku, $copy->sku);
        $this->assertNotSame($p->barcode, $copy->barcode);
    }

    /* ---------------- الرمز لا يتكرّر ولو بعد استعادة ---------------- */

    public function test_a_restore_that_would_duplicate_a_barcode_is_refused(): void
    {
        $gone = $this->product(['name' => 'أ', 'sku' => 'S1', 'barcode' => 'B1']);
        $gone->delete();

        // القيد يتجاوز المحذوف عمدًا، فالإضافة بالرمز نفسه مسموحة
        $this->post(route('admin.products.store'), [
            'name' => 'ب', 'price' => 5, 'sku' => 'S1', 'barcode' => 'B1',
        ])->assertSessionHasNoErrors();

        $this->post(route('admin.products.restore', $gone->id));

        // صنفان بباركودٍ واحد يجعلان الماسح يختار أحدهما — والفرق يظهر في الجرد
        $this->assertSame(1, Product::where('barcode', 'B1')->count());
        $this->assertSoftDeleted('products', ['id' => $gone->id]);
    }

    public function test_a_restore_with_a_free_code_still_works(): void
    {
        $gone = $this->product(['sku' => 'S9', 'barcode' => 'B9']);
        $gone->delete();

        $this->post(route('admin.products.restore', $gone->id));

        $this->assertNotSoftDeleted('products', ['id' => $gone->id]);
    }

    /* ------------------- الحذف الجماعي له فاعل ------------------- */

    public function test_a_bulk_deletion_records_who_did_it(): void
    {
        $p = $this->product();

        $this->post(route('admin.products.bulk'), ['action' => 'delete', 'ids' => [$p->id]]);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'deleted', 'subject_type' => 'product', 'subject_id' => $p->id,
        ]);
    }

    /* ------------------- الصورة المحذوفة لا تبقى ------------------- */

    /**
     * والصورة صارت بابًا آخر — انظر ProductImageController.
     *
     * كانت تُرفع مع السعر والكمية في طلبٍ واحد، وهذا النموذج يكتب الكمية
     * مطلقةً ويُزيح رصيد الفرع بفارقها: فمن بدّل صورةً أعاد الكمية إلى ما
     * كانت عليه قبل أيّ بيعةٍ وقعت بينهما. والثابت المحروس هنا واحدٌ لم
     * يتغيّر: **ما زال الملفّ يُمحى من القرص مع صفّه.**
     */
    public function test_a_removed_image_does_not_stay_on_disk(): void
    {
        Storage::fake('public');
        $p = $this->product();

        $this->post(route('admin.products.images.store', $p->id), [
            'images' => [UploadedFile::fake()->image('one.jpg')],
        ])->assertSessionHasNoErrors();

        $first = $p->fresh()->getRawOriginal('image');
        Storage::disk('public')->assertExists($first);

        $this->delete(route('admin.products.images.destroyMain', $p->id))->assertSessionHasNoErrors();

        Storage::disk('public')->assertMissing($first);
    }

    /** ونموذج المنتج ما عاد يقبلها: بابٌ مغلقٌ لا يُكتب منه شيء */
    public function test_the_product_form_no_longer_carries_the_image(): void
    {
        Storage::fake('public');
        $p = $this->product();
        $before = $p->getRawOriginal('image');

        $this->put(route('admin.products.update', $p->id), [
            'name' => 'قميص', 'price' => 10,
            'image' => UploadedFile::fake()->image('sneaked.jpg'),
        ])->assertSessionHasNoErrors();

        $this->assertSame($before, $p->fresh()->getRawOriginal('image'), 'كُتبت الصورة من بابٍ أُغلق');
        $this->assertSame(0, Storage::disk('public')->files('products') === [] ? 0 : count(Storage::disk('public')->files('products')));
    }
}
