<?php

namespace App\Http\Controllers\Admin\Payroll;

use App\Http\Controllers\Controller;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\User;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\Ledger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * مسيرة الرواتب — ثلاث حالات لا رابعة.
 *
 * مسودةٌ تُعدَّل، ومعتمدةٌ صارت مستحقًّا في الدفتر، ومصروفةٌ خرج مالُها.
 * والفصل بين «استُحقّ» و«صُرف» ليس تفصيلًا محاسبيًّا: راتبُ شهرٍ اعتُمد ولم
 * يُصرف التزامٌ قائم على المتجر، ودمجُه بالصرف يُخفي هذا الالتزام حتى يخرج
 * المال — فيُقرأ الشهر ربحًا وهو مدين برواتبه.
 *
 * والرجوع من حالةٍ إلى ما قبلها ممنوع: الاعتماد يترك قيدًا، والتراجع عن قيدٍ
 * يكون بقيدٍ عكسيّ لا بمحوه.
 */
class PayrollRunController extends Controller
{
    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    public function index(Request $request): Response
    {
        $bid = $this->bid();
        Ledger::ensureSystemAccounts($bid);

        $runs = PayrollRun::where('business_id', $bid)->with('lines')
            ->orderByDesc('period')->orderByDesc('id')->get();

        $current = $request->query('run')
            ? $runs->firstWhere('id', (int) $request->query('run'))
            : $runs->first();

        return Inertia::render('Admin/Payroll/Index', [
            'runs' => $runs->map(fn ($r) => $this->runRow($r))->all(),
            'current' => $current ? $this->runDetail($current) : null,
            // الشهور التي لم تُفتح لها مسيرة بعد — لا تُقترح شهرٌ له مسيرة
            'openPeriods' => $this->openPeriods($bid, $runs->pluck('period')->map->format('Y-m')->all()),
            'employeeCount' => $this->payableEmployees($bid)->count(),
            'today' => now()->format('Y-m-d'),
        ]);
    }

