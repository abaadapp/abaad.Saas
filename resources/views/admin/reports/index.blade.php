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

    {{-- أقسام التقارير: عنوان القسم + عدّاد + بطاقات --}}
    @php
        $reportSections = [
            [
                'title' => 'التقارير المالية',
                'accent' => '#e2574c',
                'tint' => '#fdecea',
                'items' => [
                    ['title' => 'المبيعات والإيرادات', 'desc' => 'المبيعات والإيرادات والمدفوعات والتحصيل في مكان واحد.', 'icon' => 'trending-up', 'url' => route('admin.reports.pdf')],
                    ['title' => 'تقرير إغلاق الصندوق', 'desc' => 'تقرير شامل لنشاطات النقد والمبيعات.', 'icon' => 'wallet', 'url' => route('admin.reports.pdf')],
                    ['title' => 'تقرير المصروفات', 'desc' => 'راجِع وتتبّع المصروفات بسهولة.', 'icon' => 'arrow-down-circle', 'url' => route('admin.expenses.index')],
                    ['title' => 'تقرير طلبات المنتجات', 'desc' => 'تحليل مبيعات طلبات المنتجات.', 'icon' => 'boxes', 'url' => route('admin.reports.pdf')],
                    ['title' => 'تقارير العمولات', 'desc' => 'تحليل بيانات العمولات للمنتجات والخدمات.', 'icon' => 'percent', 'url' => route('admin.reports.pdf')],
                    ['title' => 'الكوبونات والعروض', 'desc' => 'تتبّع استخدام الكوبونات وقيمة الخصومات وسلوك العملاء.', 'icon' => 'tag', 'url' => route('admin.marketing.index')],
                    ['title' => 'الربحية', 'desc' => 'أرباح المنتجات والتصنيفات وهوامش الربح.', 'icon' => 'piggy-bank', 'url' => route('admin.profitability.index')],
                    ['title' => 'ضريبة القيمة المضافة', 'desc' => 'تقرير الضريبة والفواتير الضريبية.', 'icon' => 'landmark', 'url' => route('admin.vat.index')],
                ],
            ],
            [
                'title' => 'التقارير التشغيلية',
                'accent' => '#16a34a',
                'tint' => '#e8f6ee',
                'items' => [
                    ['title' => 'تقرير المخزون', 'desc' => 'أرصدة الأصناف والتنبيهات ونواقص المخزون.', 'icon' => 'package', 'url' => route('admin.inventory.index')],
                    ['title' => 'تقرير المنتجات', 'desc' => 'حالة المنتجات وأسعارها وتكاليفها.', 'icon' => 'shopping-bag', 'url' => route('admin.products.index')],
                    ['title' => 'تقرير الموظفين', 'desc' => 'أداء فريق العمل ومبيعات كل موظف.', 'icon' => 'users', 'url' => route('admin.employees.index')],
                ],
            ],
            [
                'title' => 'تقارير التحليلات',
                'accent' => '#3b82f6',
                'tint' => '#e8f1fe',
                'items' => [
                    ['title' => 'تحليلات متقدمة', 'desc' => 'الاتجاهات وأوقات الذروة وأفضل المنتجات والعملاء.', 'icon' => 'chart-line', 'url' => route('admin.analytics.index')],
                    ['title' => 'تقرير العملاء', 'desc' => 'أفضل العملاء إنفاقًا وتكرار الشراء.', 'icon' => 'user-round', 'url' => route('admin.customers.index')],
                    ['title' => 'وسائل الدفع', 'desc' => 'توزيع المبيعات حسب وسيلة الدفع.', 'icon' => 'credit-card', 'url' => route('admin.reports.pdf')],
                ],
            ],
        ];
    @endphp

    @foreach ($reportSections as $section)
        <div class="mb-10">
            {{-- عنوان القسم + العدّاد --}}
            <div class="flex items-center justify-between mb-4">
                <h2 class="flex items-center gap-2.5 text-base font-bold text-gray-800">
                    <span class="w-2.5 h-2.5 rounded-[3px]" style="background: {{ $section['accent'] }}"></span>
                    {{ $section['title'] }}
                </h2>
                <span class="inline-flex items-center justify-center min-w-8 h-8 px-2.5 rounded-full bg-white border border-gray-200 text-sm font-semibold text-gray-500">
                    {{ count($section['items']) }}
                </span>
            </div>

            {{-- البطاقات --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                @foreach ($section['items'] as $item)
                    <div class="group bg-white rounded-2xl border border-gray-200 p-6 flex flex-col hover:shadow-md hover:border-gray-300 transition">
                        <div class="flex justify-end">
                            <span class="w-11 h-11 rounded-xl flex items-center justify-center"
                                  style="background: {{ $section['tint'] }}; color: {{ $section['accent'] }}">
                                <x-icon :name="$item['icon']" class="w-[22px] h-[22px]" />
                            </span>
                        </div>
                        <h3 class="mt-5 text-[17px] font-bold text-gray-800">{{ $item['title'] }}</h3>
                        <p class="mt-2 text-sm text-gray-400 leading-relaxed">{{ $item['desc'] }}</p>
                        <a href="{{ $item['url'] }}"
                           class="mt-6 inline-flex items-center gap-1.5 self-start text-sm font-medium transition-opacity hover:opacity-70"
                           style="color: {{ $section['accent'] }}">
                            فتح
                            <x-icon name="arrow-left" class="w-4 h-4" />
                        </a>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach

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
