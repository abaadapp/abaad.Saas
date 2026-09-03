<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\User;
use App\Support\OrderStatus;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صفحة الموظّف عن نفسه — «حسابي».
 *
 * الكاشير لا يدخل لوحة النشاط، فكان زرّ اللوحة يغيب عنه ولا بديل: لا يعرف
 * راتبه ولا كم باع ولا متى التحق. صار له بابٌ واحد يفتح ما يخصّه وحده.
 *
 * وما تفحصه هذه الحالات ليس أنّ الصفحة تُعرض، بل أنّها **لا تعرض غيرَه**:
 * لا راتب زميله، ولا مبيعات المحلّ، ولا وردية سواه. وصفحةٌ «خاصّة» تتسرّب
 * منها أرقام غيرِ صاحبها أسوأ من ألّا تكون.
 */
class EmployeeSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private User $cashier;

    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'صاحب النشاط', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'أحمد', 'email' => 'c@abaad.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
            'job_title' => 'كاشير', 'basic_salary' => 300, 'allowances' => 50,
        ]);
        $this->colleague = User::create([
            'business_id' => $this->business->id, 'name' => 'سالم', 'email' => 's@abaad.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
            'basic_salary' => 900, 'allowances' => 200,
        ]);
    }

    private function page(User $user): array
    {
        return $this->actingAs($user)->get(route('pos.me'))
            ->assertOk()->viewData('page')['props'];
    }

    /** مسيرةٌ بحالةٍ معلومة، وسطرٌ لكل من يُذكر */
    private function payroll(string $status, array $lines, string $period = '2026-07-01'): PayrollRun
    {
        $run = PayrollRun::create([
            'business_id' => $this->business->id, 'number' => 'PR-'.$status.'-'.$period,
            'period' => $period, 'status' => $status,
        ]);

        foreach ($lines as $userId => $net) {
            PayrollLine::create([
                'payroll_run_id' => $run->id, 'user_id' => $userId,
                'employee_name' => User::find($userId)->name,
                'basic' => $net, 'allowances' => 0, 'overtime' => 0, 'deductions' => 0, 'net' => $net,
            ]);
        }

        return $run;
    }

    private function order(User $user, float $total): Order
    {
        return Order::create([
            'business_id' => $this->business->id, 'number' => 'ORD-'.uniqid(),
            'user_id' => $user->id, 'employee_name' => $user->name,
            'status' => OrderStatus::COMPLETED,
            'subtotal' => $total, 'total' => $total, 'ordered_at' => now(),
            'payment_method' => 'نقدي',
        ]);
    }

    /* --------------------------- الوصول --------------------------- */

    /**
     * من لا يملك إلا نقطة البيع يُفتح له الصندوق مباشرةً بعد الدخول — لا
     * لوحةٌ يُردّ عنها بـ403.
     */
    public function test_an_employee_with_only_the_register_lands_on_it(): void
    {
        $this->cashier->update(['permissions' => ['pos']]);

        $this->assertSame(route('pos.index'), Permissions::homeFor($this->cashier->fresh()));
    }

    /**
     * الزرّ في ترويسة الصندوق يقود إلى اللوحة لمن يدخلها، وإلى «حسابي» لمن
     * لا يدخلها. والوجهتان يجب أن تُفتحا فعلًا: بابٌ يُعرض ولا يُفتح أسوأ
     * من بابٍ لا يُعرض.
     */
    public function test_the_header_button_leads_somewhere_that_actually_opens(): void
    {
        // والوجهة تُقرأ من صفحةٍ تُفتح فعلًا: `pos.index` قد تُحوّل إلى
        // إعداد الجهاز، فتُقرأ الإشارة من الصفحة التي نفحصها نفسها
        $props = $this->page($this->cashier);

        $this->assertNull($props['auth']['panelUrl'], 'الكاشير لا يدخل اللوحة');
        $this->assertNotNull($this->page($this->owner)['auth']['panelUrl']);
    }

    /** ومن لا يملك نقطة البيع لا يفتح صفحتها — الحارس نفسه لا حارسٌ ثانٍ */
    public function test_someone_without_the_register_is_refused(): void
    {
        $this->cashier->update(['permissions' => ['dashboard']]);

        $this->actingAs($this->cashier->fresh())->get(route('pos.me'))->assertForbidden();
    }

    /* --------------------------- بياناته --------------------------- */

    public function test_it_shows_his_own_salary(): void
    {
        $props = $this->page($this->cashier);

        $this->assertSame('أحمد', $props['me']['name']);
        $this->assertSame('كاشير', $props['me']['jobTitle']);
        $this->assertEqualsWithDelta(300.0, $props['salary']['basic'], 0.001);
        $this->assertEqualsWithDelta(50.0, $props['salary']['allowances'], 0.001);
        $this->assertEqualsWithDelta(350.0, $props['salary']['monthly'], 0.001);
    }

    public function test_it_shows_his_approved_payslips(): void
    {
        $this->payroll('مصروفة', [$this->cashier->id => 350]);

        $slips = $this->page($this->cashier)['payslips'];

        $this->assertCount(1, $slips);
        $this->assertEqualsWithDelta(350.0, (float) $slips[0]['net'], 0.001);
    }

    /**
     * ولا مسودّة.
     *
     * رقمٌ لم يُعتمد بعدُ يُقرأ وعدًا: يراه الموظّف ٤٠٠ ثم تُعتمد المسيرة
     * بـ٣٥٠ فيظنّ أنّ راتبه نقص. المسودّة ورقةٌ على مكتب المحاسب لا خبرٌ له.
     */
    public function test_a_draft_run_is_not_shown_to_the_employee(): void
    {
        $this->payroll('مسودة', [$this->cashier->id => 400]);

        $this->assertSame([], $this->page($this->cashier)['payslips']);
    }

    /* ------------------------- ولا شيء لغيره ------------------------- */

    public function test_it_never_shows_a_colleagues_payslip(): void
    {
        $this->payroll('معتمدة', [$this->cashier->id => 350, $this->colleague->id => 1100]);

        $slips = $this->page($this->cashier)['payslips'];

        $this->assertCount(1, $slips);
        $this->assertNotContains(1100.0, array_map(fn ($s) => (float) $s['net'], $slips));
    }

    public function test_the_sales_counted_are_his_alone(): void
    {
        $this->order($this->cashier, 20);
        $this->order($this->colleague, 500);

        $sales = $this->page($this->cashier)['sales'];

        $this->assertSame(1, $sales['allCount']);
        $this->assertEqualsWithDelta(20.0, $sales['monthTotal'], 0.001);
    }

    /**
     * وعزلُ المتاجر قبل كلّ شيء: مسيرةٌ في متجر الجار لا تُقرأ هنا مهما
     * تشابهت المعرّفات.
     */
    public function test_a_neighbours_payroll_is_never_read(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        $run = PayrollRun::create([
            'business_id' => $other->id, 'number' => 'PR-J', 'period' => '2026-07-01', 'status' => 'مصروفة',
        ]);
        PayrollLine::create([
            'payroll_run_id' => $run->id, 'user_id' => $this->cashier->id,
            'employee_name' => 'أحمد', 'basic' => 9999, 'net' => 9999,
        ]);

        $this->assertSame([], $this->page($this->cashier)['payslips']);
    }
}
