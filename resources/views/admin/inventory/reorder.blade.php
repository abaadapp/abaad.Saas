<x-layouts::admin :title="__('إعادة الطلب')">
    <x-page-header
        :title="__('إعادة الطلب')"
        :subtitle="__('الأصناف التي بلغت حد التنبيه أو نفدت — تحتاج إعادة تزويد')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('المخزون') => route('admin.inventory.index'), __('إعادة الطلب') => '#']"
    >
        <x-slot:actions>
            <x-button variant="primary" size="md" icon="plus" :href="route('admin.purchases.create')">{{ __('إنشاء أمر شراء') }}</x-button>
        </x-slot:actions>
    </x-page-header>

    @include('partials.inventory-tabs')

    @if (count($items))
        <div class="mb-6">
            <x-alert type="warning" :title="__('أصناف تحتاج إعادة طلب')">
                {!! __(':n صنفًا وصلت إلى حد التنبيه أو أقل. راجعها وأنشئ أمر شراء للمورّد.', [
                    'n' => '<span class="font-semibold">' . count($items) . '</span>',
                ]) !!}
            </x-alert>
        </div>

        <x-table :headers="[__('المنتج'), 'SKU', __('الكمية الحالية'), __('الحد الأدنى'), __('النقص المقترح'), __('حالة المخزون'), __('إجراء')]">
            @foreach ($items as $item)
                @php $suggested = max((int) $item['min'] * 2 - (int) $item['qty'], (int) $item['min']); @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ $item['name'] }}</td>
                    <td class="px-4 py-3 text-gray-500 whitespace-nowrap font-mono">{{ $item['sku'] }}</td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        <span class="font-semibold {{ (int) $item['qty'] === 0 ? 'text-danger-600' : 'text-warning-600' }}">{{ $item['qty'] }}</span>
                        <span class="text-xs text-gray-400">{{ __('وحدة') }}</span>
                    </td>
                    <td class="px-4 py-3 text-gray-500 whitespace-nowrap">{{ $item['min'] }}</td>
                    <td class="px-4 py-3 whitespace-nowrap font-semibold text-primary-600">+{{ $suggested }}</td>
                    <td class="px-4 py-3 whitespace-nowrap"><x-badge :text="__($item['status'])" /></td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        <x-button variant="light" size="sm" icon="shopping-cart" :href="route('admin.purchases.create')">{{ __('طلب') }}</x-button>
                    </td>
                </tr>
            @endforeach
        </x-table>
    @else
        <x-empty-state icon="package-check" :title="__('لا توجد أصناف تحتاج إعادة طلب')" :message="__('كل الكميات فوق حد التنبيه. أحسنت!')" />
    @endif

</x-layouts::admin>
