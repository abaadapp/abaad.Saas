<x-layouts::pos :title="__('المدفوعات')">
    @php
        $receipts = \App\Support\Demo::receipts();
        $cash = $card = $transfer = $credit = 0;
        foreach ($receipts as $r) {
            if ($r['payment'] === 'نقدي') $cash += $r['total'];
            elseif ($r['payment'] === 'بطاقة') $card += $r['total'];
            else $transfer += $r['total'];
        }
        $credit = 34.750;
        $payColors = ['نقدي' => 'success', 'بطاقة' => 'info', 'تحويل بنكي' => 'primary', 'آجل' => 'warning'];
        $payIcons = ['نقدي' => 'banknote', 'بطاقة' => 'credit-card', 'تحويل بنكي' => 'landmark', 'آجل' => 'clock'];
        $cards = [
            ['label' => __('مبيعات نقدية'), 'value' => $cash, 'icon' => 'banknote', 'color' => 'success'],
            ['label' => __('مبيعات البطاقة'), 'value' => $card, 'icon' => 'credit-card', 'color' => 'info'],
            ['label' => __('تحويلات بنكية'), 'value' => $transfer, 'icon' => 'landmark', 'color' => 'primary'],
            ['label' => __('مبيعات آجلة'), 'value' => $credit, 'icon' => 'clock', 'color' => 'warning'],
        ];
    @endphp

    <div class="h-full overflow-y-auto p-4 sm:p-6">
        {{-- عنوان --}}
        <div class="flex items-center gap-3 mb-6">
            <span class="w-11 h-11 rounded-xl bg-gray-100 text-gray-900 flex items-center justify-center">
                <x-icon name="wallet" class="w-6 h-6" />
            </span>
            <div>
                <h1 class="text-xl font-bold text-gray-800">{{ __('سجل المدفوعات') }}</h1>
                <p class="text-sm text-gray-400">{{ __('عمليات الدفع لليوم') }} — 2026-07-17</p>
            </div>
        </div>

        {{-- بطاقات الملخص --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            @foreach ($cards as $c)
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
                    <div class="flex items-center justify-between mb-3">
                        <span class="w-10 h-10 rounded-xl bg-{{ $c['color'] }}-50 text-{{ $c['color'] }}-600 flex items-center justify-center">
                            <x-icon name="{{ $c['icon'] }}" class="w-5 h-5" />
                        </span>
                        <x-badge type="{{ $c['color'] }}">{{ __('اليوم') }}</x-badge>
                    </div>
                    <p class="text-sm text-gray-400">{{ $c['label'] }}</p>
                    <p class="text-lg font-extrabold text-gray-800 mt-0.5">{{ \App\Support\Demo::money($c['value']) }}</p>
                </div>
            @endforeach
        </div>

        {{-- جدول العمليات --}}
        <x-table :headers="[__('رقم الفاتورة'), __('العميل'), __('المبلغ'), __('وسيلة الدفع'), __('الوقت'), __('الموظف')]">
            @foreach ($receipts as $r)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-semibold text-gray-900">{{ $r['number'] }}</td>
                    <td class="px-4 py-3 text-gray-700">{{ $r['customer'] }}</td>
                    <td class="px-4 py-3 font-semibold text-gray-800">{{ \App\Support\Demo::money($r['total']) }}</td>
                    <td class="px-4 py-3">
                        <x-badge type="{{ $payColors[$r['payment']] ?? 'gray' }}">
                            <x-icon name="{{ $payIcons[$r['payment']] ?? 'circle' }}" class="w-3.5 h-3.5" />
                            {{ __($r['payment']) }}
                        </x-badge>
                    </td>
                    <td class="px-4 py-3 text-gray-500 whitespace-nowrap">{{ $r['time'] }}</td>
                    <td class="px-4 py-3 text-gray-600">{{ $r['employee'] }}</td>
                </tr>
            @endforeach
            <x-slot:footer>
                <x-pagination :total="10" :perPage="10" :current="1" />
            </x-slot:footer>
        </x-table>
    </div>
</x-layouts::pos>
