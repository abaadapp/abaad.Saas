<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * خصمٌ يتجاوز ما استحقّه الموظّف.
 *
 * الصافي يُقصّ عند الصفر (`PayrollLine::computeNet`) والخصمُ يُجمع كاملًا،
 * فيختلّ ما يعتمد عليه قيدُ الاعتماد: مصروفٌ = مجموع الإجماليّات، ودائنان
 * = الخصومات + الصافي. ومتى تجاوز خصمُ سطرٍ إجماليَّه صار الدائن أكبر من
 * المدين — قيدٌ لا يتوازن.
 */
class PayrollOverDeductionTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
            'basic_salary' => 300, 'allowances' => 0,
        ]);

        $this->actingAs($this->owner);
    }

    private function draftRun(): PayrollRun
    {
        $this->post(route('admin.payroll.store'), ['period' => now()->format('Y-m')])
            ->assertSessionHasNoErrors();

        return PayrollRun::where('business_id', $this->business->id)->firstOrFail();
    }

    public function test_a_deduction_larger_than_the_pay_is_refused_at_the_line(): void
    {
        /*
         * ولا يُقبل ثمّ يُقصّ: القصُّ يجعل السطر يقول «صفر» والمسيرةَ تقول
         * «خُصم ٥٠٠» — ورقمان لا يجتمعان على حساب. والأصلُ أنّ أحدًا لا
         * يُخصم منه أكثر ممّا استحقّ.
         */
        $line = $this->draftRun()->lines()->firstOrFail();

        $this->put(route('admin.payroll.lines.update', $line->id), [
            'basic' => 300, 'allowances' => 0, 'overtime' => 0, 'deductions' => 500,
        ])->assertSessionHasErrors('deductions');

        $this->assertSame(0.0, round((float) $line->fresh()->deductions, 3), 'حُفظ خصمٌ يتجاوز الاستحقاق');
    }

    public function test_the_run_totals_stay_consistent_with_its_lines(): void
    {
        // الإجماليّ ناقصَ الخصم يساوي الصافي — وإلّا فأحد الأرقام الثلاثة يكذب
        $line = $this->draftRun()->lines()->firstOrFail();

        $this->put(route('admin.payroll.lines.update', $line->id), [
            'basic' => 300, 'allowances' => 0, 'overtime' => 0, 'deductions' => 120,
        ])->assertSessionHasNoErrors();

        $run = $line->run->fresh();

        $this->assertSame(
            round((float) $run->gross - (float) $run->deductions, 3),
            round((float) $run->net, 3),
            'مجاميع المسيرة لا تتّسق: الإجماليّ ناقصَ الخصم ≠ الصافي'
        );
    }

    public function test_approving_writes_a_balanced_entry(): void
    {
        /*
         * أهمّ ما في الملفّ: قيدُ الاعتماد يُبنى من ثلاثة مجاميع، فاختلافُ
         * أحدها عن أخويه يُخرج قيدًا لا يتوازن — ويبقى في الدفتر.
         */
        $line = $this->draftRun()->lines()->firstOrFail();

        $this->put(route('admin.payroll.lines.update', $line->id), [
            'basic' => 300, 'allowances' => 0, 'overtime' => 0, 'deductions' => 120,
        ])->assertSessionHasNoErrors();

        $this->post(route('admin.payroll.approve', $line->run->id))->assertSessionHasNoErrors();

        $this->assertSame('معتمدة', $line->run->fresh()->status);
        $this->assertTrue(Ledger::trialBalance($this->business->id)['balanced'], 'الميزان اختلّ بعد اعتماد المسيرة');
    }

    public function test_an_over_deducted_line_cannot_sneak_in_from_the_database(): void
    {
        /*
         * وصفٌّ قديم كُتب قبل هذا الحدّ يبقى في القاعدة. فالاعتماد لا يثق
         * بالمجاميع بل يفحصها: قيدٌ مختلٌّ يُرفض بسببٍ مفهوم لا برسالة
         * «خطأ في الترحيل» يبحث التاجر عن معناها.
         */
        // زميلٌ سليم معه: بلا صافٍ موجب يوقفه فحصٌ آخر قبل هذا
        User::create([
            'business_id' => $this->business->id, 'name' => 'زميل', 'email' => 'z@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
            'basic_salary' => 1000, 'allowances' => 0,
        ]);

        $run = $this->draftRun();
        PayrollLine::where('payroll_run_id', $run->id)->where('employee_name', 'المالك')
            ->update(['basic' => 300, 'allowances' => 0, 'overtime' => 0, 'deductions' => 500, 'net' => 0]);
        $run->recalculate();

        $this->post(route('admin.payroll.approve', $run->id))->assertSessionHasErrors('approve');

        // والرسالة تسمّي الموظّف: «القيد لا يتوازن» صحيحةٌ ولا تدلّ على شيء يُصلَح
        $this->assertStringContainsString(
            'المالك',
            session('errors')->first('approve'),
            'الرفض لا يقول أيُّ سطرٍ سبّبه'
        );

        $this->assertSame('مسودة', $run->fresh()->status, 'اعتُمدت مسيرةٌ مجاميعُها لا تتّسق');
        $this->assertTrue(Ledger::trialBalance($this->business->id)['balanced']);
    }
}
