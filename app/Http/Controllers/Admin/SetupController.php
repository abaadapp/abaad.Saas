<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Setting;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\ShopIdentity;
use Inertia\Inertia;
use Inertia\Response;

/**
 * تهيئةُ المتجر أوّلَ مرّة — قبل أربعةَ عشرَ قسمًا.
 *
 * التاجرُ كان يهبط على لوحةٍ فيها أربعةَ عشرَ قسمًا ولا شيءَ يقول له بمَ
 * يبدأ. فباع شهرًا واسمُ متجره «متجري»، وخرجت اثنتا عشرةَ فاتورةً ضريبيّةً
 * بذلك الاسم. ولم يكن ذلك إهمالًا منه: **النظامُ لم يسأله**.
 *
 * وهذه الشاشةُ تكتب بنفسها لا تُحيل: الإحالةُ إلى «الإعدادات ‹ بيانات
 * النشاط» تعني أربعَ نقراتٍ ورجوعًا، ومن رجع مرّةً لا يعود. والحقولُ تُحفظ
 * بالباب القائم (`admin.settings.update`) لا بباب ثانٍ يكتب المفاتيح نفسها —
 * بابان يكتبان مفتاحًا واحدًا يفترقان يومًا.
 */
class SetupController extends Controller
{
    public function index(): Response
    {
        $business = Business::findOrFail(Demo::bid());
        $settings = Setting::where('business_id', $business->id)
            ->whereIn('key', ['vat_number', 'vat_enabled'])
            ->pluck('value', 'key');

        return Inertia::render('Admin/Setup/Index', [
            'shop' => [
                'name' => (string) $business->name,
                'phone' => (string) $business->phone,
                'address' => (string) $business->address,
                'logo' => (string) $business->logo,
                'vat_number' => (string) ($settings['vat_number'] ?? ''),
                'vat_enabled' => (string) ($settings['vat_enabled'] ?? '1') !== '0',
            ],
            'steps' => ShopIdentity::steps($business),
            'confirmed' => ShopIdentity::confirmed($business),
            // أسماءُ النظام تُعرض للواجهة لتقول «هذا ليس اسمًا» قبل الإرسال
            'placeholders' => ShopIdentity::PLACEHOLDERS,
        ]);
    }

    /**
     * إقرارُ الهويّة — ولا يُقبل على اسمٍ يكتبه النظام.
     *
     * والفحصُ هنا لا في الشاشة وحدها: زرٌّ معطَّلٌ في المتصفّح يُتخطّى بطلبٍ
     * مباشر، فيعود الورقُ باسمٍ لم يختره أحد وقد قال النظام إنّه أُقرّ.
     */
    public function confirm()
    {
        $business = Business::findOrFail(Demo::bid());
        $name = trim((string) $business->name);

        if ($name === '' || in_array($name, ShopIdentity::PLACEHOLDERS, true)) {
            return back()->withErrors([
                'name' => __('اكتب اسم متجرك كما تريده على الفاتورة — «:name» اسمٌ يكتبه النظام حين لا اسم.', ['name' => $name !== '' ? $name : '—']),
            ]);
        }

        $business->update(['identity_confirmed_at' => now()]);

        Activity::log('settings', 'أقرّ هويّة المتجر: '.$name);

        return redirect()->route('admin.dashboard')->with('toast', [
            'msg' => __('تمّت التهيئة. أوراقُ متجرك تحمل اسمَ «:name».', ['name' => $name]),
            'type' => 'success',
        ]);
    }
}
