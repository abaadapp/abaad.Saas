<x-layouts::admin title="التقارير">
    <x-page-header
        title="التقارير"
        subtitle="تحليلات شاملة لأداء المحل ومبيعاته وأرباحه"
        :breadcrumbs="['الرئيسية' => route('admin.dashboard'), 'التقارير' => '#']"
    >
        <x-slot:actions>
            <x-button variant="outline" size="md" icon="file-text" :href="route('admin.reports.pdf')">تصدير PDF</x-button>
            <x-button variant="success" size="md" icon="sheet" :href="route('admin.export.orders')">تصدير Excel</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- الفلتر الزمني --}}
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 mb-6" x-data="{ period: 'month' }">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div class="inline-flex rounded-xl bg-gray-100 p-1 flex-wrap">
                <template x-for="opt in [{ k: 'day', l: 'اليوم' }, { k: 'week', l: 'الأسبوع' }, { k: 'month', l: 'الشهر' }, { k: 'year', l: 'السنة' }]" :key="opt.k">
                    <button
                        @click="period = opt.k"
                        :class="period === opt.k ? 'bg-white text-primary-600 shadow-sm' : 'text-gray-500 hover:text-gray-700'"
                        class="px-4 py-2 rounded-lg text-sm font-medium transition-colors"
                        x-text="opt.l"
                    ></button>
                </template>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <span class="text-sm text-gray-500">فترة مخصصة:</span>
                <input type="date" @click="period = 'custom'" :class="period === 'custom' ? 'border-primary-500 ring-2 ring-primary-200' : 'border-gray-300'" class="rounded-xl border bg-white px-3 py-2 text-sm text-gray-700 focus:outline-none" />
                <span class="text-gray-400">—</span>
                <input type="date" @click="period = 'custom'" :class="period === 'custom' ? 'border-primary-500 ring-2 ring-primary-200' : 'border-gray-300'" class="rounded-xl border bg-white px-3 py-2 text-sm text-gray-700 focus:outline-none" />
                <x-button variant="primary" size="sm" icon="filter" @click="period = 'custom'">تطبيق</x-button>
            </div>
        </div>
    </div>

    {{-- بطاقات التقارير --}}
    @php
        $reportCards = [
            ['title' => 'تقرير المبيعات', 'icon' => 'shopping-bag', 'color' => 'primary', 'value' => '12,640 ر.ع'],
            ['title' => 'تقرير الأرباح', 'icon' => 'piggy-bank', 'color' => 'success', 'value' => '11,400 ر.ع'],
            ['title' => 'تقرير المنتجات', 'icon' => 'package', 'color' => 'info', 'value' => '151'],
            ['title' => 'تقرير الموظفين', 'icon' => 'users', 'color' => 'secondary', 'value' => '7'],
            ['title' => 'تقرير العملاء', 'icon' => 'user-round', 'color' => 'primary', 'value' => '214'],
            ['title' => 'تقرير المخزون', 'icon' => 'boxes', 'color' => 'warning', 'value' => '9 تنبيهات'],
            ['title' => 'تقرير المصروفات', 'icon' => 'arrow-down-circle', 'color' => 'danger', 'value' => '1,240 ر.ع'],
            ['title' => 'تقرير الضرائب', 'icon' => 'percent', 'color' => 'info', 'value' => '632 ر.ع'],
            ['title' => 'وسائل الدفع', 'icon' => 'credit-card', 'color' => 'secondary', 'value' => '4 وسائل'],
        ];
        $cardColors = [
            'primary' => 'bg-primary-50 text-primary-600',
            'success' => 'bg-success-50 text-success-600',
            'info' => 'bg-info-50 text-info-600',
            'warning' => 'bg-warning-50 text-warning-600',
            'danger' => 'bg-danger-50 text-danger-600',
            'secondary' => 'bg-secondary-50 text-secondary-600',
        ];
    @endphp
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-3 gap-4 mb-6">
        @foreach ($reportCards as $card)
            <a href="{{ route('admin.reports.pdf') }}" target="_blank" class="block bg-white rounded-2xl border border-gray-100 shadow-sm p-5 hover:shadow-md hover:border-primary-200 transition">
                <div class="flex items-center justify-between">
                    <span class="w-11 h-11 rounded-xl flex items-center justify-center {{ $cardColors[$card['color']] }}">
                        <x-icon :name="$card['icon']" class="w-5 h-5" />
                    </span>
                    <x-icon name="chevron-left" class="w-4 h-4 text-gray-300" />
                </div>
                <h3 class="mt-4 font-bold text-gray-800">{{ $card['title'] }}</h3>
                <p class="mt-2 text-xl font-bold text-gray-800">{{ $card['value'] }}</p>
            </a>
        @endforeach
    </div>

    {{-- الرسوم البيانية --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <div class="lg:col-span-2 bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <h3 class="font-bold text-gray-800 mb-4">المبيعات الشهرية</h3>
            @php $salesSeries = \App\Support\Demo::salesSeries(); @endphp
            <div x-data='apexChart({
                chart: { type: "area", height: 320, fontFamily: "Tajawal", toolbar: { show: false } },
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
                chart: { type: "donut", height: 320, fontFamily: "Tajawal", toolbar: { show: false } },
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
