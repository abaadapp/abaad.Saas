<x-layouts::admin title="ضريبة القيمة المضافة">

    @php
        $period = request('period', 'quarter');
        $report = \App\Support\Demo::vatReport($period);
        $vat = \App\Support\Demo::vatSettings();
    @endphp

    <x-page-header title="ضريبة القيمة المضافة" subtitle="إقرار VAT — {{ $report['label'] }} ({{ $report['from'] }} — {{ $report['to'] }})"
        :breadcrumbs="['الرئيسية' => route('admin.dashboard'), 'ضريبة القيمة المضافة' => '#']">
        <x-slot:actions>
            <x-button variant="outline" icon="file-text" :href="route('admin.vat.pdf', ['period' => $period])" target="_blank">تصدير الإقرار PDF</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- شريط الفترة --}}
    <div class="flex items-center gap-2 mb-6">
        @foreach (['month' => 'هذا الشهر', 'quarter' => 'هذا الربع', 'year' => 'هذه السنة'] as $key => $lbl)
            <a href="{{ route('admin.vat.index', ['period' => $key]) }}"
               class="px-4 py-2 rounded-xl text-sm font-medium border {{ $period === $key ? 'bg-primary-600 text-white border-primary-600' : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50' }}">{{ $lbl }}</a>
        @endforeach
        @if (empty($vat['number']))
            <a href="{{ route('admin.settings.index') }}" class="mr-auto text-sm text-warning-600 hover:text-warning-700 flex items-center gap-1"><x-icon name="alert-triangle" class="w-4 h-4" /> أضِف الرقم الضريبي (TRN) من الإعدادات</a>
        @else
            <span class="mr-auto text-sm text-gray-400">الرقم الضريبي: <span class="font-mono text-gray-600">{{ $vat['number'] }}</span></span>
        @endif
    </div>

    {{-- البطاقات --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <x-stat-card label="المبيعات الخاضعة للضريبة" :value="\App\Support\Demo::money($report['taxable_sales'])" icon="receipt" color="info" />
        <x-stat-card label="ضريبة المخرجات (مبيعات)" :value="\App\Support\Demo::money($report['output_vat'])" icon="arrow-up-circle" color="success" />
        <x-stat-card label="ضريبة المدخلات (مشتريات)" :value="\App\Support\Demo::money($report['input_vat'])" icon="arrow-down-circle" color="warning" />
        <x-stat-card label="صافي الضريبة المستحقّة" :value="\App\Support\Demo::money($report['net_vat'])" icon="landmark" :color="$report['net_vat'] >= 0 ? 'primary' : 'success'" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- ملخّص الإقرار --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <h3 class="font-bold text-gray-800 mb-4">ملخّص الإقرار</h3>
            <ul class="space-y-3 text-sm">
                <li class="flex items-center justify-between"><span class="text-gray-500">نسبة الضريبة</span><span class="font-semibold text-gray-800">{{ rtrim(rtrim(number_format($report['rate'],2,'.',''),'0'),'.') }}%</span></li>
                <li class="flex items-center justify-between border-t border-gray-50 pt-3"><span class="text-gray-500">إجمالي المبيعات (شامل الضريبة)</span><span class="font-semibold text-gray-800">{{ \App\Support\Demo::money($report['gross_sales']) }}</span></li>
                <li class="flex items-center justify-between"><span class="text-gray-500">المبيعات الخاضعة</span><span class="text-gray-700">{{ \App\Support\Demo::money($report['taxable_sales']) }}</span></li>
                <li class="flex items-center justify-between text-success-600"><span>ضريبة المخرجات</span><span class="font-semibold">+ {{ \App\Support\Demo::money($report['output_vat']) }}</span></li>
                <li class="flex items-center justify-between text-warning-600"><span>ضريبة المدخلات</span><span class="font-semibold">- {{ \App\Support\Demo::money($report['input_vat']) }}</span></li>
                <li class="flex items-center justify-between border-t-2 border-primary-100 pt-3 mt-1">
                    <span class="font-bold text-gray-800">صافي المستحقّ للسداد</span>
                    <span class="font-bold text-primary-600 text-lg">{{ \App\Support\Demo::money($report['net_vat']) }}</span>
                </li>
            </ul>
            <p class="text-xs text-gray-400 mt-4">تُعامَل قيمة المشتريات المستلمة كأساس صافٍ لاحتساب ضريبة المدخلات. الأرقام للاسترشاد.</p>
        </div>

        {{-- التفصيل الشهري --}}
        <div class="lg:col-span-2 bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100"><h3 class="font-bold text-gray-800">التفصيل الشهري</h3></div>
            <x-table :headers="['الشهر', 'المبيعات الخاضعة', 'ضريبة المخرجات']">
                @forelse ($report['months'] as $m)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ $m['label'] }}</td>
                        <td class="px-4 py-3 text-gray-700 whitespace-nowrap">{{ \App\Support\Demo::money($m['taxable']) }}</td>
                        <td class="px-4 py-3 font-semibold text-success-600 whitespace-nowrap">{{ \App\Support\Demo::money($m['vat']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-8 text-center text-gray-400">لا توجد بيانات لهذه الفترة.</td></tr>
                @endforelse
            </x-table>
        </div>
    </div>

</x-layouts::admin>
