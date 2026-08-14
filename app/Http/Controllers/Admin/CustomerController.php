<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\Demo;
use Illuminate\Http\Request;

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
        $q = Customer::where('business_id', $this->bid())
            ->withCount('orders')
            ->withSum('orders', 'total')
            ->withMax('orders', 'ordered_at');

        if ($s = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('name_en', 'like', "%{$s}%")
                ->orWhere('phone', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"));
        }

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
            'points' => $c->points, 'avatar' => Demo::image('cust' . $c->id, 100, 100),
        ]);

        $stats = Demo::customerStats();

        return \Inertia\Inertia::render('Admin/Customers/Index', [
            'customers' => $customers->items(),
            'pagination' => \App\Support\Pagination::meta($customers),
            'filters' => $request->only('q') + \App\Support\Sort::params($request, self::SORTS),
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
            // لا name_en هنا: Customers::localizeName هو من يملأه من الاسم
            // نفسه، فأي قيمة واردة تُكتب فوقها.
            'name' => ['required', 'string', 'max:255'],
            'phone' => \App\Support\Customers::phoneRule($this->bid()),
            'email' => ['nullable', 'email'],
            'tax_number' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'branch_id' => ['nullable', 'integer'],
        ], [
            'phone.unique' => __('هذا الرقم مسجَّل لعميل آخر — نقاط الولاء تتبع الرقم.'),
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
        $customer->decrement('points', $points);
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
     */
    public function defaultAddress($id, $addressId)
    {
        $customer = Customer::where('business_id', $this->bid())->findOrFail($id);
        $address = $customer->addresses()->findOrFail($addressId);

        \DB::transaction(function () use ($customer, $address) {
            $customer->addresses()->update(['is_default' => false]);
            $address->update(['is_default' => true]);
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
