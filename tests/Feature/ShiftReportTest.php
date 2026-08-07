<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ورقةٌ تُوقَّع عند تسليم الدرج.
 *
 * كان الإقفال ينتهي عند شاشة — يُدخل الكاشير ما عدّه ويُخزَّن الفرق، ولا يبقى
 * في يد أحدٍ شيء. فإن اختلفا غدًا على عشرين ريالًا، كلٌّ يذكر رقمًا ولا ورقة
 * بينهما.
 */
class ShiftReportTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'متجر الورد', 'type' => 'عام', 'status' => 'نشط', 'phone' => '99887766',
        ]);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الخوير']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'سالم', 'email' => 'salem@abaad.om',
            'role' => 'admin', 'job_title' => 'مدير', 'status' => 'نشط', 'password' => 'x',
        ]);
    }

    private function closedShift(): Shift
    {
        return Shift::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'user_id' => $this->owner->id, 'employee_name' => 'سالم',
            'opened_at' => now()->subHours(8), 'closed_at' => now(), 'closed_by' => $this->owner->id,
            'opening_balance' => 20, 'cash_sales' => 150, 'card_sales' => 60,
            'expected_balance' => 170, 'actual_balance' => 168, 'difference' => -2,
            'status' => Shift::CLOSED, 'note' => 'نقص ريالين',
        ]);
    }

    public function test_a_closed_shift_prints_a_report(): void
    {
        $shift = $this->closedShift();

        $response = $this->actingAs($this->owner)->get(route('admin.shifts.pdf', $shift->id));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_an_open_shift_has_no_report(): void
    {
        $open = Shift::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'opened_at' => now(), 'opening_balance' => 20, 'status' => Shift::OPEN,
        ]);

        // أرقامها تتغيّر مع كل بيعة، وورقةٌ منها تُوقَّع على رقمٍ يكذّبه الصندوق
        $this->actingAs($this->owner)->get(route('admin.shifts.pdf', $open->id))->assertNotFound();
    }

    public function test_the_neighbour_cannot_read_my_shift(): void
    {
        $shift = $this->closedShift();

        $other = Business::create(['name' => 'جار', 'type' => 'عام', 'status' => 'نشط']);
        JobTitle::create(['business_id' => $other->id, 'name' => 'مدير', 'role' => 'admin']);
        $intruder = User::create([
            'business_id' => $other->id, 'name' => 'جار', 'email' => 'jar@abaad.om',
            'role' => 'admin', 'job_title' => 'مدير', 'status' => 'نشط', 'password' => 'x',
        ]);

        $this->actingAs($intruder)->get(route('admin.shifts.pdf', $shift->id))->assertNotFound();
    }
}
