<x-layouts::super-admin title="لوحة التحكم">

    <x-page-header title="لوحة التحكم" subtitle="نظرة عامة على أداء منصة Abad POS">
        <x-slot:actions>
            <span class="inline-flex items-center gap-1.5 text-xs text-gray-500 bg-gray-50 border border-gray-100 rounded-full px-3 py-1.5">
                <span class="relative flex w-2 h-2">
                    <span class="absolute inline-flex w-full h-full rounded-full bg-success-400 opacity-75 animate-ping"></span>
                    <span class="relative inline-flex w-2 h-2 rounded-full bg-success-500"></span>
                </span>
                <span data-stat-updated>مباشر</span>
            </span>
            <div class="w-40">
                <x-select name="period" :options="['today' => 'اليوم', 'week' => 'الأسبوع', 'month' => 'الشهر', 'year' => 'السنة']" selected="month" />
            </div>
            <x-button variant="primary" icon="plus" :href="route('super-admin.businesses.create')">إضافة شركة جديدة</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- شبكة الإحصائيات (تحديث لحظي + قابلة للتخصيص) --}}
    <div x-data="dashboardGrid('super')" class="mb-6">
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
             x-ref="grid" x-init="liveStats($refs.grid, '{{ route('super-admin.dashboard.stats') }}')">
            @foreach (\App\Support\Demo::superStats() as $s)
                <x-stat-card :label="$s['label']" :value="$s['value']" :icon="$s['icon']" :trend="$s['trend']" :up="$s['up']" :color="$s['color']" />
            @endforeach
        </div>
    </div>

    {{-- الرسوم البيانية --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-bold text-gray-800">الإيرادات الشهرية</h3>
                <span class="text-xs text-gray-400">آخر 12 شهرًا</span>
            </div>
            @php $revenueSeries = \App\Support\Demo::revenueSeries(); @endphp
            <div x-data='apexChart({
                chart: { type: "area", height: 300, fontFamily: "Tajawal", toolbar: { show: false } },
                series: [{ name: "الإيرادات", data: @json($revenueSeries['data']) }],
                xaxis: { categories: @json($revenueSeries['labels']) },
                colors: ["#7c3aed"],
                dataLabels: { enabled: false },
                stroke: { curve: "smooth", width: 2 },
                fill: { type: "gradient", gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.05 } },
                grid: { borderColor: "#f1f5f9" }
            })'></div>
        </div>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-bold text-gray-800">نمو الشركات</h3>
                <span class="text-xs text-gray-400">شركات جديدة شهريًا</span>
            </div>
            @php $growthSeries = \App\Support\Demo::businessesGrowthSeries(); @endphp
            <div x-data='apexChart({
                chart: { type: "bar", height: 300, fontFamily: "Tajawal", toolbar: { show: false } },
                series: [{ name: "شركات جديدة", data: @json($growthSeries['data']) }],
                xaxis: { categories: @json($growthSeries['labels']) },
                colors: ["#db2777"],
                dataLabels: { enabled: false },
                plotOptions: { bar: { borderRadius: 6, columnWidth: "55%" } },
                grid: { borderColor: "#f1f5f9" }
            })'></div>
        </div>
    </div>

    {{-- الجداول --}}
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">
        {{-- آخر الشركات المسجلة --}}
        <div class="xl:col-span-2">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-bold text-gray-800">آخر الشركات المسجلة</h3>
                <a href="{{ route('super-admin.businesses.index') }}" class="text-sm text-primary-600 hover:text-primary-700 font-medium">عرض الكل</a>
            </div>
            <x-table :headers="['الشركة', 'النوع', 'المالك', 'الباقة', 'الحالة', 'تاريخ التسجيل', '']">
                @foreach (array_slice(\App\Support\Demo::businesses(), 0, 6) as $b)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <img src="{{ $b['logo'] }}" alt="{{ $b['name'] }}" class="w-9 h-9 rounded-lg object-cover border border-gray-100" />
                                <span class="font-medium text-gray-800 whitespace-nowrap">{{ $b['name'] }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $b['type'] }}</td>
                        <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $b['owner'] }}</td>
                        <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $b['plan'] }}</td>
                        <td class="px-4 py-3"><x-badge :text="$b['status']" /></td>
                        <td class="px-4 py-3 text-gray-500 whitespace-nowrap">{{ $b['registered'] }}</td>
                        <td class="px-4 py-3">
                            <x-button variant="ghost" size="sm" icon="eye" :href="route('super-admin.businesses.show', $b['id'])">عرض</x-button>
                        </td>
                    </tr>
                @endforeach
            </x-table>
        </div>

        {{-- أحدث الأنشطة --}}
        <div>
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-bold text-gray-800">أحدث الأنشطة</h3>
            </div>
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                <ul class="space-y-4">
                    @foreach (\App\Support\Demo::activities() as $a)
                        @php
                            $colorMap = [
                                'primary' => 'bg-primary-50 text-primary-600',
                                'secondary' => 'bg-secondary-50 text-secondary-600',
                                'success' => 'bg-success-50 text-success-600',
                                'warning' => 'bg-warning-50 text-warning-600',
                                'danger' => 'bg-danger-50 text-danger-600',
                                'info' => 'bg-info-50 text-info-600',
                            ];
                            $ac = $colorMap[$a['color']] ?? $colorMap['primary'];
                        @endphp
                        <li class="flex items-start gap-3">
                            <span class="shrink-0 w-9 h-9 rounded-xl flex items-center justify-center {{ $ac }}">
                                <x-icon :name="$a['icon']" class="w-4 h-4" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-sm text-gray-700 leading-snug">{{ $a['text'] }}</p>
                                <p class="mt-0.5 text-xs text-gray-400">{{ $a['time'] }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>

    {{-- اشتراكات ستنتهي قريبًا --}}
    <div>
        <div class="flex items-center justify-between mb-3">
            <h3 class="font-bold text-gray-800">اشتراكات ستنتهي قريبًا</h3>
            <a href="{{ route('super-admin.subscriptions.index') }}" class="text-sm text-primary-600 hover:text-primary-700 font-medium">إدارة الاشتراكات</a>
        </div>
        <x-table :headers="['الشركة', 'الباقة', 'تاريخ الانتهاء', 'المبلغ', 'الحالة']">
            @foreach (array_slice(\App\Support\Demo::subscriptions(), 0, 5) as $sub)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ $sub['business'] }}</td>
                    <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $sub['plan'] }}</td>
                    <td class="px-4 py-3 text-gray-500 whitespace-nowrap">{{ $sub['end'] }}</td>
                    <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ \App\Support\Demo::money($sub['amount']) }}</td>
                    <td class="px-4 py-3"><x-badge :text="$sub['status']" /></td>
                </tr>
            @endforeach
        </x-table>
    </div>

</x-layouts::super-admin>
