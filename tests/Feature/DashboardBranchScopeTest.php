<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\User;
use App\Support\DashboardMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لوحة التحكم يجب أن تتبع الفرع المختار في كل أرقام المبيعات، لا في بعض
 * البطاقات والطلبات الحديثة فقط. هذا الحارس يثبت أن المخطط وتوزيع الدفع
 * يقرآن نفس النطاق الذي تقرؤه بطاقات اللوحة.
 */
class DashboardBranchScopeTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;
    private Branch $muscat;
    private Branch $salalah;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجر الفروع', 'status' => 'نشط']);
        $this->owner = User::create([
            'business_id' => $this->business->id,
            'name' => 'المالك',
            'email' => 'dashboard-branch@abaadapp.om',
            'password' => bcrypt('secret12345'),
            'role' => 'admin',
            'status' => 'نشط',
        ]);
        $this->muscat = Branch::create(['business_id' => $this->business->id, 'name' => 'مسقط']);
        $this->salalah = Branch::create(['business_id' => $this->business->id, 'name' => 'صلالة']);

        $this->actingAs($this->owner);
    }

    private function sale(Branch $branch, int $number, float $total, string $method): void
    {
        Order::create([
            'business_id' => $this->business->id,
            'branch_id' => $branch->id,
            'number' => $number,
            'customer_name' => 'عميل',
            'subtotal' => $total,
            'tax' => 0,
            'discount' => 0,
            'total' => $total,
            'payment_method' => $method,
            'status' => 'مكتمل',
            'is_held' => false,
            'ordered_at' => now(),
        ]);
    }

    public function test_dashboard_sales_trend_follows_the_selected_branch(): void
    {
        $this->sale($this->muscat, 101, 10, 'نقدي');
        $this->sale($this->salalah, 102, 90, 'بطاقة');
        session(['current_branch' => $this->muscat->id]);

        $trend = DashboardMetrics::salesYear();

        $this->assertSame(10.0, (float) array_sum(array_filter($trend['data'], fn ($v) => $v !== null)));
    }

    public function test_dashboard_payment_distribution_follows_the_selected_branch(): void
    {
        $this->sale($this->muscat, 201, 10, 'نقدي');
        $this->sale($this->salalah, 202, 90, 'بطاقة');
        session(['current_branch' => $this->muscat->id]);

        $distribution = DashboardMetrics::paymentDistribution();

        $this->assertSame([__('نقدي')], $distribution['labels']);
        $this->assertSame([10.0], array_map('floatval', $distribution['series']));
    }
}
