<x-layouts::admin title="تحليلات متقدمة">

    <x-page-header title="تحليلات متقدمة" subtitle="أفضل المنتجات والعملاء وأوقات الذروة"
        :breadcrumbs="['الرئيسية' => route('admin.dashboard'), 'تحليلات متقدمة' => '#']">
        <x-slot:actions>
            <x-button variant="outline" icon="download" :href="route('admin.export.analytics')">تصدير CSV</x-button>
            <x-button variant="outline" icon="file-text" :href="route('admin.analytics.pdf')" target="_blank">تصدير PDF</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- مقارنة الفترات: هذا الشهر مقابل السابق --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        @foreach (\App\Support\Demo::periodComparison() as $m)
            @php $up = $m['delta'] >= 0; @endphp
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-sm text-gray-500">{{ $m['label'] }}</p>
                        <p class="mt-2 text-2xl font-bold text-gray-800">{{ $m['cur'] }}</p>
                        <p class="text-xs text-gray-400 mt-1">الشهر السابق: {{ $m['prev'] }}</p>
                    </div>
                    <span class="shrink-0 inline-flex items-center gap-1 text-xs font-bold px-2 py-1 rounded-lg {{ $up ? 'bg-success-50 text-success-600' : 'bg-danger-50 text-danger-600' }}">
                        <x-icon :name="$up ? 'trending-up' : 'trending-down'" class="w-3.5 h-3.5" />
                        {{ $up ? '+' : '' }}{{ $m['delta'] }}%
                    </span>
                </div>
            </div>
        @endforeach
    </div>

    <p class="text-xs text-gray-400 mb-4">جميع القيم بالعملة الأساسية (الريال العماني).</p>

    @php
        $topProducts = \App\Support\Demo::topProducts();
        $topCustomers = \App\Support\Demo::topCustomers();
        $byWeekday = \App\Support\Demo::salesByWeekday();
        $byHour = \App\Support\Demo::salesByHour();
        $byCategory = \App\Support\Demo::categorySales();
    @endphp

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        {{-- أفضل المنتجات --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <h3 class="font-bold text-gray-800 mb-4">أفضل المنتجات مبيعًا (بالكمية)</h3>
            <div x-data='apexChart({
                chart: { type: "bar", height: 320, fontFamily: "Tajawal", toolbar: { show: false } },
                series: [{ name: "الكمية المباعة", data: @json(collect($topProducts)->pluck("qty")) }],
                xaxis: { categories: @json(collect($topProducts)->pluck("name")) },
                colors: ["#7c3aed"],
                plotOptions: { bar: { borderRadius: 6, horizontal: true } },
                dataLabels: { enabled: true },
                grid: { borderColor: "#f1f5f9" }
            })'></div>
        </div>

        {{-- أفضل العملاء --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <h3 class="font-bold text-gray-800 mb-4">أفضل العملاء إنفاقًا</h3>
            @if (count($topCustomers))
                <div x-data='apexChart({
                    chart: { type: "bar", height: 320, fontFamily: "Tajawal", toolbar: { show: false } },
                    series: [{ name: "إجمالي الإنفاق", data: @json(collect($topCustomers)->pluck("total")) }],
                    xaxis: { categories: @json(collect($topCustomers)->pluck("name")) },
                    colors: ["#db2777"],
                    plotOptions: { bar: { borderRadius: 6, horizontal: true } },
                    dataLabels: { enabled: false },
                    grid: { borderColor: "#f1f5f9" }
                })'></div>
            @else
                <x-empty-state icon="users" title="لا توجد بيانات عملاء" message="لم تُسجّل مبيعات لعملاء بعد." />
            @endif
        </div>

        {{-- المبيعات حسب اليوم --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <h3 class="font-bold text-gray-800 mb-4">المبيعات حسب أيام الأسبوع</h3>
            <div x-data='apexChart({
                chart: { type: "bar", height: 300, fontFamily: "Tajawal", toolbar: { show: false } },
                series: [{ name: "المبيعات", data: @json($byWeekday["data"]) }],
                xaxis: { categories: @json($byWeekday["labels"]) },
                colors: ["#10b981"],
                plotOptions: { bar: { borderRadius: 6, columnWidth: "55%" } },
                dataLabels: { enabled: false },
                grid: { borderColor: "#f1f5f9" }
            })'></div>
        </div>

        {{-- أوقات الذروة --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <h3 class="font-bold text-gray-800 mb-4">أوقات الذروة (حسب الساعة)</h3>
            <div x-data='apexChart({
                chart: { type: "area", height: 300, fontFamily: "Tajawal", toolbar: { show: false } },
                series: [{ name: "المبيعات", data: @json($byHour["data"]) }],
                xaxis: { categories: @json($byHour["labels"]) },
                colors: ["#f59e0b"],
                stroke: { curve: "smooth", width: 2 },
                fill: { type: "gradient", gradient: { opacityFrom: 0.35, opacityTo: 0.05 } },
                dataLabels: { enabled: false },
                grid: { borderColor: "#f1f5f9" }
            })'></div>
        </div>
    </div>

    {{-- المبيعات حسب التصنيف + جدول أفضل المنتجات --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <h3 class="font-bold text-gray-800 mb-4">المبيعات حسب التصنيف</h3>
            @if (count($byCategory['labels']))
                <div x-data='apexChart({
                    chart: { type: "donut", height: 320, fontFamily: "Tajawal" },
                    series: @json($byCategory["series"]),
                    labels: @json($byCategory["labels"]),
                    colors: ["#7c3aed","#db2777","#10b981","#f59e0b","#3b82f6","#ef4444"],
                    legend: { position: "bottom", fontFamily: "Tajawal" },
                    dataLabels: { enabled: true },
                    plotOptions: { pie: { donut: { size: "65%" } } }
                })'></div>
            @else
                <x-empty-state icon="pie-chart" title="لا توجد بيانات" message="لا توجد مبيعات مصنّفة بعد." />
            @endif
        </div>

        <div class="lg:col-span-2 bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100"><h3 class="font-bold text-gray-800">تفصيل أفضل المنتجات</h3></div>
            <x-table :headers="['المنتج', 'الكمية المباعة', 'الإيراد']">
                @forelse ($topProducts as $p)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-800">{{ $p['name'] }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $p['qty'] }} وحدة</td>
                        <td class="px-4 py-3 font-semibold text-gray-800">{{ \App\Support\Demo::money($p['total']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-8 text-center text-gray-400">لا توجد بيانات مبيعات بعد.</td></tr>
                @endforelse
            </x-table>
        </div>
    </div>

</x-layouts::admin>
