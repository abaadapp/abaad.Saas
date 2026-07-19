<x-layouts::admin title="حركات المخزون">
    <x-page-header
        title="حركات المخزون"
        subtitle="سجل كامل لجميع عمليات الإضافة والخصم والمرتجعات والتلف"
        :breadcrumbs="['الرئيسية' => route('admin.dashboard'), 'المخزون' => route('admin.inventory.index'), 'حركات المخزون' => '#']"
    >
        <x-slot:actions>
            <x-button variant="outline" size="md" icon="arrow-right" :href="route('admin.inventory.index')">رجوع للمخزون</x-button>
            <x-button variant="primary" size="md" icon="plus" x-on:click="$dispatch('open-modal','add-movement')">إضافة حركة</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- شريط الفلاتر --}}
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 mb-6">
        <div class="flex flex-col md:flex-row md:items-center gap-3">
            <div class="flex-1">
                <x-input name="search" placeholder="ابحث باسم المنتج أو رمز SKU..." icon="search" />
            </div>
            <div class="w-full md:w-56">
                <x-select name="type" placeholder="كل أنواع الحركات" :options="[
                    'إضافة كمية' => 'إضافة كمية',
                    'خصم كمية' => 'خصم كمية',
                    'مرتجع' => 'مرتجع',
                    'تلف' => 'تلف',
                    'تعديل يدوي' => 'تعديل يدوي',
                ]" />
            </div>
            <x-button variant="light" size="md" icon="filter">تصفية</x-button>
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
    <x-table :headers="['المنتج', 'SKU', 'نوع الحركة', 'الكمية', 'الموظف', 'التاريخ']">
        @foreach (\App\Support\Demo::movements() as $movement)
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ $movement['product'] }}</td>
                <td class="px-4 py-3 text-gray-500 whitespace-nowrap font-mono">{{ $movement['sku'] }}</td>
                <td class="px-4 py-3 whitespace-nowrap">
                    <x-badge :type="$typeColors[$movement['type']] ?? 'gray'" :text="$movement['type']" />
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

    {{-- نافذة إضافة حركة --}}
    <x-modal name="add-movement" title="إضافة حركة مخزون" maxWidth="max-w-lg">
        <form id="add-movement-form" method="POST" action="{{ route('admin.inventory.store') }}" class="space-y-4">
            @csrf
            <x-select label="المنتج" name="product_id" placeholder="اختر المنتج..." :options="collect(\App\Support\Demo::products())->pluck('name', 'id')->toArray()" :required="true" />
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-select label="نوع الحركة" name="type" placeholder="اختر النوع..." :options="[
                    'إضافة كمية' => 'إضافة كمية',
                    'خصم كمية' => 'خصم كمية',
                    'مرتجع' => 'مرتجع',
                    'تلف' => 'تلف',
                    'تعديل يدوي' => 'تعديل يدوي',
                ]" :required="true" />
                <x-input label="الكمية" name="quantity" type="number" placeholder="0" icon="hash" :required="true" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">ملاحظة</label>
                <textarea name="note" rows="2" placeholder="سبب الحركة أو أي تفاصيل إضافية..." class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-800 placeholder-gray-400 focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:outline-none transition"></textarea>
            </div>
        </form>

        <x-slot:footer>
            <x-button variant="ghost" size="md" x-on:click="$dispatch('close-modal')">إلغاء</x-button>
            <x-button variant="primary" size="md" type="submit" form="add-movement-form" icon="check">تسجيل الحركة</x-button>
        </x-slot:footer>
    </x-modal>
</x-layouts::admin>
