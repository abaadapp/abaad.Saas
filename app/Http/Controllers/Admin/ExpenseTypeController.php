<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
        ], [
            'name.unique' => 'هذا النوع موجود مسبقًا.',
        ], ['name' => 'اسم النوع']);

        ExpenseType::create(['business_id' => $bid, 'name' => $data['name']]);
        Activity::log('created', 'أضاف نوع مصروف: ' . $data['name']);

        return back()->with('toast', ['msg' => 'تم إضافة نوع المصروف', 'type' => 'success']);
    }

    public function destroy($id)
    {
        $type = ExpenseType::where('business_id', $this->bid())->findOrFail($id);
        Activity::log('deleted', 'حذف نوع المصروف: ' . $type->name, ['subject_id' => $type->id]);
        $type->delete();

        return back()->with('toast', ['msg' => 'تم حذف نوع المصروف', 'type' => 'warning']);
    }
}
