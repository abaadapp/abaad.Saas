<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\Demo;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    /**
     * ما يُرتَّب في قائمة العملاء.
     *
     * والمجاميع تُرتَّب بأسماء `withCount`/`withSum` نفسها: هي أعمدةٌ في
     * الاستعلام المُنتَج، فترتيبها لا يحتاج ضمًّا زائدًا.
     */
    private const SORTS = [
        'name' => 'name',
        'orders' => 'orders_count',
        'total_spent' => 'orders_sum_total',
        'last_order' => 'orders_max_ordered_at',
        'points' => 'points',
    ];

    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    public function index(Request $request)
    {
        /*
         * ما اشتراه العميل فعلًا — لا الملغى ولا سلّةً معلّقة.
         *
         * `withCount('orders')` تعدّ العلاقة كما هي فتتجاوز النطاق: فكانت
         * البطاقة فوق الجدول تستثني الملغى وصفوفُه تحتها تجمعه — شاشةٌ
         * واحدة تقول رقمين عن العميل نفسه. ومن ألغى فاتورةً بألف يبقى في
         * القائمة «أنفق ١٠٠٠» بينما صفحته وكشف حسابه يقولان صفرًا.
         */
        $sold = fn ($q) => $q->sold();

        $q = Customer::where('business_id', $this->bid())
            ->withCount(['orders as orders_count' => $sold])
            ->withSum(['orders as orders_sum_total' => $sold], 'total')
            ->withMax(['orders as orders_max_ordered_at' => $sold], 'ordered_at');

        // القاعدة نفسها التي يقرأ بها الملفّ — انظر App\Support\ListFilters
        \App\Support\ListFilters::customers($q, $request);

        /*
         * الافتراضي: الأحدث تسجيلًا، حتى يظهر العميل المُضاف حديثًا في الأعلى.
         *
         * وكان الترتيب هنا `match` باتّجاهٍ مثبَّت لكل مفتاح — تنازليٌّ للمال
         * وتصاعديٌّ للاسم — فلا سبيل إلى عكسه. صار كغيره: العمود من الرابط
         * واتّجاهه معه.
         */
        \App\Support\Sort::apply($q, $request, self::SORTS, fn ($w) => $w->orderByDesc('id'));

        $customers = $q->paginate(10)->withQueryString()->through(fn ($c) => [
            'id' => $c->id, 'name' => $c->name, 'name_en' => $c->name_en,
            'label' => Demo::ln($c->name, $c->name_en),
            'phone' => $c->phone, 'email' => $c->email,
            'orders' => $c->orders_count,
            'total_spent' => (float) ($c->orders_sum_total ?? 0),
            'last_order' => $c->orders_max_ordered_at
                ? \Illuminate\Support\Carbon::parse($c->orders_max_ordered_at)->format('Y-m-d') : '—',
            /*
             * ولا صورة: لا عمود لها في `customers` أصلًا.
             *
             * كان يُحسب هنا رابطُ `picsum.photos` من معرّف العميل — فيُعرض
             * لكلّ عميلٍ وجهُ إنسانٍ لا يعرفه أحد، في القائمة وفي ملفّه.
             * والحقلُ لم يكن يُقرأ من عمود، فلا صورةَ رُفعت قطّ ولا يمكن أن
             * تُرفع: الشرط `{c.avatar ? …}` في الشاشتين لم يقع أبدًا.
             *
             * فرُفع الحقل من الحمولة، ووقع البديل: الحرف الأول من الاسم.
             */
            'points' => $c->points,
            'language' => $c->language,
        ]);

        $stats = Demo::customerStats();

        /*
         * جولةُ اللغة على من سُجّل قبل أن يُسأل.
         *
         * العميلُ القديم لغتُه فارغة ويُراسَل بالعربيّة بلا أن يُسأل أحد.
         * فالشاشةُ تعدّهم وتعرض الزرّين في صفّ كلٍّ منهم، ومرشِّحُ
         * `missing=language` يجمعهم — حتى يصير العدد صفرًا ويختفي الشريط.
         */
        $unlanguaged = Customer::where('business_id', $this->bid())->whereNull('language')->count();

        return \Inertia\Inertia::render('Admin/Customers/Index', [
            'customers' => $customers->items(),
            'pagination' => \App\Support\Pagination::meta($customers),
            'filters' => $request->only('q', 'missing') + \App\Support\Sort::params($request, self::SORTS),
            'unlanguaged' => $unlanguaged,
            'sorts' => \App\Support\Sort::keys(self::SORTS),
            'stats' => [
                ['label' => __('إجمالي العملاء'), 'value' => (string) $stats['total'], 'icon' => 'users', 'color' => 'primary'],
                ['label' => __('عملاء جدد هذا الشهر'), 'value' => (string) $stats['new_this_month'], 'icon' => 'user-plus', 'color' => 'success'],
                ['label' => __('إجمالي المشتريات'), 'value' => Demo::money($stats['total_purchases']), 'icon' => 'wallet', 'color' => 'info'],
                ['label' => __('متوسط الإنفاق'), 'value' => Demo::money($stats['avg_spend']), 'icon' => 'calculator', 'color' => 'primary'],
            ],
            'branches' => Demo::branches(),
            'currentBranchId' => Demo::currentBranchId(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // مكتوبًا بيدٍ يعلو على النقل الآليّ: النقل تخمينٌ يصيب ويخطئ،
            // ومن كتب اسمه بنفسه أعلمُ بكتابته — انظر LocalName::apply
            'name_en' => ['nullable', 'string', 'max:255'],
            'phone' => \App\Support\Customers::phoneRule($this->bid()),
            'email' => ['nullable', 'email', 'max:255'],
            // لغةُ رسائل واتساب: إجباريّةٌ — لا يُحفظ عميلٌ لا يُعرف بأيّ لغةٍ يُراسَل
            'language' => \App\Support\Customers::languageRule(),
            'tax_number' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'branch_id' => ['nullable', 'integer'],
        ]);
        $data['business_id'] = $this->bid();

        // الفرع اختياري — ويجب أن يكون تابعًا لنفس النشاط
        $branchId = $data['branch_id'] ?? null;
        $data['branch_id'] = $branchId && \App\Models\Branch::where('business_id', $data['business_id'])->whereKey($branchId)->exists()
            ? $branchId
            : null;

        $data = \App\Support\Customers::localizeName($data);
        Customer::create($data);
        \App\Support\Activity::log('created', 'أضاف عميلًا: ' . $data['name']);

        return redirect()->route('admin.customers.index')->with('toast', ['msg' => __('تم إضافة العميل بنجاح'), 'type' => 'success']);
    }

    /**
     * تعديل بيانات العميل.
     *
     * كان يُضاف ولا يُعدَّل: رقمٌ فيه خطأٌ واحد يبقى خطأً أبدًا. وهو أشدّ
     * ممّا يبدو — نقاط الولاء تتبع الهاتف، فهاتفٌ خاطئ يعني عميلًا لا يجد
     * نقاطه، ولا سبيل إلى إصلاحه إلا بعميلٍ ثانٍ فيصير في القائمة اسمان
     * لشخصٍ واحد.
     */
    public function update(Request $request, $id)
    {
        $customer = Customer::where('business_id', $this->bid())->findOrFail($id);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            // يتجاوز نفسه: وإلّا لرفض حفظَ عميلٍ لم يُغيَّر رقمه
            'phone' => \App\Support\Customers::phoneRule($this->bid(), $customer->id),
            'email' => ['nullable', 'email', 'max:255'],
            'language' => \App\Support\Customers::languageRule(),
            'tax_number' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        $branchId = $data['branch_id'] ?? null;
        $data['branch_id'] = $branchId && \App\Models\Branch::where('business_id', $this->bid())->whereKey($branchId)->exists()
            ? $branchId
            : null;

        // الاسم الإنجليزي يُشتقّ من العربي — ولا يُترك على اسمٍ قديم بُدِّل
        $data = \App\Support\Customers::localizeName($data);
        $customer->update($data);

        \App\Support\Activity::log('updated', 'عدّل بيانات العميل: '.$customer->name, ['subject_id' => $customer->id]);

        return back()->with('toast', ['msg' => __('تم حفظ بيانات العميل'), 'type' => 'success']);
    }

    /**
     * حذفٌ ناعم إلى «المحذوفات» — لا محوٌ.
     *
     * لصفّ العميل ذيلٌ طويل: فواتيره تشير إليه، ونقاطه، وعناوينه. ومحوُه
     * محوًا نهائيًّا يترك فواتير تشير إلى رقمٍ لا وجود له. فيُخفى ويبقى
     * قابلًا للاستعادة، وفواتيره تبقى كما هي في التقارير.
     */
    public function destroy($id)
    {
        $customer = Customer::where('business_id', $this->bid())->findOrFail($id);
        $name = $customer->name;

        $customer->delete();
        // subject_type لازم: شاشة المحذوفات تقرأ منه «من حذف»
        \App\Support\Activity::log('deleted', 'حذف العميل: '.$name, [
            'subject_id' => $customer->id, 'subject_type' => 'customer',
        ]);

        return redirect()->route('admin.customers.index')
            ->with('toast', ['msg' => __('حُذف العميل «:name» — يمكن استعادته من المحذوفات', ['name' => $name]), 'type' => 'success']);
    }

    /** لغةُ رسائل واتساب من صفّ القائمة — لمن سُجّل قبل أن تُسأل */
    public function language(Request $request, $id)
    {
        $customer = Customer::where('business_id', $this->bid())->findOrFail($id);
        $data = $request->validate(['language' => \App\Support\Customers::languageRule()]);
        $customer->update(['language' => $data['language']]);
        \App\Support\Activity::log('updated', 'حدّد لغة رسائل العميل: ' . $customer->name . ' — ' . $data['language'], ['subject_id' => $customer->id]);

        return back();
    }

    /**
     * الميلادُ والتنبيهُ من ملفّ العميل.
     *
     * اليومُ والشهرُ معًا أو لا شيء، والسنةُ اختياريّة — لا تُخترع. والتنبيهُ
     * نوعٌ وسبب، وإطفاؤه يُفرغ النوعَ ويُبقي السببَ مكتوبًا لمن يعيده.
     * ويُقيَّد في السجلّ ما يغيّر حالَ الزبون: حُظر، رُفع حظرُه، حُذّر منه.
     */
    public function internal(Request $request, $id)
    {
        $customer = Customer::where('business_id', $this->bid())->findOrFail($id);

        $data = $request->validate([
            'birth_day' => ['nullable', 'integer', 'min:1', 'max:31', 'required_with:birth_month'],
            'birth_month' => ['nullable', 'integer', 'min:1', 'max:12', 'required_with:birth_day'],
            'birth_year' => ['nullable', 'integer', 'min:1900', 'max:'.now()->year],
            'alert_enabled' => ['nullable', 'boolean'],
            'alert_type' => ['nullable', Rule::in(\App\Support\CustomerFlags::ALERT_TYPES), 'required_if:alert_enabled,1'],
            'alert_reason' => ['nullable', 'string', 'max:1000'],
        ], [
            'birth_day.required_with' => __('اكتب اليوم مع الشهر.'),
            'birth_month.required_with' => __('اكتب الشهر مع اليوم.'),
            'alert_type.required_if' => __('اختر نوع التنبيه: تحذير أو حظر البيع.'),
        ]);

        // يومٌ لا يقع في شهره (٣١ أبريل) يُردّ — و٢٩ فبراير يُقبل بلا سنة
        if (($data['birth_day'] ?? null) !== null && ($data['birth_month'] ?? null) !== null) {
            $max = \Carbon\Carbon::create(2000, (int) $data['birth_month'], 1)->daysInMonth;
            if ((int) $data['birth_day'] > $max) {
                throw \Illuminate\Validation\ValidationException::withMessages(['birth_day' => __('هذا اليوم لا يقع في هذا الشهر.')]);
            }
            if (($data['birth_year'] ?? null) !== null
                && ! checkdate((int) $data['birth_month'], (int) $data['birth_day'], (int) $data['birth_year'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(['birth_day' => __('هذا التاريخ لا يقع في هذه السنة.')]);
            }
        }

        $before = $customer->alert_type;
        $type = ($data['alert_enabled'] ?? false) ? ($data['alert_type'] ?? null) : null;

        $customer->update([
            'birth_day' => $data['birth_day'] ?? null,
            'birth_month' => $data['birth_month'] ?? null,
            'birth_year' => ($data['birth_day'] ?? null) !== null ? ($data['birth_year'] ?? null) : null,
            'alert_type' => $type,
            'alert_reason' => filled($data['alert_reason'] ?? null) ? trim($data['alert_reason']) : null,
        ]);

        if ($before !== $type) {
            $what = match (true) {
                $type === \App\Support\CustomerFlags::BLOCK => 'حظر البيع على العميل',
                $type === \App\Support\CustomerFlags::WARNING => 'وضع تنبيه تحذيري على العميل',
                $before === \App\Support\CustomerFlags::BLOCK => 'رفع حظر البيع عن العميل',
                default => 'أزال التنبيه عن العميل',
            };
            \App\Support\Activity::log('updated', $what.': '.$customer->name, ['subject_id' => $customer->id, 'subject_type' => 'customer']);
        }

        return back()->with('toast', ['msg' => __('حُفظت المعلومات الداخلية'), 'type' => 'success']);
    }

    public function saveNote(Request $request, $id)
    {
        $customer = Customer::where('business_id', $this->bid())->findOrFail($id);
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);
        $customer->update(['notes' => $data['notes'] ?? null]);
        \App\Support\Activity::log('updated', 'حدّث ملاحظات العميل: ' . $customer->name, ['subject_id' => $customer->id]);

        return back()->with('toast', ['msg' => __('تم حفظ الملاحظة'), 'type' => 'success']);
    }

    public function redeem(Request $request, $id)
    {
        $customer = Customer::where('business_id', $this->bid())->findOrFail($id);
        $points = (int) $request->input('points', $customer->points);
        $points = max(0, min($points, (int) $customer->points));
        if ($points <= 0) {
            return back()->with('toast', ['msg' => __('لا توجد نقاط كافية للصرف'), 'type' => 'warning']);
        }
        /*
         * الخصم شرطيّ — والشرط في الجملة نفسها لا قبلها.
         *
         * كان يُقرأ الرصيد ثمّ يُخصم في خطوتين: ضغطتان متتاليتان على الزرّ
         * تقرآن مئةً كلتاهما فتخصمان مئتين، والعمود لا يقبل سالبًا فتنكسر
         * الثانية بخطأ قاعدةٍ صريح — أو تدور تحته إلى رقمٍ هائل. والصرف
         * يخصم من فاتورةٍ حقيقية، فالنقطة المصروفة مرّتين مالٌ خرج مرّتين.
         */
        $done = Customer::whereKey($customer->id)->where('points', '>=', $points)
            ->update(['points' => \DB::raw('points - '.$points)]);

        if (! $done) {
            return back()->with('toast', ['msg' => __('لا توجد نقاط كافية للصرف'), 'type' => 'warning']);
        }

        $customer->refresh();
        \App\Models\PointTransaction::record($customer, 'redeem', $points, (int) $customer->points, null, 'صرف يدوي من ملف العميل');
        \App\Support\Activity::log('updated', "صرف {$points} نقطة للعميل: {$customer->name}", ['subject_id' => $customer->id]);

        return back()->with('toast', ['msg' => __('تم صرف :points نقطة (خصم :amount)', ['points' => $points, 'amount' => Demo::money($points / 100)]), 'type' => 'success']);
    }

    /* ------------------------- عناوين العميل ------------------------- */

    /**
     * العنوان يُنشأ ويُعدَّل بالمسار نفسه: id فارغ = إضافة، وإلا تعديل.
     * أول عنوان للعميل يصير الافتراضي تلقائيًا — فلا يبقى العميل بلا
     * عنوان افتراضي بعد أن أضاف واحدًا.
     */
    public function saveAddress(Request $request, $id)
    {
        $customer = Customer::where('business_id', $this->bid())->findOrFail($id);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:60'],
            'city' => ['required', 'string', 'max:80'],
            'area' => ['nullable', 'string', 'max:80'],
            'street' => ['nullable', 'string', 'max:160'],
        ]);

        $addressId = $request->input('address_id');
        if ($addressId) {
            $address = $customer->addresses()->findOrFail($addressId);
            $address->update($data);
            $msg = 'عدّل عنوان العميل: ' . $customer->name;
        } else {
            $data['is_default'] = $customer->addresses()->count() === 0;
            $address = $customer->addresses()->create($data);
            $msg = 'أضاف عنوانًا للعميل: ' . $customer->name;
        }

        \App\Support\Activity::log('updated', $msg, ['subject_id' => $customer->id]);

        return back()->with('toast', ['msg' => __('تم حفظ العنوان'), 'type' => 'success']);
    }

    /**
     * الافتراضي واحد لا أكثر: نُنزل العَلَم عن الباقي في المعاملة نفسها،
     * وإلا ظهر عنوانان افتراضيان لو نُقر عليهما بسرعة.
     *
     * ورفعُه بالاستعلام لا بالكائن: الكائن قُرئ قبل التصفير فهو يحمل `true`
     * أصلًا، فلا يرى Eloquent تغييرًا ولا يُرسل استعلامًا. فمن ضغط الزرّ على
     * العنوان الافتراضي نفسه بقي عميلُه **بلا عنوانٍ افتراضي** — والشاشة
     * تقول «تم تعيين العنوان الافتراضي».
     */
    public function defaultAddress($id, $addressId)
    {
        $customer = Customer::where('business_id', $this->bid())->findOrFail($id);
        $address = $customer->addresses()->findOrFail($addressId);

        \DB::transaction(function () use ($customer, $address) {
            $customer->addresses()->update(['is_default' => false]);
            $customer->addresses()->whereKey($address->id)->update(['is_default' => true]);
        });

        return back()->with('toast', ['msg' => __('تم تعيين العنوان الافتراضي'), 'type' => 'success']);
    }

    public function deleteAddress($id, $addressId)
    {
        $customer = Customer::where('business_id', $this->bid())->findOrFail($id);
        $address = $customer->addresses()->findOrFail($addressId);
        $wasDefault = $address->is_default;
        $address->delete();

        // لا نترك العميل بعناوين بلا افتراضي — يرث الأقدم
        if ($wasDefault && ($next = $customer->addresses()->oldest('id')->first())) {
            $next->update(['is_default' => true]);
        }

        \App\Support\Activity::log('deleted', 'حذف عنوانًا للعميل: ' . $customer->name, ['subject_id' => $customer->id]);

        return back()->with('toast', ['msg' => __('تم حذف العنوان'), 'type' => 'success']);
    }
}
