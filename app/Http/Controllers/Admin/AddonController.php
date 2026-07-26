<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Addon;
use App\Support\Demo;
use Illuminate\Http\Request;

class AddonController extends Controller
{
    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'icon' => ['nullable', 'string', 'max:50'],
            'active' => ['nullable', 'boolean'],
        ]);
        $data['business_id'] = $this->bid();
        $data['price'] = $data['price'] ?? 0;
        $data['icon'] = $data['icon'] ?: '🎁';
        $data['active'] = $request->boolean('active');
        Addon::create($data);
        \App\Support\Activity::log('created', 'أضاف إضافة: ' . $data['name']);

        return redirect()->route('admin.addons.index')->with('toast', ['msg' => __('تم إضافة الإضافة بنجاح'), 'type' => 'success']);
    }

    public function update(Request $request, $id)
    {
        $addon = Addon::where('business_id', $this->bid())->findOrFail($id);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'icon' => ['nullable', 'string', 'max:50'],
            'active' => ['nullable', 'boolean'],
        ]);
        $addon->update([
            'name' => $data['name'],
            'name_en' => $data['name_en'] ?? null,
            'price' => $data['price'] ?? 0,
            'icon' => $data['icon'] ?: $addon->icon,
            'active' => $request->boolean('active'),
        ]);
        \App\Support\Activity::log('updated', 'عدّل الإضافة: ' . $addon->name, ['subject_id' => $addon->id]);

        return redirect()->route('admin.addons.index')->with('toast', ['msg' => __('تم تحديث الإضافة'), 'type' => 'success']);
    }

    public function destroy($id)
    {
        $addon = Addon::where('business_id', $this->bid())->findOrFail($id);
        \App\Support\Activity::log('deleted', 'حذف الإضافة: ' . $addon->name);
        $addon->delete();

        return redirect()->route('admin.addons.index')->with('toast', ['msg' => __('تم حذف الإضافة'), 'type' => 'success']);
    }
}
