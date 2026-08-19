<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Billing;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «كم مشتركًا عندي؟» — حقيقةٌ واحدة من مصدرٍ واحد.
 *
 * كان الجواب مكتوبًا في مكانين: `businesses.status` و`ends_at` وهما ما
 * يُقرّر فعلًا دخول التاجر ومنعه، وجدول `subscriptions` وهو ما تقرؤه لوحة
 * المنصّة. والثاني لا يُكتب إلا بضغطة «تجديد» يتذكّرها إنسان — فالتاجر
 * الذي أُضيف ودفع ولم يُضغط له الزرّ كان يظهر في اللوحة صفرًا.
 *
 * والتجربة ليست اشتراكًا: من دخل بأربعة عشر يومًا مجّانًا لا هو إيراد ولا
 * هو مدين.
 */
class PlatformSubscriberCountTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = Plan::create(['name' => 'الأساسية', 'monthly_price' => 10, 'yearly_price' => 100]);
        $this->super = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 's@abaad.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
        $this->actingAs($this->super);
    }

    private function business(array $attrs = []): Business
    {
        return Business::create(array_merge([
            'name' => 'متجر', 'status' => 'نشط', 'plan_id' => $this->plan->id,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonth(),
        ], $attrs));
    }

    /** البطاقة كما تصل الشاشة */
    private function card(string $label): ?string
    {
        foreach (Demo::superStats() as $c) {
            if ($c['label'] === __($label)) {
                return (string) $c['value'];
            }
        }

        return null;
    }

    public function test_a_paying_merchant_is_counted_even_though_nobody_pressed_renew(): void
    {
        $b = $this->business();
        Invoice::create([
            'number' => 'INV-1', 'business_id' => $b->id, 'plan_id' => $this->plan->id,
            'amount' => 10, 'issued_at' => now(), 'status' => 'مدفوعة',
        ]);

        $this->assertSame('1', $this->card('المشتركون'));
        $this->assertSame('0', $this->card('في التجربة'));
    }

    public function test_a_merchant_in_his_trial_is_not_counted_as_a_subscriber(): void
    {
        $this->business(['starts_at' => now(), 'ends_at' => now()->addDays(14)]);

        $this->assertSame('0', $this->card('المشتركون'), 'المجرّب حُسب مشتركًا');
        $this->assertSame('1', $this->card('في التجربة'));
    }

    public function test_an_expired_merchant_is_neither(): void
    {
        $b = $this->business(['ends_at' => now()->subDay()]);
        Invoice::create([
            'number' => 'INV-2', 'business_id' => $b->id, 'plan_id' => $this->plan->id,
            'amount' => 10, 'issued_at' => now()->subMonths(2), 'status' => 'مدفوعة',
        ]);

        $this->assertSame('0', $this->card('المشتركون'));
        $this->assertSame('0', $this->card('في التجربة'));
    }

    public function test_a_disabled_merchant_is_neither(): void
    {
        $b = $this->business(['status' => 'معطل']);
        Invoice::create([
            'number' => 'INV-3', 'business_id' => $b->id, 'plan_id' => $this->plan->id,
            'amount' => 10, 'issued_at' => now(), 'status' => 'مدفوعة',
        ]);

        $this->assertSame('0', $this->card('المشتركون'));
    }

    /**
     * من جدّد ثلاث مرّات تاجرٌ واحد لا أربعة.
     *
     * جدول الاشتراكات يعدّ دوراتٍ لا تجّارًا: دورةٌ سارية وثلاثٌ منتهية،
     * فتقول الشاشة إنك تخسر وأنت تكسب.
     */
    public function test_three_renewals_are_one_merchant_not_four(): void
    {
        $b = $this->business();
        foreach (range(1, 3) as $i) {
            Billing::renew($b->fresh(), 'monthly');
        }
        Invoice::query()->update(['status' => 'مدفوعة']);

        $stats = Demo::subscriptionStats();

        $this->assertSame(3, Subscription::count(), 'التمهيد نفسه لم يُنشئ ثلاث دورات');
        $this->assertSame(1, $stats['active']);
        $this->assertSame(0, $stats['expired']);
    }

    /** والإيراد المتكرّر من آخر دورةٍ لكل متجر لا من دوراته كلّها */
    public function test_recurring_revenue_counts_the_latest_cycle_only(): void
    {
        $b = $this->business();
        foreach (range(1, 3) as $i) {
            Billing::renew($b->fresh(), 'monthly');
        }
        Invoice::query()->update(['status' => 'مدفوعة']);

        $this->assertSame(10.0, (float) Demo::subscriptionStats()['monthly_revenue']);
    }

    public function test_the_screen_shows_the_three_numbers_apart(): void
    {
        $paid = $this->business(['name' => 'دافع']);
        Invoice::create([
            'number' => 'INV-4', 'business_id' => $paid->id, 'plan_id' => $this->plan->id,
            'amount' => 10, 'issued_at' => now(), 'status' => 'مدفوعة',
        ]);
        $this->business(['name' => 'مجرّب', 'ends_at' => now()->addDays(10)]);
        $this->business(['name' => 'منتهٍ', 'ends_at' => now()->subDay()]);

        $stats = $this->get(route('super-admin.subscriptions.index'))
            ->assertOk()->viewData('page')['props']['stats'];

        $by = collect($stats)->pluck('value', 'label');

        $this->assertSame('1', $by[__('المشتركون')]);
        $this->assertSame('1', $by[__('في التجربة')]);
        $this->assertSame('1', $by[__('منتهية')]);
    }

    /** والمتجر التجريبيّ لا يُعدّ مشتركًا مهما دُفعت فواتيره الوهميّة */
    public function test_a_demo_store_is_never_a_subscriber(): void
    {
        $b = $this->business(['name' => 'ديمو', 'is_demo' => true]);
        Invoice::create([
            'number' => 'INV-5', 'business_id' => $b->id, 'plan_id' => $this->plan->id,
            'amount' => 99, 'issued_at' => now(), 'status' => 'مدفوعة',
        ]);

        $this->assertSame('0', $this->card('المشتركون'));
        $this->assertSame('0', $this->card('في التجربة'));
    }
}
