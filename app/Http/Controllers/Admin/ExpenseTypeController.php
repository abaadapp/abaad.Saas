<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
<<<<<<< HEAD
use App\Models\Account;
=======
use App\Models\Expense;
>>>>>>> origin/main
use App\Models\ExpenseType;
use App\Support\Activity;
use App\Support\Books;
use App\Support\Demo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ExpenseTypeController extends Controller
{
    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    /**
     * قواعدُ النوع — واحدةٌ للإضافة وللتعديل.
     *
     * والحساب من قائمةٍ مغلقة: من ربط مصروفًا بـ«إيراد المبيعات» يقلب قيدَه
     * فيتوازن الدفتر ويكذب. و`nullable` تعني «اتركه للاسم» لا تعني خطأً.
     */
    private function rules(int $bid, ?int $ignore = null): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('expense_types', 'name')
                    ->where(fn ($q) => $q->where('business_id', $bid))
                    ->ignore($ignore),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'account_key' => ['nullable', Rule::in(Books::EXPENSE_ACCOUNTS)],
        ];
    }

    public function store(Request $request)
    {
        $bid = $this->bid();
        $data = $request->validate($this->rules($bid), [
            'name.unique' => __('هذا النوع موجود مسبقًا.'),
        ], ['name' => __('اسم النوع'), 'account_key' => __('الحساب')]);

        ExpenseType::create([
            'business_id' => $bid,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
<<<<<<< HEAD
            /*
             * الحساب لا يُقبل هنا.
             *
             * إضافةُ النوع فعلٌ يوميّ يفعله من يسجّل المصروفات، والحسابُ قرارٌ
             * محاسبيّ. ولو قُبل في هذا المسار لَصار من يملك «المصروفات» يكتب
             * في شجرة الحسابات من بابٍ خلفيّ — يكفي أن يُرسل `account_id` مع
             * النموذج. فالربط في مساره وحده، وحارسُه صلاحيتُه.
             */
=======
            'account_key' => $data['account_key'] ?? null,
>>>>>>> origin/main
        ]);
        Activity::log('created', 'أضاف نوع مصروف: '.$data['name']);

        return back()->with('toast', ['msg' => __('تم إضافة نوع المصروف'), 'type' => 'success']);
    }

    /**
<<<<<<< HEAD
     * ربط نوع المصروف بحسابه — للمحاسبة المتقدّمة وحدها.
     *
     * ولا يُسأل عنه من يسجّل المصروف: هو يختار «إيجار»، والنظام يعرف أنّها
     * 5300. وهذا هو الفرق بين النظامين — من يعرف المحاسبة يضبط الخريطة مرّةً،
     * ومن لا يعرفها يعمل كلّ يوم بلا أن يراها.
=======
     * تعديل النوع — وبابُ ربط الحساب لمن أنشأ أنواعه قبل أن يوجد الربط.
     *
     * بدونه لا سبيل إلى الحساب إلا بحذف النوع وإعادته، وحذفُه يترك المصروفات
     * المسجّلة تحت اسمٍ لا نوع له.
     *
     * والاسم يُغيَّر ومصروفاتُه القديمة تحمل الاسم القديم نصًّا — فتُنقل معه،
     * وإلّا صار للتاجر نوعٌ فارغٌ وتاريخٌ معلّق باسمٍ لا وجود له في القائمة.
>>>>>>> origin/main
     */
    public function update(Request $request, $id)
    {
        $bid = $this->bid();
        $type = ExpenseType::where('business_id', $bid)->findOrFail($id);

<<<<<<< HEAD
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
=======
        $data = $request->validate($this->rules($bid, $type->id), [
            'name.unique' => __('هذا النوع موجود مسبقًا.'),
        ], ['name' => __('اسم النوع'), 'account_key' => __('الحساب')]);

        $was = $type->name;

        DB::transaction(function () use ($type, $data, $was, $bid) {
            $type->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'account_key' => $data['account_key'] ?? null,
            ]);

            if ($was !== $data['name']) {
                Expense::where('business_id', $bid)
                    ->where('type', $was)
                    ->update(['type' => $data['name'], 'updated_at' => now()]);
            }
        });

        Activity::log('updated', 'عدّل نوع مصروف: '.$data['name'], ['subject_id' => $type->id]);

        return back()->with('toast', ['msg' => __('تم تعديل نوع المصروف'), 'type' => 'success']);
>>>>>>> origin/main
    }

    public function destroy($id)
    {
        $type = ExpenseType::where('business_id', $this->bid())->findOrFail($id);
        Activity::log('deleted', 'حذف نوع المصروف: '.$type->name, ['subject_id' => $type->id]);
        $type->delete();

        return back()->with('toast', ['msg' => __('تم حذف نوع المصروف'), 'type' => 'warning']);
    }
}
