<x-layouts::admin :title="__('تحليلات الربحية')">

    <x-page-header :title="__('تحليلات الربحية')" :subtitle="__('هامش الربح الحقيقي لكل منتج وتصنيف — بناءً على سعر التكلفة')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('الربحية') => '#']" />

    @php
        $sum = \App\Support\Demo::profitSummary();
        $products = \App\Support\Demo::productProfitability();
        $categories = \App\Support\Demo::categoryProfitability();
    @endphp

    <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-6">
        <x-stat-card :label="__('إجمالي الإيراد')" :value="\App\Support\Demo::money($sum['revenue'])" icon="dollar-sign" color="info" />
        <x-stat-card :label="__('تكلفة البضاعة المباعة')" :value="\App\Support\Demo::money($sum['cost'])" icon="package" color="warning" />
        <x-stat-card :label="__('صافي الربح')" :value="\App\Support\Demo::money($sum['profit'])" icon="trending-up" :color="$sum['profit'] >= 0 ? 'success' : 'danger'" />
        <x-stat-card :label="__('هامش الربح')" value="{{ $sum['margin'] }}%" icon="percent" color="primary" />
    </div>

    {{-- الأفضل/الأسوأ + التنبيه للخاسرة --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        @if ($sum['best'])
            <div class="bg-white rounded-2xl border border-success-100 shadow-sm p-5">
                <div class="flex items-center gap-2 text-success-600 mb-2"><x-icon name="award" class="w-5 h-5" /><span class="font-bold">{{ __('الأعلى ربحًا') }}</span></div>
                <p class="font-bold text-gray-800">{{ $sum['best']['name'] }}</p>
                <p class="text-sm text-gray-500 mt-1">{{ __('ربح :p · هامش :m%', ['p' => \App\Support\Demo::money($sum['best']['profit']), 'm' => $sum['best']['margin']]) }}</p>
            </div>
        @endif
        @if ($sum['worst'] && $sum['worst']['name'] !== ($sum['best']['name'] ?? null))
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                <div class="flex items-center gap-2 text-gray-500 mb-2"><x-icon name="trending-down" class="w-5 h-5" /><span class="font-bold">{{ __('الأقل ربحًا') }}</span></div>
                <p class="font-bold text-gray-800">{{ $sum['worst']['name'] }}</p>
                <p class="text-sm text-gray-500 mt-1">{{ __('ربح :p · هامش :m%', ['p' => \App\Support\Demo::money($sum['worst']['profit']), 'm' => $sum['worst']['margin']]) }}</p>
            </div>
        @endif
        <div class="bg-white rounded-2xl border {{ count($sum['loss_makers']) ? 'border-danger-100' : 'border-gray-100' }} shadow-sm p-5">
            <div class="flex items-center gap-2 {{ count($sum['loss_makers']) ? 'text-danger-600' : 'text-gray-500' }} mb-2"><x-icon name="alert-triangle" class="w-5 h-5" /><span class="font-bold">{{ __('منتجات خاسرة') }}</span></div>
            @if (count($sum['loss_makers']))
                <p class="text-2xl font-bold text-danger-600">{{ count($sum['loss_makers']) }}</p>
                <p class="text-sm text-gray-500 mt-1">{{ __('تُباع بأقل من التكلفة — راجع تسعيرها.') }}</p>
            @else
                <p class="text-sm text-gray-500">{{ __('لا توجد منتجات تُباع بخسارة. 👍') }}</p>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- ربحية التصنيفات --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <h3 class="font-bold text-gray-800 mb-4">{{ __('الربح حسب التصنيف') }}</h3>
            @if (count($categories))
                <div x-data='apexChart({
                    chart: { type: "bar", height: 320, fontFamily: "IBM Plex Sans Arabic", toolbar: { show: false } },
                    series: [{ name: "{{ __('الربح') }}", data: @json(collect($categories)->pluck("profit")) }],
                    xaxis: { categories: @json(collect($categories)->pluck("name")) },
                    colors: ["#10b981"],
                    plotOptions: { bar: { borderRadius: 6, horizontal: true } },
                    dataLabels: { enabled: false },
                    grid: { borderColor: "#f1f5f9" }
                })'></div>
            @else
                <x-empty-state icon="pie-chart" :title="__('لا توجد بيانات')" :message="__('لا مبيعات مسجّلة بعد.')" />
            @endif
        </div>

        {{-- جدول ربحية المنتجات --}}
        <div class="lg:col-span-2 bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100"><h3 class="font-bold text-gray-800">{{ __('ربحية المنتجات') }}</h3></div>
            <div x-data="listFilter()" x-ref="list">
                <div class="px-4 pt-3"><x-input name="search" :placeholder="__('ابحث عن منتج...')" icon="search" x-model="q" @input="apply()" /></div>
                <x-table :headers="[__('المنتج'), __('المُباع'), __('الإيراد'), __('التكلفة'), __('الربح'), __('الهامش')]">
                    @forelse ($products as $p)
                        <tr class="hover:bg-gray-50" data-row data-search="{{ $p['name'] }}">
                            <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ $p['name'] }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $p['qty'] }}</td>
                            <td class="px-4 py-3 text-gray-700 whitespace-nowrap">{{ \App\Support\Demo::money($p['revenue']) }}</td>
                            <td class="px-4 py-3 text-gray-500 whitespace-nowrap">{{ \App\Support\Demo::money($p['cost']) }}</td>
                            <td class="px-4 py-3 font-semibold whitespace-nowrap {{ $p['profit'] >= 0 ? 'text-success-600' : 'text-danger-600' }}">{{ \App\Support\Demo::money($p['profit']) }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex px-2 py-0.5 rounded-lg text-xs font-medium {{ $p['margin'] >= 30 ? 'bg-success-50 text-success-600' : ($p['margin'] >= 0 ? 'bg-warning-50 text-warning-600' : 'bg-danger-50 text-danger-600') }}">{{ $p['margin'] }}%</span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">{{ __('لا توجد مبيعات لحساب ربحيتها بعد.') }}</td></tr>
                    @endforelse
                </x-table>
            </div>
        </div>
    </div>

</x-layouts::admin>
