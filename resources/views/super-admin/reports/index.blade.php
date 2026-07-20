<x-layouts::super-admin title="التقارير">
    <x-page-header
        title="التقارير"
        subtitle="تقارير وتحليلات شاملة لأداء المنصة"
        :breadcrumbs="['الرئيسية' => route('super-admin.dashboard'), 'التقارير' => '#']"
    >
        <x-slot:actions>
            <x-button variant="outline" size="md" icon="file-text" :href="route('super-admin.reports.pdf')">تصدير PDF</x-button>
            <x-button variant="success" size="md" icon="sheet" :href="route('super-admin.export.businesses')">تصدير Excel</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- شريط الفلاتر الزمني --}}
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 mb-6" x-data="{ period: 'month' }">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div class="inline-flex rounded-xl bg-gray-100 p-1">
                <template x-for="opt in [{ k: 'day', l: 'اليوم' }, { k: 'week', l: 'الأسبوع' }, { k: 'month', l: 'الشهر' }, { k: 'year', l: 'السنة' }]" :key="opt.k">
                    <button type="button"
                        @click="period = opt.k"
                        :class="period === opt.k ? 'bg-white text-primary-600 shadow-sm' : 'text-gray-500 hover:text-gray-700'"
                        class="px-4 py-2 rounded-lg text-sm font-medium transition-colors"
                        x-text="opt.l"
                    ></button>
                </template>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-sm text-gray-500">فترة مخصصة:</span>
                <input type="date" @click="period = 'custom'" :class="period === 'custom' ? 'border-primary-500 ring-2 ring-primary-200' : 'border-gray-300'" class="rounded-xl border bg-white px-3 py-2 text-sm text-gray-700 focus:outline-none" />
                <span class="text-gray-400">—</span>
                <input type="date" @click="period = 'custom'" :class="period === 'custom' ? 'border-primary-500 ring-2 ring-primary-200' : 'border-gray-300'" class="rounded-xl border bg-white px-3 py-2 text-sm text-gray-700 focus:outline-none" />
                <x-button variant="primary" size="sm" icon="filter" @click="period = 'custom'">تطبيق</x-button>
            </div>
        </div>
    </div>

    {{-- بطاقات التقارير القابلة للنقر --}}
    @php
        $reportCards = [
            ['title' => 'تقرير الإيرادات', 'desc' => 'إجمالي إيرادات الاشتراكات', 'icon' => 'wallet', 'color' => 'primary', 'value' => '52,640 ر.ع'],
            ['title' => 'تقرير الاشتراكات', 'desc' => 'الاشتراكات النشطة والمنتهية', 'icon' => 'refresh-cw', 'color' => 'success', 'value' => '120'],
            ['title' => 'تقرير الشركات', 'desc' => 'الشركات المسجلة في المنصة', 'icon' => 'building-2', 'color' => 'info', 'value' => '128'],
            ['title' => 'تقرير الأنشطة', 'desc' => 'سجل الأنشطة والعمليات', 'icon' => 'activity', 'color' => 'warning', 'value' => '1,284'],
            ['title' => 'محلات الورود', 'desc' => 'أداء محلات الورود', 'icon' => 'flower', 'color' => 'secondary', 'value' => '37'],
        ];
        $cardColors = [
            'primary' => 'bg-primary-50 text-primary-600',
            'success' => 'bg-success-50 text-success-600',
            'info' => 'bg-info-50 text-info-600',
            'warning' => 'bg-warning-50 text-warning-600',
            'secondary' => 'bg-secondary-50 text-secondary-600',
        ];
    @endphp
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-4 mb-6">
        @foreach ($reportCards as $card)
            <a href="{{ route('super-admin.reports.pdf') }}" target="_blank" class="block bg-white rounded-2xl border border-gray-100 shadow-sm p-5 hover:shadow-md hover:border-primary-200 transition">
                <span class="w-11 h-11 rounded-xl flex items-center justify-center {{ $cardColors[$card['color']] }}">
                    <x-icon :name="$card['icon']" class="w-5 h-5" />
                </span>
                <h3 class="mt-4 font-bold text-gray-800">{{ $card['title'] }}</h3>
                <p class="text-xs text-gray-400 mt-1">{{ $card['desc'] }}</p>
                <p class="mt-3 text-xl font-bold text-gray-800">{{ $card['value'] }}</p>
            </a>
        @endforeach
    </div>

    {{-- الرسوم البيانية --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <div class="lg:col-span-2 bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <h3 class="font-bold text-gray-800 mb-4">الإيرادات الشهرية</h3>
            @php $revenueSeries = \App\Support\Demo::revenueSeries(); @endphp
            <div x-data='apexChart({
                chart: { type: "area", height: 320, fontFamily: "IBM Plex Sans Arabic", toolbar: { show: false } },
                series: [{ name: "الإيرادات", data: @json($revenueSeries['data']) }],
                xaxis: { categories: @json($revenueSeries['labels']) },
                colors: ["#7c3aed"],
                dataLabels: { enabled: false },
                stroke: { curve: "smooth", width: 2 },
                fill: { type: "gradient", gradient: { opacityFrom: 0.35, opacityTo: 0.05 } },
                grid: { borderColor: "#f1f5f9" }
            })'></div>
        </div>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <h3 class="font-bold text-gray-800 mb-4">توزيع الباقات</h3>
            @php $planDist = \App\Support\Demo::planDistribution(); @endphp
            <div x-data='apexChart({
                chart: { type: "donut", height: 320, fontFamily: "IBM Plex Sans Arabic", toolbar: { show: false } },
                series: @json($planDist['series']),
                labels: @json($planDist['labels']),
                colors: ["#7c3aed", "#db2777", "#10b981"],
                dataLabels: { enabled: true },
                legend: { position: "bottom" },
                stroke: { width: 0 }
            })'></div>
        </div>
    </div>

    {{-- جدول ملخص التقرير --}}
    <x-table :headers="['الباقة', 'عدد المشتركين', 'الإيراد الشهري', 'الإيراد السنوي', 'النسبة']">
        @php
            $summary = [
                ['plan' => 'الباقة الأساسية', 'subs' => 54, 'monthly' => 534.600, 'yearly' => 5346.000, 'pct' => '32%'],
                ['plan' => 'الباقة الاحترافية', 'subs' => 48, 'monthly' => 1195.200, 'yearly' => 11952.000, 'pct' => '45%'],
                ['plan' => 'باقة المؤسسات', 'subs' => 26, 'monthly' => 1557.400, 'yearly' => 15574.000, 'pct' => '23%'],
            ];
        @endphp
        @foreach ($summary as $row)
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ $row['plan'] }}</td>
                <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $row['subs'] }}</td>
                <td class="px-4 py-3 text-gray-700 whitespace-nowrap">{{ \App\Support\Demo::money($row['monthly']) }}</td>
                <td class="px-4 py-3 font-semibold text-gray-800 whitespace-nowrap">{{ \App\Support\Demo::money($row['yearly']) }}</td>
                <td class="px-4 py-3 whitespace-nowrap"><x-badge type="primary" :text="$row['pct']" /></td>
            </tr>
        @endforeach
    </x-table>
</x-layouts::super-admin>
