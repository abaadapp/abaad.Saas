{{-- شريط تبويبات قسم المنتجات (بنفس شكل تبويبات المخزون) --}}
@php
    $productTabs = [
        ['label' => __('الأقسام'), 'url' => route('admin.categories.index'), 'active' => request()->routeIs('admin.categories.*')],
        ['label' => __('المنتجات'), 'url' => route('admin.products.index'), 'active' => request()->routeIs('admin.products.*')],
        ['label' => __('الإضافات'), 'url' => route('admin.addons.index'), 'active' => request()->routeIs('admin.addons.*')],
    ];
@endphp

<div class="flex items-center gap-1 border-b border-gray-200 mb-6 overflow-x-auto">
    @foreach ($productTabs as $t)
        <a href="{{ $t['url'] }}"
           class="px-4 py-3 text-sm font-medium border-b-2 -mb-px whitespace-nowrap transition-colors
                  {{ $t['active'] ? 'text-gray-900 border-gray-900' : 'text-gray-500 border-transparent hover:text-gray-700' }}">
            {{ $t['label'] }}
        </a>
    @endforeach
</div>
