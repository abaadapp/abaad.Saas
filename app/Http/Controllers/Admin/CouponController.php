<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Support\Demo;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CouponController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    public function store(Request $request)
    {
        $bid = $this->bid();
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', Rule::unique('coupons', 'code')->where('business_id', $bid)],
            'type' => ['required', 'in:نسبة,مبلغ'],
            'value' => ['required', 'numeric', 'min:0'],
            'min_order' => ['nullable', 'numeric', 'min:0'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'date'],
        ]);
        Coupon::create([
            'business_id' => $bid,
            'code' => strtoupper($data['code']),
            'type' => $data['type'],
            'value' => $data['value'],
            'min_order' => $data['min_order'] ?? 0,
            'max_uses' => $data['max_uses'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'active' => true,
        ]);
        \App\Support\Activity::log('created', 'أنشأ كوبون خصم: ' . strtoupper($data['code']));

        return back()->with('toast', ['msg' => __('تم إنشاء الكوبون'), 'type' => 'success']);
    }

    public function toggle($id)
    {
        $coupon = Coupon::where('business_id', $this->bid())->findOrFail($id);
        $coupon->update(['active' => ! $coupon->active]);

        return back()->with('toast', ['msg' => $coupon->active ? __('تم تفعيل الكوبون') : __('تم إيقاف الكوبون'), 'type' => $coupon->active ? 'success' : 'warning']);
    }

    public function destroy($id)
    {
        $coupon = Coupon::where('business_id', $this->bid())->findOrFail($id);
        $code = $coupon->code;
        $coupon->delete();
        \App\Support\Activity::log('deleted', 'حذف الكوبون: ' . $code);

        return back()->with('toast', ['msg' => __('تم حذف الكوبون'), 'type' => 'warning']);
    }
}
