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
            'icon' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:50'],
        ]);
        $data['business_id'] = $this->bid();
        $data['icon'] = $data['icon'] ?? 'tag';
        $data['color'] = $data['color'] ?? 'primary';
        Category::create($data);
        \App\Support\Activity::log('created', 'أضاف تصنيفًا: ' . $data['name']);

        return redirect()->route('admin.categories.index')->with('toast', ['msg' => 'تم إضافة التصنيف بنجاح', 'type' => 'success']);
    }

    public function update(Request $request, $id)
    {
        $category = Category::where('business_id', $this->bid())->findOrFail($id);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:50'],
        ]);
        $category->update([
            'name' => $data['name'],
            'icon' => $data['icon'] ?: $category->icon,
            'color' => $data['color'] ?: $category->color,
        ]);
        \App\Support\Activity::log('updated', 'عدّل التصنيف: ' . $category->name, ['subject_id' => $category->id]);

        return redirect()->route('admin.categories.index')->with('toast', ['msg' => 'تم تحديث التصنيف', 'type' => 'success']);
    }

    public function destroy($id)
    {
        $category = Category::where('business_id', $this->bid())->findOrFail($id);

        // لا يُحذف تصنيف مرتبط بمنتجات — وإلا بقيت منتجات بتصنيف لا وجود له
        $used = $category->products()->count();
        if ($used > 0) {
            return back()->with('toast', [
                'msg' => "لا يمكن حذف «{$category->name}» لأنه مرتبط بـ {$used} منتج. غيّر تصنيفها أولًا.",
                'type' => 'error',
            ]);
        }
        \App\Support\Activity::log('deleted', 'حذف التصنيف: ' . $category->name);
        $category->delete();

        return redirect()->route('admin.categories.index')->with('toast', ['msg' => 'تم حذف التصنيف', 'type' => 'success']);
    }
}
