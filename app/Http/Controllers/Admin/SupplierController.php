<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Support\Demo;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // مكتوبًا بيدٍ يعلو على النقل الآليّ — انظر LocalName::apply
            'name_en' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $data = \App\Support\LocalName::apply($data);
        $supplier = Supplier::create(array_merge($data, ['business_id' => $this->bid()]));
        \App\Support\Activity::log('created', 'أضاف مورّدًا: ' . $data['name']);

        /*
         * ومن أضافه من داخل شاشةٍ أخرى يُختار له فور العودة.
         *
         * شاشةُ أمر الشراء تفتح النافذة وتعود إلى نفسها، فلا يبقى إلّا أن
         * يُقال أيُّهم. ومن لا يُختار له يعود يبحث في القائمة عن اسمٍ كتبه
         * قبل ثانية — أو يظنّ أنّ الحفظ لم يقع.
         *
         * والبابُ واحدٌ لا يُنسخ: شاشةُ الموردين تمرّ من هنا كذلك وتُهمل
         * المفتاح، فلا تتفرّق قاعدتان لإنشاء مورّد.
         */
        return back()->with('toast', ['msg' => __('تم إضافة المورّد بنجاح'), 'type' => 'success'])
            ->with('new_supplier_id', $supplier->id);
    }

    public function update(Request $request, $id)
    {
        $supplier = Supplier::where('business_id', $this->bid())->findOrFail($id);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // مكتوبًا بيدٍ يعلو على النقل الآليّ — انظر LocalName::apply
            'name_en' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $supplier->update(\App\Support\LocalName::apply($data));
        \App\Support\Activity::log('updated', 'عدّل المورّد: ' . $supplier->name, ['subject_id' => $supplier->id]);

        return back()->with('toast', ['msg' => __('تم تحديث بيانات المورّد'), 'type' => 'success']);
    }

    public function destroy($id)
    {
        $supplier = Supplier::where('business_id', $this->bid())->findOrFail($id);
        $name = $supplier->name;
        $supplier->delete();
        \App\Support\Activity::log('deleted', 'حذف المورّد: ' . $name);

        return back()->with('toast', ['msg' => __('تم حذف المورّد'), 'type' => 'warning']);
    }
}
