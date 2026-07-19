<x-layouts::admin title="لوحة التحكم">

    <x-page-header title="لوحة التحكم" subtitle="نظرة عامة على أداء محل الورود">
        <x-slot:actions>
            <span class="inline-flex items-center gap-1.5 text-xs text-gray-500 bg-gray-50 border border-gray-100 rounded-full px-3 py-1.5">
                <span class="relative flex w-2 h-2">
                    <span class="absolute inline-flex w-full h-full rounded-full bg-success-400 opacity-75 animate-ping"></span>
                    <span class="relative inline-flex w-2 h-2 rounded-full bg-success-500"></span>
                </span>
                <span data-stat-updated>مباشر</span>
            </span>
            <div class="w-44">
                <x-select name="period" :options="['today' => 'اليوم', 'week' => 'هذا الأسبوع', 'month' => 'هذا الشهر', 'year' => 'هذه السنة']" selected="month" />
            </div>
            <x-button variant="outline" icon="calendar" type="button">تحديد التاريخ</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- بطاقات الإحصائيات (تحديث لحظي + قابلة للتخصيص) --}}
    <div x-data="dashboardGrid('admin')" class="mb-6">
        <div class="flex flex-wrap items-center justify-end gap-2 mb-3">
            <div x-show="editing" x-cloak class="flex items-center gap-1.5 flex-wrap ml-auto mr-0">
                <span class="text-xs text-gray-400">المخفية:</span>
                <template x-for="label in hidden" :key="label">
                    <button type="button" @click="show(label)" class="inline-flex items-center gap-1 text-xs bg-gray-100 hover:bg-gray-200 rounded-lg px-2 py-1 text-gray-600">
                        <i data-lucide="plus" class="w-3 h-3"></i><span x-text="label"></span>
                    </button>
                </template>
                <span x-show="!hidden.length" class="text-xs text-gray-300">لا شيء</span>
            </div>
            <button type="button" @click="toggleEdit()" :class="editing ? 'bg-primary-600 text-white border-primary-600' : 'bg-white text-gray-600 border-gray-200'" class="inline-flex items-center gap-1.5 rounded-xl border px-3 py-1.5 text-sm font-medium transition-colors">
                <i :data-lucide="editing ? 'check' : 'sliders-horizontal'" class="w-4 h-4"></i>
                <span x-text="editing ? 'تم' : 'تخصيص اللوحة'"></span>
            </button>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4"
             x-ref="grid" x-init="liveStats($refs.grid, '{{ route('admin.dashboard.stats') }}')">
            @foreach (\App\Support\Demo::adminStats() as $s)
                <x-stat-card :label="$s['label']" :value="$s['value']" :icon="$s['icon']" :trend="$s['trend']" :up="$s['up']" :color="$s['color']" />
            @endforeach
        </div>
    </div>

    {{-- المبيعات + وسائل الدفع --}}
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-4 mb-6">
        <div class="xl:col-span-2 bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-bold text-gray-800">حركة المبيعات</h3>
                <span class="text-xs text-gray-400">آخر 12 شهرًا</span>
            </div>
            @php $salesSeries = \App\Support\Demo::salesSeries(); @endphp
            <div x-data='apexChart({
                chart: { type: "area", height: 320, fontFamily: "Tajawal", toolbar: { show: false } },
                series: [{ name: "المبيعات", data: @json($salesSeries['data']) }],
                xaxis: { categories: @json($salesSeries['labels']) },
                colors: ["#7c3aed"],
                dataLabels: { enabled: false },
                stroke: { curve: "smooth", width: 2 },
                fill: { type: "gradient", gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.05 } },
                grid: { borderColor: "#f1f5f9" }
            })'></div>
        </div>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-bold text-gray-800">ملخص وسائل الدفع</h3>
                <span class="text-xs text-gray-400">هذا الشهر</span>
            </div>
            @php $payDist = \App\Support\Demo::paymentDistribution(); @endphp
            <div x-data='apexChart({
                chart: { type: "donut", height: 300, fontFamily: "Tajawal" },
                series: @json($payDist['series']),
                labels: @json($payDist['labels']),
                colors: ["#7c3aed","#db2777","#10b981","#f59e0b"],
                dataLabels: { enabled: false },
                legend: { position: "bottom", fontFamily: "Tajawal" },
                plotOptions: { pie: { donut: { size: "68%" } } }
            })'></div>
        </div>
    </div>

    {{-- أفضل المنتجات + آخر الطلبات --}}
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">
        {{-- أفضل المنتجات مبيعًا --}}
        <div>
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-bold text-gray-800">أفضل المنتجات مبيعًا</h3>
                <a href="{{ route('admin.products.index') }}" class="text-sm text-primary-600 hover:text-primary-700 font-medium">عرض الكل</a>
            </div>
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                <ul class="space-y-4">
                    @php $topProducts = array_slice(\App\Support\Demo::products(), 0, 5); $maxSold = 340; @endphp
                    @foreach ($topProducts as $i => $p)
                        @php $sold = 340 - ($i * 55); $pct = round(($sold / $maxSold) * 100); @endphp
                        <li>
                            <div class="flex items-center gap-3">
                                <img src="{{ $p['image'] }}" alt="{{ $p['name'] }}" class="w-10 h-10 rounded-lg object-cover border border-gray-100" />
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="text-sm font-medium text-gray-800 truncate">{{ $p['name'] }}</p>
                                        <span class="text-xs text-gray-400 whitespace-nowrap">{{ $sold }} مبيعًا</span>
                                    </div>
                                    <div class="mt-1.5 h-1.5 w-full rounded-full bg-gray-100 overflow-hidden">
                                        <div class="h-full rounded-full bg-primary-500" style="width: {{ $pct }}%"></div>
                                    </div>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        {{-- آخر الطلبات --}}
        <div class="xl:col-span-2">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-bold text-gray-800">آخر الطلبات</h3>
                <a href="{{ route('admin.orders.index') }}" class="text-sm text-primary-600 hover:text-primary-700 font-medium">عرض الكل</a>
            </div>
            <x-table :headers="['رقم الطلب', 'العميل', 'الإجمالي', 'الحالة', '']">
                @foreach (array_slice(\App\Support\Demo::orders(), 0, 6) as $o)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ $o['id'] }}</td>
                        <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $o['customer'] }}</td>
                        <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ \App\Support\Demo::money($o['total']) }}</td>
                        <td class="px-4 py-3"><x-badge :text="$o['status']" /></td>
                        <td class="px-4 py-3">
                            <x-button variant="ghost" size="sm" icon="eye" :href="route('admin.orders.show', $o['id'])">عرض</x-button>
                        </td>
                    </tr>
                @endforeach
            </x-table>
        </div>
    </div>

    {{-- منخفض المخزون + الموظفون الأكثر مبيعًا --}}
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
        {{-- منتجات منخفضة المخزون --}}
        <div>
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-bold text-gray-800">منتجات منخفضة المخزون</h3>
                <a href="{{ route('admin.inventory.index') }}" class="text-sm text-primary-600 hover:text-primary-700 font-medium">إدارة المخزون</a>
            </div>
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                @php $lowStock = array_filter(\App\Support\Demo::products(), fn ($p) => $p['qty'] < 10); @endphp
                @if (count($lowStock))
                    <ul class="divide-y divide-gray-50">
                        @foreach ($lowStock as $p)
                            <li class="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
                                <img src="{{ $p['image'] }}" alt="{{ $p['name'] }}" class="w-10 h-10 rounded-lg object-cover border border-gray-100" />
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-gray-800 truncate">{{ $p['name'] }}</p>
                                    <p class="text-xs text-gray-400">{{ $p['sku'] }}</p>
                                </div>
                                <div class="text-left">
                                    <p class="text-sm font-bold {{ $p['qty'] == 0 ? 'text-danger-600' : 'text-warning-600' }}">{{ $p['qty'] }} قطعة</p>
                                    <x-badge :text="$p['stock_status']" />
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <x-empty-state icon="package-check" title="المخزون بحالة جيدة" message="لا توجد منتجات منخفضة المخزون حاليًا." />
                @endif
            </div>
        </div>

        {{-- الموظفون الأكثر مبيعًا --}}
        <div>
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-bold text-gray-800">الموظفون الأكثر مبيعًا</h3>
                <a href="{{ route('admin.employees.index') }}" class="text-sm text-primary-600 hover:text-primary-700 font-medium">عرض الكل</a>
            </div>
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                @php
                    $employees = \App\Support\Demo::employees();
                    usort($employees, fn ($a, $b) => $b['sales'] <=> $a['sales']);
                    $topEmployees = array_slice($employees, 0, 5);
                @endphp
                <ul class="space-y-4">
                    @foreach ($topEmployees as $i => $e)
                        <li class="flex items-center gap-3">
                            <span class="relative shrink-0">
                                <img src="{{ $e['avatar'] }}" alt="{{ $e['name'] }}" class="w-10 h-10 rounded-full object-cover border border-gray-100" />
                                <span class="absolute -top-1 -right-1 w-5 h-5 rounded-full bg-primary-600 text-white text-[10px] font-bold flex items-center justify-center">{{ $i + 1 }}</span>
                            </span>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-800 truncate">{{ $e['name'] }}</p>
                                <p class="text-xs text-gray-400">{{ $e['role'] }}</p>
                            </div>
                            <span class="text-sm font-bold text-gray-800 whitespace-nowrap">{{ \App\Support\Demo::money($e['sales']) }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>

</x-layouts::admin>
