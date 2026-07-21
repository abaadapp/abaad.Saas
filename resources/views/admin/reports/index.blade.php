<x-layouts::admin title="التقارير">
    <x-page-header
        title="التقارير"
        subtitle="تحليلات شاملة لأداء المحل ومبيعاته وأرباحه"
        :breadcrumbs="['الرئيسية' => route('admin.dashboard'), 'التقارير' => '#']"
    >
        <x-slot:actions>
            <x-dropdown align="left" width="w-56">
                <x-slot:trigger>
                    <span class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium rounded-full bg-primary-600 hover:bg-primary-700 text-white cursor-pointer">
                        <x-icon name="download" class="w-4 h-4" /> تصدير
                    </span>
                </x-slot:trigger>
                <a href="{{ route('admin.reports.xlsx') }}" class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50">
                    <x-icon name="file-spreadsheet" class="w-4 h-4 text-gray-400" /> تصدير كملف إكسل
                </a>
                <a href="{{ route('admin.reports.pdf') }}" target="_blank" class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50">
                    <x-icon name="file-text" class="w-4 h-4 text-gray-400" /> تصدير كملف PDF
                </a>
            </x-dropdown>
        </x-slot:actions>
    </x-page-header>

    {{-- بطاقات التقارير مصنّفة مع شرائح تصفية --}}
    @php
        // group: مالية | تشغيلية | تحليلات
        $reportCards = [
            ['title' => 'تقرير المبيعات', 'icon' => 'shopping-bag', 'color' => 'primary', 'value' => '12,640 ر.ع', 'group' => 'مالية', 'url' => route('admin.reports.pdf')],
            ['title' => 'تقرير الأرباح', 'icon' => 'piggy-bank', 'color' => 'success', 'value' => '11,400 ر.ع', 'group' => 'مالية', 'url' => route('admin.reports.pdf')],
            ['title' => 'تقرير المصروفات', 'icon' => 'arrow-down-circle', 'color' => 'danger', 'value' => '1,240 ر.ع', 'group' => 'مالية', 'url' => route('admin.reports.pdf')],
            ['title' => 'تقرير الضرائب', 'icon' => 'percent', 'color' => 'info', 'value' => '632 ر.ع', 'group' => 'مالية', 'url' => route('admin.reports.pdf')],
            ['title' => 'وسائل الدفع', 'icon' => 'credit-card', 'color' => 'secondary', 'value' => '4 وسائل', 'group' => 'مالية', 'url' => route('admin.reports.pdf')],
            ['title' => 'الربحية', 'icon' => 'trending-up', 'color' => 'success', 'value' => 'هوامش الربح', 'group' => 'مالية', 'url' => route('admin.profitability.index')],
            ['title' => 'ضريبة القيمة المضافة', 'icon' => 'landmark', 'color' => 'info', 'value' => 'الإقرار الضريبي', 'group' => 'مالية', 'url' => route('admin.vat.index')],

            ['title' => 'تقرير المنتجات', 'icon' => 'package', 'color' => 'info', 'value' => '151', 'group' => 'تشغيلية', 'url' => route('admin.reports.pdf')],
            ['title' => 'تقرير المخزون', 'icon' => 'boxes', 'color' => 'warning', 'value' => '9 تنبيهات', 'group' => 'تشغيلية', 'url' => route('admin.reports.pdf')],
            ['title' => 'تقرير الموظفين', 'icon' => 'users', 'color' => 'secondary', 'value' => '7', 'group' => 'تشغيلية', 'url' => route('admin.reports.pdf')],

            ['title' => 'تحليلات متقدمة', 'icon' => 'chart-line', 'color' => 'primary', 'value' => 'الاتجاهات والذروة', 'group' => 'تحليلات', 'url' => route('admin.analytics.index')],
            ['title' => 'تقرير العملاء', 'icon' => 'user-round', 'color' => 'primary', 'value' => '214', 'group' => 'تحليلات', 'url' => route('admin.reports.pdf')],
        ];
        $cardColors = [
            'primary' => 'bg-primary-50 text-primary-600',
            'success' => 'bg-success-50 text-success-600',
            'info' => 'bg-info-50 text-info-600',
            'warning' => 'bg-warning-50 text-warning-600',
            'danger' => 'bg-danger-50 text-danger-600',
            'secondary' => 'bg-secondary-50 text-secondary-600',
        ];
        $chips = [
            ['key' => 'all', 'label' => 'الكل', 'count' => count($reportCards)],
            ['key' => 'مالية', 'label' => 'التقارير المالية', 'count' => collect($reportCards)->where('group', 'مالية')->count()],
            ['key' => 'تشغيلية', 'label' => 'التقارير التشغيلية', 'count' => collect($reportCards)->where('group', 'تشغيلية')->count()],
            ['key' => 'تحليلات', 'label' => 'تقارير التحليلات', 'count' => collect($reportCards)->where('group', 'تحليلات')->count()],
        ];
    @endphp

    <div x-data="{ g: 'all' }" class="mb-6">
        {{-- شرائح التصفية --}}
        <div class="flex flex-wrap items-center gap-2.5 mb-5">
            @foreach ($chips as $chip)
                <button type="button" @click="g = '{{ $chip['key'] }}'"
                    :class="g === '{{ $chip['key'] }}'
                        ? 'bg-[#111] text-white border-[#111]'
                        : 'bg-white text-gray-700 border-gray-200 hover:border-gray-300'"
                    class="inline-flex items-center gap-2 h-11 px-5 rounded-full border text-sm font-medium transition-colors">
                    {{ $chip['label'] }}
                    <span :class="g === '{{ $chip['key'] }}' ? 'text-white/70' : 'text-gray-400'"
                          class="text-xs font-semibold">{{ $chip['count'] }}</span>
                </button>
            @endforeach
        </div>

        {{-- البطاقات --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach ($reportCards as $card)
                <a href="{{ $card['url'] }}" @if ($card['url'] === route('admin.reports.pdf')) target="_blank" @endif
                   x-show="g === 'all' || g === '{{ $card['group'] }}'"
                   class="block bg-white rounded-2xl border border-gray-100 shadow-sm p-5 hover:shadow-md hover:border-gray-300 transition">
                    <div class="flex items-center justify-between">
                        <span class="w-11 h-11 rounded-full flex items-center justify-center {{ $cardColors[$card['color']] }}">
                            <x-icon :name="$card['icon']" class="w-5 h-5" />
                        </span>
                        <x-icon name="chevron-left" class="w-4 h-4 text-gray-300" />
                    </div>
                    <h3 class="mt-4 font-bold text-gray-800">{{ $card['title'] }}</h3>
                    <p class="mt-2 text-xl font-bold text-gray-800">{{ $card['value'] }}</p>
                </a>
            @endforeach
        </div>
    </div>

    {{-- الرسوم البيانية --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <div class="lg:col-span-2 bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <h3 class="font-bold text-gray-800 mb-4">المبيعات الشهرية</h3>
            @php $salesSeries = \App\Support\Demo::salesSeries(); @endphp
            <div x-data='apexChart({
                chart: { type: "area", height: 320, fontFamily: "IBM Plex Sans Arabic", toolbar: { show: false } },
                series: [{ name: "المبيعات (ر.ع)", data: @json($salesSeries['data']) }],
                xaxis: { categories: @json($salesSeries['labels']) },
                colors: ["#7c3aed"],
                dataLabels: { enabled: false },
                stroke: { curve: "smooth", width: 2 },
                fill: { type: "gradient", gradient: { opacityFrom: 0.35, opacityTo: 0.05 } },
                grid: { borderColor: "#f1f5f9" }
            })'></div>
        </div>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <h3 class="font-bold text-gray-800 mb-4">وسائل الدفع</h3>
            @php $payDist = \App\Support\Demo::paymentDistribution(); @endphp
            <div x-data='apexChart({
                chart: { type: "donut", height: 320, fontFamily: "IBM Plex Sans Arabic", toolbar: { show: false } },
                series: @json($payDist['series']),
                labels: @json($payDist['labels']),
                colors: ["#7c3aed", "#db2777", "#10b981", "#f59e0b"],
                dataLabels: { enabled: true },
                legend: { position: "bottom" },
                stroke: { width: 0 }
            })'></div>
        </div>
    </div>

    {{-- جدول أفضل المنتجات --}}
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100">
            <h3 class="font-bold text-gray-800">أفضل المنتجات مبيعًا</h3>
        </div>
        <x-table :headers="['المنتج', 'الفئة', 'الكمية المباعة', 'الإيراد', 'نسبة المبيعات']">
            @php
                $topProducts = [
                    ['name' => 'باقة ورد أحمر', 'cat' => 'باقات ورد', 'sold' => 320, 'revenue' => 4000.000, 'pct' => '28%'],
                    ['name' => 'صندوق ورد وشوكولاتة', 'cat' => 'هدايا', 'sold' => 180, 'revenue' => 4500.000, 'pct' => '22%'],
                    ['name' => 'بوكيه زفاف', 'cat' => 'مناسبات', 'sold' => 64, 'revenue' => 2880.000, 'pct' => '16%'],
                    ['name' => 'وردة مفردة', 'cat' => 'باقات ورد', 'sold' => 640, 'revenue' => 1600.000, 'pct' => '14%'],
                    ['name' => 'صندوق شوكولاتة فاخر', 'cat' => 'شوكولاتة', 'sold' => 96, 'revenue' => 1488.000, 'pct' => '12%'],
                ];
            @endphp
            @foreach ($topProducts as $product)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ $product['name'] }}</td>
                    <td class="px-4 py-3 whitespace-nowrap"><x-badge type="secondary" :text="$product['cat']" /></td>
                    <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $product['sold'] }} وحدة</td>
                    <td class="px-4 py-3 font-semibold text-gray-800 whitespace-nowrap">{{ \App\Support\Demo::money($product['revenue']) }}</td>
                    <td class="px-4 py-3 whitespace-nowrap"><x-badge type="primary" :text="$product['pct']" /></td>
                </tr>
            @endforeach
        </x-table>
    </div>
</x-layouts::admin>
