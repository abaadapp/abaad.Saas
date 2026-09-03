<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Expense;
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
            'account_key' => $data['account_key'] ?? null,
        ]);
        Activity::log('created', 'أضاف نوع مصروف: '.$data['name']);

        return back()->with('toast', ['msg' => __('تم إضافة نوع المصروف'), 'type' => 'success']);
    }

    /**
     * تعديل النوع — وبابُ ربط الحساب لمن أنشأ أنواعه قبل أن يوجد الربط.
     *
     * بدونه لا سبيل إلى الحساب إلا بحذف النوع وإعادته، وحذفُه يترك المصروفات
     * المسجّلة تحت اسمٍ لا نوع له.
     *
     * والاسم يُغيَّر ومصروفاتُه القديمة تحمل الاسم القديم نصًّا — فتُنقل معه،
     * وإلّا صار للتاجر نوعٌ فارغٌ وتاريخٌ معلّق باسمٍ لا وجود له في القائمة.
     */
    public function update(Request $request, $id)
    {
        $bid = $this->bid();
        $type = ExpenseType::where('business_id', $bid)->findOrFail($id);

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
    }

    public function destroy($id)
    {
        $type = ExpenseType::where('business_id', $this->bid())->findOrFail($id);
        Activity::log('deleted', 'حذف نوع المصروف: '.$type->name, ['subject_id' => $type->id]);
        $type->delete();

        return back()->with('toast', ['msg' => __('تم حذف نوع المصروف'), 'type' => 'warning']);
    }
}
