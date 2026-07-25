<x-layouts::admin :title="__('تفاصيل المنتج')">

    @php
        $product = \App\Support\Demo::product(request()->route('id'));
        $margin = $product['price'] - $product['cost'];
        $marginPct = $product['price'] > 0 ? round(($margin / $product['price']) * 100) : 0;
        $thumbs = [
            \App\Support\Demo::image('prod0', 600, 600),
            \App\Support\Demo::image('prod0b', 600, 600),
            \App\Support\Demo::image('prod0c', 600, 600),
            \App\Support\Demo::image('prod0d', 600, 600),
        ];
    @endphp

    <x-page-header title="{{ $product['name'] }}" subtitle="{{ $product['cat'] }} · {{ $product['sku'] }}"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('المنتجات') => route('admin.products.index'), $product['name'] => '#']">
        <x-slot:actions>
            <x-button variant="outline" icon="barcode" :href="route('admin.products.barcodes', ['copies' => 1])">{{ __('الباركود') }}</x-button>
            <x-button variant="outline" icon="pencil" :href="route('admin.products.edit', $product['id'])">{{ __('تعديل') }}</x-button>
            <form method="POST" action="{{ route('admin.products.destroy', $product['id']) }}" @submit="if(!confirm(@js(__('حذف هذا المنتج نهائيًا؟')))) $event.preventDefault()">
                @csrf @method('DELETE')
                <x-button variant="danger" icon="trash-2" type="submit">{{ __('حذف') }}</x-button>
            </form>
        </x-slot:actions>
    </x-page-header>

    {{-- العمودان الرئيسيان --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        {{-- صور المنتج --}}
        <div x-data="{ current: '{{ $thumbs[0] }}' }" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <div class="aspect-square rounded-xl overflow-hidden bg-gray-50 border border-gray-100">
                <img :src="current" alt="{{ $product['name'] }}" class="w-full h-full object-cover" />
            </div>
            <div class="grid grid-cols-4 gap-3 mt-4">
                @foreach ($thumbs as $thumb)
                    <button type="button" @click="current = '{{ $thumb }}'"
                        :class="current === '{{ $thumb }}' ? 'border-primary-500 ring-2 ring-primary-200' : 'border-gray-100'"
                        class="aspect-square rounded-full overflow-hidden border-2 transition">
                        <img src="{{ $thumb }}" alt="{{ __('صورة مصغرة') }}" class="w-full h-full object-cover" />
                    </button>
                @endforeach
            </div>
        </div>

        {{-- تفاصيل المنتج --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            <div class="flex items-center gap-2 mb-3">
                <x-badge :text="__($product['stock_status'])" />
                <x-badge :type="$product['active'] ? 'success' : 'gray'">{{ $product['active'] ? __('مفعّل') : __('غير مفعّل') }}</x-badge>
                @if ($product['discount'])
                    <x-badge type="secondary">{{ __('خصم :n%', ['n' => $product['discount']]) }}</x-badge>
                @endif
            </div>
            <p class="text-sm text-primary-600 font-medium">{{ $product['cat'] }}</p>
            <h2 class="text-2xl font-bold text-gray-800 mt-1">{{ $product['name'] }}</h2>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-3xl font-bold text-gray-900">{{ \App\Support\Demo::money($product['price']) }}</span>
                <span class="text-sm text-gray-400">{{ __('شامل ضريبة :n%', ['n' => $product['tax']]) }}</span>
            </div>
            <p class="mt-4 text-sm text-gray-600 leading-relaxed">
                {{ __('باقة أنيقة من الورود الطبيعية الطازجة مناسبة لجميع المناسبات، منسّقة بعناية بأيدي خبراء التنسيق لدينا لتمنح لمسة جمالية مميزة.') }}
            </p>
            <dl class="mt-6 grid grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-gray-400">{{ __('رمز المنتج SKU') }}</dt>
                    <dd class="mt-0.5 font-medium text-gray-800">{{ $product['sku'] }}</dd>
                </div>
                <div>
                    <dt class="text-gray-400">{{ __('الباركود') }}</dt>
                    <dd class="mt-0.5 font-medium text-gray-800">{{ $product['barcode'] }}</dd>
                </div>
                <div>
                    <dt class="text-gray-400">{{ __('سعر التكلفة') }}</dt>
                    <dd class="mt-0.5 font-medium text-gray-800">{{ \App\Support\Demo::money($product['cost']) }}</dd>
                </div>
                <div>
                    <dt class="text-gray-400">{{ __('حد التنبيه') }}</dt>
                    <dd class="mt-0.5 font-medium text-gray-800">{{ __(':n قطعة', ['n' => $product['alert']]) }}</dd>
                </div>
            </dl>
        </div>
    </div>

    {{-- البطاقات الإحصائية --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <x-stat-card :label="__('الكمية المتوفرة')" :value="__(':n قطعة', ['n' => $product['qty']])" icon="package" color="primary" />
        <x-stat-card :label="__('إجمالي المبيعات')" :value="__(':n قطعة', ['n' => \App\Support\Demo::productSold($product['id'])])" icon="shopping-cart" color="success" />
        <x-stat-card :label="__('سعر التكلفة')" :value="\App\Support\Demo::money($product['cost'])" icon="tag" color="info" />
        <x-stat-card :label="__('هامش الربح')" :value="$marginPct . '%'" :trend="\App\Support\Demo::money($margin)" :up="true" color="secondary" />
    </div>

    {{-- حركة المخزون --}}
    <div>
        <div class="flex items-center justify-between mb-3">
            <h3 class="font-bold text-gray-800">{{ __('حركة المخزون') }}</h3>
            <a href="{{ route('admin.inventory.movements') }}" class="text-sm text-primary-600 hover:text-primary-700 font-medium">{{ __('عرض الكل') }}</a>
        </div>
        <x-table :headers="[__('نوع الحركة'), __('الكمية'), __('الموظف'), __('التاريخ')]">
            @foreach (array_slice(array_filter(\App\Support\Demo::movements(), fn ($m) => $m['product'] === $product['name']), 0, 6) as $m)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ __($m['type']) }}</td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        <span class="font-medium {{ str_starts_with($m['qty'], '+') ? 'text-success-600' : 'text-danger-600' }}">{{ $m['qty'] }}</span>
                    </td>
                    <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $m['employee'] }}</td>
                    <td class="px-4 py-3 text-gray-500 whitespace-nowrap">{{ $m['date'] }}</td>
                </tr>
            @endforeach
        </x-table>
    </div>

</x-layouts::admin>
