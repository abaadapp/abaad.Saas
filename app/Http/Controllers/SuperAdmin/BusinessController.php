<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use Illuminate\Http\Request;

class BusinessController extends Controller
{
    /**
     * ما يُرتَّب في قائمة الأنشطة.
     *
     * والمالك والباقة عمودان هنا لا في جدولٍ آخر (`owner_name` و`plan_id`)،
     * فيُرتَّبان بلا ضمّ. و«آخر بيع» محسوبٌ من الطلبات فلا يُرتَّب به.
     */
    private const SORTS = [
        'name' => 'name',
        'type' => 'type',
        'owner' => 'owner_name',
        'status' => 'status',
        'registered' => 'starts_at',
        'expires' => 'ends_at',
        'branches' => 'branches_count',
    ];

    public function index(Request $request)
    {
        /*
         * «آخر بيعة» عمودٌ يقلب الجدول من سجلّ إلى أداة.
         *
         * شركةٌ «نشطة» باشتراكٍ سارٍ إلى ٢٠٢٧ وصفر طلبات منذ ثلاثة أسابيع
         * مشتركٌ سيلغي ولا تدري — واللوحة تعدّه في «النشطة». ومن مضى عليه
         * أسبوع يُتّصل به قبل أن يتصل هو ليلغي.
         *
         * وبفرعٍ استعلاميّ لا بعلاقة: صفٌّ واحد لكل شركة، لا استعلامٌ لكلٍّ منها.
         */
        /*
         * متاجر التجّار وحدها — والتجريبيّة في قسم «الديمو».
         *
         * خلطُهما يجعل من يقرأ «١٤ شركة» يعدّ فيها متجرًا وهميًّا، ومن يبحث
         * عن عميلٍ يمرّ على متجرٍ لا يدفع. ولها بابها الذي يُبنى ويُمحى منه.
         */
        $q = Business::real()->with('plan')->addSelect([
            'last_sale' => \App\Models\Order::selectRaw('MAX(ordered_at)')
                ->whereColumn('orders.business_id', 'businesses.id')
                ->sold(),

            /*
             * بريد الدخول لا بريد التواصل.
             *
             * كان العمود يعرض businesses.email — عنوانَ تواصلٍ يُكتب عند
             * التسجيل ولا علاقة له بالدخول. فيبدّل المشغّل حساب الدخول من
             * بطاقة الحساب، ثم يعود إلى الجدول فيرى العنوان القديم ويظنّ أن
             * التعديل لم يقع. وهو العمود الذي يبحث فيه الدعم عن تاجرٍ يتّصل.
             *
             * وبفرعٍ استعلاميّ لا بعلاقة: صفٌّ واحد لكل شركة، ونفس شرط
             * MerchantAccount::owner — أوّل حسابٍ بدور admin فيها.
             */
            'owner_email' => \App\Models\User::select('email')
                ->whereColumn('users.business_id', 'businesses.id')
                ->where('role', 'admin')
                ->orderBy('id')
                ->limit(1),
        ]);

        if ($s = trim((string) $request->query('q'))) {
            // ويُبحث في بريد الدخول أيضًا: هو ما يعرفه الدعم عن التاجر
            $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")
                ->orWhere('owner_name', 'like', "%{$s}%")
                ->orWhere('email', 'like', "%{$s}%")
                ->orWhereHas('users', fn ($u) => $u->where('role', 'admin')->where('email', 'like', "%{$s}%")));
        }
        if ($t = $request->query('type')) { $q->where('type', $t); }
        if ($p = $request->query('plan')) { $q->whereHas('plan', fn ($w) => $w->where('name', $p)); }
        if ($st = $request->query('status')) { $q->where('status', $st); }

        \App\Support\Sort::apply($q, $request, self::SORTS, fn ($w) => $w->orderByDesc('id'));

        $businesses = $q->paginate(10)->withQueryString()->through(fn ($b) => [
            'id' => $b->id, 'name' => $b->name, 'type' => $b->type, 'owner' => $b->owner_name,
            'phone' => $b->phone, 'email' => $b->owner_email, 'contactEmail' => $b->email,
            'plan' => $b->plan?->name ?? '—',
            'status' => $b->status, 'registered' => optional($b->starts_at)->format('Y-m-d') ?? '—',
            'expires' => optional($b->ends_at)->format('Y-m-d'),
            /*
             * الأيّام الباقية — الواجهة تلوّن بها ولا تحسب.
             *
             * وسالبها يعني انقضاءً: بينه وبين الإقفال مهلةُ السماح، فالرقم
             * وحده لا يقول إن المتجر واقف — انظر Tenancy::locked.
             */
            'daysLeft' => \App\Support\Tenancy::daysLeft($b),
            'branches' => $b->branches_count, 'city' => $b->city, 'country' => $b->country,
            // مسار مخزَّن أو رابط مطلق — المحوّل يميّز بينهما
            'logo' => PageController::logoUrl($b->logo),
            'lastSale' => $b->last_sale ? \Illuminate\Support\Carbon::parse($b->last_sale)->format('Y-m-d') : null,
            // الأيام منذ آخر بيعة — الواجهة تلوّن بها ولا تحسب
            'silentDays' => $b->last_sale
                ? (int) \Illuminate\Support\Carbon::parse($b->last_sale)->startOfDay()->diffInDays(now()->startOfDay())
                : null,
        ]);

