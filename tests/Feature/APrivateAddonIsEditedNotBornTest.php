<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\ProductCompositionController;
use App\Models\Addon;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use App\Support\ProductAddons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الإضافةُ المملوكةُ لمنتج — تُعدَّل وتُعطَّل، ولا تُولد ولا تُحوَّل.
 *
 * ═══ الحال التي أنشأت هذا الملفّ ═══
 *
 * زال قسمُ التركيب، وكان بابَ هذه الإضافات الوحيد: يعرضها ويعدّلها ويفكُّ
 * ربطها. وفي الإنتاج بقي منها ثلاثةُ صفوفٍ على منتجٍ واحد — تُعرض في الكاشير
 * ولا يملك صاحبُها تصحيحَ سعرها ولا إطفاءها. فأُعيد لها بابُ التعديل وحدَه،
 * في شاشة مالكها، بلا بابِ إنشاء وبلا عودة القسم.
 *
 * ═══ وما يُحرَس هنا ═══
 *
 * أنّ البابَ الجديد لا يصير بابَ تحويل. إضافةٌ خاصّةٌ تُحوَّل إلى إضافة
 * متجرٍ تظهر فجأةً مع كلّ منتجاته: سعرٌ يُضاف إلى سلّةٍ لم يُطلب فيها، في
 * متجرٍ لم يطلب أحدٌ فيه ذلك ولا يراه. وكان هذا ممكنًا فعلًا: الشاشةُ ترسل
 * `scope: 'all'`، والخادمُ كان يقرأ ذلك «ارفع الملكيّة».
 *
 * فالملكيّةُ تُقرأ من الصفّ لا من الطلب، والطلبُ الذي يخالف يُردّ ردًّا
 * صريحًا لا يُفسَّر تفسيرًا صامتًا.
 *
 * وأنّ البيانات القائمة لا تُمسّ: الوصفةُ القديمة وصفوفُ الربط تبقى كما
 * هي بعد حفظٍ من الشاشة الجديدة.
 */
class APrivateAddonIsEditedNotBornTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;
    private Product $gift;
    private Product $other;
    private Addon $mine;
    private Addon $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل الشرائط', 'email' => 'r@test.local', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'مالك', 'email' => 'owner@r.local',
            'password' => 'secret', 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->gift = Product::create([
            'business_id' => $this->business->id, 'name' => 'هديّة مغلَّفة',
            'price' => 12, 'cost' => 4, 'quantity' => 20, 'active' => true,
        ]);

        $this->other = Product::create([
            'business_id' => $this->business->id, 'name' => 'شمعة',
            'price' => 3, 'cost' => 1, 'quantity' => 40, 'active' => true,
        ]);

        // صفٌّ كتبه القسمُ الذي زال — هو حالُ الإنتاج بالضبط
        $this->mine = Addon::create([
            'business_id' => $this->business->id, 'product_id' => $this->gift->id,
            'name' => 'شريط ذهبي', 'price' => 0, 'active' => true,
        ]);

        DB::table('product_addons')->insert([
            'business_id' => $this->business->id, 'product_id' => $this->gift->id,
            'addon_id' => $this->mine->id, 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->shop = Addon::create([
            'business_id' => $this->business->id, 'name' => 'كرت إهداء', 'price' => 0.2, 'active' => true,
        ]);
    }

    /** @param array<string, mixed> $body */
    private function save(Addon $addon, array $body, ?User $as = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($as ?? $this->owner)
            ->putJson(route('admin.products.addons.update', $addon->id), $body);
    }

    // ───────────────────────── ما صار مُتاحًا ─────────────────────────

    /** سعرُها يُصحَّح — وتبقى له */
    public function test_its_price_is_corrected_and_it_stays_his(): void
    {
        $this->save($this->mine, ['name' => 'شريط ذهبي', 'price' => 0.75])
            ->assertOk()->assertJsonPath('addon.private', true);

        $fresh = $this->mine->fresh();

        $this->assertSame('0.750', (string) $fresh->price);
        $this->assertSame($this->gift->id, (int) $fresh->product_id);
        $this->assertNull($fresh->scope);
    }

    /** وتُعطَّل، فيكفّ الكاشير عن عرضها — ولا يُحذف صفٌّ */
    public function test_it_is_switched_off_and_the_register_stops_offering_it(): void
    {
        $this->assertTrue(
            ProductAddons::for($this->gift)->contains(fn (Addon $a) => (int) $a->id === (int) $this->mine->id),
        );

        $this->save($this->mine, ['name' => 'شريط ذهبي', 'price' => 0, 'active' => false])->assertOk();

        $this->assertFalse((bool) $this->mine->fresh()->active);
        $this->assertFalse(
            ProductAddons::for($this->gift->fresh())->contains(fn (Addon $a) => (int) $a->id === (int) $this->mine->id),
        );

        // التعطيلُ إخفاءٌ لا محو: الصفُّ باقٍ ليُعاد تشغيله
        $this->assertDatabaseHas('addons', ['id' => $this->mine->id, 'product_id' => $this->gift->id]);
    }

    /**
     * واسمٌ يطابق إضافةَ المتجر لا يمنع حفظَها.
     *
     * التفرّدُ يتبع المالك، وكان يُقرأ من الطلب — والشاشةُ الجديدة لا ترسل
     * مالكًا. فكان تصحيحُ سعر «كرت إهداء» الخاصّ يُردّ بحجّة أنّ في المتجر
     * واحدًا بالاسم نفسه.
     */
    public function test_a_name_that_matches_a_shop_addon_still_saves(): void
    {
        $twin = Addon::create([
            'business_id' => $this->business->id, 'product_id' => $this->gift->id,
            'name' => 'كرت إهداء', 'price' => 0, 'active' => true,
        ]);

        $this->save($twin, ['name' => 'كرت إهداء', 'price' => 0.5])->assertOk();

        $this->assertSame('0.500', (string) $twin->fresh()->price);
    }

    /** وتُعرض في شاشة مالكها وحدَه */
    public function test_it_reaches_the_screen_of_its_owner_only(): void
    {
        $ids = fn (Product $p) => array_column(ProductCompositionController::payload($p)['addons'], 'value');

        $this->assertContains($this->mine->id, $ids($this->gift));
        $this->assertNotContains($this->mine->id, $ids($this->other));
    }

    // ──────────────────────── ما يُردّ في الخادم ────────────────────────

    /** مدًى أوسعُ يُردّ — ولا تنقلب إلى إضافة متجر */
    public function test_a_request_for_a_wider_reach_is_refused(): void
    {
        $this->save($this->mine, ['name' => 'شريط ذهبي', 'price' => 0, 'scope' => Addon::SCOPE_ALL])
            ->assertStatus(422)->assertJsonValidationErrors('scope');

        $this->assertSame($this->gift->id, (int) $this->mine->fresh()->product_id);
    }

    /** وقائمةُ منتجاتٍ أخرى تُردّ — ولا صفَّ ربطٍ يُكتب */
    public function test_a_request_that_lists_other_products_is_refused(): void
    {
        $this->save($this->mine, [
            'name' => 'شريط ذهبي', 'price' => 0, 'product_ids' => [$this->other->id],
        ])->assertStatus(422)->assertJsonValidationErrors('product_ids');

        $this->assertDatabaseMissing('product_addons', [
            'addon_id' => $this->mine->id, 'product_id' => $this->other->id,
        ]);
    }

    /** ولا تُحوَّل إضافةُ المتجر إلى إضافةِ منتج */
    public function test_a_shop_addon_is_not_turned_private(): void
    {
        $this->save($this->shop, [
            'name' => 'كرت إهداء', 'price' => 0.2, 'product_id' => $this->gift->id,
        ])->assertStatus(422)->assertJsonValidationErrors('product_id');

        $this->assertNull($this->shop->fresh()->product_id);
    }

    /** ولا تُولد واحدةٌ جديدة — لا بمالكٍ مذكور ولا بمدًى يعني الملكيّة */
    public function test_no_new_private_addon_is_born(): void
    {
        $before = Addon::count();

        $this->actingAs($this->owner)->postJson(route('admin.products.addons.store'), [
            'name' => 'شريط فضّي', 'price' => 0.4, 'product_id' => $this->gift->id,
        ])->assertStatus(422)->assertJsonValidationErrors('product_id');

        $this->actingAs($this->owner)->postJson(route('admin.products.addons.store'), [
            'name' => 'شريط فضّي', 'price' => 0.4, 'scope' => 'product',
        ])->assertStatus(422)->assertJsonValidationErrors('scope');

        // ولا لمنتجِ متجرٍ آخر: المالكُ لا يُطلب أصلًا، فلا يُسأل لمن هو
        $theirs = Business::create(['name' => 'محل رابع', 'email' => 'f@test.local', 'status' => 'نشط']);
        $theirProduct = Product::create([
            'business_id' => $theirs->id, 'name' => 'باقتهم',
            'price' => 9, 'cost' => 3, 'quantity' => 5, 'active' => true,
        ]);

        $this->actingAs($this->owner)->postJson(route('admin.products.addons.store'), [
            'name' => 'شريط نحاسيّ', 'price' => 0.4, 'product_id' => $theirProduct->id,
        ])->assertStatus(422)->assertJsonValidationErrors('product_id');

        $this->assertSame($before, Addon::count());
        $this->assertSame(0, Addon::whereNotNull('product_id')->where('name', 'شريط فضّي')->count());
    }

    /** ويُقال السببُ بالعربية: من يُردّ يقرأ لماذا */
    public function test_the_refusal_says_why_in_arabic(): void
    {
        $this->actingAs($this->owner)->postJson(route('admin.products.addons.store'), [
            'name' => 'شريط فضّي', 'price' => 0.4, 'product_id' => $this->gift->id,
        ])->assertStatus(422)->assertJsonPath(
            'errors.product_id.0',
            'لا تُنشأ إضافةٌ خاصّةٌ بمنتجٍ واحد — تُنشأ للمتجر ثمّ يُضيَّق مداها.',
        );
    }

    // ─────────────────── ما كُتب قبل اليوم يبقى ───────────────────

    /**
     * وصفوفُ الربط تبقى بعد حفظِ سعر.
     *
     * كان الخادمُ يمحو صفوفَ الإضافة المملوكة عند كلّ حفظ. وتلك صفوفُ إنتاجٍ
     * كتبها القسمُ الذي زال، ومحوُها يغيّر أيَّ إضافاتِ متجرٍ تظهر مع مالكها
     * — تغييرًا لم يطلبه من صحّح سعرًا.
     */
    public function test_the_narrowing_rows_survive_a_save(): void
    {
        $this->save($this->mine, ['name' => 'شريط ذهبي', 'price' => 0.9])->assertOk();

        $this->assertDatabaseHas('product_addons', [
            'addon_id' => $this->mine->id, 'product_id' => $this->gift->id,
        ]);
    }

    /**
     * والوصفةُ القديمة تبقى بعد حفظِ المنتج من الشاشة الجديدة.
     *
     * الشاشةُ لم تعد ترسل `composition` أصلًا، فحفظُ الاسم والسعر لا يجوز أن
     * يمسّ سطرًا كُتب قبل سنة — ولا الإضافةَ المملوكة ولا ربطَها.
     */
    public function test_a_save_from_the_new_screen_leaves_the_old_recipe_alone(): void
    {
        $stem = Product::create([
            'business_id' => $this->business->id, 'name' => 'ساق ورد',
            'price' => 1, 'cost' => 0.4, 'quantity' => 100, 'active' => true,
        ]);

        DB::table('recipe_items')->insert([
            'business_id' => $this->business->id, 'product_id' => $this->gift->id,
            'component_product_id' => $stem->id, 'quantity' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->owner)
            ->put(route('admin.products.update', $this->gift->id), ['name' => 'هديّة مغلَّفة', 'price' => '13.000'])
            ->assertSessionHasNoErrors();

        $this->assertSame('13.000', (string) $this->gift->fresh()->price);

        $this->assertDatabaseHas('recipe_items', [
            'product_id' => $this->gift->id, 'component_product_id' => $stem->id, 'quantity' => 3,
        ]);
        $this->assertDatabaseHas('addons', ['id' => $this->mine->id, 'product_id' => $this->gift->id]);
        $this->assertDatabaseHas('product_addons', [
            'addon_id' => $this->mine->id, 'product_id' => $this->gift->id,
        ]);
    }

    // ────────────────────────── عزلُ الشركات ──────────────────────────

    /** ومتجرٌ آخر لا يعدّلها ولا يعطّلها */
    public function test_another_shop_cannot_edit_it(): void
    {
        $theirs = Business::create(['name' => 'محل آخر', 'email' => 'o@test.local', 'status' => 'نشط']);
        Branch::create(['business_id' => $theirs->id, 'name' => 'فرعهم']);

        $stranger = User::create([
            'business_id' => $theirs->id, 'name' => 'مالكهم', 'email' => 'owner@o.local',
            'password' => 'secret', 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->save($this->mine, ['name' => 'شريطهم', 'price' => 9, 'active' => false], $stranger)
            ->assertNotFound();

        $fresh = $this->mine->fresh();
        $this->assertSame('شريط ذهبي', $fresh->name);
        $this->assertTrue((bool) $fresh->active);
    }

    /** ولا تُعرض في شاشة منتجٍ من متجرٍ آخر */
    public function test_another_shop_does_not_see_it_on_its_own_product(): void
    {
        $theirs = Business::create(['name' => 'محل ثالث', 'email' => 't@test.local', 'status' => 'نشط']);
        $theirProduct = Product::create([
            'business_id' => $theirs->id, 'name' => 'باقتهم',
            'price' => 9, 'cost' => 3, 'quantity' => 5, 'active' => true,
        ]);

        $payload = ProductCompositionController::payload($theirProduct);

        $this->assertNotContains($this->mine->id, array_column($payload['addons'], 'value'));
        $this->assertNotContains($this->shop->id, array_column($payload['addons'], 'value'));
    }

    /** ولا تُباع مع منتجٍ آخر ولو حمل الطلبُ معرّفها */
    public function test_it_is_not_allowed_on_another_product(): void
    {
        $this->assertTrue(ProductAddons::allows($this->gift, $this->mine));
        $this->assertFalse(ProductAddons::allows($this->other, $this->mine));
    }
}
