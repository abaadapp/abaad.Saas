<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Support\Demo;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:50'],
        ]);
        $data['business_id'] = $this->bid();
        $data['icon'] = $data['icon'] ?: '🏷️';
        $data['color'] = $data['color'] ?? 'primary';
        Category::create($data);
        \App\Support\Activity::log('created', 'أضاف قسمًا: ' . $data['name']);

        return redirect()->route('admin.categories.index')->with('toast', ['msg' => __('تم إضافة القسم بنجاح'), 'type' => 'success']);
    }

    public function update(Request $request, $id)
    {
        $category = Category::where('business_id', $this->bid())->findOrFail($id);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:50'],
        ]);
        $category->update([
            'name' => $data['name'],
            'name_en' => $data['name_en'] ?? null,
            'icon' => $data['icon'] ?: $category->icon,
            'color' => $data['color'] ?: $category->color,
        ]);
        \App\Support\Activity::log('updated', 'عدّل القسم: ' . $category->name, ['subject_id' => $category->id]);

        return redirect()->route('admin.categories.index')->with('toast', ['msg' => __('تم تحديث القسم'), 'type' => 'success']);
    }

    public function destroy($id)
    {
        $category = Category::where('business_id', $this->bid())->findOrFail($id);

        // لا يُحذف قسم مرتبط بمنتجات — وإلا بقيت منتجات بقسم لا وجود له
        $used = $category->products()->count();
        if ($used > 0) {
            return back()->with('toast', [
                'msg' => __('لا يمكن حذف «:name» لأنه مرتبط بـ :count منتج. غيّر قسمها أولًا.', ['name' => $category->name, 'count' => $used]),
                'type' => 'error',
            ]);
        }
        \App\Support\Activity::log('deleted', 'حذف القسم: ' . $category->name);
        $category->delete();

        return redirect()->route('admin.categories.index')->with('toast', ['msg' => __('تم حذف القسم'), 'type' => 'success']);
    }
}
