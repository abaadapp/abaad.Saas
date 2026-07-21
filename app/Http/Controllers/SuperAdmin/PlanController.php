<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'monthly_price' => ['required', 'numeric', 'min:0'],
            'yearly_price' => ['required', 'numeric', 'min:0'],
            'color' => ['nullable', 'string', 'max:30'],
            'features' => ['nullable', 'string'],
            'is_popular' => ['nullable', 'boolean'],
        ]);
        Plan::create([
            'name' => $data['name'],
            'monthly_price' => $data['monthly_price'],
            'yearly_price' => $data['yearly_price'],
            'color' => $data['color'] ?? 'primary',
            'is_popular' => $request->boolean('is_popular'),
            'features' => array_values(array_filter(array_map('trim', explode("\n", (string) ($data['features'] ?? ''))))),
        ]);
        \App\Support\Activity::log('created', 'أضاف باقة جديدة: ' . $data['name']);

        return back()->with('toast', ['msg' => __('تمت إضافة الباقة بنجاح'), 'type' => 'success']);
    }
}
