<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Plan;
use Illuminate\Http\Request;

/**
 * محلات الورود = شركات من نوع «محل ورود». هذا المتحكم يربط نماذج المحلات
 * بجدول الشركات نفسه.
 */
class FlowerShopController extends Controller
{
    public function store(Request $request)
    {
        $business = Business::create($this->mapped($request, true));
        \App\Support\Activity::log('created', 'أضاف محل ورود: ' . $business->name, ['business_id' => null, 'subject_id' => $business->id]);

        return redirect()->route('super-admin.flower-shops.index')->with('toast', ['msg' => 'تم حفظ محل الورود بنجاح', 'type' => 'success']);
    }

    public function update(Request $request, $id)
    {
        $business = Business::findOrFail($id);
        $business->update($this->mapped($request, false));
        \App\Support\Activity::log('updated', 'عدّل محل الورود: ' . $business->name, ['business_id' => null, 'subject_id' => $business->id]);

        return redirect()->route('super-admin.flower-shops.show', $business->id)->with('toast', ['msg' => 'تم حفظ التعديلات بنجاح', 'type' => 'success']);
    }

    private function mapped(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'owner' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email'],
            'branches' => ['nullable', 'integer', 'min:1'],
            'plan' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:50'],
            'start' => ['nullable', 'date'],
            'end' => ['nullable', 'date'],
            'logo' => ['nullable', 'image', 'max:2048'],
        ]);

        $mapped = [
            'type' => 'محل ورود',
            'name' => $data['name'],
            'owner_name' => $data['owner'] ?? null,
            'city' => $data['city'] ?? null,
            'country' => 'عُمان',
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'branches_count' => $data['branches'] ?? 1,
            'plan_id' => ! empty($data['plan']) ? optional(Plan::where('name', $data['plan'])->first())->id : null,
            'status' => $data['status'] ?? 'نشط',
            'starts_at' => $data['start'] ?? null,
            'ends_at' => $data['end'] ?? null,
        ];

        if ($request->hasFile('logo')) {
            $mapped['logo'] = $request->file('logo')->store('logos', 'public');
        }

        return array_filter($mapped, fn ($v) => $v !== null || $creating);
    }
}
