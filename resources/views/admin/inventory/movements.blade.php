<x-layouts::admin :title="__('حركات المخزون')">
    <x-page-header
        :title="__('حركات المخزون')"
        :subtitle="__('سجل كامل لجميع عمليات الإضافة والخصم والمرتجعات والتلف')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('المخزون') => route('admin.inventory.index'), __('حركات المخزون') => '#']"
    >
        <x-slot:actions>
            <x-button variant="outline" size="md" icon="arrow-right" :href="route('admin.inventory.index')">{{ __('رجوع للمخزون') }}</x-button>
            <x-button variant="primary" size="md" icon="plus" x-on:click="$dispatch('open-modal','add-movement')">{{ __('إضافة حركة') }}</x-button>
        </x-slot:actions>
    </x-page-header>

    @include('partials.inventory-tabs')

    <div x-data="listFilter()" x-ref="list">
    {{-- شريط الفلاتر --}}
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 mb-6">
        <div class="flex flex-col md:flex-row md:items-center gap-3">
            <div class="flex-1">
                <x-input name="search" :placeholder="__('ابحث باسم المنتج أو رمز SKU...')" icon="search" x-model="q" @input="apply()" />
            </div>
            <div class="w-full md:w-56">
                <x-select name="type" :placeholder="__('كل أنواع الحركات')" x-model="tag" @change="apply()" :options="[
                    'إضافة كمية' => __('إضافة كمية'),
                    'خصم كمية' => __('خصم كمية'),
                    'مرتجع' => __('مرتجع'),
                    'تلف' => __('تلف'),
                    'تعديل يدوي' => __('تعديل يدوي'),
                ]" />
            </div>
            <x-button variant="light" size="md" icon="filter" @click="apply()">{{ __('تصفية') }}</x-button>
        </div>
    </div>

    {{-- جدول الحركات --}}
    @php
        $typeColors = [
            'إضافة كمية' => 'success',
            'خصم كمية' => 'danger',
            'مرتجع' => 'info',
            'تلف' => 'warning',
            'تعديل يدوي' => 'primary',
        ];
    @endphp
    <x-table :headers="[__('المنتج'), 'SKU', __('الفرع'), __('نوع الحركة'), __('الكمية'), __('الموظف'), __('التاريخ')]">
        @foreach (\App\Support\Demo::movements() as $movement)
            <tr class="hover:bg-gray-50" data-row data-tag="{{ $movement['type'] }}" data-search="{{ $movement['product'] }} {{ $movement['sku'] }} {{ $movement['branch'] }} {{ $movement['employee'] }}">
                <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ $movement['product'] }}</td>
                <td class="px-4 py-3 text-gray-500 whitespace-nowrap font-mono">{{ $movement['sku'] }}</td>
                <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $movement['branch'] }}</td>
                <td class="px-4 py-3 whitespace-nowrap">
                    <x-badge :type="$typeColors[$movement['type']] ?? 'gray'" :text="__($movement['type'])" />
                </td>
                <td class="px-4 py-3 whitespace-nowrap">
                    <span class="font-semibold {{ str_starts_with($movement['qty'], '+') ? 'text-success-600' : 'text-danger-600' }}" dir="ltr">{{ $movement['qty'] }}</span>
                </td>
                <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $movement['employee'] }}</td>
                <td class="px-4 py-3 text-gray-500 whitespace-nowrap" dir="ltr">{{ $movement['date'] }}</td>
            </tr>
        @endforeach

        <x-slot:footer>
            <x-pagination :total="count(\App\Support\Demo::movements())" :perPage="10" :current="1" />
        </x-slot:footer>
    </x-table>
    </div>

    {{-- نافذة إضافة حركة --}}
    <x-modal name="add-movement" :title="__('إضافة حركة مخزون')" maxWidth="max-w-lg">
        <form id="add-movement-form" method="POST" action="{{ route('admin.inventory.store') }}" class="space-y-4">
            @csrf
            <x-select :label="__('الفرع')" name="branch_id" :placeholder="__('اختر الفرع...')" :required="true"
                :options="collect(\App\Support\Demo::branches())->pluck('name', 'id')->toArray()"
                :selected="\App\Support\Demo::currentBranchId()" />
            <x-select :label="__('المنتج')" name="product_id" :placeholder="__('اختر المنتج...')" :options="collect(\App\Support\Demo::products())->pluck('name', 'id')->toArray()" :required="true" />
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-select :label="__('نوع الحركة')" name="type" :placeholder="__('اختر النوع...')" :options="[
                    'إضافة كمية' => __('إضافة كمية'),
                    'خصم كمية' => __('خصم كمية'),
                    'مرتجع' => __('مرتجع'),
                    'تلف' => __('تلف'),
                    'تعديل يدوي' => __('تعديل يدوي'),
                ]" :required="true" />
                <x-input :label="__('الكمية')" name="quantity" type="number" placeholder="0" icon="hash" :required="true" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">{{ __('ملاحظة') }}</label>
                <textarea name="note" rows="2" placeholder="{{ __('سبب الحركة أو أي تفاصيل إضافية...') }}" class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-800 placeholder-gray-400 focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:outline-none transition"></textarea>
            </div>
        </form>

        <x-slot:footer>
            <x-button variant="ghost" size="md" x-on:click="$dispatch('close-modal')">{{ __('إلغاء') }}</x-button>
            <x-button variant="primary" size="md" type="submit" form="add-movement-form" icon="check">{{ __('تسجيل الحركة') }}</x-button>
        </x-slot:footer>
    </x-modal>
</x-layouts::admin>
