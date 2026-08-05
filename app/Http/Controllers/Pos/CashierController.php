<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Support\PosCashier;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** اختيار الموظف الواقف على الصندوق — انظر App\Support\PosCashier */
class CashierController extends Controller
{
    public function choose()
    {
        return Inertia::render('Pos/ChooseCashier', [
            'employees' => PosCashier::selectable()->map(fn ($u) => [
                'id' => $u->id,
                // لا name_en على المستخدمين، فالاسم يُعرض كما أُدخل — كما في
                // بقيّة شاشات الموظفين
                'name' => $u->name,
                'role' => $u->job_title ?: $u->roleLabel(),
                'avatar' => $u->avatar,
            ])->values()->all(),
            'currentId' => PosCashier::current()?->id,
        ]);
    }

    public function select(Request $request)
    {
        $data = $request->validate(['employee_id' => ['required', 'integer']]);

        // لا نثق بالمعرّف القادم من النموذج: set() يرفض من ليس من موظفي
        // هذا المتجر، فلا تُنسب بيعة إلى اسم من متجر الجار
        if (! PosCashier::set((int) $data['employee_id'])) {
            return back()->with('toast', ['msg' => __('هذا الموظف غير متاح.'), 'type' => 'danger']);
        }

        return redirect()->route('pos.index');
    }

    /** «تبديل الموظف» — يعيد الشاشة إلى الاختيار بلا تسجيل خروج */
    public function leave()
    {
        PosCashier::forget();

        return redirect()->route('pos.cashier');
    }
}
