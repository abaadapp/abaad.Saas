<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\Demo;
use Illuminate\Http\Request;

class GoalController extends Controller
{
    public function update(Request $request)
    {
        $bid = auth()->user()->business_id ?? Demo::bid();
        $data = $request->validate(['monthly_target' => ['required', 'numeric', 'min:0']]);

        Setting::updateOrCreate(
            ['business_id' => $bid, 'key' => 'monthly_target'],
            ['value' => $data['monthly_target']]
        );
        \App\Support\Activity::log('settings', 'حدّد الهدف الشهري: ' . Demo::money($data['monthly_target']));

        return back()->with('toast', ['msg' => __('تم حفظ الهدف الشهري'), 'type' => 'success']);
    }
}
