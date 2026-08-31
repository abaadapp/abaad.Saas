<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Support\Waste;
use App\Support\WasteInsights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تحليلات الهالك — قراءةٌ فوق تعديلات المخزون القائمة.
 *
 * وأكثر ما يُحرس هنا شيئان: أنّ الصفوف القديمة لا تُمسّ، وأنّ الملاحظات لا
 * تُقال على بياناتٍ قليلة. «هلك مئة بالمئة» جملةٌ صحيحة حسابيًّا حين بيعت
 * قطعةٌ وهلكت قطعة، وكاذبةٌ عمليًّا — وثلاثُ جملٍ كهذه تُفقد الشاشة كلَّها
 * مصداقيّتها.
 */
class WasteAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;
    private Branch $branch;
    private Product $rose;
    private Product $tulip;
    private Category $flowers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل الهالك', 'email' => 'w@test.local', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الخوض']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'مالك', 'email' => 'owner@w.local',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->flowers = Category::create(['business_id' => $this->business->id, 'name' => 'ورود']);

        $this->rose = Product::create([
            'business_id' => $this->business->id, 'category_id' => $this->flowers->id,
            'name' => 'ورد أبيض', 'price' => 2, 'cost' => 1, 'quantity' => 500, 'active' => true,
        ]);

        $this->tulip = Product::create([
            'business_id' => $this->business->id, 'category_id' => $this->flowers->id,
            'name' => 'توليب', 'price' => 3, 'cost' => 2, 'quantity' => 200, 'active' => true,
        ]);
    }

    private function adjust(Product $product, float $delta, string $reason, ?string $at = null, ?Branch $branch = null, float $cost = 1): StockAdjustment
    {
        return StockAdjustment::create([
            'business_id' => $this->business->id,
            'branch_id' => ($branch ?? $this->branch)->id,
            'product_id' => $product->id,
            'number' => StockAdjustment::nextNumber($this->business->id),
            'quantity_delta' => $delta,
            'cost_at_time' => $cost,
            'reason' => $reason,
            'adjusted_at' => $at ?? now()->toDateString(),
        ]);
    }

    private function window(): array
    {
        return ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()];
    }

    /* ------------------------------ التصنيف ------------------------------ */

    public function test_only_damage_and_loss_count_as_waste(): void
    {
        $this->assertTrue(Waste::isWaste('تلف'));
        $this->assertTrue(Waste::isWaste('فقد'));
        // «جرد» تصحيحُ عدٍّ لا خسارة، و«إهداء» خروجٌ بقرارٍ لا بحادث
        $this->assertFalse(Waste::isWaste('جرد'));
        $this->assertFalse(Waste::isWaste('إهداء'));
        $this->assertFalse(Waste::isWaste('تصحيح'));
        $this->assertFalse(Waste::isWaste(null));
    }

    public function test_a_stocktake_is_not_counted_in_the_waste_totals(): void
    {
        $this->adjust($this->rose, -10, 'تلف');
        $this->adjust($this->rose, -40, 'جرد');

        $this->assertEqualsWithDelta(10.0, Waste::totals($this->business->id, $this->window())['quantity'], 0.001);
    }

    /* ------------------------------- الإشارة ----------------------------- */

    public function test_a_new_damage_entry_always_decreases_stock(): void
    {
        $this->actingAs($this->owner)->post(route('admin.inventory.adjustments.store'), [
            'branch_id' => $this->branch->id,
            'product_id' => $this->rose->id,
            // موجبةٌ كما ينطقها الإنسان — والخادم يجعلها سالبة
            'quantity_delta' => 6,
            'reason' => 'تلف',
            'adjusted_at' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(-6.0, (float) StockAdjustment::latest('id')->first()->quantity_delta, 0.001);
        $this->assertSame(494, (int) $this->rose->fresh()->quantity);
    }

    public function test_a_new_loss_entry_always_decreases_stock(): void
    {
        $this->actingAs($this->owner)->post(route('admin.inventory.adjustments.store'), [
            'branch_id' => $this->branch->id,
            'product_id' => $this->rose->id, 'quantity_delta' => 3,
            'reason' => 'فقد', 'adjusted_at' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(-3.0, (float) StockAdjustment::latest('id')->first()->quantity_delta, 0.001);
    }

    public function test_a_stocktake_may_still_add_stock(): void
    {
        // القاعدة على الهالك وحده — «جرد» يزيد وينقص كما كان
        $this->actingAs($this->owner)->post(route('admin.inventory.adjustments.store'), [
            'branch_id' => $this->branch->id,
            'product_id' => $this->rose->id, 'quantity_delta' => 7,
            'reason' => 'جرد', 'adjusted_at' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(7.0, (float) StockAdjustment::latest('id')->first()->quantity_delta, 0.001);
        $this->assertSame(507, (int) $this->rose->fresh()->quantity);
    }

    public function test_old_rows_that_break_the_rule_are_reported_not_repaired(): void
    {
        $bad = $this->adjust($this->rose, 6, 'تلف');

        $rows = Waste::suspiciousRows($this->business->id);

        $this->assertCount(1, $rows);
        $this->assertSame($bad->id, $rows[0]['id']);
        // ولم تُمسّ
        $this->assertEqualsWithDelta(6.0, (float) $bad->fresh()->quantity_delta, 0.001);
        // ولا تدخل الأرقام
        $this->assertEqualsWithDelta(0.0, Waste::totals($this->business->id, $this->window())['value'], 0.001);
    }

    /* ------------------------------- القيمة ------------------------------ */

    public function test_the_value_uses_the_cost_of_the_moment_not_of_today(): void
    {
        $this->adjust($this->rose, -10, 'تلف', cost: 1.5);
        $this->rose->update(['cost' => 9]);

        $this->assertEqualsWithDelta(15.0, Waste::totals($this->business->id, $this->window())['value'], 0.001);
    }

    /* ------------------------------ المرشّحات ---------------------------- */

    public function test_the_date_filter_narrows_the_window(): void
    {
        $this->adjust($this->rose, -10, 'تلف', now()->toDateString());
        $this->adjust($this->rose, -50, 'تلف', now()->subMonths(3)->toDateString());

        $this->assertEqualsWithDelta(10.0, Waste::totals($this->business->id, $this->window())['quantity'], 0.001);
    }

    public function test_the_branch_filter_narrows_the_window(): void
    {
        $other = Branch::create(['business_id' => $this->business->id, 'name' => 'السيب']);
        $this->adjust($this->rose, -10, 'تلف');
        $this->adjust($this->rose, -4, 'تلف', branch: $other);

        $filters = $this->window() + ['branch_id' => $this->branch->id];

        $this->assertEqualsWithDelta(10.0, Waste::totals($this->business->id, $filters)['quantity'], 0.001);
    }

    public function test_the_product_and_category_filters_work(): void
    {
        $tools = Category::create(['business_id' => $this->business->id, 'name' => 'أدوات']);
        $scissors = Product::create([
            'business_id' => $this->business->id, 'category_id' => $tools->id,
            'name' => 'مقص', 'price' => 5, 'cost' => 3, 'quantity' => 10,
        ]);

        $this->adjust($this->rose, -10, 'تلف');
        $this->adjust($scissors, -1, 'فقد');

        $byProduct = Waste::totals($this->business->id, $this->window() + ['product_id' => $this->rose->id]);
        $byCategory = Waste::totals($this->business->id, $this->window() + ['category_id' => $tools->id]);

        $this->assertEqualsWithDelta(10.0, $byProduct['quantity'], 0.001);
        $this->assertEqualsWithDelta(1.0, $byCategory['quantity'], 0.001);
    }

    public function test_the_reason_filter_works(): void
    {
        $this->adjust($this->rose, -10, 'تلف');
        $this->adjust($this->rose, -4, 'فقد');

        $this->assertEqualsWithDelta(4.0, Waste::totals($this->business->id, $this->window() + ['reason' => 'فقد'])['quantity'], 0.001);
    }

    /* ------------------------------ التجميع ------------------------------ */

    public function test_grouping_by_product_category_branch_and_reason(): void
    {
        $this->adjust($this->rose, -10, 'تلف');
        $this->adjust($this->tulip, -2, 'فقد', cost: 2);

        $filters = $this->window();

        $this->assertCount(2, Waste::groupedBy($this->business->id, 'product', $filters));
        $this->assertCount(1, Waste::groupedBy($this->business->id, 'category', $filters));
        $this->assertCount(1, Waste::groupedBy($this->business->id, 'branch', $filters));
        $this->assertCount(2, Waste::groupedBy($this->business->id, 'reason', $filters));

        $top = Waste::groupedBy($this->business->id, 'product', $filters)[0];
        $this->assertSame('ورد أبيض', $top['label']);
        $this->assertEqualsWithDelta(10.0, $top['value'], 0.001);
    }

    /* ------------------------------ المقارنة ----------------------------- */

    public function test_the_previous_window_matches_the_length_of_the_current_one(): void
    {
        $window = Waste::previousWindow('2026-08-11', '2026-08-20');   // عشرة أيام

        $this->assertSame('2026-08-01', $window['from']);
        $this->assertSame('2026-08-10', $window['to']);
    }

    /* ------------------------------ الملاحظات ---------------------------- */

    public function test_no_insight_is_offered_on_a_nearly_empty_period(): void
    {
        $this->adjust($this->rose, -1, 'تلف', cost: 0.5);

        $this->assertSame([], WasteInsights::all($this->business->id, $this->window()));
    }

    public function test_a_real_rise_is_reported(): void
    {
        $window = ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->endOfMonth()->toDateString()];
        $previous = Waste::previousWindow($window['from'], $window['to']);

        $this->adjust($this->rose, -100, 'تلف', $window['from'], cost: 1);
        $this->adjust($this->rose, -20, 'تلف', $previous['to'], cost: 1);

        $insights = WasteInsights::all($this->business->id, $window);
        $texts = array_column($insights, 'text');

        $this->assertNotEmpty($insights);
        $this->assertTrue(
            collect($texts)->contains(fn ($x) => str_contains($x, 'ارتفع') || str_contains($x, 'rose')),
            'لم تُذكر الزيادة: '.implode(' | ', $texts),
        );
    }

    public function test_a_single_branch_shop_is_never_told_one_branch_dominates(): void
    {
        $this->adjust($this->rose, -100, 'تلف');

        $texts = array_column(WasteInsights::all($this->business->id, $this->window()), 'text');

        // فرعٌ واحد ليس «أعلى من بقيّة الفروع» — لا بقيّة هناك
        $this->assertFalse(collect($texts)->contains(fn ($x) => str_contains($x, 'الخوض')));
    }

    /* ------------------------------ العزل ------------------------------- */

    public function test_one_shop_never_sees_another_shops_waste(): void
    {
        $other = Business::create(['name' => 'محل آخر', 'email' => 'o@w.local', 'status' => 'نشط']);
        $theirBranch = Branch::create(['business_id' => $other->id, 'name' => 'فرعهم']);
        $theirRose = Product::create([
            'business_id' => $other->id, 'name' => 'وردهم', 'price' => 2, 'cost' => 1, 'quantity' => 100,
        ]);

        StockAdjustment::create([
            'business_id' => $other->id, 'branch_id' => $theirBranch->id, 'product_id' => $theirRose->id,
            'number' => 'SA-000999', 'quantity_delta' => -80, 'cost_at_time' => 1,
            'reason' => 'تلف', 'adjusted_at' => now()->toDateString(),
        ]);

        $this->adjust($this->rose, -10, 'تلف');

        $this->assertEqualsWithDelta(10.0, Waste::totals($this->business->id, $this->window())['quantity'], 0.001);
        $this->assertEqualsWithDelta(80.0, Waste::totals($other->id, $this->window())['quantity'], 0.001);
        $this->assertSame([], Waste::suspiciousRows($this->business->id));
    }

    public function test_the_screen_opens_and_carries_its_numbers(): void
    {
        $this->adjust($this->rose, -10, 'تلف');

        $this->actingAs($this->owner)->get(route('admin.reports.waste'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Reports/Waste')
                ->where('totals.quantity', 10)
                ->where('totals.value', 10));
    }
}
