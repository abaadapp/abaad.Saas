<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\RecipeItem;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\CustomArrangement;
use App\Support\DocumentRenderer;
use App\Support\DocumentTemplates;
use App\Support\OrderCorrection;
use App\Support\OrderStatus;
use App\Support\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * الطلبُ المخصَّص: باقةٌ تُركَّب على الطاولة، ومكوّناتُها تُعرف.
 *
 * ═══ العطب ═══
 *
 * الزبون يقول «ورد بعشرين ريالًا في كيسٍ أسود». والصندوقُ لا يبيع إلا صنفًا
 * مُعرَّفًا سلفًا — فيبيع الكاشيرُ أقربَ باقةٍ شبيهة. والرفُّ حينئذٍ يُخصم منه
 * **ما في وصفة تلك الباقة** لا ما أُخذ منه: وردٌ أحمرُ يَنقص في الدفتر وهو
 * في الدلو، وأبيضُ يَنفد وهو موجودٌ في الدفتر.
 *
 * ═══ والقاعدةُ التي يقوم عليها كلُّ ما تحت ═══
 *
 * **قيمةُ البيع لا تقول كم وردةً خرجت.** الرفُّ لا يُخصم بالمال — يُخصم
 * بالعدد. فالسعرُ للفاتورة، والموادُّ للمخزون، ولا تُستنبط إحداهما من الأخرى.
 *
 * وما عدا السعر لا يُقرأ من المتصفّح: التكلفةُ من `products.cost`، وأسماءُ
 * الموادّ من صفوفها، والضريبةُ من إعدادات المتجر.
 */
class AnArrangementIsBuiltAtTheCounterTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Branch $khoud;

    private Branch $seeb;

    private User $cashier;

    private Product $white;

    private Product $pink;

    private Product $bag;

    private Addon $card;

    private Addon $ribbon;

    /** إضافةٌ خدمةٌ لا بضاعة — لا رصيدَ لها فلا تُخصم */
    private Addon $wrapService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        $this->shop = Business::create(['name' => 'محل ورد', 'status' => 'نشط']);
        $this->khoud = Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوض']);
        $this->seeb = Branch::create(['business_id' => $this->shop->id, 'name' => 'السيب']);

        $this->cashier = User::create([
            'business_id' => $this->shop->id, 'name' => 'كاشير', 'email' => 'c@abaadapp.om',
            'password' => bcrypt('password12345'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $this->white = Product::create([
            'business_id' => $this->shop->id, 'name' => 'ورد أبيض', 'sku' => 'ROSE-WHITE',
            'price' => 1, 'cost' => 0.5, 'quantity' => 50, 'active' => true,
        ]);
        $this->pink = Product::create([
            'business_id' => $this->shop->id, 'name' => 'ورد وردي', 'sku' => 'ROSE-PINK',
            'price' => 1, 'cost' => 0.6, 'quantity' => 40, 'active' => true,
        ]);
        $this->bag = Product::create([
            'business_id' => $this->shop->id, 'name' => 'كيس أسود', 'sku' => 'BAG-BLACK',
            'price' => 2, 'cost' => 0.8, 'quantity' => 20, 'active' => true,
        ]);

        $this->card = Addon::create([
            'business_id' => $this->shop->id, 'name' => 'كرت', 'price' => 0.5,
            'active' => true, 'inventory_product_id' => Product::create([
                'business_id' => $this->shop->id, 'name' => 'مخزون الكرت',
                'price' => 0.5, 'cost' => 0.1, 'quantity' => 30, 'active' => true,
            ])->id,
        ]);
        $this->ribbon = Addon::create([
            'business_id' => $this->shop->id, 'name' => 'شريطة', 'price' => 0.3,
            'active' => true, 'inventory_product_id' => Product::create([
                'business_id' => $this->shop->id, 'name' => 'مخزون الشريطة',
                'price' => 0.3, 'cost' => 0.05, 'quantity' => 25, 'active' => true,
            ])->id,
        ]);
        // خدمةٌ بلا رصيد — `inventory_product_id` فارغ
        $this->wrapService = Addon::create([
            'business_id' => $this->shop->id, 'name' => 'تغليف فاخر', 'price' => 1, 'active' => true,
        ]);
    }

    /** حمولةُ الطلب المخصَّص كما ترسلها الشاشة */
    private function custom(array $over = []): array
    {
        return array_merge([
            'mode' => CustomArrangement::MODE_VALUE,
            'price' => 20,
            'flower_value' => 20,
            'colors' => ['أبيض', 'وردي'],
            'packaging_label' => 'كيس أسود',
            'florist_notes' => 'الأبيض أكثر من الوردي',
            'components' => [
                ['product_id' => $this->white->id, 'quantity' => 8, 'kind' => 'flower'],
                ['product_id' => $this->pink->id, 'quantity' => 6, 'kind' => 'flower'],
                ['product_id' => $this->bag->id, 'quantity' => 1, 'kind' => 'packaging'],
            ],
        ], $over);
    }

    private function sell(array $custom = [], array $addons = [], array $extra = [], int $qty = 1)
    {
        return $this->actingAs($this->cashier)->postJson('/pos/checkout', array_merge([
            'items' => [[
                'name' => 'تنسيق ورد مخصص',
                'qty' => $qty,
                'addons' => $addons,
                'custom' => $this->custom($custom),
            ]],
            'payment_method' => 'نقدي',
            'client_uuid' => uniqid('c', true),
        ], $extra));
    }

    private function order(): Order
    {
        return Order::latest('id')->with('items.components', 'items.addons')->firstOrFail();
    }

    /* ══════════════ ١ · التسعير ══════════════ */

    /** «قيمة الورد + الإضافات» — الإضافةُ تزيد ما يدفعه الزبون */
    public function test_flower_value_plus_addons_is_what_the_customer_pays(): void
    {
        $this->sell([], [
            ['addon_id' => $this->card->id, 'qty' => 1],
            ['addon_id' => $this->ribbon->id, 'qty' => 1],
        ])->assertOk()->assertJsonPath('ok', true);

        $order = $this->order();
        $item = $order->items->first();

        $this->assertEqualsWithDelta(20.0, (float) $item->total, 0.0005);
        $this->assertEqualsWithDelta(0.8, (float) $item->addons_total, 0.0005, 'ثمنُ الكرت والشريطة لم يُضف');
        $this->assertEqualsWithDelta(20.8, (float) $order->subtotal, 0.0005);
    }

    /**
     * «ميزانية نهائية» — الإضافةُ **لا** تزيدها.
     *
     * الزبون قال «ثلاثون للطلب كلّه». فالكرتُ والشريطةُ داخلَها لا فوقها:
     * يُخصمان من الرفّ ويدخلان التكلفة، ولا يُضافان إلى ما يدفع.
     */
    public function test_a_final_budget_does_not_move_when_addons_are_chosen(): void
    {
        $this->sell(['mode' => CustomArrangement::MODE_BUDGET, 'price' => 30, 'flower_value' => null], [
            ['addon_id' => $this->card->id, 'qty' => 1],
            ['addon_id' => $this->ribbon->id, 'qty' => 2],
        ])->assertOk();

        $order = $this->order();

        $this->assertEqualsWithDelta(30.0, (float) $order->subtotal, 0.0005, 'الإضافاتُ رفعت الميزانية النهائيّة');
        $this->assertEqualsWithDelta(0.0, (float) $order->items->first()->addons_total, 0.0005);
    }

    /** والإضافاتُ تُخصم من الرفّ في الوضعين سواء — الثمنُ يختلف لا الاستهلاك */
    public function test_a_budget_still_consumes_what_it_used(): void
    {
        $cardStock = (int) Product::find($this->card->inventory_product_id)->quantity;

        $this->sell(['mode' => CustomArrangement::MODE_BUDGET, 'price' => 30], [
            ['addon_id' => $this->card->id, 'qty' => 1],
        ])->assertOk();

        $this->assertSame(
            $cardStock - 1,
            (int) Product::find($this->card->inventory_product_id)->quantity,
            'الكرتُ لم يُخصم في وضع الميزانية',
        );
    }

    /** والضريبةُ من إعدادات المتجر لا من نسبةٍ مكتوبة */
    public function test_vat_follows_the_shops_own_settings(): void
    {
        foreach (['vat_enabled' => '1', 'vat_rate' => '5', 'tax_mode' => 'exclusive'] as $k => $v) {
            Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => $k], ['value' => $v]);
        }

        $this->sell(['mode' => CustomArrangement::MODE_BUDGET, 'price' => 30])->assertOk();

        $order = $this->order();
        $this->assertEqualsWithDelta(1.5, (float) $order->tax, 0.0005, 'الضريبةُ ليست ٥٪ من ٣٠');
        $this->assertEqualsWithDelta(31.5, (float) $order->total, 0.0005);
    }

    /** ومشمولةً تُستخرَج ولا تُضاف — كما لكلّ بندٍ آخر */
    public function test_an_inclusive_budget_is_the_total_not_the_net(): void
    {
        foreach (['vat_enabled' => '1', 'vat_rate' => '5', 'tax_mode' => 'inclusive'] as $k => $v) {
            Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => $k], ['value' => $v]);
        }

        $this->sell(['mode' => CustomArrangement::MODE_BUDGET, 'price' => 30])->assertOk();

        $order = $this->order();
        $this->assertEqualsWithDelta(30.0, (float) $order->total, 0.0005, 'المشمولةُ رفعت ما قاله الزبون');
        $this->assertEqualsWithDelta(1.429, (float) $order->tax, 0.002);
    }

    /** وسعرٌ صفرٌ أو سالب لا يُقبل */
    public function test_a_zero_price_is_refused(): void
    {
        $this->sell(['price' => 0])->assertStatus(422);
        $this->assertSame(0, Order::count());
    }

    /* ══════════════ ٢ · التكلفة ══════════════ */

    /**
     * تكلفةُ الموادّ تُحسب في الخادم — ولو أرسل المتصفّح غيرها.
     *
     * ٨×٠٫٥ + ٦×٠٫٦ + ١×٠٫٨ = ٨٫٤
     */
    public function test_material_cost_is_computed_from_the_shelf_not_from_the_request(): void
    {
        /*
         * والكذبُ يُرسَل في الموضعين معًا: مجموعًا وفي كلّ مادّة.
         *
         * جُرّبت طفرةٌ تقرأ `unit_cost` من صفّ المادّة فنجت — لأنّ الاختبار
         * كان يكذب في المجموع وحدَه ولا يرسل للمادّة تكلفةً تُقرأ. فحارسٌ
         * يشهد على بابٍ ويترك بابًا.
         */
        $this->sell(['material_cost' => 999, 'components' => [
            ['product_id' => $this->white->id, 'quantity' => 8, 'kind' => 'flower', 'unit_cost' => 99, 'total_cost' => 999],
            ['product_id' => $this->pink->id, 'quantity' => 6, 'kind' => 'flower', 'unit_cost' => 99],
            ['product_id' => $this->bag->id, 'quantity' => 1, 'kind' => 'packaging', 'unit_cost' => 99],
        ]])->assertOk();

        $item = $this->order()->items->first();

        $this->assertEqualsWithDelta(8.4, (float) $item->cost, 0.0005, 'التكلفةُ قُرئت من الطلب');

        $white = $item->components->firstWhere('sku', 'ROSE-WHITE');
        $this->assertEqualsWithDelta(0.5, (float) $white->unit_cost, 0.0005, 'تكلفةُ المادّة قُرئت من الطلب');
        $this->assertEqualsWithDelta(4.0, (float) $white->total_cost, 0.0005);
    }

    /** ولا يتحرّك ربحُ الماضي حين يرتفع سعرُ المورّد اليوم */
    public function test_a_later_cost_change_does_not_move_a_past_order(): void
    {
        $this->sell()->assertOk();
        $item = $this->order()->items->first();
        $before = (float) $item->cost;

        $this->white->update(['cost' => 5]);

        $this->assertEqualsWithDelta($before, (float) $item->fresh()->cost, 0.0005);
        $this->assertEqualsWithDelta(0.5, (float) $item->components->first()->unit_cost, 0.0005);
    }

    /* ══════════════ ٣ · المخزون ══════════════ */

    /** الورد والتغليف والإضافات — كلُّها تَنقص */
    public function test_every_tracked_material_leaves_the_shelf(): void
    {
        $this->sell([], [
            ['addon_id' => $this->card->id, 'qty' => 1],
            ['addon_id' => $this->ribbon->id, 'qty' => 1],
        ])->assertOk();

        $this->assertSame(42, (int) $this->white->fresh()->quantity);
        $this->assertSame(34, (int) $this->pink->fresh()->quantity);
        $this->assertSame(19, (int) $this->bag->fresh()->quantity);
        $this->assertSame(29, (int) Product::find($this->card->inventory_product_id)->quantity);
        $this->assertSame(24, (int) Product::find($this->ribbon->inventory_product_id)->quantity);
    }

    /** وإضافةٌ خدمةٌ لا رصيدَ لها لا تُخصم — ولا تُسقط البيعة */
    public function test_a_service_addon_consumes_nothing(): void
    {
        $before = InventoryMovement::count();

        $this->sell([], [['addon_id' => $this->wrapService->id, 'qty' => 1]])->assertOk();

        // حركاتُ الموادّ الثلاث وحدَها — لا رابعةَ للخدمة
        $this->assertSame($before + 3, InventoryMovement::count());
    }

    /** ولا تُخصم المادّةُ مرّتين: لا هي ولا بندُها */
    public function test_nothing_is_deducted_twice(): void
    {
        $this->sell()->assertOk();

        $moves = InventoryMovement::where('product_id', $this->white->id)->get();
        $this->assertCount(1, $moves, 'الوردُ الأبيض خرج بحركتين لبيعةٍ واحدة');
        $this->assertSame('-8', $moves->first()->quantity);
    }

    /**
     * ومادّةٌ واحدة في طلبٍ مخصَّص وفي باقةٍ ذاتِ وصفةٍ تُجمع ثمّ تُرفع مرّةً.
     *
     * ═══ ولمَ هذا أخطرُ ما في الباب ═══
     *
     * الوصفةُ تسمح بالكسر والرفُّ أعدادٌ صحيحة، فالرفعُ لازم. ورفعُ كلٍّ على
     * حدة يُنقص الرفَّ وردةً لم تخرج منه: ٢٫٥ + ٠٫٥ = ٣، ولو رُفع كلٌّ وحدَه
     * لَصارا ٣ + ١ = ٤.
     */
    public function test_a_shared_material_is_summed_before_it_is_rounded(): void
    {
        $bouquet = Product::create([
            'business_id' => $this->shop->id, 'name' => 'بوكيه', 'price' => 10,
            'cost' => 0, 'quantity' => 0, 'active' => true,
        ]);
        RecipeItem::create([
            'business_id' => $this->shop->id, 'product_id' => $bouquet->id,
            'component_product_id' => $this->white->id, 'quantity' => 2.5,
        ]);

        $this->actingAs($this->cashier)->postJson('/pos/checkout', [
            'items' => [
                ['id' => $bouquet->id, 'name' => 'بوكيه', 'qty' => 1],
                [
                    'name' => 'تنسيق ورد مخصص', 'qty' => 1,
                    'custom' => $this->custom(['components' => [
                        ['product_id' => $this->white->id, 'quantity' => 0.5, 'kind' => 'flower'],
                    ]]),
                ],
            ],
            'payment_method' => 'نقدي',
            'client_uuid' => uniqid('m', true),
        ])->assertOk();

        // ٢٫٥ + ٠٫٥ = ٣ بالضبط — لا أربع
        $this->assertSame(47, (int) $this->white->fresh()->quantity, 'رُفع الكسرُ مرّتين');
    }

    /** والحركةُ تُقيَّد برقم الطلب — فيُعرف من أين خرجت */
    public function test_the_movement_names_the_order_it_belongs_to(): void
    {
        $this->sell()->assertOk();

        $move = InventoryMovement::where('product_id', $this->white->id)->firstOrFail();
        $this->assertSame(StockLedger::RECIPE, $move->type);
        $this->assertSame($this->order()->number, $move->note);
        $this->assertSame('ورد أبيض', $move->product_name);
    }

    /* ══════════════ ٤ · الفرع ══════════════ */

    /** الخصمُ من فرع الصندوق — ولا يُمسّ غيرُه */
    public function test_only_the_selling_branch_loses_stock(): void
    {
        BranchStock::updateOrCreate(
            ['business_id' => $this->shop->id, 'branch_id' => $this->khoud->id, 'product_id' => $this->white->id],
            ['quantity' => 50],
        );
        BranchStock::updateOrCreate(
            ['business_id' => $this->shop->id, 'branch_id' => $this->seeb->id, 'product_id' => $this->white->id],
            ['quantity' => 30],
        );
        $this->white->update(['quantity' => 80]);

        session(['pos_branch_id' => $this->khoud->id]);
        $this->sell()->assertOk();

        $here = BranchStock::where('branch_id', $this->khoud->id)->where('product_id', $this->white->id)->first();
        $there = BranchStock::where('branch_id', $this->seeb->id)->where('product_id', $this->white->id)->first();

        $this->assertSame(42, (int) $here->quantity, 'فرعُ البيع لم يَنقص ثمانيًا');
        $this->assertSame(30, (int) $there->quantity, 'فرعٌ آخر نقص بلا بيعة');
    }

    /* ══════════════ ٥ · المخزون غير الكافي ══════════════ */

    /** يُمنع البيعُ ويُقال المتوفر والمطلوب — بسياسة المتجر نفسِها */
    public function test_a_short_shelf_refuses_the_sale_and_says_the_numbers(): void
    {
        $this->white->update(['quantity' => 5]);

        $res = $this->sell();

        $res->assertStatus(422);
        $this->assertStringContainsString('ورد أبيض', json_encode($res->json(), JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('5', json_encode($res->json(), JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, Order::count(), 'كُتب طلبٌ رغم نقص الرفّ');
        $this->assertSame(5, (int) $this->white->fresh()->quantity);
    }

    /** ومن أذن بالسالب صراحةً يُباع له — لا سياسةَ ثانية */
    public function test_a_shop_that_allows_negative_stock_is_obeyed(): void
    {
        Setting::updateOrCreate(
            ['business_id' => $this->shop->id, 'key' => 'allow_negative_stock'],
            ['value' => '1'],
        );
        $this->white->update(['quantity' => 5]);

        $this->sell()->assertOk();
        $this->assertSame(-3, (int) $this->white->fresh()->quantity);
    }

    /* ══════════════ ٦ · الحصر بالمتجر ══════════════ */

    /** لا يُبنى طلبٌ بوردِ متجرٍ آخر — ولو أُرسل معرّفُه */
    public function test_a_material_from_another_shop_is_refused(): void
    {
        $other = Business::create(['name' => 'محل الجار', 'status' => 'نشط']);
        $theirs = Product::create([
            'business_id' => $other->id, 'name' => 'ورد الجار',
            'price' => 1, 'cost' => 1, 'quantity' => 100, 'active' => true,
        ]);

        $this->sell(['components' => [
            ['product_id' => $theirs->id, 'quantity' => 5, 'kind' => 'flower'],
        ]])->assertStatus(422);

        $this->assertSame(0, Order::count());
        $this->assertSame(100, (int) $theirs->fresh()->quantity, 'نقص مخزونُ متجرٍ آخر');
    }

    /** ولا بإضافةِ متجرٍ آخر */
    public function test_an_addon_from_another_shop_is_refused(): void
    {
        $other = Business::create(['name' => 'محل الجار', 'status' => 'نشط']);
        $theirAddon = Addon::create([
            'business_id' => $other->id, 'name' => 'كرت الجار', 'price' => 1, 'active' => true,
        ]);

        $this->sell([], [['addon_id' => $theirAddon->id, 'qty' => 1]])->assertStatus(422);
        $this->assertSame(0, Order::count());
    }

    /** وصنفٌ أُوقف عن البيع لا يُركَّب في باقة */
    public function test_a_discontinued_material_is_refused(): void
    {
        $this->white->update(['active' => false]);

        $this->sell()->assertStatus(422);
        $this->assertSame(0, Order::count());
    }

    /* ══════════════ ٧ · اللقطة والتجهيز ══════════════ */

    /** الموادُّ تُحفظ بأسمائها ورموزها وتكاليفها — لا بمعرّفاتٍ تُقرأ لاحقًا */
    public function test_the_order_keeps_a_readable_record_of_what_went_into_it(): void
    {
        $this->sell()->assertOk();
        $item = $this->order()->items->first();

        $this->assertCount(3, $item->components);

        $white = $item->components->firstWhere('sku', 'ROSE-WHITE');
        $this->assertSame('ورد أبيض', $white->name);
        $this->assertEqualsWithDelta(8.0, (float) $white->quantity, 0.0005);
        $this->assertEqualsWithDelta(4.0, (float) $white->total_cost, 0.0005);
        $this->assertSame('flower', $white->kind);
        $this->assertFalse($white->restockable, 'وردٌ رُكّب يُعدّ قابلًا للردّ');

        $bag = $item->components->firstWhere('sku', 'BAG-BLACK');
        $this->assertSame('packaging', $bag->kind);
        $this->assertTrue($bag->restockable, 'تغليفٌ لم يُفتح يُعدّ غيرَ قابلٍ للردّ');
    }

    /** ويبقى مفهومًا ولو حُذف الصنفُ من الكتالوج */
    public function test_the_record_survives_the_deletion_of_the_product(): void
    {
        $this->sell()->assertOk();
        $item = $this->order()->items->first();

        $this->white->delete();

        $row = $item->fresh()->components->firstWhere('sku', 'ROSE-WHITE');
        $this->assertNotNull($row, 'سطرُ التاريخ مُحي بحذف صنف');
        $this->assertSame('ورد أبيض', $row->name);
    }

    /** ووصفُ الطلب يُحفظ: الوضعُ والألوانُ والتغليفُ وملاحظاتُ المنسّق */
    public function test_the_arrangement_remembers_how_it_was_described(): void
    {
        $this->sell()->assertOk();
        $details = $this->order()->items->first()->custom_details;

        $this->assertSame(CustomArrangement::MODE_VALUE, $details['mode']);
        $this->assertSame(['أبيض', 'وردي'], $details['colors']);
        $this->assertSame('كيس أسود', $details['packaging_label']);
        $this->assertSame('الأبيض أكثر من الوردي', $details['florist_notes']);
        $this->assertEqualsWithDelta(8.4, (float) $details['material_cost'], 0.0005);
    }

    /** ورسالةُ الكرت تُحفظ في مكانها القائم — لا حقلَ ثانٍ لها */
    public function test_the_card_message_uses_the_field_it_already_has(): void
    {
        $this->sell([], [], ['card_message' => 'مبروك التخرج'])->assertOk();

        $this->assertSame('مبروك التخرج', $this->order()->card_message);
    }

    /* ══════════════ ٨ · الفاتورة والمالية ══════════════ */

    /** الفاتورةُ تقول «تنسيق ورد مخصص» ولا تفشي موادَّه */
    public function test_the_customer_paper_names_the_line_and_hides_its_materials(): void
    {
        $this->sell()->assertOk();
        $order = $this->order();

        $html = DocumentRenderer::saleSheet(
            $this->shop->id,
            $order,
            DocumentTemplates::settings($this->shop->id, 'sale'),
        );

        $this->assertStringContainsString('تنسيق ورد مخصص', $html);
        $this->assertStringNotContainsString('ROSE-WHITE', $html, 'رمزُ الورد على ورقة الزبون');
        $this->assertStringNotContainsString('الأبيض أكثر من الوردي', $html, 'ملاحظةُ المنسّق على ورقة الزبون');
    }

    /** والمالية تقرأ البيعةَ كأيّ بيعةٍ أخرى — لا مسارَ ثانٍ */
    public function test_the_sale_reaches_finance_like_any_other(): void
    {
        $this->sell(['mode' => CustomArrangement::MODE_BUDGET, 'price' => 30])->assertOk();

        $order = $this->order();
        $tx = Transaction::where('order_id', $order->id)->firstOrFail();

        $this->assertSame('دخل', $tx->type);
        $this->assertEqualsWithDelta((float) $order->total, (float) $tx->amount, 0.0005);
    }

    /* ══════════════ ٩ · ما قبل الدفع ══════════════ */

    /** سلّةٌ لم تُدفع لا تُنقص الرفَّ — لا يوجد حجزٌ ولا خصمٌ مبكّر */
    public function test_nothing_leaves_the_shelf_before_the_sale_is_paid(): void
    {
        $before = (int) $this->white->fresh()->quantity;

        // التعليقُ يكتب طلبًا معلَّقًا ولا يمسّ مخزونًا
        $this->actingAs($this->cashier)->postJson('/pos/hold', [
            'items' => [[
                'name' => 'تنسيق ورد مخصص', 'qty' => 1, 'custom' => $this->custom(),
            ]],
            'kind' => 'hold',
        ])->assertOk();

        $this->assertSame($before, (int) $this->white->fresh()->quantity, 'التعليقُ خصم من الرفّ');
        $this->assertSame(0, InventoryMovement::count());
    }

    /** وسلّةٌ عُلّقت تعود بموادّها — لا سطرًا بسعرٍ بلا مواد */
    public function test_a_held_arrangement_comes_back_with_its_materials(): void
    {
        $this->actingAs($this->cashier)->postJson('/pos/hold', [
            'items' => [[
                'name' => 'تنسيق ورد مخصص', 'qty' => 1, 'custom' => $this->custom(),
            ]],
            'kind' => 'hold',
        ])->assertOk();

        $held = Order::where('is_held', true)->latest('id')->firstOrFail();

        $this->actingAs($this->cashier)->get(route('pos.orders.resume', $held->id))->assertRedirect();

        $cart = session('resume_cart');
        $this->assertNotNull($cart);
        $custom = $cart['items'][0]['custom'];
        $this->assertNotNull($custom, 'الباقةُ عادت بلا موادّ — تُباع فلا يَنقص الرفّ');
        $this->assertCount(3, $custom['components']);
        $this->assertEqualsWithDelta(20.0, (float) $custom['price'], 0.0005);
    }

    /* ══════════════ ١٠ · ولا يُبدَّل البيعُ القائم ══════════════ */

    /** باقةٌ عاديّةٌ ذاتُ وصفةٍ تُباع كما كانت — الميزةُ إضافةٌ لا بديل */
    public function test_an_ordinary_recipe_bouquet_still_sells_unchanged(): void
    {
        $bouquet = Product::create([
            'business_id' => $this->shop->id, 'name' => 'بوكيه الحب', 'price' => 18,
            'cost' => 0, 'quantity' => 0, 'active' => true,
        ]);
        RecipeItem::create([
            'business_id' => $this->shop->id, 'product_id' => $bouquet->id,
            'component_product_id' => $this->white->id, 'quantity' => 12,
        ]);

        $this->actingAs($this->cashier)->postJson('/pos/checkout', [
            'items' => [['id' => $bouquet->id, 'name' => 'بوكيه الحب', 'qty' => 1]],
            'payment_method' => 'نقدي',
            'client_uuid' => uniqid('o', true),
        ])->assertOk();

        $this->assertSame(38, (int) $this->white->fresh()->quantity);
        $this->assertSame(0, (int) $bouquet->fresh()->quantity, 'خُصمت الباقةُ وموادُّها معًا');
        $this->assertCount(0, $this->order()->items->first()->components);
    }

    /* ══════════════ ١١ · الكميّة ══════════════ */

    /**
     * باقتان متماثلتان تأخذان ضِعفَ الموادّ.
     *
     * ═══ والعطبُ الذي يحرسه هذا ═══
     *
     * اللقطةُ تصف باقةً واحدة، والسعرُ يُضرب في الكميّة. فضغطةُ «+» في
     * السلّة كانت تُضاعف ما يدفعه الزبون ولا تُضاعف ما يخرج من الدلو:
     * تُباع باقتان ويُخصم وردُ واحدة، ولا يظهر الفارقُ إلّا في الجرد.
     */
    public function test_two_arrangements_take_twice_the_materials(): void
    {
        $this->sell([], [], [], 2)->assertOk();

        $this->assertSame(34, (int) $this->white->fresh()->quantity);
        $this->assertSame(28, (int) $this->pink->fresh()->quantity);
        $this->assertSame(18, (int) $this->bag->fresh()->quantity);
    }

    /** واللقطةُ تبقى لوحدةٍ واحدة — وصفُ باقةٍ لا مجموعُ الشحنة */
    public function test_the_snapshot_still_describes_one_arrangement(): void
    {
        $this->sell([], [], [], 2)->assertOk();

        $item = $this->order()->items->first();
        $this->assertSame(2, (int) $item->quantity);
        $this->assertSame(8.0, (float) $item->components->firstWhere('sku', 'ROSE-WHITE')->quantity);
        // تكلفةُ الوحدة كما لكلّ بندٍ آخر — لا تكلفةُ البند كلِّه
        $this->assertSame(8.4, round((float) $item->cost, 3), 'تكلفةُ الوحدة صارت تكلفةَ الشحنة');
    }

    /** ورفٌّ يكفي واحدةً يمنع اثنتين — بالحساب نفسِه الذي يخصم */
    public function test_a_shelf_that_fits_one_refuses_two(): void
    {
        $this->white->update(['quantity' => 10]);

        $this->sell([], [], [], 2)->assertStatus(422);

        $this->assertSame(0, Order::count(), 'كُتب طلبٌ لباقتين والرفُّ يكفي واحدة');
        $this->assertSame(10, (int) $this->white->fresh()->quantity);
    }

    /* ══════════════ ١٢ · الإلغاء والإرجاع ══════════════ */

    /**
     * الإلغاءُ يردّ التغليفَ ولا يردّ وردًا قُصّ.
     *
     * والسياسةُ مكتوبةٌ في الصفّ يومَ البيع (`restockable`) لا تُخمَّن يوم
     * الإلغاء: كيسٌ لم يُفتح يعود، ووردٌ رُكّب لا يعود إلى الدلو.
     */
    public function test_cancelling_returns_the_packaging_not_the_cut_flowers(): void
    {
        $this->sell()->assertOk();
        $order = $this->order();

        OrderCorrection::cancel($order, 'اعتذر الزبون');

        $this->assertSame(20, (int) $this->bag->fresh()->quantity, 'الكيسُ بقي منقوصًا وهو في الدرج');
        $this->assertSame(42, (int) $this->white->fresh()->quantity, 'عاد وردٌ قُصّ إلى الدلو');
        $this->assertSame(34, (int) $this->pink->fresh()->quantity);
    }

    /** والمردودُ حركةٌ باسم الطلب — لا رصيدٌ يظهر بلا سبب */
    public function test_the_returned_packaging_is_a_named_movement(): void
    {
        $this->sell()->assertOk();
        $order = $this->order();

        OrderCorrection::cancel($order, 'اعتذر الزبون');

        $move = InventoryMovement::where('product_id', $this->bag->id)
            ->where('type', StockLedger::CORRECTION)->firstOrFail();

        $this->assertSame('+1', $move->quantity);
        $this->assertSame($order->number, $move->note);
    }

    /** وإلغاءُ باقتين يردّ كيسين — المردودُ بكميّة البند */
    public function test_cancelling_two_arrangements_returns_two_bags(): void
    {
        $this->sell([], [], [], 2)->assertOk();

        OrderCorrection::cancel($this->order(), 'اعتذر الزبون');

        $this->assertSame(20, (int) $this->bag->fresh()->quantity);
    }

    /** ونقصانُ الكميّة يردّ حصّةَ ما نقص وحدَها */
    public function test_lowering_the_quantity_returns_one_bag_only(): void
    {
        $this->sell([], [], [], 2)->assertOk();
        $order = $this->order();

        OrderCorrection::setQuantity($order, $order->items->first(), 1, 'اكتفى بواحدة');

        $this->assertSame(19, (int) $this->bag->fresh()->quantity);
        $this->assertSame(34, (int) $this->white->fresh()->quantity, 'عاد وردٌ قُصّ');
    }

    /**
     * ورفعُ الكميّة يأخذ الباقةَ كاملةً ثانيةً — لا تغليفَها وحدَه.
     *
     * والطريقان غيرُ متماثلين عن قصد: باقةٌ ثانيةٌ تُركَّب من وردٍ وتغليفٍ
     * كما رُكّبت الأولى، ولو أخذ التصحيحُ ما يردّه فقط لخرجت باقةٌ بكيسٍ
     * بلا ورد.
     */
    public function test_raising_the_quantity_takes_the_whole_arrangement_again(): void
    {
        $this->sell()->assertOk();
        $order = $this->order();

        OrderCorrection::setQuantity($order, $order->items->first(), 2, 'طلب ثانية');

        $this->assertSame(34, (int) $this->white->fresh()->quantity);
        $this->assertSame(28, (int) $this->pink->fresh()->quantity);
        $this->assertSame(18, (int) $this->bag->fresh()->quantity);
    }

    /** ولا يتجاوز حدَّ المخزون — التصحيحُ ليس بابًا خلفيًّا */
    public function test_raising_the_quantity_is_refused_by_a_short_shelf(): void
    {
        $this->white->update(['quantity' => 10]);
        $this->sell()->assertOk();
        $order = $this->order();

        try {
            OrderCorrection::setQuantity($order, $order->items->first(), 2, 'طلب ثانية');
            $this->fail('رُفعت الكميّة والرفُّ لا يكفي');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ورد أبيض', $e->getMessage());
        }

        $this->assertSame(2, (int) $this->white->fresh()->quantity, 'خُصم رغم المنع');
    }

    /** وصنفٌ حُذف من الكتالوج لا يُسقط الإلغاء — واللقطةُ تبقى مقروءة */
    public function test_a_deleted_material_does_not_break_the_cancellation(): void
    {
        $this->sell()->assertOk();
        $order = $this->order();
        $this->bag->delete();

        OrderCorrection::cancel($order, 'اعتذر الزبون');

        $row = $order->items->first()->fresh()->components->firstWhere('sku', 'BAG-BLACK');
        $this->assertSame('كيس أسود', $row->name);
        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
    }
}
