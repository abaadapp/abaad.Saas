<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ExpenseType;
use App\Support\Activity;
use App\Support\Demo;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExpenseTypeController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    public function store(Request $request)
    {
        $bid = $this->bid();
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('expense_types', 'name')->where(fn ($q) => $q->where('business_id', $bid)),
            ],
            'description' => ['nullable', 'string', 'max:255'],
        ], [
            'name.unique' => __('هذا النوع موجود مسبقًا.'),
        ], ['name' => __('اسم النوع')]);

        ExpenseType::create([
            'business_id' => $bid,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            /*
             * الحساب لا يُقبل هنا.
             *
             * إضافةُ النوع فعلٌ يوميّ يفعله من يسجّل المصروفات، والحسابُ قرارٌ
             * محاسبيّ. ولو قُبل في هذا المسار لَصار من يملك «المصروفات» يكتب
             * في شجرة الحسابات من بابٍ خلفيّ — يكفي أن يُرسل `account_id` مع
             * النموذج. فالربط في مساره وحده، وحارسُه صلاحيتُه.
             */
        ]);
        Activity::log('created', 'أضاف نوع مصروف: ' . $data['name']);

        return back()->with('toast', ['msg' => __('تم إضافة نوع المصروف'), 'type' => 'success']);
    }

    /**
     * ربط نوع المصروف بحسابه — للمحاسبة المتقدّمة وحدها.
     *
     * ولا يُسأل عنه من يسجّل المصروف: هو يختار «إيجار»، والنظام يعرف أنّها
     * 5300. وهذا هو الفرق بين النظامين — من يعرف المحاسبة يضبط الخريطة مرّةً،
     * ومن لا يعرفها يعمل كلّ يوم بلا أن يراها.
     */
    public function update(Request $request, $id)
    {
        $bid = $this->bid();
        $type = ExpenseType::where('business_id', $bid)->findOrFail($id);

        $data = $request->validate([
            'account_id' => ['nullable', Rule::exists('accounts', 'id')->where('business_id', $bid)],
        ]);

        $account = $data['account_id'] ?? null
            ? Account::where('business_id', $bid)->find($data['account_id'])
            : null;

        /*
         * ورقةٌ مفتوحة وحدها تقبل الربط.
         *
         * الحساب المغلق أو الذي صار أبًا لغيره لا يُرحَّل إليه، فربطُه بالنوع
         * يجعل كلّ مصروفٍ منه يُردّ عند التسجيل برسالةٍ لا يفهمها من سجّله —
         * والخطأ وقع يوم الربط لا يوم التسجيل.
         */
        if ($account && ! $account->isPostable()) {
            return back()->withErrors([
                'account_id' => __('لا يُرحَّل إلى «:name»: حسابٌ مغلق أو له حسابات فرعية', ['name' => $account->name]),
            ]);
        }

        // ونوعُ الحساب مصروف: ربطُ الإيجار بحساب بنكٍ يقلب القيد ولا يشتكي
        if ($account && $account->type !== 'مصروف') {
            return back()->withErrors([
                'account_id' => __('اختر حسابًا من المصروفات — «:name» ليس منها', ['name' => $account->name]),
            ]);
        }

        $type->update(['account_id' => $account?->id]);

        Activity::log('updated', $account
            ? 'ربط نوع المصروف «'.$type->name.'» بحساب '.$account->code.' — '.$account->name
            : 'فكّ ربط نوع المصروف «'.$type->name.'» بحسابه',
            ['subject_id' => $type->id]);

        return back()->with('toast', ['msg' => __('حُفظ ربط الحساب'), 'type' => 'success']);
    }

    public function destroy($id)
    {
        $type = ExpenseType::where('business_id', $this->bid())->findOrFail($id);
        Activity::log('deleted', 'حذف نوع المصروف: ' . $type->name, ['subject_id' => $type->id]);
        $type->delete();

        return back()->with('toast', ['msg' => __('تم حذف نوع المصروف'), 'type' => 'warning']);
    }
}
