<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * قسمٌ أو إضافةٌ تُنشأ من حيث تُحتاج.
 *
 * ولم يكن لهما بابُ إنشاءٍ في النظام إطلاقًا: الأقسام من تهيئة نوع النشاط
 * أو من استيراد ملفّ، والإضافات من التهيئة وحدها. فمن أراد قسمًا جديدًا
 * وهو يُدخل منتجًا لم يكن أمامه إلّا أن يتركه بلا قسم.
 */
class CatalogQuickAddTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل ورد', 'email' => 'q@test.local', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'مالك', 'email' => 'owner@q.local',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@q.local',
            'password' => bcrypt('secret'), 'role' => 'cashier', 'status' => 'نشط',
        ]);
    }

    /* ------------------------------ الأقسام ------------------------------ */

    public function test_a_category_can_be_created_from_the_product_form(): void
    {
        $this->actingAs($this->owner)
            ->postJson(route('admin.products.categories.store'), ['name' => 'باقات'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('category.name', 'باقات');

        $this->assertDatabaseHas('categories', [
            'business_id' => $this->business->id, 'name' => 'باقات',
        ]);
    }

    public function test_the_new_category_comes_back_with_its_id_so_the_field_can_select_it(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson(route('admin.products.categories.store'), ['name' => 'ورود'])
            ->assertOk();

        $this->assertSame(
            Category::where('business_id', $this->business->id)->value('id'),
            $response->json('category.id'),
        );
    }

    public function test_two_categories_cannot_share_a_name_in_one_shop(): void
    {
        Category::create(['business_id' => $this->business->id, 'name' => 'ورود']);

        $this->actingAs($this->owner)
            ->postJson(route('admin.products.categories.store'), ['name' => 'ورود'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertSame(1, Category::count());
    }

    public function test_the_same_name_is_free_in_another_shop(): void
    {
        // التفرّد على مستوى النشاط لا النظام — «ورود» عند غيرك ليست عندك
        $other = Business::create(['name' => 'محل آخر', 'email' => 'o@q.local', 'status' => 'نشط']);
        Category::create(['business_id' => $other->id, 'name' => 'ورود']);

        $this->actingAs($this->owner)
            ->postJson(route('admin.products.categories.store'), ['name' => 'ورود'])
            ->assertOk();

        $this->assertSame(2, Category::count());
    }

    public function test_the_category_belongs_to_the_signed_in_shop_not_to_a_posted_id(): void
    {
        $other = Business::create(['name' => 'محل آخر', 'email' => 'o2@q.local', 'status' => 'نشط']);

        $this->actingAs($this->owner)
            ->postJson(route('admin.products.categories.store'), [
                'name' => 'باقات', 'business_id' => $other->id,
            ])->assertOk();

        $this->assertSame(
            $this->business->id,
            (int) Category::where('name', 'باقات')->value('business_id'),
        );
    }

    /* ------------------------------ الإضافات ----------------------------- */

    public function test_an_addon_can_be_created_and_comes_back_ready_to_pick(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson(route('admin.products.addons.store'), ['name' => 'دبّ', 'price' => 5])
            ->assertOk()
            ->assertJsonPath('addon.label', 'دبّ')
            ->assertJsonPath('addon.price', 5);

        $this->assertNotNull($response->json('addon.value'));
        $this->assertDatabaseHas('addons', [
            'business_id' => $this->business->id, 'name' => 'دبّ', 'active' => true,
        ]);
    }

    public function test_an_addon_may_be_tied_to_a_stock_item(): void
    {
        $bear = Product::create([
            'business_id' => $this->business->id, 'name' => 'دبّ (مخزون)',
            'price' => 5, 'cost' => 2, 'quantity' => 10,
        ]);

        $this->actingAs($this->owner)
            ->postJson(route('admin.products.addons.store'), [
                'name' => 'دبّ', 'price' => 5, 'inventory_product_id' => $bear->id,
            ])->assertOk();

        $this->assertSame($bear->id, (int) Addon::firstOrFail()->inventory_product_id);
    }

    public function test_an_addon_cannot_be_tied_to_another_shops_product(): void
    {
        $other = Business::create(['name' => 'محل آخر', 'email' => 'o3@q.local', 'status' => 'نشط']);
        $theirs = Product::create([
            'business_id' => $other->id, 'name' => 'دبّهم', 'price' => 5, 'cost' => 2, 'quantity' => 10,
        ]);

        $this->actingAs($this->owner)
            ->postJson(route('admin.products.addons.store'), [
                'name' => 'دبّ', 'price' => 5, 'inventory_product_id' => $theirs->id,
            ])->assertStatus(422)->assertJsonValidationErrors('inventory_product_id');

        $this->assertSame(0, Addon::count());
    }

    public function test_two_addons_cannot_share_a_name_in_one_shop(): void
    {
        Addon::create(['business_id' => $this->business->id, 'name' => 'دبّ', 'price' => 5, 'active' => true]);

        $this->actingAs($this->owner)
            ->postJson(route('admin.products.addons.store'), ['name' => 'دبّ', 'price' => 9])
            ->assertStatus(422)->assertJsonValidationErrors('name');

        $this->assertSame(1, Addon::count());
    }

    public function test_a_price_is_required_so_no_addon_sells_for_nothing_by_accident(): void
    {
        $this->actingAs($this->owner)
            ->postJson(route('admin.products.addons.store'), ['name' => 'بالون'])
            ->assertStatus(422)->assertJsonValidationErrors('price');
    }

    /* ------------------------------ الحراسة ------------------------------ */

    public function test_a_cashier_cannot_create_categories_or_addons(): void
    {
        // الصلاحية نفسها التي تحرس شاشة المنتجات — لا صلاحيةَ جديدة
        $this->actingAs($this->cashier)
            ->postJson(route('admin.products.categories.store'), ['name' => 'باقات'])
            ->assertForbidden();

        $this->actingAs($this->cashier)
            ->postJson(route('admin.products.addons.store'), ['name' => 'دبّ', 'price' => 5])
            ->assertForbidden();

        $this->assertSame(0, Category::count());
        $this->assertSame(0, Addon::count());
    }

    public function test_a_guest_is_turned_away(): void
    {
        $this->postJson(route('admin.products.categories.store'), ['name' => 'باقات'])
            ->assertUnauthorized();
    }
}
