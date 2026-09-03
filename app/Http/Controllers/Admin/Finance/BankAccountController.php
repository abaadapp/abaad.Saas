<?php

namespace App\Http\Controllers\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\BankStatementLine;
use App\Support\Bank;
use App\Support\Demo;
use App\Support\Ledger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * الحسابات البنكية — بيانات كلّ حساب ورصيده وكشفه.
 *
 * لكلّ حساب ورقةٌ في شجرة الحسابات تُنشأ معه: بلا الورقة يبقى الرصيد رقمًا في
 * شاشةٍ لا يظهر في ميزان المراجعة ولا في الميزانية، فيقرأ المحاسب أصولًا
 * ينقصها ما في البنك.
 */
class BankAccountController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    public function index(): Response
    {
        $bid = $this->bid();
        Ledger::ensureSystemAccounts($bid);

        // نشاطٌ بلا حساب يرى شاشةً فارغة لا تقول ماذا يفعل — فيُهيّأ الأوّل
        Bank::account($bid);

        /*
         * حسابٌ بلا ورقةٍ في الشجرة يُستدرك هنا.
         *
         * الحسابات التي أُنشئت قبل هذه النسخة — ومنها الأوّل الذي يُهيّئه
         * `Bank::account` — لا `account_id` لها. وبلا ورقة يبقى رصيدها رقمًا
         * في هذه الشاشة لا يظهر في ميزان المراجعة ولا في الميزانية، ويُحذف
         * الحساب بلا حارسٍ لأن الحارس يسأل عن حركة ورقته.
         */
        BankAccount::where('business_id', $bid)->whereNull('account_id')->orderBy('id')->get()
            ->each(fn ($a) => $a->update(['account_id' => $this->leafFor($bid, $a)->id]));

        $accounts = BankAccount::where('business_id', $bid)->with('account')
            ->orderByDesc('is_primary')->orderBy('id')->get();

        $lineCounts = BankStatementLine::where('business_id', $bid)
            ->selectRaw('bank_account_id, COUNT(*) total, SUM(CASE WHEN match_status = ? THEN 1 ELSE 0 END) matched', ['مطابق'])
            ->groupBy('bank_account_id')->get()->keyBy('bank_account_id');

        return Inertia::render('Admin/Finance/Banks', [
            'accounts' => $accounts->map(fn ($a) => [
                'id' => $a->id,
                'label' => $a->displayName(),
                'bank_name' => $a->bank_name,
                'account_name' => $a->account_name,
                'iban' => $a->iban,
                'opening_balance' => (float) $a->opening_balance,
                'opening_date' => optional($a->opening_date)->format('Y-m-d'),
                'active' => (bool) $a->active,
                'is_primary' => (bool) $a->is_primary,
                'balance' => $a->balance(),
                'account_code' => $a->account?->code,
                'lines' => (int) ($lineCounts[$a->id]->total ?? 0),
                'matched' => (int) ($lineCounts[$a->id]->matched ?? 0),
            ])->all(),
            'summary' => [
                'count' => $accounts->where('active', true)->count(),
                'balance' => round($accounts->where('active', true)->sum(fn ($a) => $a->balance()), 3),
            ],
            'today' => now()->format('Y-m-d'),
        ]);
    }

    public function store(Request $request)
    {
        $bid = $this->bid();
        $data = $this->rules($request);

        DB::transaction(function () use ($bid, $data) {
            $account = BankAccount::create($data + [
                'business_id' => $bid,
                // أوّل حسابٍ هو الرئيسيّ بالضرورة: بلا رئيسيّ لا وجهة لما لا يُنسب
                'is_primary' => ! BankAccount::where('business_id', $bid)->exists(),
            ]);

            $account->update(['account_id' => $this->leafFor($bid, $account)->id]);
        });

        \App\Support\Activity::log('created', 'أضاف حسابًا بنكيًّا: '.($data['label'] ?? $data['bank_name'] ?? ''));

        return back()->with('toast', ['msg' => __('أُضيف الحساب البنكي'), 'type' => 'success']);
    }

    public function update(Request $request, $id)
    {
        $bid = $this->bid();
        $account = BankAccount::where('business_id', $bid)->findOrFail($id);
        $data = $this->rules($request);

        $account->update($data);

        /*
         * اسمُ الورقة يتبع اسم الحساب — إلا الورقة النظامية.
         *
         * «البنك» (1200) اسمٌ يعرفه كلّ محاسب ويقصده الترحيل التلقائي؛
         * تسميتُه باسم فرعٍ من فروع بنكٍ بعينه تُخفي ما هو.
         */
        if ($account->account && ! $account->account->system_key) {
            $account->account->update(['name' => __('البنك: ').$account->fresh()->displayName()]);
        } elseif (! $account->account) {
            $account->update(['account_id' => $this->leafFor($bid, $account->fresh())->id]);
        }

        return back()->with('toast', ['msg' => __('حُفظ الحساب البنكي'), 'type' => 'success']);
    }

    /** تعيين الحساب الرئيسيّ — واحدٌ لا غير */
    public function primary($id)
    {
        $bid = $this->bid();
        $account = BankAccount::where('business_id', $bid)->findOrFail($id);

        DB::transaction(function () use ($bid, $account) {
            BankAccount::where('business_id', $bid)->update(['is_primary' => false]);
            $account->update(['is_primary' => true, 'active' => true]);
        });

        return back()->with('toast', ['msg' => __('صار الحساب الرئيسيّ'), 'type' => 'success']);
    }

    public function destroy($id)
    {
        $bid = $this->bid();
        $account = BankAccount::where('business_id', $bid)->findOrFail($id);

        /*
         * حسابٌ عليه حركةٌ في الدفتر لا يُحذف.
         *
         * حذفُه يقطع الصلة بين قيودٍ مرحَّلة وبين الحساب الذي جرت فيه: يبقى
         * المبلغ في ميزان المراجعة ولا يُعرف أيّ بنكٍ خرج منه.
         */
        if ($account->account && $account->account->lines()->exists()) {
            return back()->with('toast', ['msg' => __('على الحساب حركةٌ في الدفتر — أوقفه ولا تحذفه'), 'type' => 'warning']);
        }

        if ($account->is_primary && BankAccount::where('business_id', $bid)->count() > 1) {
            return back()->with('toast', ['msg' => __('عيّن حسابًا رئيسيًّا آخر قبل الحذف'), 'type' => 'warning']);
        }

        DB::transaction(function () use ($account) {
            // الكشف المستورد يتبع حسابه، وورقتُه في الشجرة تُغلق ولا تُحذف —
            // والورقة النظامية لا تُغلق: الترحيل التلقائي يقصدها بمفتاحها
            $account->lines()->delete();
            if ($account->account && ! $account->account->system_key) {
                $account->account->update(['active' => false]);
            }
            $account->delete();
        });

        \App\Support\Activity::log('deleted', 'حذف حسابًا بنكيًّا', ['subject_id' => $account->id]);

        return back()->with('toast', ['msg' => __('حُذف الحساب البنكي'), 'type' => 'warning']);
    }

    /** كشف حسابٍ واحد ومطابقته */
    public function statement(Request $request, $id = null): Response
    {
        $bid = $this->bid();
        Bank::account($bid);

        $accounts = BankAccount::where('business_id', $bid)->orderByDesc('is_primary')->orderBy('id')->get();
        $current = ($id ? $accounts->firstWhere('id', (int) $id) : null) ?? $accounts->first();

        return Inertia::render('Admin/Finance/Statement', [
            'account' => Demo::bankAccount($current?->id),
            'statement' => Demo::bankStatement($current?->id),
            'lines' => Demo::bankLines($current?->id),
            'reconciliation' => Demo::reconciliationSummary($current?->id),
            'accounts' => $accounts->map(fn ($a) => ['id' => $a->id, 'label' => $a->displayName()])->all(),
            'today' => now()->format('Y-m-d'),
        ]);
    }

    private function rules(Request $request): array
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'account_name' => ['nullable', 'string', 'max:255'],
            'iban' => ['nullable', 'string', 'max:64'],
            'opening_balance' => ['nullable', 'numeric'],
            'opening_date' => ['nullable', 'date'],
            'active' => ['nullable', 'boolean'],
        ]);

        // حقلٌ فارغ يعني صفرًا لا فراغًا: العمود لا يقبل NULL
        $data['opening_balance'] = ($data['opening_balance'] ?? '') === '' ? 0 : $data['opening_balance'];

        return $data;
    }

    /**
     * ورقةُ الحساب البنكي في الشجرة.
     *
     * الأوّل يأخذ ورقة «البنك» (1200) نفسها، وما بعده يأخذ ورقةً أختًا لها
     * تحت «الأصول» — **لا** ابنةً لها.
     *
     * وهذا الفرق ليس ترتيبًا في الشكل: لو صارت 1200 أبًا لتعطّل أمران معًا.
     * الأوّل أنّ الترحيل التلقائي يقصدها بمفتاحها `bank`، والحساب ذو الأبناء
     * لا يُرحَّل إليه — فيسقط كلّ بيعٍ بالبطاقة برسالةٍ لا يفهمها الكاشير.
     * والثاني أنّ سطورها القديمة تبقى عليها، فتُقرأ مرّةً فيها ومرّةً في
     * مجموع أبنائها: يتضخّم البنك في الميزانية بلا سبب ظاهر.
     */
    private function leafFor(int $bid, BankAccount $bankAccount): Account
    {
        $main = Ledger::account($bid, 'bank');

        if (! $main) {
            Ledger::ensureSystemAccounts($bid);
            $main = Ledger::account($bid, 'bank');
        }

        $taken = BankAccount::where('business_id', $bid)
            ->where('id', '!=', $bankAccount->id)
            ->where('account_id', $main->id)->exists();

        if (! $taken && ! $main->children()->exists()) {
            return $main;
        }

        $n = BankAccount::where('business_id', $bid)->count();

        return Account::create([
            'business_id' => $bid,
            'parent_id' => $main->parent_id,
            'code' => $this->freeCode($bid, (string) ((int) $main->code + $n * 10)),
            'name' => __('البنك: ').$bankAccount->displayName(),
            'type' => 'أصل',
            'normal_side' => 'debit',
        ]);
    }

    /** أوّل رمزٍ غير مشغول — الرمز فريدٌ في القاعدة فالتصادم يُسقط الحفظ */
    private function freeCode(int $bid, string $wanted): string
    {
        $code = $wanted;
        $n = 0;

        while (Account::where('business_id', $bid)->where('code', $code)->exists()) {
            $code = $wanted.'-'.(++$n);
        }

        return $code;
    }
}
