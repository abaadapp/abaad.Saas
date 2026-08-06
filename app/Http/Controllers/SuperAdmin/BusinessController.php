<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use Illuminate\Http\Request;

class BusinessController extends Controller
{
    public function index(Request $request)
    {
        $q = Business::with('plan');

        if ($s = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('owner_name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"));
        }
        if ($t = $request->query('type')) { $q->where('type', $t); }
        if ($p = $request->query('plan')) { $q->whereHas('plan', fn ($w) => $w->where('name', $p)); }
        if ($st = $request->query('status')) { $q->where('status', $st); }

        $businesses = $q->orderByDesc('id')->paginate(10)->withQueryString()->through(fn ($b) => [
            'id' => $b->id, 'name' => $b->name, 'type' => $b->type, 'owner' => $b->owner_name,
            'phone' => $b->phone, 'email' => $b->email, 'plan' => $b->plan?->name ?? '—',
            'status' => $b->status, 'registered' => optional($b->starts_at)->format('Y-m-d') ?? '—',
            'branches' => $b->branches_count, 'city' => $b->city, 'country' => $b->country,
            // مسار مخزَّن أو رابط مطلق — المحوّل يميّز بينهما
            'logo' => PageController::logoUrl($b->logo),
        ]);

        return \Inertia\Inertia::render('Platform/Businesses/Index', [
            'businesses' => $businesses->items(),
            'pagination' => \App\Support\Pagination::meta($businesses),
            'filters' => $request->only('q', 'type', 'plan', 'status'),
            'options' => PageController::filterOptions($request),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $account = $this->validateAccount($request);

        $data['logo'] = $request->hasFile('logo') ? $request->file('logo')->store('logos', 'public') : null;
        $business = Business::create($data);
        $owner = \App\Support\MerchantAccount::create($business, $account['login_username'], $account['login_password']);
        // بذرة تصنيفات حسب النوع — لئلا يفتح التاجر لوحته على صفحة بيضاء
        \App\Support\BusinessTypes::provision($business);
        \App\Support\Activity::log('created', 'أضاف شركة: ' . $business->name, ['business_id' => null, 'subject_id' => $business->id]);

        return redirect()->route('super-admin.businesses.index')->with('toast', [
            'msg' => __('تم إضافة الشركة · حساب الدخول: :email', ['email' => $owner->email]),
            'type' => 'success',
        ]);
    }

    public function update(Request $request, $id)
    {
        $business = Business::findOrFail($id);
        $data = $this->validateData($request);

        /*
         * الشعار: ملفٌ جديد يحلّ محلّ القديم، وطلبُ الحذف يمسحه.
         *
         * وبلا أيٍّ منهما لا يُمسّ العمود — الحقل غائب عن الطلب حين لا
         * يُختار ملف، فتمريره لـupdate كان سيمسح الشعار عند كل تعديلٍ
         * لحقلٍ آخر لا صلة له به.
         */
        if ($request->hasFile('logo')) {
            $data['logo'] = $request->file('logo')->store('logos', 'public');
        } elseif ($request->boolean('remove_logo')) {
            $data['logo'] = null;
        }

        $business->update($data);
        $extra = $this->syncAccount($request, $business);
        \App\Support\Activity::log('updated', 'عدّل الشركة: ' . $business->name, ['business_id' => null, 'subject_id' => $business->id]);

        return redirect()->route('super-admin.businesses.index')->with('toast', ['msg' => __('تم تحديث الشركة بنجاح') . $extra, 'type' => 'success']);
    }

    public function destroy($id)
    {
        $business = Business::findOrFail($id);
        $business->update(['status' => 'معطل']);
        \App\Support\Activity::log('deleted', 'عطّل الشركة: ' . $business->name, ['business_id' => null, 'subject_id' => $business->id]);

        return redirect()->route('super-admin.businesses.index')->with('toast', ['msg' => __('تم تعطيل الشركة'), 'type' => 'warning']);
    }

    /**
     * حساب الدخول عند التعديل: يُنشأ إن لم يكن، وتُبدَّل كلمته إن طُلب.
     *
     * الشركات المسجّلة قبل إلزام الحساب بقيت بلا مستخدم، وصفحة التعديل كانت
     * تعرض «—» بلا حقلٍ ولا زرّ: عطبٌ لا مخرج منه إلا بفتح قاعدة البيانات.
     * ومَن يفقد كلمته كان لا سبيل إلى إعادتها — «نسيت كلمة المرور» محذوفة،
     * وصفحة الموظفين لا يفتحها من لا يدخل أصلًا.
     *
     * @return string لاحقةُ رسالةٍ تُقال للمشغّل، أو '' إن لم يتغيّر شيء
     */
    private function syncAccount(Request $request, Business $business): string
    {
        $owner = \App\Support\MerchantAccount::owner($business);

        // بلا حساب: الحقول إلزامية كما في الإنشاء
        if (! $owner) {
            $account = $this->validateAccount($request);
            $owner = \App\Support\MerchantAccount::create($business, $account['login_username'], $account['login_password']);
            \App\Support\Activity::log('created', 'أنشأ حساب دخول لـ' . $business->name, ['business_id' => null, 'subject_id' => $business->id]);

            return __(' · حساب الدخول: :email', ['email' => $owner->email]);
        }

        /*
         * بحساب: الحقلان اختياريان — الفارغ يعني «لا تغيّره».
         *
         * لو كانا إلزاميين لصار كل تعديلٍ لمدينةٍ أو باقة يطالب بإعادة كتابة
         * كلمة المرور، فتُخترع كلمةٌ جديدة كل مرّة ويخرج التاجر من حسابه.
         */
        $request->validate([
            'login_username' => array_merge(['nullable'], \App\Support\MerchantAccount::usernameRules()),
            'login_password' => ['nullable', 'string', 'min:8'],
        ], \App\Support\MerchantAccount::messages(), [
            'login_username' => __('اسم المستخدم'),
            'login_password' => __('كلمة المرور'),
        ]);

        $changed = [];

        if (filled($username = $request->input('login_username'))) {
            $email = \App\Support\MerchantAccount::email($username);

            if ($email !== $owner->email) {
                // التفرّد يستثني صاحبَ الحساب نفسه، وإلا اصطدم بنفسه
                if (\App\Support\MerchantAccount::taken($username, $owner->id)) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'login_username' => __('اسم المستخدم محجوز — اختر غيره.'),
                    ]);
                }

                $owner->email = $email;
                $changed[] = __(' · حساب الدخول: :email', ['email' => $email]);
            }
        }

        if (filled($request->input('login_password'))) {
            $owner->password = $request->input('login_password');
            $changed[] = __(' · تم تغيير كلمة المرور');
        }

        if (! $changed) {
            return '';
        }

        $owner->save();
        \App\Support\Activity::log('updated', 'عدّل حساب دخول ' . $business->name, ['business_id' => null, 'subject_id' => $business->id]);

        return implode('', $changed);
    }

