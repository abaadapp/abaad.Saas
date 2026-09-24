<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الرواتب تقول ما وقع — لا ما نوت أن تفعله.
 *
 * ═══ العطبُ الذي يحرسه هذا ═══
 *
 * بابا «الاعتماد» و«الصرف» يُعيدان قراءةَ الحال **مقفلةً** داخل المعاملة
 * وينصرفان إن سبقهما غيرُهما. وهو الصواب: الدفترُ لا يحمل قيدين، والمال لا
 * يخرج مرّتين. لكنّ ما بعد المعاملة كان يُكتب على كلّ حال.
 *
 * وفي الصرف خاصّةً: `DB::transaction(function () use ($lines, ...))` تأخذ
 * **نسخةً** لا مرجعًا. فإعادةُ الإسناد داخل المغلَّف (`$lines = $ready`،
 * `$total = ...`) لا تبلغ ما خارجه — ويبقى العددُ والمبلغُ على ما قُرئ
 * **قبل** القفل.
 *
 * ═══ وما يقع حين يقع ═══
 *
 * الضغطةُ الثانية — وهي ما يقع حين يبطؤ الردّ — لا تصرف فلسًا، ثمّ تقول:
 *
 *     رسالةٌ خضراء: «صُرف ٢ موظّفًا بقيمة ٧٠٠٫٠٠٠»
 *     وسطرٌ في السجلّ: «صرف رواتب ٢٠٢٧-٠٢ لـ٢ موظّفًا بقيمة ٧٠٠»
 *
 * فيقرأ التاجرُ أنّ ألفًا وأربعَ مئةٍ خرجت وقد خرجت سبعُ مئة. ويحمل السجلُّ
 * صرفين لصرفٍ واحد — وهو أوّلُ ما يُرجَع إليه حين يُسأل «متى صُرف راتبُ
 * سالم، ومن صرفه؟».
 *
 * والاعتمادُ مثلُه: اعتمادان في السجلّ باسمين وساعتين لاعتمادٍ واحد.
 *
 * ═══ وكيف يُقاس السباق ═══
 *
 * بلا خيطين: يُنصَت لاستعلام السطور غير المدفوعة، فحين يقع تُصرَف السطورُ
 * من تحته — كما لو سبقك غيرُك بين القراءة والقفل. وهو عينُ ما يحرسه القفل.
 */
class APayrollSaysOnlyWhatItDidTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط', 'basic_salary' => 0,
        ]);

        foreach ([['سالم', 300], ['ريم', 400]] as [$name, $salary]) {
            User::create([
                'business_id' => $this->business->id, 'name' => $name, 'password' => bcrypt('x'),
                'role' => 'cashier', 'status' => 'نشط', 'basic_salary' => $salary,
            ]);
        }

        $this->actingAs($this->owner);
        Ledger::seedChart($this->business->id);
    }

    private function openRun(): PayrollRun
    {
        $this->post(route('admin.payroll.store'), ['period' => now()->format('Y-m')])->assertSessionHasNoErrors();

        return PayrollRun::firstOrFail();
    }

    /** سطرُ السجلّ الأخير كما كُتب */
    private function lastLogLine(): string
    {
        return (string) DB::table('activity_logs')->latest('id')->value('description');
    }

    private function logLinesLike(string $needle): int
    {
        return DB::table('activity_logs')->where('description', 'like', '%'.$needle.'%')->count();
    }

    /**
     * يجعل السطورَ مدفوعةً بين القراءة والقفل — محاكاةُ من سبقك.
     *
     * والإنصاتُ للاستعلام لا للنموذج: قراءةُ المسيرة تُحمّل سطورَها أوّلًا
     * (`with('lines')`)، فربطُه بالنموذج يقع في الموضع الخطأ.
     */
    private function someoneElsePaysFirst(array $ids): void
    {
        $fired = false;

        DB::listen(function ($q) use (&$fired, $ids) {
            if ($fired || ! str_contains($q->sql, 'payroll_lines') || ! str_contains($q->sql, '"paid"')) {
                return;
            }

            if (! str_starts_with(strtolower(trim($q->sql)), 'select')) {
                return;
            }

            $fired = true;
            DB::table('payroll_lines')->whereIn('id', $ids)->update(['paid' => true, 'paid_at' => now()]);
        });
    }

    /** يصرف بعضَ السطور من تحته — لا كلَّها */
    private function someoneElsePaysSomeOf(array $ids): void
    {
        $fired = false;

        DB::listen(function ($q) use (&$fired, $ids) {
            if ($fired || ! str_contains($q->sql, 'payroll_lines') || ! str_contains($q->sql, '"paid"')) {
                return;
            }

            if (! str_starts_with(strtolower(trim($q->sql)), 'select')) {
                return;
            }

            $fired = true;
            DB::table('payroll_lines')->where('id', $ids[0])->update(['paid' => true, 'paid_at' => now()]);
        });
    }

    /* ═════════════ الصرف ═════════════ */

    public function test_a_payment_that_lost_the_race_does_not_claim_a_payment(): void
    {
        $run = $this->openRun();
        $this->post(route('admin.payroll.approve', $run->id))->assertSessionHasNoErrors();
        $ids = PayrollLine::where('payroll_run_id', $run->id)->pluck('id')->all();

        $entriesBefore = DB::table('journal_entries')->count();
        $this->someoneElsePaysFirst($ids);

        $this->post(route('admin.payroll.pay', $run->id), [
            'lines' => $ids, 'paid_at' => now()->toDateString(), 'from' => 'cash',
        ]);

        // لا مالَ خرج — وهو ما كان سليمًا من قبل
        $this->assertSame($entriesBefore, DB::table('journal_entries')->count(), 'قيدُ صرفٍ ثانٍ دخل الدفتر');

        // ولا يُقال إنّه خرج
        $this->assertNotSame('success', session('toast')['type'] ?? null,
            'رسالةٌ خضراء تقول «صُرف» ولم يُصرف شيء');
        $this->assertStringNotContainsString('صُرف', (string) (session('toast')['msg'] ?? ''));
    }

    /** ولا سطرَ في السجلّ لصرفٍ لم يقع */
    public function test_a_payment_that_lost_the_race_writes_no_log_line(): void
    {
        $run = $this->openRun();
        $this->post(route('admin.payroll.approve', $run->id));
        $ids = PayrollLine::where('payroll_run_id', $run->id)->pluck('id')->all();

        $this->someoneElsePaysFirst($ids);

        $this->post(route('admin.payroll.pay', $run->id), [
            'lines' => $ids, 'paid_at' => now()->toDateString(), 'from' => 'cash',
        ]);

        $this->assertSame(0, $this->logLinesLike('صرف رواتب'),
            'السجلُّ يحمل صرفًا لم يقع — وهو أوّلُ ما يُرجَع إليه');
    }

    /* ═════════════ والصرفُ الصحيح يُقال كما هو ═════════════ */

    public function test_a_real_payment_is_reported_and_logged(): void
    {
        $run = $this->openRun();
        $this->post(route('admin.payroll.approve', $run->id));
        $ids = PayrollLine::where('payroll_run_id', $run->id)->pluck('id')->all();

        $this->post(route('admin.payroll.pay', $run->id), [
            'lines' => $ids, 'paid_at' => now()->toDateString(), 'from' => 'cash',
        ])->assertSessionHasNoErrors();

        $this->assertSame('success', session('toast')['type'] ?? null);
        $this->assertStringContainsString('700', (string) session('toast')['msg']);
        $this->assertSame(1, $this->logLinesLike('صرف رواتب'));
        $this->assertSame(2, PayrollLine::where('paid', true)->count());
    }

    /**
     * وأخطرُ الحالات: بعضُها صُرف من تحته لا كلُّها.
     *
     * فلا تنصرف المعاملةُ — تصرف الباقيَ فعلًا — ثمّ يُقال العددُ والمبلغُ
     * **كما قُرئا قبل القفل**: «صُرف ٢ بقيمة ٧٠٠» وقد صُرف واحدٌ بأربع مئة.
     * والفرقُ يدخل السجلَّ ويُقرأ بعد شهر.
     */
    public function test_a_partial_race_reports_only_what_it_paid(): void
    {
        $run = $this->openRun();
        $this->post(route('admin.payroll.approve', $run->id));

        $lines = PayrollLine::where('payroll_run_id', $run->id)->orderBy('id')->get();
        $ids = $lines->pluck('id')->all();
        $mine = (float) $lines[1]->net;

        $this->someoneElsePaysSomeOf($ids);

        $this->post(route('admin.payroll.pay', $run->id), [
            'lines' => $ids, 'paid_at' => now()->toDateString(), 'from' => 'cash',
        ])->assertSessionHasNoErrors();

        $msg = (string) (session('toast')['msg'] ?? '');

        $this->assertStringContainsString('صُرف 1', $msg, 'قيل إنّه صرف اثنين وقد صرف واحدًا — '.$msg);
        $this->assertStringContainsString(number_format($mine, 3), $msg);

        $log = $this->lastLogLine();
        $this->assertStringContainsString('لـ1 موظّفًا', $log, 'السجلُّ يقول عددًا غيرَ ما صُرف — '.$log);
        $this->assertStringContainsString((string) $mine, $log);
    }

    /* ═════════════ الاعتماد ═════════════ */

    /** واعتمادان لاعتمادٍ واحد لا يُكتبان — ولو ضُغط الزرُّ مرّتين */
    public function test_approving_twice_writes_one_approval(): void
    {
        $run = $this->openRun();

        $this->post(route('admin.payroll.approve', $run->id))->assertSessionHasNoErrors();
        $this->post(route('admin.payroll.approve', $run->id));

        $this->assertSame(1, $this->logLinesLike('اعتمد مسيرة'),
            'السجلُّ يحمل اعتمادين لاعتمادٍ واحد — فيُقرأ أنّ اثنين اعتمداها');
        $this->assertSame('معتمدة', $run->fresh()->status);
    }

    /** والثانيةُ تُقال «معتمدةٌ أصلًا» لا «اعتُمدت الآن» */
    public function test_the_second_approval_says_it_was_already_approved(): void
    {
        $run = $this->openRun();

        $this->post(route('admin.payroll.approve', $run->id));
        $this->post(route('admin.payroll.approve', $run->id));

        $this->assertSame('info', session('toast')['type'] ?? null);
        $this->assertStringContainsString('أصلًا', (string) session('toast')['msg']);
    }

    /**
     * والسباقُ الحقيقيُّ في الاعتماد: الفحصُ الأعلى يمرّ ثمّ يسبقك غيرُك.
     *
     * والضغطتان المتتاليتان لا تبلغانه — الفحصُ قبل المعاملة يردّ الثانية.
     * فيُقاس بما يقع فعلًا: تُعتمد المسيرةُ من تحته بين قراءته وقفله.
     */
    public function test_an_approval_that_lost_the_race_writes_no_log_line(): void
    {
        $run = $this->openRun();

        $fired = false;
        DB::listen(function ($q) use (&$fired, $run) {
            if ($fired || ! str_contains($q->sql, 'payroll_runs')) {
                return;
            }

            if (! str_starts_with(strtolower(trim($q->sql)), 'select')) {
                return;
            }

            $fired = true;
            DB::table('payroll_runs')->where('id', $run->id)
                ->update(['status' => 'معتمدة', 'approved_at' => now()]);
        });

        $this->post(route('admin.payroll.approve', $run->id));

        $this->assertSame(0, $this->logLinesLike('اعتمد مسيرة'),
            'السجلُّ يحمل اعتمادًا لم يقع — باسمٍ وساعةٍ ليسا له');
        $this->assertNotSame('success', session('toast')['type'] ?? null,
            'رسالةٌ خضراء تقول «اعتُمدت الآن» ولم يعتمدها هذا الطلب');
    }

    /** والاعتمادُ الأوّل يُقيَّد ويُكتب كما كان */
    public function test_the_first_approval_still_posts_and_logs(): void
    {
        $run = $this->openRun();

        $this->post(route('admin.payroll.approve', $run->id))->assertSessionHasNoErrors();

        $this->assertSame('معتمدة', $run->fresh()->status);
        $this->assertSame(1, $this->logLinesLike('اعتمد مسيرة'));
        $this->assertStringContainsString('اعتُمدت', (string) session('toast')['msg']);
    }
}
