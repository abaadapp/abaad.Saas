{{-- شريط تبويبات قسم المخزون (بنفس شكل تبويبات المصروفات) --}}
@php
    $inventoryTabs = [
        ['label' => 'المخزون', 'url' => route('admin.inventory.index'), 'active' => request()->routeIs('admin.inventory.index')],
        ['label' => 'المورّدون', 'url' => route('admin.suppliers.index'), 'active' => request()->routeIs('admin.suppliers.*')],
        ['label' => 'أوامر الشراء', 'url' => route('admin.purchases.index'), 'active' => request()->routeIs('admin.purchases.*')],
        ['label' => 'حركات المخزون', 'url' => route('admin.inventory.movements'), 'active' => request()->routeIs('admin.inventory.movements')],
    ];
@endphp

<div class="flex items-center gap-1 border-b border-gray-200 mb-6 overflow-x-auto">
    @foreach ($inventoryTabs as $t)
        <a href="{{ $t['url'] }}"
           class="px-4 py-3 text-sm font-medium border-b-2 -mb-px whitespace-nowrap transition-colors
                  {{ $t['active'] ? 'text-gray-900 border-gray-900' : 'text-gray-500 border-transparent hover:text-gray-700' }}">
            {{ $t['label'] }}
        </a>
    @endforeach
</div>
