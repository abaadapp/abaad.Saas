<?php

namespace App\Http\Controllers\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\BoutiqueSettlement;
use App\Models\Expense;
use App\Models\PayrollRun;
use App\Models\SupplierInvoice;
use App\Models\Transaction;
use App\Support\Bank;
use App\Support\Demo;
use App\Support\Ledger;
use App\Support\Receivables;
use App\Support\Settlements;
use App\Support\SupplierInvoices;
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
    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

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
        // وأرصدتُها باستعلامٍ واحد — انظر `Account::balancesFor`
        $balances = Account::balancesFor($banks->pluck('account'));

        /*
         * صافي الربح يُقرأ من مستنداته لا من الحركة.
         *
         * `Demo::reportSummary` تحسبه من الفواتير والمصروفات — وهو الحساب
         * نفسه الذي يقرؤه ملخّص المبيعات ولوحة التحكم. وحسابُه هنا من جديد
         * كان سيُنتج رقمًا ثالثًا لا يطابق أيًّا منهما.
         */
        $report = Demo::reportSummary($range);

        /*
         * ومُجملُ الربح من القيم نفسِها — لا حسابٌ ثانٍ لتكلفة البضاعة.
         *
         * `cogs` يحسبه `reportSummary` أصلًا ويطرحه داخل `profit`، فكان
         * صافي الربح يُقرأ بلا ما يشرحه. فيُعرض الطرحُ خطوتين:
         *   مجمل الربح = صافي الإيرادات − تكلفة البضاعة المباعة
         *   صافي الربح = مجمل الربح − المصروفات التشغيلية
         * و`profit` يبقى كما حسبه `reportSummary` — لا يُعاد حسابُه هنا.
         * وصافي الإيرادات من الدفتر (`Ledger::netRevenue`)، والمبيعاتُ
         * والضريبةُ من القيود نفسِها — فالمبيعات − الضريبة = صافي الإيرادات.
         */
        $cogs = round((float) $report['cogs'], 3);
        $gross = round((float) $report['net_revenue'] - $cogs, 3);

        return Inertia::render('Admin/Finance/Summary', [
            'range' => $range,
            'cash' => round(Ledger::account($bid, 'cash')?->balance() ?? 0.0, 3),
            // ومجموعُ البنك من `Bank::total` — رقمٌ واحد هنا وفي شاشة الحسابات
            'bank' => Bank::total($bid),
            'accounts' => $banks->map(fn ($a) => [
                'id' => $a->id,
                'label' => $a->displayName(),
                'balance' => $balances[$a->account_id] ?? 0.0,
                'active' => (bool) $a->active,
            ])->values()->all(),
            'period' => [
                'sales' => round((float) $report['sales'], 3),
                'cogs' => $cogs,
                'gross_profit' => $gross,
                'expenses' => round((float) $report['expenses'], 3),
                'profit' => round((float) $report['profit'], 3),
                'tax' => round((float) $report['tax'], 3),
                'in' => round((float) ($byType['دخل'] ?? 0), 3),
                'out' => round((float) ($byType['مصروف'] ?? 0), 3),
                // التحويل ينتقل ولا يدخل ولا يخرج — يُعرض وحده أو لا يُعرض
                'transfers' => round((float) ($byType['تحويل'] ?? 0), 3),
            ],
            /*
             * والتحصيلُ والسدادُ في المدة — مالٌ قُبض أو دُفع فعلًا، بالفترة
             * نفسِها. للعلم وحده: لا يدخل `period` ولا يمسّ ربحًا ولا ذمّة.
             * انظر `Settlements`.
             */
            'settlements' => [
                'collections' => Settlements::collections($bid, $start),
                'supplier_payments' => Settlements::supplierPayments($bid, $start),
            ],
            'dues' => $this->dueTotals($bid),
            /*
             * وسنداتٌ وصلت ولم تُعتمد — معلومةٌ بجوار الدَّين لا داخله.
             *
             * لا تدخل `dues`: الذمّةُ تنشأ بالاعتماد وحده (`SupplierInvoice::scopeOwed`)،
             * ولا قيدَ لها في الدفتر بعد. لكنّ من يقرّر أيدفع اليوم يريد أن
             * يعرف أنّ وراء «عليك الآن» أوراقًا تنتظر توقيعه. والمرفوضةُ
             * والملغاة ليست هنا ولا هناك.
             */
            'pending_invoices' => $this->pendingInvoices($bid),
            /*
             * و«ما لك» بجوار «ما عليك».
             *
             * الملخّصُ كان يجيب عن نصف السؤال: كم عليّ. ومن يقرّر أيدفع اليوم
             * أم ينتظر يحتاج النصف الآخر — كم لي وكم منه تأخّر.
             *
             * ويُقرأ من `Receivables` نفسها التي تقرأ منها شاشةُ الذمم وصفحةُ
             * العميل: ثلاث شاشاتٍ برقمٍ واحد.
             */
            'receivables' => $this->receivableCard($bid),
        ]);
    }

    /**
     * «لك الآن» مفصّلًا — من `Receivables::totals` كما هي، وقيمتان مشتقّتان.
     *
     * `total` إجماليُّ الذمم **قبل** الرصيد الدائن: الفواتيرُ المفتوحة ومعها
     * البيعاتُ الآجلة التي لم تُفوتَر. فلا يُسمّى «صافيًا»:
     *
     *   invoiced = total − uninvoiced   فواتيرُ العملاء وحدها
     *   net      = total − credit       ما يبقى لك بعد ما دفعوه مقدّمًا
     *
     * ولا يُمسّ حسابُ الذمم نفسُه: طرحٌ للعرض، والأصلُ في `Receivables`.
     *
     * @return array<string, float|int>
     */
    private function receivableCard(int $bid): array
    {
        $r = Receivables::totals($bid);

        return $r + [
            'invoiced' => round($r['total'] - $r['uninvoiced'], 3),
            'net' => round($r['total'] - $r['credit'], 3),
        ];
    }

    /**
     * المبالغ المستحقة — ما على المتجر، مجموعًا في مكانٍ واحد.
     *
     * وما للمتجر على عملائه بابُه «الذمم المدينة»: خلطُ ما لك بما عليك في
     * جدولٍ واحد يجعل التاجر يقرأ رقمًا لا يعرف أدائنٌ هو أم مدين. والملخّصُ
     * وحده يجمع الطرفين — مفصولين بعنوانيهما.
     */
    public function dues(): Response
    {
        $bid = $this->bid();

        $expenses = Expense::where('business_id', $bid)->unpaid()
            ->orderByRaw('due_date is null')->orderBy('due_date')->orderByDesc('id')
            ->limit(200)->get();

        /*
         * والمعتمَدةُ وحدَها دَين — انظر `SupplierInvoice::scopeOwed`.
         *
         * كانت تُجمع الحالاتُ كلُّها: سندٌ رُفض لأنّه مكرَّر، وسندٌ أُلغي
         * وعُكس قيدُه، وسندٌ لم يوقّعه أحد بعد — كلُّها تقول للتاجر «عليك».
         * وحسابُ الموردين في الدفتر لا يعرف منها شيئًا، ولا يُقبل سدادُ
         * واحدةٍ منها أصلًا (`SupplierInvoiceController::pay` تردّ غيرَ
         * المعتمَد). فيقرأ التاجرُ دَينًا لا وجود له، ويقرأ في سجلّ
         * المشتريات غيرَه — فقد أُصلح هناك وحدَه.
         */
        $invoices = SupplierInvoice::where('business_id', $bid)->with('supplier')
            ->owed()->whereColumn('paid', '<', 'total')
            ->orderByRaw('due_at is null')->orderBy('due_at')->orderByDesc('id')
            ->limit(200)->get();

        $runs = PayrollRun::where('business_id', $bid)->with('lines')
            ->where('status', 'معتمدة')->orderByDesc('period')->get();

        $today = now()->startOfDay();

        /*
         * ═══ ولمن التسوية: البوتيكُ باسمه ═══
         *
         * مصروفُ التسوية يقول «تسوية بوتيكات» نوعًا — ولا يقول لأيّ بوتيك.
         * والاسمُ يُقرأ من العلاقة لا من نصّ الوصف: `boutique_settlements.expense_id`
         * ← `boutique_id` ← `Boutique`. الوصفُ نصٌّ يُكتب ويُترجم، والعلاقةُ لا.
         *
         * واستعلامٌ واحد للصفحة كلّها — لا استعلامٌ لكلّ صفّ.
         */
        $boutiqueOf = BoutiqueSettlement::where('business_id', $bid)
            ->whereIn('expense_id', $expenses->pluck('id'))
            ->with('boutique:id,name,name_en')
            ->get(['expense_id', 'boutique_id'])
            ->mapWithKeys(fn ($s) => [(int) $s->expense_id => $s->boutique?->label()]);

        return Inertia::render('Admin/Finance/Dues', [
            'expenses' => $expenses->map(fn ($e) => [
                'id' => $e->id,
                'reference' => $e->reference,
                'title' => $e->description ?: $e->type,
                'type' => $e->type,
                // اسمُ البوتيك لمصروف تسويته — `null` لكلّ مصروفٍ آخر
                'boutique' => $boutiqueOf[$e->id] ?? null,
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
     * سنداتُ الموردين بانتظار الاعتماد — عددُها ومجموعُها.
     *
     * @return array{count: int, total: float}
     */
    private function pendingInvoices(int $bid): array
    {
        $pending = SupplierInvoice::where('business_id', $bid)
            ->where('approval_status', SupplierInvoices::PENDING);

        return [
            'count' => (clone $pending)->count(),
            'total' => round((float) (clone $pending)->sum('total'), 3),
        ];
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
            ->owed()->whereColumn('paid', '<', 'total')
            ->selectRaw('COALESCE(SUM(total - paid),0) t')->value('t'), 3);

        $payroll = round((float) PayrollRun::where('business_id', $bid)->where('status', 'معتمدة')
            ->with('lines')->get()
            ->sum(fn ($r) => $r->lines->where('paid', false)->sum('net')), 3);

        /*
         * والمتأخّرُ وما يستحقّ خلال سبعة أيام — عددًا ومبلغًا.
         *
         * من المصدرين اللذين لهما تاريخُ استحقاق: المصروفُ غيرُ المدفوع
         * (`due_date`) وسندُ المورّد المعتمَد (`due_at`). والرواتبُ لا تاريخَ
         * استحقاقٍ لها في المسيرة، فلا يُخترع لها موعد: تبقى في «عليك الآن»
         * ولا تدخل هنا.
         *
         * والحدُّ الأعلى «قبل اليوم الثامن» لا «حتّى السابع»: SQLite يقرأ العمودَ
         * نصًّا (`2026-10-10 00:00:00`)، فـ`<= 2026-10-10` تُسقط اليومَ السابع
         * عليه وحده. و«أصغرُ من» صحيحةٌ على المحرّكين. وما يستحقّ قريبًا من اليوم
         * حتّى نهاية اليوم السابع، والمتأخّرُ خارجه — التعريفُ نفسُه في
         * `Receivables::totals`.
         */
        $today = now()->toDateString();
        $afterWeek = now()->addDays(8)->toDateString();

        $expenseDue = fn () => Expense::where('business_id', $bid)->unpaid()->whereNotNull('due_date');
        $invoiceDue = fn () => SupplierInvoice::where('business_id', $bid)->owed()
            ->whereColumn('paid', '<', 'total')->whereNotNull('due_at');

        $late = [
            $expenseDue()->where('due_date', '<', $today)
                ->selectRaw('COUNT(*) c, COALESCE(SUM(amount),0) s')->first(),
            $invoiceDue()->where('due_at', '<', $today)
                ->selectRaw('COUNT(*) c, COALESCE(SUM(total - paid),0) s')->first(),
        ];

        $soon = (float) $expenseDue()->where('due_date', '>=', $today)->where('due_date', '<', $afterWeek)->sum('amount')
            + (float) $invoiceDue()->where('due_at', '>=', $today)->where('due_at', '<', $afterWeek)
                ->selectRaw('COALESCE(SUM(total - paid),0) s')->value('s');

        $lateCount = (int) $late[0]->c + (int) $late[1]->c;

        return [
            'expenses' => round($expenses, 3),
            'invoices' => $invoices,
            'payroll' => $payroll,
            'total' => round($expenses + $invoices + $payroll, 3),
            // العددُ باسمه القديم أيضًا — تقرؤه شاشةُ المستحقّات
            'overdue' => $lateCount,
            'overdue_count' => $lateCount,
            'overdue_amount' => round((float) $late[0]->s + (float) $late[1]->s, 3),
            'due_soon_amount' => round($soon, 3),
        ];
    }
}
