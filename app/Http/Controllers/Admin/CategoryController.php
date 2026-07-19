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

    public function destroy($id)
    {
        Category::where('business_id', $this->bid())->findOrFail($id)->delete();

        return redirect()->route('admin.categories.index')->with('toast', ['msg' => 'تم حذف التصنيف', 'type' => 'success']);
    }
}