    /**
     * فتح مسيرة الشهر — تُملأ من رواتب الموظّفين كما هي اليوم.
     *
     * والقيم تُنسخ إلى السطور ولا تُقرأ من الموظّف عند كل عرض: راتبٌ يُرفع في
     * مارس لا يجوز أن يُعيد كتابة مسيرة يناير.
     */
    public function store(Request $request)
    {
        $bid = $this->bid();

        $data = $request->validate([
            'period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $period = Carbon::createFromFormat('Y-m-d', $data['period'].'-01')->startOfMonth();

        if (PayrollRun::where('business_id', $bid)->whereDate('period', $period)->exists()) {
            return back()->withErrors(['period' => __('لهذا الشهر مسيرةٌ مفتوحة أصلًا')]);
        }

        $employees = $this->payableEmployees($bid);

        if ($employees->isEmpty()) {
            return back()->withErrors(['period' => __('لا موظّف له راتبٌ مسجَّل — اضبط الرواتب في صفحة الموظفين أولًا')]);
        }

        $run = DB::transaction(function () use ($bid, $period, $employees) {
            $run = PayrollRun::create([
                'business_id' => $bid,
                'number' => PayrollRun::nextNumber($bid),
                'period' => $period->toDateString(),
                'created_by' => auth()->id(),
            ]);

            foreach ($employees as $employee) {
                $line = new PayrollLine([
                    'payroll_run_id' => $run->id,
                    'user_id' => $employee->id,
                    // الاسم يُنسخ: موظّفٌ يُحذف حسابه لا يُفرّغ مسيرةً مضت
                    'employee_name' => $employee->name,
                    'basic' => (float) $employee->basic_salary,
                    'allowances' => (float) $employee->allowances,
                ]);
                $line->net = $line->computeNet();
                $line->save();
            }

            $run->recalculate();

            return $run;
        });

        Activity::log('created', 'فتح مسيرة رواتب '.$period->format('Y-m'));

        return redirect()->route('admin.payroll.index', ['run' => $run->id])
            ->with('toast', ['msg' => __('فُتحت مسيرة :m', ['m' => $period->format('Y-m')]), 'type' => 'success']);
    }

    /** تعديل سطر — ما دامت المسيرة مسودة */
    public function updateLine(Request $request, $id)
    {
        $bid = $this->bid();
        $line = PayrollLine::whereHas('run', fn ($q) => $q->where('business_id', $bid))->findOrFail($id);

        if (! $line->run->isEditable()) {
            return back()->with('toast', ['msg' => __('المسيرة اعتُمدت — لا تُعدَّل سطورها'), 'type' => 'warning']);
        }

        $data = $request->validate([
            'basic' => ['required', 'numeric', 'min:0'],
            'allowances' => ['required', 'numeric', 'min:0'],
            'overtime' => ['required', 'numeric', 'min:0'],
            'deductions' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        /*
         * ولا يُخصم من أحدٍ أكثر ممّا استحقّ.
         *
         * الصافي يُقصّ عند الصفر (`PayrollLine::computeNet`) والخصمُ يُجمع
         * كاملًا في مجاميع المسيرة، فيفترق ما يجب أن يجتمع: إجماليٌّ ناقصَ
         * خصمٍ لا يساوي الصافي. وقيدُ الاعتماد يُبنى من هذه الثلاثة، فيخرج
         * دائنُه أكبر من مدينه ويُرفض.
         *
         * فتصير المسيرة بابًا مسدودًا: تُحفظ ولا تُعتمد أبدًا، والرسالة تقول
         * إنّ القيد لا يتوازن ولا تقول أيُّ موظّفٍ سبّب ذلك. والردّ هنا —
         * حيث يُكتب الرقم ويُرى الموظّف — لا هناك.
         */
        $earned = round((float) $data['basic'] + (float) $data['allowances'] + (float) $data['overtime'], 3);

        if (round((float) $data['deductions'], 3) > $earned) {
            throw ValidationException::withMessages([
                'deductions' => __('الخصم أكبر من مستحقّ :name (:earned) — لا يُخصم أكثر ممّا استحقّ.', [
                    'name' => $line->employee_name,
                    'earned' => Demo::money($earned),
                ]),
            ]);
        }

        $line->fill($data);
        $line->net = $line->computeNet();
        $line->save();

        $line->run->recalculate();

        return back()->with('toast', ['msg' => __('حُفظ السطر'), 'type' => 'success']);
    }

    /** حذف سطر من مسودة — موظّفٌ لا يستحقّ راتب هذا الشهر */
    public function destroyLine($id)
    {
        $bid = $this->bid();
        $line = PayrollLine::whereHas('run', fn ($q) => $q->where('business_id', $bid))->findOrFail($id);

        if (! $line->run->isEditable()) {
            return back()->with('toast', ['msg' => __('المسيرة اعتُمدت — لا تُعدَّل سطورها'), 'type' => 'warning']);
        }

        $run = $line->run;
        $line->delete();
        $run->recalculate();

        return back()->with('toast', ['msg' => __('حُذف السطر'), 'type' => 'warning']);
    }

    /**
     * الاعتماد — يقيّد المستحقّ ولا يصرف شيئًا.
     *
     * مصروف الرواتب مدين، والرواتب المستحقّة دائنة. وبعده تصير المسيرة وثيقةً
     * لا تُعدَّل: تعديلُ سطرٍ بعد اعتماده يجعل الدفتر يقول غير ما تقوله المسيرة.
     */
    public function approve($id)
    {
        $bid = $this->bid();
        $run = PayrollRun::where('business_id', $bid)->with('lines')->findOrFail($id);

        if ($run->status !== 'مسودة') {
            return back()->with('toast', ['msg' => __('المسيرة معتمدةٌ أصلًا'), 'type' => 'info']);
        }

        if ($run->lines->isEmpty() || (float) $run->net <= 0) {
            return back()->withErrors(['approve' => __('مسيرةٌ بلا صافٍ لا تُعتمد')]);
        }

        /*
         * وسطرٌ خصمُه أكبر من مستحقّه يُوقف الاعتماد باسمه.
         *
         * الحدُّ يقع عند حفظ السطر، لكنّ صفوفًا كُتبت قبله تبقى في القاعدة.
         * والقيد يُبنى من مجاميع الثلاثة، فيخرج دائنُه أكبر من مدينه ويرفضه
         * الدفتر برسالة «لا يتوازن» — صحيحةٌ ولا تدلّ على شيء يُصلَح.
         */
        foreach ($run->lines as $line) {
            $earned = round((float) $line->basic + (float) $line->allowances + (float) $line->overtime, 3);

            if (round((float) $line->deductions, 3) > $earned) {
                return back()->withErrors(['approve' => __('خصم :name أكبر من مستحقّه — صحّح سطره قبل الاعتماد.', [
                    'name' => $line->employee_name,
                ])]);
            }
        }

        try {
            DB::transaction(function () use ($bid, $run) {
                /*
                 * والحال تُقرأ ثانيةً تحت قفل.
                 *
                 * الفحص أعلاه يقع قبل المعاملة. فضغطتان على «اعتماد» — أو
                 * مديران يفتحان المسيرة نفسها — تُقيّدان مصروف الرواتب مرّتين
                 * ومستحقَّها مرّتين: كلفةُ عمالةٍ مضاعفة في قائمة الدخل،
                 * والتزامٌ مضاعف في الميزانية. والقيدان صحيحان كلاهما في
                 * نفسه، فلا يختلّ الميزان ولا يشتكي شيء.
                 */
                $fresh = PayrollRun::where('business_id', $bid)->lockForUpdate()->find($run->id);
                if (! $fresh || $fresh->status !== 'مسودة') {
                    return;
                }

                $lines = [];

                // سطرٌ لكل موظّف: بعد سنتين يُسأل «ممّ تكوّن مصروف الرواتب؟»
                foreach ($run->lines as $line) {
                    $gross = round((float) $line->basic + (float) $line->allowances + (float) $line->overtime, 3);
                    if ($gross > 0) {
                        $lines[] = ['account' => 'salaries', 'debit' => $gross, 'memo' => $line->employee_name];
                    }
                }

                /*
                 * الخصومات تُطرح من المستحقّ لا من المصروف.
                 *
                 * ما استحقّه الموظّف هو إجماليه، وما يُدفع له بعد الخصم أقلّ —
                 * والفرق مبلغٌ بقي عند المتجر. وطرحُه من المصروف يُخفي كلفة
                 * العمالة الحقيقية.
                 */
                $deductions = round((float) $run->deductions, 3);
                if ($deductions > 0) {
                    $lines[] = ['account' => 'other_income', 'credit' => $deductions, 'memo' => __('خصومات المسيرة')];
                }

                $lines[] = ['account' => 'salaries_payable', 'credit' => round((float) $run->net, 3)];

                Ledger::post(
                    $bid,
                    __('رواتب :m', ['m' => $run->period->format('Y-m')]),
                    $lines,
                    $run->period->copy()->endOfMonth(),
                    'رواتب',
                    null,
                    auth()->id(),
                    $run,
                );

                $run->update(['status' => 'معتمدة', 'approved_at' => now()]);
            });
        } catch (RuntimeException $e) {
            return back()->withErrors(['approve' => $e->getMessage()]);
        }

        Activity::log('updated', 'اعتمد مسيرة '.$run->period->format('Y-m'), ['subject_id' => $run->id]);

        return back()->with('toast', ['msg' => __('اعتُمدت المسيرة وقُيّد المستحقّ'), 'type' => 'success']);
    }

    /** حذف مسيرة — ما دامت مسودة لم تدخل الدفتر */
    public function destroy($id)
    {
        $bid = $this->bid();
        $run = PayrollRun::where('business_id', $bid)->findOrFail($id);

        if ($run->status !== 'مسودة') {
            return back()->with('toast', [
                'msg' => __('المسيرة دخلت الدفتر — لا تُحذف'),
                'type' => 'warning',
            ]);
        }

        Activity::log('deleted', 'حذف مسيرة '.$run->period->format('Y-m'), ['subject_id' => $run->id]);
        $run->delete();

        return redirect()->route('admin.payroll.index')
            ->with('toast', ['msg' => __('حُذفت المسيرة'), 'type' => 'warning']);
    }

    /* ------------------------------ مشترَك ------------------------------ */

    /** الموظّفون الذين لهم راتبٌ مسجَّل — من لا راتب له لا سطر له */
    private function payableEmployees(int $bid)
    {
        return User::where('business_id', $bid)
            ->where('status', 'نشط')
            ->where(fn ($q) => $q->where('basic_salary', '>', 0)->orWhere('allowances', '>', 0))
            ->orderBy('name')->get();
    }

    /** آخر ستّة شهور لم تُفتح لها مسيرة — والجاري أوّلها */
    private function openPeriods(int $bid, array $taken): array
    {
        $out = [];

        for ($i = 0; $i < 6; $i++) {
            $month = now()->copy()->subMonths($i)->format('Y-m');
            if (! in_array($month, $taken, true)) {
                $out[] = $month;
            }
        }

        return $out;
    }

    private function runRow(PayrollRun $run): array
    {
        return [
            'id' => $run->id,
            'number' => $run->number,
            'period' => $run->period->format('Y-m'),
            'status' => $run->status,
            'gross' => (float) $run->gross,
            'deductions' => (float) $run->deductions,
            'net' => (float) $run->net,
            'employees' => $run->lines->count(),
            'paid_count' => $run->lines->where('paid', true)->count(),
        ];
    }

    public static function runDetail(PayrollRun $run): array
    {
        return [
            'id' => $run->id,
            'number' => $run->number,
            'period' => $run->period->format('Y-m'),
            'status' => $run->status,
            'editable' => $run->isEditable(),
            'gross' => (float) $run->gross,
            'deductions' => (float) $run->deductions,
            'net' => (float) $run->net,
            'approved_at' => optional($run->approved_at)->format('Y-m-d'),
            'paid_at' => optional($run->paid_at)->format('Y-m-d'),
            'lines' => $run->lines->map(fn ($l) => [
                'id' => $l->id,
                'name' => $l->employee_name,
                'basic' => (float) $l->basic,
                'allowances' => (float) $l->allowances,
                'overtime' => (float) $l->overtime,
                'deductions' => (float) $l->deductions,
                'net' => (float) $l->net,
                'paid' => (bool) $l->paid,
                'paid_at' => optional($l->paid_at)->format('Y-m-d'),
                'method' => $l->payment_method,
                'notes' => $l->notes,
            ])->values()->all(),
        ];
    }

    /** ما بقي غير مصروفٍ من المسيرة — يقرؤه الاعتماد وشاشة الصرف معًا */
    public static function unpaidNet(PayrollRun $run): float
    {
        return round((float) $run->lines()->where('paid', false)->sum('net'), 3);
    }
}
