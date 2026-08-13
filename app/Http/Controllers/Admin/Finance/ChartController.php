<?php

namespace App\Http\Controllers\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Support\Demo;
use App\Support\Ledger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * شجرة الحسابات — الهيكل الذي تُقرأ عليه كلّ الأرقام.
 *
 * ما يُحرَس هنا ثلاثة، وكلٌّ منها يُفسد الدفتر بصمت لو تُرك:
 *
 * ١) حسابٌ عليه حركة لا يُحذف. حذفُه يترك سطورًا معلّقة على حسابٍ مجهول،
 *    فلا يتوازن ميزانٌ بعده ولا يُعرف السبب.
 * ٢) حسابٌ نظاميّ لا يُحذف ولا يُغلق. الترحيل التلقائي يقصده بمفتاحه، فإغلاقه
 *    يوقف البيع نفسه — والكاشير يرى «لا يُرحَّل إلى الصندوق» ولا يفهمها.
 * ٣) أبٌ لا يصير ابنًا لابنه. الشجرة تصير حلقةً فيدور كل جمعٍ عليها إلى الأبد.
 */
class ChartController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    public function index(): Response
    {
        $bid = $this->bid();

        // متجرٌ أُنشئ قبل هذه النسخة لا شجرة له، وشاشةٌ فارغة لا تقول ماذا يفعل
        Ledger::ensureSystemAccounts($bid);

        $accounts = Account::where('business_id', $bid)->orderBy('code')->get();
        $balances = collect(Ledger::trialBalance($bid)['accounts'])->keyBy('id');
        $hasLines = Account::where('business_id', $bid)->has('lines')->pluck('id')->all();
        $parents = $accounts->whereNotNull('parent_id')->pluck('parent_id')->unique()->all();

        return Inertia::render('Admin/Finance/Chart', [
            'accounts' => $accounts->map(fn ($a) => [
                'id' => $a->id,
                'parent_id' => $a->parent_id,
                'code' => $a->code,
                'name' => $a->name,
                'type' => $a->type,
                'normal_side' => $a->normal_side,
                'active' => $a->active,
                // النظاميّ يُعلَّم في الشاشة: التاجر يرى لماذا لا يُحذف قبل أن يحاول
                'system' => (bool) $a->system_key,
                'is_parent' => in_array($a->id, $parents, true),
                'has_lines' => in_array($a->id, $hasLines, true),
                'balance' => (float) ($balances[$a->id]['balance'] ?? 0),
            ])->values()->all(),
            'trial' => Ledger::trialBalance($bid),
            'types' => array_keys(Account::TYPES),
        ]);
    }

    public function store(Request $request)
    {
        $bid = $this->bid();

        $data = $request->validate([
            'parent_id' => ['nullable', Rule::exists('accounts', 'id')->where('business_id', $bid)],
            'code' => ['required', 'string', 'max:20', Rule::unique('accounts', 'code')->where('business_id', $bid)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(array_keys(Account::TYPES))],
            'normal_side' => ['nullable', Rule::in(['debit', 'credit'])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        Account::create([
            'business_id' => $bid,
            'parent_id' => $data['parent_id'] ?? null,
            'code' => $data['code'],
            'name' => $data['name'],
            'type' => $data['type'],
            // الطبيعة تتبع النوع ما لم تُقلب عمدًا (حسابٌ مقابل)
            'normal_side' => $data['normal_side'] ?? Account::TYPES[$data['type']],
            'notes' => $data['notes'] ?? null,
        ]);

        \App\Support\Activity::log('created', 'أضاف حسابًا في الشجرة: '.$data['code'].' '.$data['name']);

        return back()->with('toast', ['msg' => __('أُضيف الحساب'), 'type' => 'success']);
    }

    public function update(Request $request, $id)
    {
        $bid = $this->bid();
        $account = Account::where('business_id', $bid)->findOrFail($id);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('accounts', 'code')->where('business_id', $bid)->ignore($account->id)],
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', Rule::exists('accounts', 'id')->where('business_id', $bid)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        // أبٌ يصير ابنًا لابنه يجعل الشجرة حلقةً لا نهاية لجمعها
        if (! empty($data['parent_id']) && $this->wouldLoop($account, (int) $data['parent_id'])) {
            return back()->withErrors(['parent_id' => __('لا يصير الحساب تابعًا لأحد فروعه')]);
        }

        /*
         * النوع والطبيعة لا يُعدَّلان بعد أوّل قيد.
         *
         * قلبُ الطبيعة على حسابٍ عليه حركة يقلب إشارة رصيده التاريخيّ كلّه:
         * تُقرأ أرباح العام الماضي خسائر بلا أن يمسّ أحدٌ قيدًا واحدًا.
         */
        if (! $account->lines()->exists()) {
            $extra = $request->validate([
                'type' => ['required', Rule::in(array_keys(Account::TYPES))],
                'normal_side' => ['required', Rule::in(['debit', 'credit'])],
            ]);
            $data += $extra;
        }

        $account->update($data);

        return back()->with('toast', ['msg' => __('حُفظ الحساب'), 'type' => 'success']);
    }

    /** فتحُ الحساب وإغلاقه — المغلق يبقى في التقارير ولا يقبل قيدًا جديدًا */
    public function toggle($id)
    {
        $account = Account::where('business_id', $this->bid())->findOrFail($id);

        if ($account->system_key && $account->active) {
            return back()->with('toast', [
                'msg' => __('حسابٌ يرحّل إليه النظام تلقائيًّا — إغلاقه يوقف البيع والشراء'),
                'type' => 'warning',
            ]);
        }

        $account->update(['active' => ! $account->active]);

        return back()->with('toast', [
            'msg' => $account->active ? __('فُتح الحساب') : __('أُغلق الحساب'),
            'type' => 'success',
        ]);
    }

    public function destroy($id)
    {
        $account = Account::where('business_id', $this->bid())->findOrFail($id);

        if ($account->system_key) {
            return back()->with('toast', ['msg' => __('حسابٌ نظاميّ لا يُحذف — أغلقه إن لم تعد تستعمله'), 'type' => 'warning']);
        }

        if ($account->lines()->exists()) {
            return back()->with('toast', ['msg' => __('على الحساب حركة — أغلقه ولا تحذفه'), 'type' => 'warning']);
        }

        if ($account->children()->exists()) {
            return back()->with('toast', ['msg' => __('احذف الحسابات الفرعية أولًا'), 'type' => 'warning']);
        }

        \App\Support\Activity::log('deleted', 'حذف الحساب: '.$account->code.' '.$account->name, ['subject_id' => $account->id]);
        $account->delete();

        return back()->with('toast', ['msg' => __('حُذف الحساب'), 'type' => 'warning']);
    }

    /** هل يجعل هذا الأبُ الشجرةَ حلقة؟ */
    private function wouldLoop(Account $account, int $parentId): bool
    {
        $seen = [];
        $cursor = Account::find($parentId);

        while ($cursor) {
            if ($cursor->id === $account->id) {
                return true;
            }
            // شجرةٌ معطوبة سابقًا لا تُعلّق الطلب إلى الأبد
            if (in_array($cursor->id, $seen, true)) {
                return true;
            }
            $seen[] = $cursor->id;
            $cursor = $cursor->parent;
        }

        return false;
    }
}
