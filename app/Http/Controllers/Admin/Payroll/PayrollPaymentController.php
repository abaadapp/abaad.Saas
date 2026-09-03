<?php

namespace App\Http\Controllers\Admin\Payroll;

use App\Http\Controllers\Controller;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Support\Demo;
use App\Support\Ledger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * صرف الرواتب — إخراج المال مقابل مستحقٍّ قُيّد من قبل.
 *
 * الصرف لا يقيّد مصروفًا: المصروف قُيّد يوم الاعتماد. وهذا القيد يُنقص
 * المستحقّ ويُنقص النقد — ولو قيّد مصروفًا ثانيًا لظهرت رواتب الشهر مرّتين
 * في قائمة الدخل.
 *
 * والصرف يقع سطرًا سطرًا: موظّفٌ يُصرف له اليوم وآخر في الأسبوع القادم واقعٌ
 * لا استثناء، ومسيرةٌ تُصرف كتلةً واحدة تُجبر التاجر على الانتظار حتى يكتمل
 * الجميع أو على أن يكذب في التاريخ.
 */
class PayrollPaymentController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    public function index(Request $request): Response
    {
        $bid = $this->bid();
        Ledger::ensureSystemAccounts($bid);

        // المسودّات خارج هذه الشاشة: لا يُصرف ما لم يُعتمد
        $runs = PayrollRun::where('business_id', $bid)->with('lines')
            ->whereIn('status', ['معتمدة', 'مصروفة'])
            ->orderByDesc('period')->orderByDesc('id')->get();

        $current = $request->query('run')
            ? $runs->firstWhere('id', (int) $request->query('run'))
            : $runs->first(fn ($r) => $r->status === 'معتمدة') ?? $runs->first();

        return Inertia::render('Admin/Payroll/Payments', [
            'runs' => $runs->map(fn ($r) => [
                'id' => $r->id,
                'number' => $r->number,
                'period' => $r->period->format('Y-m'),
                'status' => $r->status,
                'net' => (float) $r->net,
                'remaining' => PayrollRunController::unpaidNet($r),
                'employees' => $r->lines->count(),
                'paid_count' => $r->lines->where('paid', true)->count(),
            ])->all(),
            'current' => $current ? PayrollRunController::runDetail($current) : null,
            'remaining' => $current ? PayrollRunController::unpaidNet($current) : 0.0,
            'due' => round($runs->where('status', 'معتمدة')->sum(fn ($r) => PayrollRunController::unpaidNet($r)), 3),
            'today' => now()->format('Y-m-d'),
        ]);
    }

    public function pay(Request $request, $id)
    {
        $bid = $this->bid();
        $run = PayrollRun::where('business_id', $bid)->with('lines')->findOrFail($id);

        if ($run->status === 'مسودة') {
            return back()->withErrors(['lines' => __('لا يُصرف ما لم يُعتمد')]);
        }

        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*' => ['integer'],
            'paid_at' => ['required', 'date'],
            'from' => ['required', Rule::in(['cash', 'bank'])],
        ]);

        $lines = PayrollLine::where('payroll_run_id', $run->id)
            ->whereIn('id', $data['lines'])->where('paid', false)->get();

        if ($lines->isEmpty()) {
            return back()->with('toast', ['msg' => __('لا سطور تنتظر الصرف'), 'type' => 'info']);
        }

        $total = round($lines->sum(fn ($l) => (float) $l->net), 3);

        if ($total <= 0) {
            return back()->withErrors(['lines' => __('لا مبلغ يُصرف')]);
        }

        $method = $data['from'] === 'bank' ? 'تحويل بنكي' : 'نقدي';

        try {
            DB::transaction(function () use ($bid, $run, $lines, $data, $method) {
                /*
                 * والسطور تُقرأ ثانيةً تحت قفل — لا من مجموعةٍ قُرئت قبلها.
                 *
                 * الصرف يختار غير المدفوع ثمّ يكتب. فضغطتان متتاليتان — وهو
                 * ما يقع حين يبطؤ الردّ — تصرفان الرواتب مرّتين: قيدُ صرفٍ
                 * مضاعف يُخرج من الصندوق ضعف ما استحقّ، والسطور تُوسَم
                 * مدفوعةً مرّةً فلا يبقى في الشاشة ما يدلّ على الزيادة.
                 */
                $ready = PayrollLine::where('payroll_run_id', $run->id)
                    ->whereIn('id', $lines->pluck('id'))
                    ->where('paid', false)
                    ->lockForUpdate()->get();

                if ($ready->isEmpty()) {
                    return;
                }

                $total = round($ready->sum(fn ($l) => (float) $l->net), 3);
                $lines = $ready;

                $entryLines = $lines->map(fn ($l) => [
                    'account' => 'salaries_payable',
                    'debit' => (float) $l->net,
                    'memo' => $l->employee_name,
                ])->all();

                $entryLines[] = ['account' => $data['from'], 'credit' => $total];

                Ledger::post(
                    $bid,
                    __('صرف رواتب :m', ['m' => $run->period->format('Y-m')]),
                    $entryLines,
                    Carbon::parse($data['paid_at']),
                    'صرف رواتب',
                    null,
                    auth()->id(),
                    $run,
                );

                foreach ($lines as $line) {
                    $line->update([
                        'paid' => true,
                        'paid_at' => Carbon::parse($data['paid_at']),
                        'payment_method' => $method,
                    ]);
                }

                /*
                 * المسيرة تُقفل حين لا يبقى فيها من ينتظر.
                 *
                 * والحساب يُعاد من القاعدة لا من المجموعة المحمّلة: سطورٌ
                 * قُرئت قبل التحديث تقول «غير مدفوع» عن سطرٍ دُفع للتوّ،
                 * فتبقى مسيرةٌ صُرفت كاملةً معلَّقةً بحالة «معتمدة» إلى الأبد.
                 */
                if (PayrollRunController::unpaidNet($run->fresh()) <= 0.0005) {
                    $run->update(['status' => 'مصروفة', 'paid_at' => Carbon::parse($data['paid_at'])]);
                }
            });
        } catch (RuntimeException $e) {
            return back()->withErrors(['lines' => $e->getMessage()]);
        }

        \App\Support\Activity::log(
            'updated',
            'صرف رواتب '.$run->period->format('Y-m').' لـ'.$lines->count().' موظّفًا بقيمة '.$total,
            ['subject_id' => $run->id]
        );

        return back()->with('toast', [
            'msg' => __('صُرف :n موظّفًا بقيمة :v', ['n' => $lines->count(), 'v' => number_format($total, 3)]),
            'type' => 'success',
        ]);
    }
}