        return \Inertia\Inertia::render('Platform/Businesses/Index', [
            'businesses' => $businesses->items(),
            'pagination' => \App\Support\Pagination::meta($businesses),
            'filters' => $request->only('q', 'type', 'plan', 'status')
                + \App\Support\Sort::params($request, self::SORTS),
            'sorts' => \App\Support\Sort::keys(self::SORTS),
            'options' => PageController::filterOptions($request),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $account = $this->validateAccount($request);

        $data['logo'] = $request->hasFile('logo') ? $request->file('logo')->store('logos', 'public') : null;

        /*
         * مدّة التجربة — من إعدادات المنصة.
         *
         * `trial_days` كان حقلًا يُملأ ولا يقرؤه شيء: شركةٌ تُضاف بلا تاريخ
         * انتهاء تعمل إلى الأبد، فلا تجربةَ تنتهي ولا مطالبةَ تحلّ. ولا
         * يُطبَّق إن حدّد المشغّل التاريخين بنفسه: اختيارُه أولى من الافتراضي.
         */
        /*
         * الباقة الافتراضية — بالاسم كما يكتبه المشغّل في الإعدادات.
         *
         * كانت شركةٌ تُضاف بلا باقة فتبقى بلا سعرٍ ولا فاتورة، والحقل في
         * الإعدادات يُملأ ولا يقرؤه شيء. واسمٌ لا يطابق باقةً قائمة يُترك
         * كما كان: لا نخترع باقة.
         */
        if (empty($data['plan_id'])) {
            $name = trim((string) \App\Support\Tenancy::platform('default_plan', ''));
            if ($name !== '') {
                $data['plan_id'] = \App\Models\Plan::where('name', $name)->value('id') ?: null;
            }
        }

        if (empty($data['ends_at'])) {
            $days = (int) \App\Support\Tenancy::platform('trial_days', 14);
            $starts = ! empty($data['starts_at']) ? \Illuminate\Support\Carbon::parse($data['starts_at']) : now();
            $data['starts_at'] = $data['starts_at'] ?? $starts->toDateString();
            if ($days > 0) {
                $data['ends_at'] = $starts->copy()->addDays($days)->toDateString();
            }
        }

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
     * إعادة تشغيل شركةٍ معطَّلة — الطرف الآخر من زرّ التعطيل.
     *
     * كان التعطيل بابًا يُغلق ولا يُفتح: المسار الوحيد يكتب «معطل» ولا مسار
     * يردّها. فمن عطّل شركةً بالخطأ، أو عطّلها لتأخّر دفعةٍ ثم وصلت، كان
     * سبيله الوحيد نموذجَ التعديل الكامل — يعيد كتابة الاسم والنوع والباقة
     * لتغيير كلمة، وأيّ حقلٍ يسقط من الطلب يمحو ما في القاعدة.
     *
     * والحالة الجديدة تُحسب ولا تُفترض: شركةٌ انتهى اشتراكها تعود «منتهي» لا
     * «نشط». وإلا لقالت الشاشة «نشط» ولم يستطع التاجر الدخول — يقرأ الحارس
     * تاريخ الانتهاء لا الكلمة المكتوبة — ثم يقلبها المجدول ليلًا فيظنّ
     * المشغّل أن أحدًا عطّلها ثانيةً.
     */
    public function activate($id)
    {
        $business = Business::findOrFail($id);

        if (! in_array((string) $business->status, ['معطل', 'معطّل'], true)) {
            return back()->with('toast', ['msg' => __('هذه الشركة ليست معطَّلة'), 'type' => 'info']);
        }

        $expired = \App\Support\Tenancy::expired($business);
        $business->update(['status' => $expired ? 'منتهي' : 'نشط']);

        \App\Support\Activity::log('status', 'أعاد تشغيل الشركة: ' . $business->name, [
            'business_id' => null,
            'subject_id' => $business->id,
        ]);

        return back()->with('toast', [
            'msg' => $expired
                // لا نقول «عاد يعمل» لمن لن يستطيع الدخول: السبب يُقال في موضعه
                ? __('أُعيد تشغيل الشركة، لكنّ اشتراكها منتهٍ — جدّده ليتمكّن التاجر من الدخول')
                : __('تمت إعادة تشغيل الشركة'),
            'type' => $expired ? 'warning' : 'success',
        ]);
    }

    /**
     * تعديل حساب الدخول وحده — من صفحة الشركة.
     *
     * مسارٌ مستقلّ لا تمريرٌ عبر نموذج الشركة: إعادةُ كتابة الاسم والنوع
     * والحالة لتغيير كلمة مرورٍ نسيها تاجر عملٌ زائد يُخطئ فيه المشغّل، وأيّ
     * حقلٍ يسقط من الطلب يمحو ما في القاعدة.
     *
     * وكلمة المرور تُعاد في الرسالة مرّةً واحدة: هي مجزَّأة في القاعدة فلا
     * تُقرأ بعدها أبدًا — وبلا عرضها هنا لا سبيل لإبلاغ التاجر بها.
     */
    public function account(Request $request, $id)
    {
        $business = Business::findOrFail($id);
        $extra = $this->syncAccount($request, $business);

        if ($extra === '') {
            return back()->with('toast', ['msg' => __('لم يتغيّر شيء'), 'type' => 'info']);
        }

        $shown = filled($request->input('login_password'))
            ? __(' · كلمة المرور: :password', ['password' => $request->input('login_password')])
            : '';

        return back()->with('toast', [
            'msg' => __('تم تحديث حساب الدخول').$extra.$shown,
            'type' => 'success',
        ]);
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