    /**
     * حساب دخول المالك — إلزاميّ عند الإنشاء.
     *
     * شركةٌ بلا حساب سجلٌّ في جدول لا يفتحه أحد: لا التاجر يدخل، ولا الدعم
     * يستطيع «الدخول كتاجر» لأنه لا يجد من ينتحله. وتأجيلُه إلى «لاحقًا»
     * يعني أنه يُنسى حتى يتصل صاحب الشركة يسأل عن كلمة مروره.
     *
     * والبريد يُبنى من اسمٍ ونطاقٍ ثابت (MerchantAccount::DOMAIN)، فلا
     * يُكتب النطاق يدويًّا ولا يفترق على أشكال.
     */
    private function validateAccount(Request $request): array
    {
        $account = $request->validate([
            'login_username' => array_merge(['required'], \App\Support\MerchantAccount::usernameRules()),
            'login_password' => ['required', 'string', 'min:8'],
        ], \App\Support\MerchantAccount::messages(), [
            'login_username' => __('اسم المستخدم'),
            'login_password' => __('كلمة المرور'),
        ]);

        // التفرّد يُفحص على البريد الكامل: القاعدة تخزّنه لا الاسم وحده
        if (\App\Support\MerchantAccount::taken($account['login_username'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'login_username' => __('اسم المستخدم محجوز — اختر غيره.'),
            ]);
        }

        return $account;
    }

    private function validateData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // النوع والحالة عمودان NOT NULL لهما قيم افتراضية؛ إرسال null صراحةً
            // يتخطّى الافتراضي ويكسر القيد بخطأ 500 بدل رسالة حقل مفقود.
            'type' => ['required', 'string', 'max:100'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email'],
            'country' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'plan_id' => ['nullable', 'integer'],
            'logo' => ['nullable', 'image', 'max:2048'],
            // لا يُحفظ في العمود — يُقرأ في update ويُستبعد هنا
            'remove_logo' => ['nullable', 'boolean'],
            'status' => ['required', 'string', 'max:50'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
        ]);

        // علامةٌ للنيّة لا عمودٌ في الجدول
        unset($data['remove_logo']);

        return $data;
    }
}
