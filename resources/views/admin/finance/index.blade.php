<x-layouts::admin :title="__('المالية')">
    @php
        $range = in_array(request('range'), ['today', 'week', 'month', 'year']) ? request('range') : 'month';
        $rangeLabel = ['today' => __('اليوم'), 'week' => __('هذا الأسبوع'), 'month' => __('هذا الشهر'), 'year' => __('هذه السنة')][$range];
        $stats = \App\Support\Demo::financeStats($range);
        $methods = \App\Support\Demo::paymentMethods($range);
        $transactions = \App\Support\Demo::transactions($range);
        $totalIn = 0; $totalOut = 0;
        foreach ($transactions as $t) {
            if ($t['amount'] >= 0) { $totalIn += $t['amount']; } else { $totalOut += abs($t['amount']); }
        }
    @endphp

    <x-page-header :title="__('المالية')" :subtitle="__('نظرة عامة على الإيرادات ووسائل الدفع والمعاملات')"
                   :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('المالية') => '#']">
        <x-slot:actions>
            <x-select name="finance-range" onchange="location.href='{{ route('admin.finance.index') }}?range='+this.value"
                :options="['today' => __('اليوم'), 'week' => __('هذا الأسبوع'), 'month' => __('هذا الشهر'), 'year' => __('هذه السنة')]" :selected="$range" />
            <x-button variant="outline" icon="landmark" :href="route('admin.finance.statement')">{{ __('كشف الحساب البنكي') }}</x-button>
            <x-dropdown align="left" width="w-56">
                <x-slot:trigger>
                    <span class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium rounded-full bg-primary-600 hover:bg-primary-700 text-white cursor-pointer">
                        <x-icon name="download" class="w-4 h-4" /> {{ __('تصدير') }}
                    </span>
                </x-slot:trigger>
                <a href="{{ route('admin.finance.xlsx') }}" class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50">
                    <x-icon name="file-spreadsheet" class="w-4 h-4 text-gray-400" /> {{ __('تصدير كملف إكسل') }}
                </a>
                <a href="{{ route('admin.finance.pdf') }}" target="_blank" class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50">
                    <x-icon name="file-text" class="w-4 h-4 text-gray-400" /> {{ __('تصدير كملف PDF') }}
                </a>
                <a href="{{ route('admin.export.transactions') }}" class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50">
                    <x-icon name="file-down" class="w-4 h-4 text-gray-400" /> {{ __('تصدير كملف CSV') }}
                </a>
            </x-dropdown>
            <x-button variant="primary" icon="plus" @click="$dispatch('open-modal','add-transaction')">{{ __('تسجيل معاملة') }}</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- بطاقات الملخص --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        @foreach ($stats as $s)
            <x-stat-card :label="$s['label']" :value="$s['value']" :icon="$s['icon']" :trend="$s['trend']" :up="$s['up']" :color="$s['color']" />
        @endforeach
    </div>

    {{-- توزيع وسائل الدفع + الرسم البياني --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        {{-- الرسم الدائري --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <h3 class="font-bold text-gray-800 mb-1">{{ __('توزيع وسائل الدفع') }}</h3>
            <p class="text-xs text-gray-400 mb-4">{{ __('نسبة كل وسيلة من إجمالي المدفوعات') }}</p>
            <div x-data='apexChart({
                chart: { type: "donut", height: 260, fontFamily: "IBM Plex Sans Arabic" },
                series: @json(collect($methods)->pluck('total')),
                labels: @json(collect($methods)->pluck('name')),
                colors: ["#10b981", "#3b82f6", "#7c3aed"],
                legend: { position: "bottom", fontFamily: "IBM Plex Sans Arabic" },
                dataLabels: { enabled: true, formatter: (v) => Math.round(v) + "%" },
                stroke: { width: 0 },
                plotOptions: { pie: { donut: { size: "62%" } } }
            })'></div>
        </div>

        {{-- ملخص كل وسيلة دفع --}}
        <div class="lg:col-span-2 bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-bold text-gray-800">{{ __('ملخص وسائل الدفع') }}</h3>
                <x-button variant="light" size="sm" icon="download" :href="route('admin.finance.xlsx')">{{ __('تصدير') }}</x-button>
            </div>
            <div class="space-y-4">
                @foreach ($methods as $m)
                    @php
                        $barColor = match ($m['color']) {
                            'success' => 'bg-success-500',
                            'info' => 'bg-info-500',
                            'secondary' => 'bg-secondary-500',
                            default => 'bg-primary-500',
                        };
                        $iconBg = match ($m['color']) {
                            'success' => 'bg-success-50 text-success-600',
                            'info' => 'bg-info-50 text-info-600',
                            'secondary' => 'bg-secondary-50 text-secondary-600',
                            default => 'bg-primary-50 text-primary-600',
                        };
                    @endphp
                    <div class="flex items-center gap-4">
                        <span class="w-11 h-11 rounded-xl flex items-center justify-center shrink-0 {{ $iconBg }}">
                            <x-icon :name="$m['icon']" class="w-5 h-5" />
                        </span>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between mb-1.5">
                                <p class="font-semibold text-gray-800 text-sm">{{ $m['name'] }}</p>
                                <p class="font-bold text-gray-800 text-sm">{{ \App\Support\Demo::money($m['total']) }}</p>
                            </div>
                            <div class="h-2 rounded-full bg-gray-100 overflow-hidden">
                                <div class="h-full rounded-full {{ $barColor }}" style="width: {{ $m['percent'] }}%"></div>
                            </div>
                            <div class="flex items-center justify-between mt-1.5">
                                <p class="text-xs text-gray-400">{{ __(':count عملية', ['count' => $m['count']]) }}</p>
                                <p class="text-xs text-gray-400">{{ $m['percent'] }}%</p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- ملخص الدخل مقابل المصروف --}}
            <div class="grid grid-cols-2 gap-3 mt-5 pt-5 border-t border-gray-100">
                <div class="rounded-xl bg-success-50 p-3">
                    <p class="text-xs text-success-700 flex items-center gap-1"><x-icon name="arrow-up-circle" class="w-4 h-4" /> {{ __('إجمالي الدخل') }}</p>
                    <p class="font-bold text-success-700 mt-1">{{ \App\Support\Demo::money($totalIn) }}</p>
                </div>
                <div class="rounded-xl bg-danger-50 p-3">
                    <p class="text-xs text-danger-600 flex items-center gap-1"><x-icon name="arrow-down-circle" class="w-4 h-4" /> {{ __('إجمالي المصروف') }}</p>
                    <p class="font-bold text-danger-600 mt-1">{{ \App\Support\Demo::money($totalOut) }}</p>
                </div>
            </div>
        </div>
    </div>

    {{-- بطاقات وسائل الدفع الثلاث --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        @foreach ($methods as $m)
            @php
                $iconBg = match ($m['color']) {
                    'success' => 'bg-success-50 text-success-600',
                    'info' => 'bg-info-50 text-info-600',
                    'secondary' => 'bg-secondary-50 text-secondary-600',
                    default => 'bg-primary-50 text-primary-600',
                };
            @endphp
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 hover:shadow-md transition-shadow">
                <div class="flex items-center justify-between">
                    <span class="w-12 h-12 rounded-2xl flex items-center justify-center {{ $iconBg }}">
                        <x-icon :name="$m['icon']" class="w-6 h-6" />
                    </span>
                    <x-badge type="success" :text="__('مفعّلة')" dot />
                </div>
                <h3 class="mt-4 font-bold text-gray-800">{{ $m['name'] }}</h3>
                <p class="text-2xl font-extrabold text-gray-800 mt-1">{{ \App\Support\Demo::money($m['total']) }}</p>
                <p class="text-xs text-gray-400 mt-1">{{ __(':count عملية', ['count' => $m['count']]) }} · {{ $rangeLabel }}</p>
            </div>
        @endforeach
    </div>

    {{-- جدول المعاملات --}}
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm mb-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-4 border-b border-gray-100">
            <h3 class="font-bold text-gray-800">{{ __('المعاملات المالية') }}</h3>
            <div class="flex items-center gap-2 flex-wrap">
                <div class="w-full sm:w-56">
                    <x-input name="trx-search" :placeholder="__('بحث في المعاملات...')" icon="search" />
                </div>
                <x-select name="trx-method" :options="['' => __('كل الوسائل'), 'نقدي' => __('نقدي'), 'تحويل بنكي' => __('تحويل بنكي'), 'بطاقة' => __('بطاقة')]" :placeholder="__('كل الوسائل')" />
                <x-select name="trx-type" :options="['' => __('كل الأنواع'), 'دخل' => __('دخل'), 'مصروف' => __('مصروف')]" :placeholder="__('كل الأنواع')" />
            </div>
        </div>

        <x-table :headers="[__('رقم العملية'), __('التاريخ'), __('الوصف'), __('وسيلة الدفع'), __('النوع'), __('الموظف'), __('المبلغ')]">
            @foreach ($transactions as $t)
                @php $method = ['نقدي' => 'banknote', 'تحويل بنكي' => 'landmark', 'بطاقة' => 'credit-card'][$t['method']] ?? 'wallet'; @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-medium text-gray-700 whitespace-nowrap">{{ $t['id'] }}</td>
                    <td class="px-4 py-3 text-gray-500 whitespace-nowrap">{{ $t['date'] }}</td>
                    <td class="px-4 py-3 text-gray-700">{{ $t['description'] }}</td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        <span class="inline-flex items-center gap-1.5 text-sm text-gray-600">
                            <x-icon :name="$method" class="w-4 h-4 text-gray-400" />
                            {{ __($t['method']) }}
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        <x-badge :type="$t['type'] === 'دخل' ? 'success' : 'danger'" :text="__($t['type'])" />
                    </td>
                    <td class="px-4 py-3 text-gray-500 whitespace-nowrap">{{ $t['employee'] }}</td>
                    <td class="px-4 py-3 font-bold whitespace-nowrap {{ $t['amount'] >= 0 ? 'text-success-600' : 'text-danger-600' }}">
                        {{ $t['amount'] >= 0 ? '+ ' : '- ' }}{{ \App\Support\Demo::money(abs($t['amount'])) }}
                    </td>
                </tr>
            @endforeach
        </x-table>

        <div class="p-4 border-t border-gray-100">
            <x-pagination :total="86" :perPage="14" :current="1" />
        </div>
    </div>

    {{-- نافذة تسجيل معاملة --}}
    <x-modal name="add-transaction" :title="__('تسجيل معاملة مالية')" maxWidth="max-w-lg">
        <form id="add-transaction-form" method="POST" action="{{ route('admin.finance.store') }}" class="space-y-4">
            @csrf
            <div class="grid grid-cols-2 gap-3">
                <x-select :label="__('نوع المعاملة')" name="type" :options="['دخل' => __('دخل'), 'مصروف' => __('مصروف')]" />
                <x-input :label="__('المبلغ')" name="amount" type="number" placeholder="0.000" icon="wallet" :required="true" />
            </div>
            <x-input :label="__('الوصف')" name="description" :placeholder="__('وصف المعاملة أو المصدر')" icon="file-text" :required="true" />
            <div class="grid grid-cols-2 gap-3">
                <x-select :label="__('وسيلة الدفع')" name="method" :options="['نقدي' => __('نقدي'), 'تحويل بنكي' => __('تحويل بنكي'), 'بطاقة' => __('بطاقة (فيزا)')]" />
                <x-input :label="__('التاريخ')" name="occurred_at" type="date" value="2026-07-18" />
            </div>
            {{-- اختيار سريع لوسيلة الدفع --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">{{ __('اختيار سريع لوسيلة الدفع') }}</label>
                <div class="grid grid-cols-3 gap-2" x-data="{ m: 'نقدي' }">
                    @foreach (['نقدي' => 'banknote', 'تحويل بنكي' => 'landmark', 'بطاقة' => 'credit-card'] as $label => $mi)
                        <button type="button" @click="m = '{{ $label }}'"
                                :class="m === '{{ $label }}' ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-gray-200 text-gray-600 hover:bg-gray-50'"
                                class="flex flex-col items-center gap-1 border rounded-full py-3 text-xs font-medium transition-colors">
                            <x-icon name="{{ $mi }}" class="w-5 h-5" />
                            {{ __($label) }}
                        </button>
                    @endforeach
                </div>
            </div>
        </form>
        <x-slot:footer>
            <x-button variant="outline" @click="$dispatch('close-modal')">{{ __('إلغاء') }}</x-button>
            <x-button variant="primary" type="submit" form="add-transaction-form" icon="check">{{ __('حفظ المعاملة') }}</x-button>
        </x-slot:footer>
    </x-modal>
</x-layouts::admin>
