<?php

namespace Tests\Feature;

use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\User;
use App\Support\DemoStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مسيرةُ الرواتب ثلاثُ حالات لا أربع — والبذرةُ تكتب ما يقرؤه الصندوق.
 *
 * قيس على الإنتاج: ستُّ مسيراتٍ من سبعٍ في المتجر التجريبيّ حالُها «مدفوعة»
 * — لفظٌ لا يعرفه `PayrollRunController` ولا شاشةُ الصرف — فلا تظهر في
 * شاشة الصرف، ولا لونَ لها في القائمة. وطريقةُ دفع سطورها «تحويل» بينما
 * يكتب الصندوقُ «تحويل بنكي». انظر `DemoStore::payroll`.
 */
class APayrollRunHasThreeStatesNotFourTest extends TestCase
{
    use RefreshDatabase;

    /** الحالاتُ التي يقرؤها الصندوق — كما في `PayrollRunController` */
    private const KNOWN = ['مسودة', 'معتمدة', 'مصروفة'];

    public function test_every_seeded_run_carries_a_state_the_register_knows(): void
    {
        $business = DemoStore::create('متجر تجريبي', 'صغير');

        $runs = PayrollRun::where('business_id', $business->id)->get();
        $this->assertNotEmpty($runs, 'البذرةُ لم تكتب مسيرةً — الاختبارُ لا يقيس شيئًا');

        foreach ($runs as $run) {
            $this->assertContains($run->status, self::KNOWN, "المسيرة {$run->number} بحالٍ لا يعرفها الصندوق: {$run->status}");
        }

        $methods = PayrollLine::whereIn('payroll_run_id', $runs->pluck('id'))->distinct()->pluck('payment_method')->all();
        $this->assertSame(['تحويل بنكي'], $methods, 'طريقةُ الدفع بلفظٍ غير لفظ الصندوق');
    }

    /** وكلُّ مسيرةٍ صُرفت تظهر في شاشة الصرف — لا تختفي بلفظٍ لا يُرشَّح */
    public function test_every_paid_run_shows_on_the_payments_screen(): void
    {
        $business = DemoStore::create('متجر تجريبي', 'صغير');
        $owner = User::where('business_id', $business->id)->where('role', 'admin')->firstOrFail();

        $expected = PayrollRun::where('business_id', $business->id)->where('status', '!=', 'مسودة')->count();

        $this->actingAs($owner)->get(route('admin.payroll.payments'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('runs', $expected));
    }
}
