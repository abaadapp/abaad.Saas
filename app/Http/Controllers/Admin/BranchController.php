<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Support\Demo;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    /** تبديل الفرع الحالي (يُحفظ في الجلسة) */
    public function switch(Request $request, $id)
    {
        if ($id === 'all') {
            $request->session()->forget('current_branch');
        } else {
            $request->session()->put('current_branch', (int) $id);
        }

        return back();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);
        $data['business_id'] = $this->bid();
        Branch::create($data);
        \App\Support\Activity::log('created', 'أضاف فرعًا: ' . $data['name']);

        return back()->with('toast', ['msg' => 'تم إضافة الفرع', 'type' => 'success']);
    }

    public function destroy($id)
    {
        $branch = Branch::where('business_id', $this->bid())->findOrFail($id);
        \App\Support\Activity::log('deleted', 'حذف الفرع: ' . $branch->name, ['subject_id' => $branch->id]);
        $branch->delete();

        return back()->with('toast', ['msg' => 'تم حذف الفرع', 'type' => 'warning']);
    }
}
