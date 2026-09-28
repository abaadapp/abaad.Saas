<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RecipeItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * التركيب يُكتب مع المنتج لا بعده.
 *
 * كان قسم «التركيب» يُخفى عند الإنشاء لأنّ المقاس والوصفة لا معرّف
 * يُعلَّقان به قبل الحفظ. فكان على من يُدخل باقةً أن يحفظها، ثم يعود إلى
 * الشاشة نفسها من القائمة، ثم يقول ممّ تتركّب: ثلاث خطوات لفعلٍ واحد في
 * ذهنه — وأكثرُهم لا يعود.
 *
 * فصار التركيب مسوّدةً تُرسل مع طلب الحفظ، والمقاس يُشار إليه برقم موضعه
 * في القائمة ثم يُترجَم إلى معرّفه الحقيقيّ.
 */
class ProductCompositionDraftTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;
    private Product $rose;
    private Product $wrap;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل ورد', 'email' => 'd@test.local', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'مالك', 'email' => 'owner@d.local',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->rose = Product::create([
            'business_id' => $this->business->id, 'name' => 'وردة حمراء',
            'price' => 1, 'cost' => 0.4, 'quantity' => 500, 'active' => true,
        ]);

        $this->wrap = Product::create([
            'business_id' => $this->business->id, 'name' => 'ورق تغليف',
            'price' => 0.5, 'cost' => 0.2, 'quantity' => 200, 'active' => true,
        ]);
    }

    /** @param  array<string, mixed>  $composition */
    private function create(array $composition, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->owner)->post(route('admin.products.store'), array_merge([
            'name' => 'بوكيه الحب',
            'price' => 15,
            'cost' => 5,
            'quantity' => 0,
            'composition' => $composition,
        ], $overrides));
    }

    private function product(): Product
    {
        return Product::where('name', 'بوكيه الحب')->firstOrFail();
    }

    /* ------------------------------ المقاسات ------------------------------ */

    public function test_sizes_written_beside_the_product_are_saved_with_it(): void
    {
        $this->create([
            'variants' => [
                ['name' => 'وسط', 'name_en' => 'Medium', 'price' => 18, 'sku' => 'BQ-M'],
                ['name' => 'كبير', 'name_en' => '', 'price' => 25, 'sku' => ''],
            ],
        ])->assertSessionHasNoErrors();

        $variants = ProductVariant::where('product_id', $this->product()->id)->orderBy('sort_order')->get();

        $this->assertSame(['وسط', 'كبير'], $variants->pluck('name')->all());
        $this->assertSame('BQ-M', $variants[0]->sku);
        // الفراغ ليس رمزًا: رمزان فارغان يتعارضان في قيد التفرّد
        $this->assertNull($variants[1]->sku);
        $this->assertEqualsWithDelta(25.0, (float) $variants[1]->price, 0.0005);
    }

    /* ------------------------------- الوصفة ------------------------------- */

    public function test_more_than_one_component_all_arrive(): void
    {
        // العطب الذي شُكي منه: مكوّنٌ واحد يمرّ والثاني يضيع
        $this->create([
            'recipe' => [
                ['component_product_id' => $this->rose->id, 'quantity' => 12, 'wastage_percent' => 5, 'variant_index' => null],
                ['component_product_id' => $this->wrap->id, 'quantity' => 1, 'wastage_percent' => 0, 'variant_index' => null],
            ],
        ])->assertSessionHasNoErrors();

        $items = RecipeItem::where('product_id', $this->product()->id)->orderBy('sort_order')->get();

        $this->assertCount(2, $items);
        $this->assertSame([$this->rose->id, $this->wrap->id], $items->pluck('component_product_id')->map(fn ($i) => (int) $i)->all());
        $this->assertEqualsWithDelta(12.0, (float) $items[0]->quantity, 0.0005);
        $this->assertEqualsWithDelta(5.0, (float) $items[0]->wastage_percent, 0.0005);
    }

    public function test_a_component_written_twice_is_summed_not_duplicated(): void
    {
        $this->create([
            'recipe' => [
                ['component_product_id' => $this->rose->id, 'quantity' => 7, 'wastage_percent' => 0, 'variant_index' => null],
                ['component_product_id' => $this->rose->id, 'quantity' => 5, 'wastage_percent' => 0, 'variant_index' => null],
            ],
        ])->assertSessionHasNoErrors();

        $items = RecipeItem::where('product_id', $this->product()->id)->get();

        $this->assertCount(1, $items);
        $this->assertEqualsWithDelta(12.0, (float) $items[0]->quantity, 0.0005);
    }

    public function test_a_recipe_line_follows_the_size_it_was_written_under(): void
    {
        $this->create([
            'variants' => [
                ['name' => 'وسط', 'name_en' => '', 'price' => 18, 'sku' => ''],
                ['name' => 'كبير', 'name_en' => '', 'price' => 25, 'sku' => ''],
            ],
            'recipe' => [
                ['component_product_id' => $this->wrap->id, 'quantity' => 1, 'wastage_percent' => 0, 'variant_index' => null],
                ['component_product_id' => $this->rose->id, 'quantity' => 12, 'wastage_percent' => 0, 'variant_index' => 0],
                ['component_product_id' => $this->rose->id, 'quantity' => 24, 'wastage_percent' => 0, 'variant_index' => 1],
            ],
        ])->assertSessionHasNoErrors();

        $product = $this->product();
        $sizes = ProductVariant::where('product_id', $product->id)->orderBy('sort_order')->pluck('id', 'name');
        $items = RecipeItem::where('product_id', $product->id)->get();

        $this->assertNull($items->firstWhere('component_product_id', $this->wrap->id)->variant_id);
        $this->assertSame(
            [12.0, 24.0],
            [
                (float) $items->firstWhere('variant_id', $sizes['وسط'])->quantity,
                (float) $items->firstWhere('variant_id', $sizes['كبير'])->quantity,
            ],
        );
    }

    public function test_a_component_from_another_shop_is_refused_outright(): void
    {
        $other = Business::create(['name' => 'محل آخر', 'email' => 'o@d.local', 'status' => 'نشط']);
        $theirs = Product::create([
            'business_id' => $other->id, 'name' => 'وردتهم', 'price' => 1, 'cost' => 0.4, 'quantity' => 10,
        ]);

        $this->create([
            'recipe' => [
                ['component_product_id' => $theirs->id, 'quantity' => 1, 'wastage_percent' => 0, 'variant_index' => null],
            ],
        ])->assertSessionHasErrors('composition.recipe.0.component_product_id');

        // ولا يُنشأ المنتج نصفَ مكتمل
        $this->assertSame(0, Product::where('name', 'بوكيه الحب')->count());
    }

    public function test_a_bouquet_cannot_be_a_component_of_another_bouquet(): void
    {
        // صنفٌ له وصفته الخاصّة لا يصلح مكوّنًا — نفس قاعدة شاشة التعديل
        $inner = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة صغيرة',
            'price' => 5, 'cost' => 2, 'quantity' => 5, 'active' => true,
        ]);
        RecipeItem::create([
            'business_id' => $this->business->id, 'product_id' => $inner->id,
            'component_product_id' => $this->rose->id, 'quantity' => 3, 'wastage_percent' => 0, 'sort_order' => 0,
        ]);

        $this->create([
            'recipe' => [
                ['component_product_id' => $inner->id, 'quantity' => 1, 'wastage_percent' => 0, 'variant_index' => null],
                ['component_product_id' => $this->rose->id, 'quantity' => 6, 'wastage_percent' => 0, 'variant_index' => null],
            ],
        ])->assertSessionHasNoErrors();

        $items = RecipeItem::where('product_id', $this->product()->id)->get();

        // الصفّ المرفوض يُسقَط وحده ولا يُسقِط المنتج معه
        $this->assertCount(1, $items);
        $this->assertSame($this->rose->id, (int) $items[0]->component_product_id);
    }

    /* ------------------------------ الإضافات ------------------------------ */

    /**
     * ولا تُولد إضافةٌ خاصّةٌ بمنتجٍ من هذه المسوّدة.
     *
     * كانت تُولد: `private => true` يكتب صفًّا مملوكًا للمنتج. وزال قسمُ
     * التركيب وكان بابَ تلك الصفوف الوحيد — يعرضها ويعدّلها ويفكُّ ربطها.
     * فبابٌ يكتب ما لا تُديره شاشةٌ بعده أُغلق.
     *
     * والمكتوبُ قبل اليوم يبقى ويُعدَّل ويُعطَّل من شاشة مالكه — انظر
     * `APrivateAddonIsEditedNotBornTest`.
     */
    public function test_no_new_private_addon_is_born_from_the_draft(): void
    {
        $this->create([
            'new_addons' => [['name' => 'شريط ذهبي', 'price' => 0.5, 'private' => true]],
        ])->assertSessionHasErrors('composition.new_addons.0.private');

        $this->assertSame(0, Addon::where('name', 'شريط ذهبي')->count());
        // ولا يُكتب نصفُ حفظ: الردُّ قبل أن يُنشأ المنتج أصلًا
        $this->assertSame(0, Product::where('name', 'بوكيه الحب')->count());
    }

    /**
     * والغيابُ ليس إذنًا.
     *
     * كان `private` الغائبُ يُقرأ «خاصّة» — فحذفُ الشرط وحده يترك البابَ
     * مفتوحًا لمن لا يذكر الحقل. ولا يُقرأ «عامّة» أيضًا: تلك تظهر مع كلّ
     * منتجات المتجر، فيكون الطلبُ قد قُلب معناه في صمت.
     */
    public function test_a_draft_addon_that_does_not_say_its_reach_is_refused(): void
    {
        $this->create([
            'new_addons' => [['name' => 'شريط ذهبي', 'price' => 0.5]],
        ])->assertSessionHasErrors('composition.new_addons.0.private');

        $this->assertSame(0, Addon::where('name', 'شريط ذهبي')->count());
    }

    /** ويُقال السببُ بالعربية لا باسم الحقل */
    public function test_the_refusal_says_why_in_arabic(): void
    {
        $this->create([
            'new_addons' => [['name' => 'شريط ذهبي', 'price' => 0.5, 'private' => true]],
        ])->assertSessionHasErrors();

        $message = (string) collect(session('errors')->getBag('default')->all())->first();

        $this->assertStringContainsString('لا تُنشأ إضافةٌ خاصّةٌ بمنتجٍ واحد', $message);
        $this->assertStringNotContainsString('composition', $message);
    }

    /**
     * والكاتبُ نفسُه يردّ — ولو نُودي بمصفوفةٍ لم تمرّ بالفحص.
     *
     * `applyDraft` عامّةٌ ساكنة: الفحصُ في `draftRules` هو البابُ الأوّل،
     * وهذا الثاني. ومن استدعاها غدًا من مسارٍ جديد لا يفتح بابًا أُغلق.
     */
    public function test_the_writer_itself_refuses_an_unchecked_private_draft(): void
    {
        $product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة مباشرة',
            'price' => 9, 'cost' => 3, 'quantity' => 1, 'active' => true,
        ]);

        foreach ([['private' => true], []] as $said) {
            $threw = false;

            try {
                \App\Http\Controllers\Admin\ProductCompositionController::applyDraft($product, [
                    'new_addons' => [['name' => 'شريط فضّي', 'price' => 0.5] + $said],
                ]);
            } catch (\Illuminate\Validation\ValidationException $e) {
                $threw = true;
            }

            // والغيابُ كالتصريح: هو ما كان يُقرأ «خاصّة» قبل أن يُغلق الباب
            $this->assertTrue($threw, 'مرَّت مسوّدةٌ خاصّة: '.json_encode($said));
            $this->assertSame(0, Addon::where('name', 'شريط فضّي')->count());
        }
    }

    public function test_an_addon_marked_for_all_products_stays_shop_wide(): void
    {
        $this->create([
            'new_addons' => [['name' => 'تغليف فاخر', 'price' => 1, 'private' => false]],
        ])->assertSessionHasNoErrors();

        $this->assertNull(Addon::where('name', 'تغليف فاخر')->firstOrFail()->product_id);
    }

    public function test_a_shop_addon_with_the_same_name_is_reused_not_duplicated(): void
    {
        $existing = Addon::create([
            'business_id' => $this->business->id, 'name' => 'تغليف فاخر', 'price' => 1, 'active' => true,
        ]);

        $this->create([
            'new_addons' => [['name' => 'تغليف فاخر', 'price' => 3, 'private' => false]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Addon::where('name', 'تغليف فاخر')->count());
        // والسعر القائم لا يُدهَس: تغييره من هنا يمسّ منتجاتٍ أخرى بلا علم
        $this->assertEqualsWithDelta(1.0, (float) $existing->fresh()->price, 0.0005);
    }

    public function test_an_existing_addon_can_be_picked_while_the_product_is_written(): void
    {
        $wrap = Addon::create([
            'business_id' => $this->business->id, 'name' => 'تغليف فاخر', 'price' => 1, 'active' => true,
        ]);

        $this->create(['addon_ids' => [$wrap->id]])->assertSessionHasNoErrors();

        $this->assertSame(1, DB::table('product_addons')
            ->where('product_id', $this->product()->id)->where('addon_id', $wrap->id)->count());
    }

    public function test_another_products_addon_cannot_be_picked_for_a_new_product(): void
    {
        $box = Product::create([
            'business_id' => $this->business->id, 'name' => 'علبة', 'price' => 6, 'cost' => 3, 'quantity' => 4,
        ]);
        $ribbon = Addon::create([
            'business_id' => $this->business->id, 'product_id' => $box->id,
            'name' => 'شريط', 'price' => 1, 'active' => true,
        ]);

        $this->create(['addon_ids' => [$ribbon->id]])
            ->assertSessionHasErrors('composition.addon_ids.0');
    }

    /** ولا تُلتقط إضافةُ متجرٍ آخر ولو كانت إضافةَ متجرٍ عامّة */
    public function test_an_addon_of_another_shop_cannot_be_picked(): void
    {
        $other = Business::create(['name' => 'محل ثالث', 'email' => 't@d.local', 'status' => 'نشط']);
        $theirs = Addon::create([
            'business_id' => $other->id, 'name' => 'تغليفهم', 'price' => 1, 'active' => true,
        ]);

        $this->create(['addon_ids' => [$theirs->id]])
            ->assertSessionHasErrors('composition.addon_ids.0');

        $this->assertSame(0, DB::table('product_addons')->where('addon_id', $theirs->id)->count());
    }

    /* ------------------------------ التوافق ------------------------------- */

    public function test_a_product_saved_with_no_composition_behaves_exactly_as_before(): void
    {
        $this->actingAs($this->owner)->post(route('admin.products.store'), [
            'name' => 'وردة مفردة', 'price' => 2, 'cost' => 1, 'quantity' => 10,
        ])->assertSessionHasNoErrors();

        $product = Product::where('name', 'وردة مفردة')->firstOrFail();

        $this->assertSame(0, ProductVariant::where('product_id', $product->id)->count());
        $this->assertSame(0, RecipeItem::where('product_id', $product->id)->count());
        $this->assertSame(0, DB::table('product_addons')->where('product_id', $product->id)->count());
    }

    public function test_the_create_screen_carries_the_lists_it_needs(): void
    {
        $this->actingAs($this->owner)->get(route('admin.products.create'))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('composition.components')
                ->where('composition.variants', [])
                ->where('composition.addon_ids', []));
    }

    /* ------------------------- الحفظ لا يصمت ------------------------- */

    public function test_a_refused_composition_says_why_in_arabic(): void
    {
        /*
         * الرسالة الافتراضية تقول «composition.recipe.0.quantity» — اسمُ
         * حقلٍ لا يعرفه من يملأ الشاشة. فيبدو الحفظ كأنه لم يقع أصلًا.
         */
        $response = $this->create([
            'recipe' => [
                ['component_product_id' => $this->rose->id, 'quantity' => 0, 'variant_index' => null],
            ],
        ]);

        $response->assertSessionHasErrors();

        $message = (string) collect(session('errors')->getBag('default')->all())->first();

        $this->assertStringContainsString('كمية المكوّن', $message);
        $this->assertStringNotContainsString('composition', $message);
    }

    public function test_a_written_english_name_reaches_the_new_addon(): void
    {
        $this->create([
            'new_addons' => [
                ['name' => 'شوكولاتة بلجيكية', 'name_en' => 'Belgian chocolate', 'price' => 3, 'private' => false],
            ],
        ])->assertSessionHasNoErrors();

        $addon = Addon::where('name', 'شوكولاتة بلجيكية')->firstOrFail();

        // المكتوب بيده لا يُترجَم فوقه: القاموس لا يعرف «بلجيكية»
        $this->assertSame('Belgian chocolate', $addon->name_en);
        // وتُربط بالمنتج الذي كُتبت معه: من كتبها وهو يُعدّه يريدها معه
        $this->assertSame(1, DB::table('product_addons')
            ->where('product_id', $this->product()->id)->where('addon_id', $addon->id)->count());
    }

    public function test_a_new_addon_may_be_tied_to_stock_from_the_create_screen(): void
    {
        $this->create([
            'new_addons' => [[
                'name' => 'زيادة 3 وردات', 'price' => 2.5, 'private' => false,
                'inventory_product_id' => $this->rose->id, 'inventory_quantity' => 3,
            ]],
        ])->assertSessionHasNoErrors();

        $addon = Addon::where('name', 'زيادة 3 وردات')->firstOrFail();

        $this->assertSame($this->rose->id, (int) $addon->inventory_product_id);
        $this->assertEqualsWithDelta(3.0, (float) $addon->inventory_quantity, 0.0005);
    }
}
