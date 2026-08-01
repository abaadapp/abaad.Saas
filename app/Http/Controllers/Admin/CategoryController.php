<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Support\Demo;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    /**
     * قواعد الحقول — واحدة للإضافة والتعديل.
     *
     * parent مقيَّد بأقسام هذا المتجر: بدونه يستطيع أي تاجر ربط قسمه بقسم متجر
     * آخر بتمرير معرّف من عنده.
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:50'],
            'parent' => [
                'nullable',
                Rule::exists('categories', 'id')->where('business_id', $this->bid()),
            ],
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());
        $data['business_id'] = $this->bid();
        $data['icon'] = $data['icon'] ?: '🏷️';
        // اللون سداسي كما يرسله منتقي الألوان — انظر Demo::categoryColor
        $data['color'] = $data['color'] ?: '#7c3aed';
        // النموذج يسمّيه parent والعمود parent_id
        $data['parent_id'] = $data['parent'] ?? null;
        unset($data['parent']);
        Category::create($data);
        \App\Support\Activity::log('created', 'أضاف قسمًا: ' . $data['name']);

        return redirect()->route('admin.categories.index')->with('toast', ['msg' => __('تم إضافة القسم بنجاح'), 'type' => 'success']);
    }

    public function update(Request $request, $id)
    {
        $category = Category::where('business_id', $this->bid())->findOrFail($id);
        $data = $request->validate($this->rules());
        $parent = $data['parent'] ?? null;
        // قسم أبًا لنفسه يصنع حلقة لا نهائية في أي عرض شجريّ
        if ((int) $parent === (int) $category->id) {
            $parent = null;
        }
        $category->update([
            'name' => $data['name'],
            'name_en' => $data['name_en'] ?? null,
            'icon' => $data['icon'] ?: $category->icon,
            'color' => $data['color'] ?: $category->color,
            'parent_id' => $parent,
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
        // ولا قسم له أقسام فرعية — وإلا بقيت معلّقة بأبٍ محذوف
        $children = Category::where('business_id', $this->bid())->where('parent_id', $category->id)->count();
        if ($children > 0) {
            return back()->with('toast', [
                'msg' => __('لا يمكن حذف «:name» لأنه أبٌ لـ :count قسمًا. احذفها أو انقلها أولًا.', ['name' => $category->name, 'count' => $children]),
                'type' => 'error',
            ]);
        }

        \App\Support\Activity::log('deleted', 'حذف القسم: ' . $category->name);
        $category->delete();

        return redirect()->route('admin.categories.index')->with('toast', ['msg' => __('تم حذف القسم'), 'type' => 'success']);
    }
}
