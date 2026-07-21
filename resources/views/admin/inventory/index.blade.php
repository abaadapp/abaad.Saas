@php
    $inventory = \App\Support\Demo::inventory();
    $available = collect($inventory)->where('status', 'متوفر')->count();
    $low = collect($inventory)->where('status', 'منخفض')->count();
    $out = collect($inventory)->where('status', 'نفد المخزون')->count();
@endphp

<x-layouts::admin :title="__('المخزون')">
    <x-page-header
        :title="__('إدارة المخزون')"
        :subtitle="__('متابعة كميات المنتجات وحالات المخزون والتنبيهات')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('المخزون') => '#']"
    >
        <x-slot:actions>
            <x-button variant="outline" size="md" icon="clipboard-list" :href="route('admin.inventory.xlsx')">{{ __('جرد المخزون (Excel)') }}</x-button>
            <x-button variant="primary" size="md" icon="repeat" :href="route('admin.inventory.movements')">{{ __('حركة مخزون جديدة') }}</x-button>
        </x-slot:actions>
    </x-page-header>

    @include('partials.inventory-tabs')

    {{-- بطاقات الحالة --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <x-stat-card :label="__('منتجات متوفرة')" :value="$available" icon="package-check" color="success" />
        <x-stat-card :label="__('مخزون منخفض')" :value="$low" icon="alert-triangle" color="warning" />
        <x-stat-card :label="__('نفد المخزون')" :value="$out" icon="package-x" color="danger" />
    </div>

    {{-- تنبيه المخزون --}}
    @if ($low + $out > 0)
        <div class="mb-6">
            <x-alert type="warning" :title="__('تنبيه المخزون')">
                {!! __('يوجد :low منتج بمخزون منخفض و:out منتج نفد من المخزون. يُرجى مراجعة الكميات وإعادة التزويد.', [
                    'low' => '<span class="font-semibold">' . (int) $low . '</span>',
                    'out' => '<span class="font-semibold">' . (int) $out . '</span>',
                ]) !!}
            </x-alert>
        </div>
    @endif

    <div x-data="listFilter()" x-ref="list">
    {{-- شريط الفلاتر --}}
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 mb-6">
        <div class="flex flex-col md:flex-row md:items-center gap-3">
            <div class="flex-1">
                <x-input name="search" :placeholder="__('ابحث باسم المنتج أو رمز SKU...')" icon="search" x-model="q" @input="apply()" />
            </div>
            <div class="w-full md:w-56">
                <x-select name="status" :placeholder="__('كل الحالات')" x-model="tag" @change="apply()" :options="[
                    'متوفر' => __('متوفر'),
                    'منخفض' => __('منخفض'),
                    'نفد المخزون' => __('نفد المخزون'),
                ]" />
            </div>
            <x-button variant="light" size="md" icon="filter" @click="apply()">{{ __('تصفية') }}</x-button>
        </div>
    </div>

    {{-- جدول المخزون --}}
    <div x-data="{ sel: { id: '', name: '', sku: '', qty: 0 } }">
    <x-table :headers="[__('المنتج'), 'SKU', __('الكمية الحالية'), __('الحد الأدنى'), __('حالة المخزون'), __('آخر تحديث'), __('إجراء')]">
        @foreach ($inventory as $item)
            <tr class="hover:bg-gray-50" data-row data-tag="{{ $item['status'] }}" data-search="{{ $item['name'] }} {{ $item['sku'] }}">
                <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ $item['name'] }}</td>
                <td class="px-4 py-3 text-gray-500 whitespace-nowrap font-mono">{{ $item['sku'] }}</td>
                <td class="px-4 py-3 whitespace-nowrap">
                    <span class="font-semibold {{ $item['qty'] === 0 ? 'text-danger-600' : ($item['qty'] < $item['min'] ? 'text-warning-600' : 'text-gray-800') }}">{{ $item['qty'] }}</span>
                    <span class="text-xs text-gray-400">{{ __('وحدة') }}</span>
                </td>
                <td class="px-4 py-3 text-gray-500 whitespace-nowrap">{{ $item['min'] }}</td>
                <td class="px-4 py-3 whitespace-nowrap"><x-badge :text="__($item['status'])" /></td>
                <td class="px-4 py-3 text-gray-500 whitespace-nowrap" dir="ltr">{{ $item['updated'] }}</td>
                <td class="px-4 py-3 whitespace-nowrap">
                    <x-button variant="light" size="sm" icon="pencil"
                        x-on:click="sel = { id: {{ $item['id'] }}, name: {{ \Illuminate\Support\Js::from($item['name']) }}, sku: {{ \Illuminate\Support\Js::from($item['sku']) }}, qty: {{ $item['qty'] }} }; $dispatch('open-modal','edit-qty')">{{ __('تعديل الكمية') }}</x-button>
                </td>
            </tr>
        @endforeach

        <x-slot:footer>
            <x-pagination :total="count($inventory)" :perPage="10" :current="1" />
        </x-slot:footer>
    </x-table>

    {{-- نافذة تعديل الكمية --}}
    <x-modal name="edit-qty" :title="__('تعديل كمية المنتج')" maxWidth="max-w-md">
        <form id="adjust-form" method="POST" action="{{ route('admin.inventory.store') }}" class="space-y-4">
            @csrf
            <input type="hidden" name="product_id" :value="sel.id" />
            <div class="rounded-xl bg-gray-50 border border-gray-100 p-3 text-sm text-gray-600">
                {{ __('المنتج:') }} <span class="font-semibold text-gray-800" x-text="sel.name"></span> — SKU: <span class="font-mono" x-text="sel.sku"></span>
            </div>
            <x-select :label="__('الفرع')" name="branch_id" :placeholder="__('اختر الفرع...')" :required="true"
                :options="collect(\App\Support\Demo::branches())->pluck('name', 'id')->toArray()"
                :selected="\App\Support\Demo::currentBranchId()" />
            <x-select :label="__('نوع التعديل')" name="type" :options="[
                'تعديل يدوي' => __('تعيين الكمية الجديدة'),
                'إضافة كمية' => __('إضافة كمية'),
                'خصم كمية' => __('خصم كمية'),
            ]" selected="تعديل يدوي" />
            <x-input :label="__('الكمية')" name="quantity" type="number" x-bind:value="sel.qty" icon="hash" :required="true" />
            <x-input :label="__('ملاحظة')" name="note" :placeholder="__('سبب التعديل (اختياري)')" icon="sticky-note" />
        </form>

        <x-slot:footer>
            <x-button variant="ghost" size="md" x-on:click="$dispatch('close-modal')">{{ __('إلغاء') }}</x-button>
            <x-button variant="primary" size="md" icon="check" type="submit" form="adjust-form">{{ __('حفظ') }}</x-button>
        </x-slot:footer>
    </x-modal>
    </div>
    </div>
</x-layouts::admin>
