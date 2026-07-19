<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Support\Demo;
use Illuminate\Http\Request;

class CurrencyController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    /** تبديل عملة العرض (تُحفظ في الجلسة) */
    public function switch(Request $request, $code)
    {
        if ($code === 'base') {
            $request->session()->forget('display_currency');
        } else {
            $request->session()->put('display_currency', strtoupper($code));
        }

        return back();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:8'],
            'name' => ['required', 'string', 'max:100'],
            'symbol' => ['nullable', 'string', 'max:12'],
            'rate' => ['required', 'numeric', 'min:0.000001'],
        ]);
        $bid = $this->bid();
        $isFirst = ! Currency::where('business_id', $bid)->exists();

        Currency::create([
            'business_id' => $bid,
            'code' => strtoupper($data['code']),
            'name' => $data['name'],
            'symbol' => $data['symbol'] ?? null,
            'rate' => $isFirst ? 1 : $data['rate'],
            'is_base' => $isFirst,
            'active' => true,
        ]);
        \App\Support\Activity::log('settings', 'أضاف عملة: ' . strtoupper($data['code']));

        return back()->with('toast', ['msg' => 'تمت إضافة العملة', 'type' => 'success']);
    }

    public function update(Request $request, $id)
    {
        $currency = Currency::where('business_id', $this->bid())->findOrFail($id);
        $data = $request->validate([
            'rate' => ['required', 'numeric', 'min:0.000001'],
            'active' => ['nullable', 'boolean'],
        ]);
        // العملة الأساسية سعرها دائمًا 1
        $currency->update([
            'rate' => $currency->is_base ? 1 : $data['rate'],
            'active' => $request->boolean('active', $currency->active),
        ]);

        return back()->with('toast', ['msg' => 'تم تحديث سعر الصرف', 'type' => 'success']);
    }

    public function setBase($id)
    {
        $bid = $this->bid();
        $currency = Currency::where('business_id', $bid)->findOrFail($id);
        Currency::where('business_id', $bid)->update(['is_base' => false]);
        $currency->update(['is_base' => true, 'rate' => 1]);
        \App\Support\Activity::log('settings', 'جعل ' . $currency->code . ' العملة الأساسية');

        return back()->with('toast', ['msg' => $currency->code . ' أصبحت العملة الأساسية', 'type' => 'success']);
    }

    public function destroy($id)
    {
        $currency = Currency::where('business_id', $this->bid())->findOrFail($id);
        if ($currency->is_base) {
            return back()->with('toast', ['msg' => 'لا يمكن حذف العملة الأساسية', 'type' => 'error']);
        }
        $currency->delete();

        return back()->with('toast', ['msg' => 'تم حذف العملة', 'type' => 'warning']);
    }
}
