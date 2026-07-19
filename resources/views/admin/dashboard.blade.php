<x-layouts::admin title="لوحة التحكم">

    {{-- ===== الترويسة ===== --}}
    <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between mb-8" data-animate>
        <div>
            <h1 class="text-[26px] md:text-[30px] font-semibold text-[#111] tracking-tight">مساء الخير 👋</h1>
            <p class="mt-1 text-[15px] text-[#6b7280]">نظرة هادئة على أداء محل الورود اليوم.</p>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            <span class="inline-flex items-center gap-2 text-xs text-[#6b7280] bg-white rounded-full px-3 h-9" style="border:1px solid var(--ui-border);">
                <span class="relative flex w-1.5 h-1.5">
                    <span class="absolute inline-flex w-full h-full rounded-full bg-[#16a34a] opacity-60 animate-ping"></span>
                    <span class="relative inline-flex w-1.5 h-1.5 rounded-full bg-[#16a34a]"></span>
                </span>
                <span data-stat-updated>مباشر</span>
            </span>
            <select name="period" class="ui-select w-40 h-9">
                <option value="today">اليوم</option>
                <option value="week">هذا الأسبوع</option>
                <option value="month" selected>هذا الشهر</option>
                <option value="year">هذه السنة</option>
            </select>
            <div class="relative" x-data="{ open: false, from: '', to: '' }" @click.outside="open = false">
                <button type="button" @click="open = !open" class="ui-btn ui-btn-secondary ui-btn-sm">
                    <x-icon name="calendar" class="w-4 h-4" /> تحديد التاريخ
                </button>
                <div x-show="open" x-cloak x-transition class="absolute left-0 mt-2 z-30 w-64 bg-white rounded-2xl p-4 space-y-3" style="border:1px solid var(--ui-border); box-shadow:0 12px 32px -12px rgba(17,17,17,0.18);">
                    <div>
                        <label class="block text-xs text-[#6b7280] mb-1.5">من تاريخ</label>
                        <input type="date" x-model="from" class="ui-input h-10" />
                    </div>
                    <div>
                        <label class="block text-xs text-[#6b7280] mb-1.5">إلى تاريخ</label>
                        <input type="date" x-model="to" class="ui-input h-10" />
                    </div>
                    <a x-bind:href="'{{ route('admin.reports.index') }}' + (from ? ('?from=' + from + '&to=' + to) : '')" class="ui-btn ui-btn-primary w-full">عرض التقرير للفترة</a>
                </div>
            </div>
        </div>
    </div>

    {{-- ===== بطاقات المؤشرات (تحديث لحظي + قابلة للتخصيص) ===== --}}
    <div x-data="dashboardGrid('admin')" class="mb-6" data-animate style="animation-delay:.04s">
        <div class="flex flex-wrap items-center justify-end gap-2 mb-3">
            <div x-show="editing" x-cloak class="flex items-center gap-1.5 flex-wrap ml-auto mr-0">
                <span class="text-xs text-[#9ca3af]">المخفية:</span>
                <template x-for="label in hidden" :key="label">
                    <button type="button" @click="show(label)" class="inline-flex items-center gap-1 text-xs bg-[#f2f2f0] hover:bg-[#e8e8e6] rounded-lg px-2 py-1 text-[#6b7280]">
                        <i data-lucide="plus" class="w-3 h-3"></i><span x-text="label"></span>
                    </button>
                </template>
                <span x-show="!hidden.length" class="text-xs text-[#c4c4c4]">لا شيء</span>
            </div>
            <button type="button" @click="toggleEdit()" :class="editing ? 'ui-btn-primary' : 'ui-btn-secondary'" class="ui-btn ui-btn-sm">
                <i :data-lucide="editing ? 'check' : 'sliders-horizontal'" class="w-4 h-4"></i>
                <span x-text="editing ? 'تم' : 'تخصيص'"></span>
            </button>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4"
             x-ref="grid" x-init="liveStats($refs.grid, '{{ route('admin.dashboard.stats') }}')">
            @php
                $statTone = ['primary' => '#111', 'secondary' => '#111', 'success' => '#16a34a', 'warning' => '#f59e0b', 'danger' => '#dc2626', 'info' => '#111'];
            @endphp
            @foreach (\App\Support\Demo::adminStats() as $s)
                <div class="ui-card ui-card-hover p-5">
                    <div class="flex items-start justify-between">
                        <p class="text-[13px] text-[#6b7280] truncate">{{ $s['label'] }}</p>
                        <span class="shrink-0 w-8 h-8 rounded-[10px] bg-[#f2f2f0] flex items-center justify-center text-[#111]">
                            <x-icon :name="$s['icon']" class="w-[18px] h-[18px]" />
                        </span>
                    </div>
                    <p class="mt-3 text-[26px] font-semibold text-[#111] tracking-tight truncate transition-colors" data-stat-value="{{ $s['label'] }}">{{ $s['value'] }}</p>
                    @if ($s['trend'])
                        <div class="mt-2 flex items-center gap-1.5 text-xs">
                            <span class="inline-flex items-center gap-1 font-medium" style="color: {{ $s['up'] ? '#16a34a' : '#dc2626' }};">
                                <x-icon :name="$s['up'] ? 'trending-up' : 'trending-down'" class="w-3.5 h-3.5" />{{ $s['trend'] }}
                            </span>
                            <span class="text-[#9ca3af]">مقارنة بالفترة السابقة</span>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    {{-- ===== الإجراءات السريعة ===== --}}
    @php
        $quick = [
            ['label' => 'منتج جديد', 'icon' => 'package-plus', 'url' => route('admin.products.create')],
            ['label' => 'فتح نقطة البيع', 'icon' => 'store', 'url' => route('pos.index')],
            ['label' => 'أمر شراء', 'icon' => 'clipboard-list', 'url' => route('admin.purchases.create')],
            ['label' => 'العملاء', 'icon' => 'users', 'url' => route('admin.customers.index')],
            ['label' => 'التقارير', 'icon' => 'bar-chart-3', 'url' => route('admin.reports.index')],
        ];
    @endphp
    <div class="flex flex-wrap gap-2.5 mb-8" data-animate style="animation-delay:.08s">
        @foreach ($quick as $q)
            <a href="{{ $q['url'] }}" class="ui-card ui-card-hover flex items-center gap-2.5 px-4 h-11 text-sm font-medium text-[#111]">
                <x-icon :name="$q['icon']" class="w-[18px] h-[18px] text-[#6b7280]" />
                {{ $q['label'] }}
            </a>
        @endforeach
    </div>

    {{-- ===== الهدف الشهري + التنبيهات الذكية ===== --}}
    @php $kpi = \App\Support\Demo::kpi(); $alerts = \App\Support\Demo::smartAlerts(); @endphp
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-5 mb-8">
        {{-- الهدف --}}
        <div class="ui-card p-6" x-data="{ editing: {{ $kpi['target'] > 0 ? 'false' : 'true' }} }" data-animate style="animation-delay:.10s">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-semibold text-[#111] flex items-center gap-2"><x-icon name="target" class="w-[18px] h-[18px] text-[#6b7280]" /> الهدف الشهري</h3>
                <button type="button" @click="editing = !editing" class="text-xs text-[#111] hover:text-black font-medium">
                    <span x-text="editing ? 'إلغاء' : 'تعديل'"></span>
                </button>
            </div>

            <form x-show="editing" x-cloak method="POST" action="{{ route('admin.goals.update') }}" class="flex items-end gap-2 mb-3">
                @csrf
                <div class="flex-1">
                    <label class="block text-xs text-[#6b7280] mb-1.5">قيمة الهدف (ر.ع)</label>
                    <input type="number" step="0.001" name="monthly_target" value="{{ $kpi['target'] }}" placeholder="0.000" class="ui-input h-10" />
                </div>
                <button type="submit" class="ui-btn ui-btn-primary"><x-icon name="check" class="w-4 h-4" /> حفظ</button>
            </form>

            <div x-show="!editing">
                @if ($kpi['target'] > 0)
                    <div class="flex items-end justify-between mb-3">
                        <div>
                            <p class="text-[28px] font-semibold text-[#111] tracking-tight">{{ \App\Support\Demo::money($kpi['achieved']) }}</p>
                            <p class="text-xs text-[#9ca3af] mt-0.5">من هدف {{ \App\Support\Demo::money($kpi['target']) }}</p>
                        </div>
                        <span class="text-lg font-semibold" style="color: {{ $kpi['pct'] >= 100 ? '#16a34a' : '#111' }};">{{ $kpi['pct'] }}%</span>
                    </div>
                    <div class="h-2 rounded-full bg-[#f0f0ee] overflow-hidden">
                        <div class="h-full rounded-full" style="width: {{ $kpi['pct'] }}%; background: {{ $kpi['pct'] >= 100 ? '#16a34a' : '#111' }};"></div>
                    </div>
                    <div class="mt-3.5 flex items-center justify-between text-xs">
                        <span class="text-[#6b7280]">متبقٍ: {{ \App\Support\Demo::money($kpi['remaining']) }}</span>
                        <span class="inline-flex items-center gap-1" style="color: {{ $kpi['on_track'] ? '#16a34a' : '#f59e0b' }};">
                            <x-icon :name="$kpi['on_track'] ? 'trending-up' : 'trending-down'" class="w-3.5 h-3.5" />
                            متوقّع: {{ \App\Support\Demo::money($kpi['projected']) }}
                        </span>
                    </div>
                    <p class="text-[11px] text-[#9ca3af] mt-1.5">{{ $kpi['days_left'] }} يومًا متبقية في الشهر</p>
                @else
                    <x-empty-state icon="target" title="لم يُحدَّد هدف" message="حدّد هدف مبيعات شهري لمتابعة إنجازه." />
                @endif
            </div>
        </div>

        {{-- التنبيهات الذكية --}}
        <div class="xl:col-span-2 ui-card p-6" data-animate style="animation-delay:.12s">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-semibold text-[#111] flex items-center gap-2"><x-icon name="sparkles" class="w-[18px] h-[18px] text-[#6b7280]" /> تنبيهات ذكية</h3>
                @if (count($alerts))<span class="ui-chip">{{ count($alerts) }}</span>@endif
            </div>
            @php $alertColors = ['danger' => 'bg-[#fef2f2] text-[#dc2626]', 'warning' => 'bg-[#fffbeb] text-[#d97706]', 'info' => 'bg-[#f2f2f0] text-[#111]']; @endphp
            @if (count($alerts))
                <ul class="space-y-1 max-h-64 overflow-y-auto -mx-2">
                    @foreach ($alerts as $a)
                        <a href="{{ $a['url'] }}" class="flex items-start gap-3 p-2.5 rounded-xl hover:bg-[#fafafa] transition">
                            <span class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0 {{ $alertColors[$a['color']] ?? $alertColors['info'] }}">
                                <x-icon :name="$a['icon']" class="w-4 h-4" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold text-[#9ca3af]">{{ $a['type'] }}</p>
                                <p class="text-sm text-[#111]">{{ $a['text'] }}</p>
                            </div>
                        </a>
                    @endforeach
                </ul>
            @else
                <x-empty-state icon="check-circle" title="كل شيء على ما يُرام" message="لا توجد تنبيهات ذكية حاليًا." />
            @endif
        </div>
    </div>

    {{-- ===== المبيعات + وسائل الدفع ===== --}}
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-5 mb-8">
        <div class="xl:col-span-2 ui-card p-6" data-animate style="animation-delay:.14s">
            <div class="flex items-center justify-between mb-5">
                <h3 class="font-semibold text-[#111]">حركة المبيعات</h3>
                <span class="ui-chip">آخر 12 شهرًا</span>
            </div>
            @php $salesSeries = \App\Support\Demo::salesSeries(); @endphp
            <div x-data='apexChart({
                chart: { type: "area", height: 320, fontFamily: "inherit", toolbar: { show: false } },
                series: [{ name: "المبيعات", data: @json($salesSeries['data']) }],
                xaxis: { categories: @json($salesSeries['labels']), axisBorder: { show: false }, axisTicks: { show: false }, labels: { style: { colors: "#9ca3af" } } },
                yaxis: { labels: { style: { colors: "#9ca3af" } } },
                colors: ["#111111"],
                dataLabels: { enabled: false },
                stroke: { curve: "smooth", width: 2 },
                fill: { type: "gradient", gradient: { shadeIntensity: 1, opacityFrom: 0.18, opacityTo: 0.02 } },
                grid: { borderColor: "#f0f0ee", strokeDashArray: 4 },
                tooltip: { theme: "light" }
            })'></div>
        </div>
        <div class="ui-card p-6" data-animate style="animation-delay:.16s">
            <div class="flex items-center justify-between mb-5">
                <h3 class="font-semibold text-[#111]">وسائل الدفع</h3>
                <span class="ui-chip">هذا الشهر</span>
            </div>
            @php $payDist = \App\Support\Demo::paymentDistribution(); @endphp
            <div x-data='apexChart({
                chart: { type: "donut", height: 300, fontFamily: "inherit" },
                series: @json($payDist['series']),
                labels: @json($payDist['labels']),
                colors: ["#111111","#8a8a8a","#bdbdbd","#e0e0e0"],
                dataLabels: { enabled: false },
                stroke: { width: 0 },
                legend: { position: "bottom", fontFamily: "inherit", labels: { colors: "#6b7280" }, markers: { radius: 4 } },
                plotOptions: { pie: { donut: { size: "72%" } } }
            })'></div>
        </div>
    </div>

    {{-- ===== آخر الطلبات + أفضل المنتجات ===== --}}
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-8">
        {{-- آخر الطلبات --}}
        <div class="xl:col-span-2" data-animate style="animation-delay:.18s">
            <div class="flex items-center justify-between mb-3.5">
                <h3 class="font-semibold text-[#111]">آخر الطلبات</h3>
                <a href="{{ route('admin.orders.index') }}" class="text-sm text-[#111] hover:text-black font-medium inline-flex items-center gap-1">عرض الكل <x-icon name="chevron-left" class="w-4 h-4" /></a>
            </div>
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead>
                        <tr>
                            <th>رقم الطلب</th><th>العميل</th><th>الإجمالي</th><th>الحالة</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse (array_slice(\App\Support\Demo::orders(), 0, 6) as $o)
                            <tr>
                                <td class="font-medium whitespace-nowrap">{{ $o['id'] }}</td>
                                <td class="text-[#6b7280] whitespace-nowrap">{{ $o['customer'] }}</td>
                                <td class="font-medium whitespace-nowrap">{{ \App\Support\Demo::money($o['total']) }}</td>
                                <td><x-badge :text="$o['status']" /></td>
                                <td>
                                    <a href="{{ route('admin.orders.show', $o['id']) }}" class="ui-btn ui-btn-ghost ui-btn-sm"><x-icon name="eye" class="w-4 h-4" /> عرض</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-[#9ca3af] py-10">لا توجد طلبات بعد.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- أفضل المنتجات --}}
        <div data-animate style="animation-delay:.20s">
            <div class="flex items-center justify-between mb-3.5">
                <h3 class="font-semibold text-[#111]">أفضل المنتجات</h3>
                <a href="{{ route('admin.products.index') }}" class="text-sm text-[#111] hover:text-black font-medium">عرض الكل</a>
            </div>
            <div class="ui-card p-5">
                <ul class="space-y-4">
                    @php $topProducts = array_slice(\App\Support\Demo::products(), 0, 5); $maxSold = 340; @endphp
                    @foreach ($topProducts as $i => $p)
                        @php $sold = 340 - ($i * 55); $pct = round(($sold / $maxSold) * 100); @endphp
                        <li>
                            <div class="flex items-center gap-3">
                                <img src="{{ $p['image'] }}" alt="{{ $p['name'] }}" class="w-10 h-10 rounded-lg object-cover" style="border:1px solid var(--ui-border);" />
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="text-sm font-medium text-[#111] truncate">{{ $p['name'] }}</p>
                                        <span class="text-xs text-[#9ca3af] whitespace-nowrap">{{ $sold }} مبيعًا</span>
                                    </div>
                                    <div class="mt-1.5 h-1.5 w-full rounded-full bg-[#f0f0ee] overflow-hidden">
                                        <div class="h-full rounded-full bg-[#111]" style="width: {{ $pct }}%"></div>
                                    </div>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>

    {{-- ===== منخفض المخزون + الموظفون الأكثر مبيعًا ===== --}}
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
        <div data-animate style="animation-delay:.22s">
            <div class="flex items-center justify-between mb-3.5">
                <h3 class="font-semibold text-[#111]">منتجات منخفضة المخزون</h3>
                <a href="{{ route('admin.inventory.index') }}" class="text-sm text-[#111] hover:text-black font-medium">إدارة المخزون</a>
            </div>
            <div class="ui-card p-5">
                @php $lowStock = array_filter(\App\Support\Demo::products(), fn ($p) => $p['qty'] < 10); @endphp
                @if (count($lowStock))
                    <ul class="divide-y" style="--tw-divide-opacity:1;">
                        @foreach ($lowStock as $p)
                            <li class="flex items-center gap-3 py-3 first:pt-0 last:pb-0" style="border-color:#f2f2f0;">
                                <img src="{{ $p['image'] }}" alt="{{ $p['name'] }}" class="w-10 h-10 rounded-lg object-cover" style="border:1px solid var(--ui-border);" />
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-[#111] truncate">{{ $p['name'] }}</p>
                                    <p class="text-xs text-[#9ca3af]">{{ $p['sku'] }}</p>
                                </div>
                                <div class="text-left">
                                    <p class="text-sm font-semibold" style="color: {{ $p['qty'] == 0 ? '#dc2626' : '#f59e0b' }};">{{ $p['qty'] }} قطعة</p>
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

        <div data-animate style="animation-delay:.24s">
            <div class="flex items-center justify-between mb-3.5">
                <h3 class="font-semibold text-[#111]">الموظفون الأكثر مبيعًا</h3>
                <a href="{{ route('admin.employees.index') }}" class="text-sm text-[#111] hover:text-black font-medium">عرض الكل</a>
            </div>
            <div class="ui-card p-5">
                @php
                    $employees = \App\Support\Demo::employees();
                    usort($employees, fn ($a, $b) => $b['sales'] <=> $a['sales']);
                    $topEmployees = array_slice($employees, 0, 5);
                @endphp
                <ul class="space-y-4">
                    @foreach ($topEmployees as $i => $e)
                        <li class="flex items-center gap-3">
                            <span class="relative shrink-0">
                                <img src="{{ $e['avatar'] }}" alt="{{ $e['name'] }}" class="w-10 h-10 rounded-full object-cover" style="border:1px solid var(--ui-border);" />
                                <span class="absolute -top-1 -right-1 w-5 h-5 rounded-full bg-[#111] text-white text-[10px] font-bold flex items-center justify-center">{{ $i + 1 }}</span>
                            </span>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-[#111] truncate">{{ $e['name'] }}</p>
                                <p class="text-xs text-[#9ca3af]">{{ $e['role'] }}</p>
                            </div>
                            <span class="text-sm font-semibold text-[#111] whitespace-nowrap">{{ \App\Support\Demo::money($e['sales']) }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>

</x-layouts::admin>
