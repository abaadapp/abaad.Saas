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

    /**
     * حذفُ مورّد — ما لم يكن له تاريخُ شراء.
     *
     * ═══ ما كان يقع ═══
     *
     * الحذفُ كان مطلقًا، وللمورّد في القاعدة نسبان مختلفان:
     *
     *  • **سنداتُه** مقيّدةٌ عليه (`restrictOnDelete`). فمورّدٌ له سندٌ واحد
     *    كان يردّ صفحةَ خطأٍ خمسمئة — لا رسالةً تقول لمَ. والزرُّ مرسومٌ على
     *    كلّ صفّ: بابٌ معروضٌ لا يُفتح.
     *
     *  • **أوامرُه وأوراقُ استلامه** تُفرَّغ مراجعُها (`nullOnDelete`). فتُحذف
     *    صامتةً صلةُ خمسةِ أوامرَ بمن ورّدها — يبقى الاسمُ لقطةً في
     *    `supplier_name` ولا يبقى شيءٌ يُصفّى به أو يُجمع عليه. وهذا أسوأُ من
     *    الخطأ: لا أحد يعرف أنّه وقع.
     *
     * ═══ والقاعدة ═══
     *
     * من اشتُري منه مرّةً يبقى: حذفُه يُعيد كتابةَ تاريخ الشراء. ومن أُضيف
     * خطأً ولم يُشترَ منه شيء يُحذف كما كان.
     */
    public function destroy($id)
    {
        $supplier = Supplier::where('business_id', $this->bid())->findOrFail($id);

        $orders = $supplier->purchaseOrders()->count();
        $invoices = $supplier->invoices()->count();

        if ($orders > 0 || $invoices > 0) {
            // ‏ثلاثُ رسائلَ كاملة لا واحدةٌ تُركَّب من قطع: المركَّبةُ لا تُترجَم
            $msg = match (true) {
                $orders > 0 && $invoices > 0 => __('لهذا المورّد :o أمر شراء و:i سند — لا يُحذف، وإلّا بقيت أوراقُه بلا من أصدرها.', ['o' => $orders, 'i' => $invoices]),
                $orders > 0 => __('لهذا المورّد :o أمر شراء — لا يُحذف، وإلّا بقيت أوامرُه بلا من ورّدها.', ['o' => $orders]),
                default => __('لهذا المورّد :i سند — لا يُحذف، وإلّا بقيت سنداتُه بلا من أصدرها.', ['i' => $invoices]),
            };

            return back()->with('toast', ['msg' => $msg, 'type' => 'warning']);
        }

        $name = $supplier->name;
        $supplier->delete();
        \App\Support\Activity::log('deleted', 'حذف المورّد: ' . $name);

        return back()->with('toast', ['msg' => __('تم حذف المورّد'), 'type' => 'warning']);
    }
}
