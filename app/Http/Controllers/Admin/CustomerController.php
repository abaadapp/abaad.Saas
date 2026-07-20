<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\Demo;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    public function index(Request $request)
    {
        $q = Customer::where('business_id', $this->bid())->withCount('orders');

        if ($s = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"));
        }
        $sort = $request->query('sort');
        $q->orderBy('id');

        $customers = $q->paginate(10)->withQueryString()->through(fn ($c) => [
            'id' => $c->id, 'name' => $c->name, 'phone' => $c->phone, 'email' => $c->email,
            'orders' => $c->orders_count,
            'total_spent' => (float) \App\Models\Order::where('customer_id', $c->id)->sum('total'),
            'last_order' => optional(\App\Models\Order::where('customer_id', $c->id)->max('ordered_at'))
                ? \Illuminate\Support\Carbon::parse(\App\Models\Order::where('customer_id', $c->id)->max('ordered_at'))->format('Y-m-d') : '—',
            'points' => $c->points, 'avatar' => Demo::image('cust' . $c->id, 100, 100),
        ]);

        return view('admin.customers.index', ['customers' => $customers, 'filters' => $request->only('q', 'sort')]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email'],
            'address' => ['nullable', 'string', 'max:255'],
            'branch_id' => ['nullable', 'integer'],
        ]);
        $data['business_id'] = $this->bid();

        // الفرع اختياري — ويجب أن يكون تابعًا لنفس النشاط
        $branchId = $data['branch_id'] ?? null;
        $data['branch_id'] = $branchId && \App\Models\Branch::where('business_id', $data['business_id'])->whereKey($branchId)->exists()
            ? $branchId
            : null;

        Customer::create($data);
        \App\Support\Activity::log('created', 'أضاف عميلًا: ' . $data['name']);

        return redirect()->route('admin.customers.index')->with('toast', ['msg' => 'تم إضافة العميل بنجاح', 'type' => 'success']);
    }

    public function saveNote(Request $request, $id)
    {
        $customer = Customer::where('business_id', $this->bid())->findOrFail($id);
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);
        $customer->update(['notes' => $data['notes'] ?? null]);
        \App\Support\Activity::log('updated', 'حدّث ملاحظات العميل: ' . $customer->name, ['subject_id' => $customer->id]);

        return back()->with('toast', ['msg' => 'تم حفظ الملاحظة', 'type' => 'success']);
    }

    public function redeem(Request $request, $id)
    {
        $customer = Customer::where('business_id', $this->bid())->findOrFail($id);
        $points = (int) $request->input('points', $customer->points);
        $points = max(0, min($points, (int) $customer->points));
        if ($points <= 0) {
            return back()->with('toast', ['msg' => 'لا توجد نقاط كافية للصرف', 'type' => 'warning']);
        }
        $customer->decrement('points', $points);
        \App\Support\Activity::log('updated', "صرف {$points} نقطة للعميل: {$customer->name}", ['subject_id' => $customer->id]);

        return back()->with('toast', ['msg' => "تم صرف {$points} نقطة (خصم " . Demo::money($points / 100) . ')', 'type' => 'success']);
    }
}
