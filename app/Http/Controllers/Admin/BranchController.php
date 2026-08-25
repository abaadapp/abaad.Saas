<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\PosDevice;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\PlanLimits;
use App\Support\PosTerminal;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    /** تبديل الفرع الحالي (يُحفظ في الجلسة) */
    public function switch(Request $request, $branch)
    {
        if ($branch === 'all') {
            $request->session()->forget('current_branch');

            return back();
        }

        // المعرّف يصل من شريط العنوان، وكان يُخزَّن كما هو. فرعُ متجرٍ آخر
        // كان يمرّ: الاستعلامات تُرجع فراغًا (لأنها مقيّدة بـbusiness_id)
        // لكن اسم الفرع يُعرض في الترويسة — تسريب اسم من متجر الجار.
        $belongs = Branch::where('id', (int) $branch)
            ->where('business_id', $this->bid())
            ->exists();

        abort_unless($belongs, 404);

        $request->session()->put('current_branch', (int) $branch);

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
        PlanLimits::enforce(auth()->user()->business, 'branches');
        Branch::create($data);
        Activity::log('created', 'أضاف فرعًا: '.$data['name']);

        return back()->with('toast', ['msg' => __('تم إضافة الفرع'), 'type' => 'success']);
    }

    /**
     * حذف الفرع — بعد إفراغه، لا قبله.
     *
     * كان الحذف يقع بلا سؤال، فيبقى مخزون الفرع في مكانه ويختفي من كلّ شاشة:
     * الصنف يقول «الكمية ١٠» ومجموع الفروع الظاهرة ٤. ستّ قطعٍ لا يجدها
     * التاجر في فرعٍ ولا يستطيع صرفها ولا بيعها، ولا يعرف أين ذهبت — ولا
     * يكتشف الفرق إلا في جردٍ آخر السنة، حين يكون سببُه قد نُسي.
     *
     * فيُمنع الحذف ويُقال له كم بقي وأين ينقله: التحويل بين الفروع موجود،
     * والمنعُ مع إرشادٍ أصدق من حذفٍ يُخفي بضاعة.
     *
     * وأجهزةُ الفرع تُبطَل معه: جهازٌ يبقى «نشطًا» على فرعٍ لا وجود له يردّ
     * كاشيرَه برسالة «رمز غير صحيح أو غير مسموح في هذا الفرع» — فيظنّ أنّه
     * أخطأ رمزه، والسبب حذفٌ وقع في اللوحة. وهو ما يقع عند نقل الجهاز بين
     * الفروع أصلًا، وحذفُ الفرع أشدّ من نقله.
     */
    public function destroy($id)
    {
        $branch = Branch::where('business_id', $this->bid())->findOrFail($id);

        $left = (float) BranchStock::where('branch_id', $branch->id)
            ->where('quantity', '>', 0)->sum('quantity');

        if ($left > 0) {
            return back()->withErrors(['branch' => __(
                'في «:branch» ما زال :qty قطعة. انقلها إلى فرعٍ آخر ثم احذفه.',
                ['branch' => $branch->name, 'qty' => rtrim(rtrim(number_format($left, 3, '.', ''), '0'), '.')]
            )]);
        }

        $devices = PosDevice::where('business_id', $this->bid())
            ->where('branch_id', $branch->id)
            ->where('status', PosDevice::ACTIVE)->get();

        foreach ($devices as $device) {
            PosTerminal::revoke($device);
        }

        Activity::log('deleted', 'حذف الفرع: '.$branch->name, ['subject_id' => $branch->id, 'subject_type' => 'branch']);
        $branch->delete();

        $msg = $devices->isEmpty()
            ? __('تم حذف الفرع')
            : __('تم حذف الفرع، وأُبطل تفعيل :count من أجهزته.', ['count' => $devices->count()]);

        return back()->with('toast', [
            'msg' => $msg,
            'type' => 'warning',
            'undo' => ['url' => route('admin.branches.restore', $branch->id), 'label' => $branch->name],
        ]);
    }
}
