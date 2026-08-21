<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\JobTitle;
use App\Models\User;
use App\Support\Activity;
use App\Support\Demo;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class JobTitleController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    public function store(Request $request)
    {
        $bid = $this->bid();
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('job_titles', 'name')->where(fn ($q) => $q->where('business_id', $bid)),
            ],
            /*
             * الصلاحية المكافئة اختيارية: صلاحيات الموظف تُحدَّد يدويًّا في
             * شاشته، فلم تعد الوظيفة تقرّرها. وحين تُترك، يُحفظ أدنى دور
             * معروف — لا قيمة فارغة تُسقط صاحبها خارج النظام.
             */
            'role' => ['nullable', 'string', Rule::in(array_keys(JobTitle::roles()))],
            'description' => ['nullable', 'string', 'max:255'],
        ], [
            'name.unique' => __('هذه الوظيفة موجودة مسبقًا.'),
        ], ['name' => __('اسم الوظيفة')]);

        JobTitle::create([
            'business_id' => $bid,
            'name' => $data['name'],
            'role' => ($data['role'] ?? null) ?: 'cashier',
            'description' => $data['description'] ?? null,
        ]);
        Activity::log('created', 'أضاف وظيفة: ' . $data['name']);

        return back()->with('toast', ['msg' => __('تم إضافة الوظيفة'), 'type' => 'success']);
    }

    /**
     * تعديل وظيفة.
     *
     * والفخّ هنا أن الموظفين مرتبطون بالوظيفة **بالاسم لا بالمعرّف**
     * (users.job_title نصّ). فتغييرُ الاسم وحده يترك موظفين معلَّقين على
     * وظيفة لا وجود لها: لا تظهر في قائمة التعديل، وأوّل حفظٍ لبياناتهم
     * يُرفض بـ«الوظيفة المحددة غير موجودة». ولذلك يُنقل الاسم إليهم.
     *
     * والدور لم يعد يُطلب في الشاشة — الصلاحيات تُحدَّد لكل موظف على حدة —
     * فيبقى دور الوظيفة كما هو ولا يُمسّ دور حامليها عند تغيير الاسم.
     */
    public function update(Request $request, $id)
    {
        $bid = $this->bid();
        $title = JobTitle::where('business_id', $bid)->findOrFail($id);

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('job_titles', 'name')
                    ->where(fn ($q) => $q->where('business_id', $bid))
                    ->ignore($title->id),
            ],
            'role' => ['nullable', 'string', Rule::in(array_keys(JobTitle::roles()))],
            'description' => ['nullable', 'string', 'max:255'],
        ], [
            'name.unique' => __('هذه الوظيفة موجودة مسبقًا.'),
        ], ['name' => __('اسم الوظيفة')]);

        $oldName = $title->name;
        $data['role'] = ($data['role'] ?? null) ?: $title->role;
        $title->update($data);

        // الاسم وحده هو ما يربط الموظف بوظيفته، فهو وحده ما يُنقل إليه
        $affected = User::where('business_id', $bid)->where('job_title', $oldName)
            ->update(['job_title' => $data['name']]);

        Activity::log('updated', "عدّل الوظيفة: {$oldName} → {$data['name']} (تأثّر {$affected} موظفًا)", ['subject_id' => $title->id]);

        $msg = __('تم تحديث الوظيفة');
        if ($affected > 0) {
            $msg .= ' · ' . __('حُدِّث :n موظفًا يحملها', ['n' => $affected]);
        }

        return back()->with('toast', ['msg' => $msg, 'type' => 'success']);
    }

    public function destroy($id)
    {
        $title = JobTitle::where('business_id', $this->bid())->findOrFail($id);

        // لا تُحذف وظيفة مستخدَمة — وإلا بقي موظفون بوظيفة لا وجود لها
        $used = User::where('business_id', $this->bid())->where('job_title', $title->name)->count();
        if ($used > 0) {
            return back()->with('toast', [
                'msg' => __('لا يمكن حذف «:name» لأنها مستخدمة لدى :count موظف. غيّر وظيفتهم أولًا.', ['name' => $title->name, 'count' => $used]),
                'type' => 'error',
            ]);
        }

        Activity::log('deleted', 'حذف الوظيفة: ' . $title->name, ['subject_id' => $title->id]);
        $title->delete();

        return back()->with('toast', ['msg' => __('تم حذف الوظيفة'), 'type' => 'warning']);
    }
}
