<?php

namespace App\Http\Controllers\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\PayrollRun;
use App\Models\SupplierInvoice;
use App\Models\Transaction;
use App\Support\Demo;
use App\Support\Ledger;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * شاشتان تجيبان السؤالين اللذين يُسألان كلّ يوم.
 *
 * «كم عندي وكم ربحت؟» و«ماذا عليّ؟». وكان جوابهما مفرّقًا على خمس شاشات:
 * الرصيد في الحسابات البنكية، والربح في ملخّص المبيعات، والمستحقّ في ثلاثة
 * جداول لا يجمعها شيء — فاتورةٌ في المصروفات، وسندٌ في المشتريات، وراتبٌ
 * معتمدٌ في مسيرة الرواتب. فيدفع التاجر ما تذكّره ويفوته ما نسيه.
 *
 * وكلّ رقمٍ هنا قراءةٌ لا كتابة: لا تُنشئ هاتان الشاشتان شيئًا ولا تُغيّرانه،
 * فلا يحتاج فتحُهما إلى ما يُخشى منه.
 */
class OverviewController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    /**
     * الملخّص المالي — أين المال الآن، وماذا جرى في المدة.
     *
     * الرصيد يُقرأ من الدفتر لا من جمع الحركات: الدفتر هو المصدر، وجمعُ
     * `transactions` بالعين يُسقط كلَّ ما لم يمرّ بها (سدادُ مورّدٍ قبل هذه
     * النسخة مثلًا).
     */
    public function summary(Request $request): Response
    {
        $bid = $this->bid();
        Ledger::ensureSystemAccounts($bid);

        $range = Demo::range($request->query('range', 'month'));
        $start = Demo::rangeStart($range);

        // والملغاة لا تُجمع: التعريف واحدٌ هنا وفي الحركة وفي التقارير
        $movements = Transaction::where('business_id', $bid)->notCancelled()
            ->when($start, fn ($q) => $q->where('occurred_at', '>=', $start));

        $byType = (clone $movements)->selectRaw('type, COALESCE(SUM(amount),0) total')
            ->groupBy('type')->pluck('total', 'type');

        $banks = BankAccount::where('business_id', $bid)->with('account')->get();

        /*
         * صافي الربح يُقرأ من مستنداته لا من الحركة.
         *
         * `Demo::reportSummary` تحسبه من الفواتير والمصروفات — وهو الحساب
         * نفسه الذي يقرؤه ملخّص المبيعات ولوحة التحكم. وحسابُه هنا من جديد
         * كان سيُنتج رقمًا ثالثًا لا يطابق أيًّا منهما.
         */
        $report = Demo::reportSummary($range);

        return Inertia::render('Admin/Finance/Summary', [
            'range' => $range,
            'cash' => round(Ledger::account($bid, 'cash')?->balance() ?? 0.0, 3),
            /*
             * والموقوفة تُجمع مع المفعّلة — انظر BankAccountController::index.
             *
             * حسابٌ أُوقف قد يبقى فيه رصيد، وإخفاؤه من «أين المال الآن» يجعل
             * الشاشة تقول رقمًا أصغر ممّا في الدفتر بلا أن تقول لماذا.
             */
            'bank' => round($banks->sum(fn ($a) => $a->balance()), 3),
            'accounts' => $banks->map(fn ($a) => [
                'id' => $a->id,
                'label' => $a->displayName(),
                'balance' => $a->balance(),
                'active' => (bool) $a->active,
            ])->values()->all(),
            'period' => [
                'sales' => round((float) $report['sales'], 3),
                'expenses' => round((float) $report['expenses'], 3),
                'profit' => round((float) $report['profit'], 3),
                'tax' => round((float) $report['tax'], 3),
                'in' => round((float) ($byType['دخل'] ?? 0), 3),
                'out' => round((float) ($byType['مصروف'] ?? 0), 3),
                // التحويل ينتقل ولا يدخل ولا يخرج — يُعرض وحده أو لا يُعرض
                'transfers' => round((float) ($byType['تحويل'] ?? 0), 3),
            ],
            'dues' => $this->dueTotals($bid),
        ]);
    }

    /**
     * المبالغ المستحقة — ما على المتجر، مجموعًا في مكانٍ واحد.
     *
     * ولا يُعرض ما للمتجر على العملاء: البيع الآجل حُذف من النظام (انظر
     * هجرة `drop_credit_sales`)، وعمودٌ يقول صفرًا دائمًا يوهم أنّه يُحسب.
     */
    public function dues(): Response
    {
        $bid = $this->bid();

        $expenses = Expense::where('business_id', $bid)->unpaid()
            ->orderByRaw('due_date is null')->orderBy('due_date')->orderByDesc('id')
            ->limit(200)->get();

        $invoices = SupplierInvoice::where('business_id', $bid)->with('supplier')
            ->whereColumn('paid', '<', 'total')
            ->orderByRaw('due_at is null')->orderBy('due_at')->orderByDesc('id')
            ->limit(200)->get();

        $runs = PayrollRun::where('business_id', $bid)->with('lines')
            ->where('status', 'معتمدة')->orderByDesc('period')->get();

        $today = now()->startOfDay();

        return Inertia::render('Admin/Finance/Dues', [
            'expenses' => $expenses->map(fn ($e) => [
                'id' => $e->id,
                'reference' => $e->reference,
                'title' => $e->description ?: $e->type,
                'type' => $e->type,
                'amount' => (float) $e->amount,
                'due' => optional($e->due_date)->format('Y-m-d'),
                'overdue' => $e->due_date !== null && $e->due_date->lt($today),
            ])->all(),
            'invoices' => $invoices->map(fn ($i) => [
                'id' => $i->id,
                'reference' => $i->supplier_ref,
                'supplier' => $i->supplier?->name ?? '—',
                'amount' => $i->outstanding(),
                'due' => optional($i->due_at)->format('Y-m-d'),
                'overdue' => $i->isOverdue(),
            ])->all(),
            'payroll' => $runs->map(fn ($r) => [
                'id' => $r->id,
                'number' => $r->number,
                'period' => $r->period->format('Y-m'),
                'amount' => round((float) $r->lines->where('paid', false)->sum('net'), 3),
                'employees' => $r->lines->where('paid', false)->count(),
            ])->filter(fn ($r) => $r['amount'] > 0)->values()->all(),
            'totals' => $this->dueTotals($bid),
        ]);
    }

    /**
     * مجاميع ما على المتجر — تقرؤها الشاشتان.
     *
     * موضعٌ واحد لأن الرقم واحد: «عليك ٤٢٠» في الملخّص و«عليك ٣٩٠» في
     * المستحقّات يجعل التاجر لا يصدّق أيًّا منهما.
     *
     * @return array<string, float|int>
     */
    private function dueTotals(int $bid): array
    {
        $expenses = (float) Expense::where('business_id', $bid)->unpaid()->sum('amount');

        $invoices = round((float) SupplierInvoice::where('business_id', $bid)
            ->whereColumn('paid', '<', 'total')
            ->selectRaw('COALESCE(SUM(total - paid),0) t')->value('t'), 3);

        $payroll = round((float) PayrollRun::where('business_id', $bid)->where('status', 'معتمدة')
            ->with('lines')->get()
            ->sum(fn ($r) => $r->lines->where('paid', false)->sum('net')), 3);

        return [
            'expenses' => round($expenses, 3),
            'invoices' => $invoices,
            'payroll' => $payroll,
            'total' => round($expenses + $invoices + $payroll, 3),
            'overdue' => Expense::where('business_id', $bid)->unpaid()
                ->whereNotNull('due_date')->where('due_date', '<', now()->startOfDay())->count()
                + SupplierInvoice::where('business_id', $bid)->whereColumn('paid', '<', 'total')
                    ->whereNotNull('due_at')->where('due_at', '<', now()->startOfDay())->count(),
        ];
    }
}
