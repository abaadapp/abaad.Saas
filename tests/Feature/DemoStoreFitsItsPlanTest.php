<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Support\DemoStore;
use App\Support\PlanLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المتجر التجريبيّ لا يُولد عند سقف باقته.
 *
 * الديمو هو الوعد لا موضع تحصيله: من يُعرض عليه النظام يُجرّب فيه، وأوّل ما
 * يُجرّبه الإضافة. وكان المتجر يُبذر بثلاثة فروع على باقةٍ تأذن بثلاثة —
 * فيُردّ أوّلُ «أضف فرعًا» بـ«بلغت حدّ باقتك» أمام من يُشترى له النظام.
 *
 * والعطب من نوعٍ لا يظهر في متجرٍ صغير ولا في اختبارٍ يُنشئ صفًّا واحدًا:
 * يظهر في المتجر الممتلئ وحده — وهو حالُ كلّ متجرٍ يُعرض.
 */
class DemoStoreFitsItsPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // سقوف الإنتاج نفسها: افتراضاتُ الأعمدة أضيق منها، فاختبارٌ يعتمد
        // عليها يقيس متجرًا لا وجود له
        Plan::updateOrCreate(['name' => 'الباقة الاحترافية'], [
            'monthly_price' => 30, 'yearly_price' => 300,
            'max_branches' => 3, 'max_employees' => 15, 'max_products' => 100000,
        ]);
    }

    public static function sizes(): array
    {
        return array_map(fn ($s) => [$s], array_keys(DemoStore::SIZES));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sizes')]
    public function test_no_ceiling_is_reached_at_birth(string $size): void
    {
        $business = DemoStore::create("متجر {$size}", $size);

        foreach (['branches', 'employees', 'products'] as $key) {
            $this->assertFalse(
                PlanLimits::reached($business, $key),
                sprintf(
                    'المتجر التجريبيّ «%s» يُولد عند سقف «%s»: %d من %s — فلا يُضاف إليه واحد',
                    $size, $key, PlanLimits::used($business->id, $key), PlanLimits::cap($business, $key),
                ),
            );
        }
    }

    public function test_a_branch_can_actually_be_added_in_the_demo(): void
    {
        $business = DemoStore::create('متجر تجريبي', 'متوسط');
        $owner = $business->users()->where('role', 'admin')->first();

        $this->actingAs($owner)
            ->post(route('admin.branches.store'), ['name' => 'فرع صحار', 'phone' => '+968 24000002'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('branches', ['business_id' => $business->id, 'name' => 'فرع صحار']);
    }

    /** وعدّاد الفروع في لوحة المنصّة يقول ما في القاعدة لا رقمًا مكتوبًا بيد */
    public function test_the_branch_counter_matches_the_rows(): void
    {
        $business = DemoStore::create('متجر تجريبي', 'صغير');

        $this->assertSame(
            \App\Models\Branch::where('business_id', $business->id)->count(),
            (int) $business->branches_count,
        );
    }
}
