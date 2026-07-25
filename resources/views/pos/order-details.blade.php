<x-layouts::pos :title="__('تفاصيل الطلب')">
    @php
        $order = \App\Support\Demo::orderDetails(request()->route('id')); abort_if(empty($order), 404);
        abort_if(empty($order), 404);
    @endphp

    <div class="h-full overflow-y-auto p-4 sm:p-6">
        {{-- عنوان --}}
        <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
            <div class="flex items-center gap-3">
                <a href="{{ route('pos.receipts') }}" class="w-10 h-10 flex items-center justify-center rounded-xl bg-white border border-gray-100 text-gray-500 hover:bg-gray-50">
                    <x-icon name="arrow-right" class="w-5 h-5" />
                </a>
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-xl font-bold text-gray-800">{{ __('طلب :id', ['id' => $order['id']]) }}</h1>
                        <x-badge :text="__($order['status'])" />
                    </div>
                    <p class="text-sm text-gray-400">{{ $order['date'] }}</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <x-button variant="outline" icon="printer" :href="route('pos.receipt.pdf', $order['id'])" target="_blank">{{ __('إعادة طباعة') }}</x-button>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {{-- المنتجات + الملخص --}}
            <div class="lg:col-span-2 space-y-6">
                <x-table :headers="[__('المنتج'), __('الكمية'), __('السعر'), __('الإجمالي')]">
                    @foreach ($order['items'] as $l)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-semibold text-gray-800">{{ $l['name'] }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $l['qty'] }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ \App\Support\Demo::money($l['price']) }}</td>
                            <td class="px-4 py-3 font-semibold text-gray-800">{{ \App\Support\Demo::money($l['total']) }}</td>
                        </tr>
                    @endforeach
                </x-table>

                {{-- الملخص المالي --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 max-w-sm mr-auto ml-0 w-full">
                    <h3 class="font-bold text-gray-800 mb-3">{{ __('الملخص المالي') }}</h3>
                    <div class="space-y-2.5 text-sm">
                        <div class="flex justify-between text-gray-600"><span>{{ __('المجموع الفرعي') }}</span><span class="font-medium text-gray-800">{{ \App\Support\Demo::money($order['subtotal']) }}</span></div>
                        <div class="flex justify-between text-gray-600"><span>{{ __('الخصم') }}</span><span class="text-danger-500">- {{ \App\Support\Demo::money($order['discount']) }}</span></div>
                        <div class="flex justify-between text-gray-600"><span>{{ __('الضريبة') }}</span><span class="font-medium text-gray-800">{{ \App\Support\Demo::money($order['tax']) }}</span></div>
                        <div class="flex justify-between text-gray-600"><span>{{ __('رسوم التوصيل') }}</span><span class="font-medium text-gray-800">{{ \App\Support\Demo::money($order['delivery']) }}</span></div>
                        <div class="flex justify-between pt-2.5 border-t border-dashed border-gray-200">
                            <span class="font-bold text-gray-800">{{ __('الإجمالي') }}</span>
                            <span class="text-lg font-extrabold text-gray-900">{{ \App\Support\Demo::money($order['total']) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- بيانات جانبية --}}
            <div class="space-y-6">
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <h3 class="font-bold text-gray-800 mb-3">{{ __('بيانات العميل') }}</h3>
                    <div class="flex items-center gap-3 mb-3">
                        <span class="w-12 h-12 rounded-xl bg-gray-100 text-gray-700 flex items-center justify-center font-bold">{{ mb_substr($order['customer'], 0, 1) }}</span>
                        <div>
                            <p class="font-semibold text-gray-800">{{ $order['customer'] }}</p>
                            <p class="text-xs text-gray-400">{{ $order['branch'] }}</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <h3 class="font-bold text-gray-800 mb-3">{{ __('الدفع') }}</h3>
                    <div class="space-y-2.5 text-sm">
                        <div class="flex items-center justify-between text-gray-600"><span>{{ __('وسيلة الدفع') }}</span><span class="font-medium text-gray-800">{{ __($order['payment']) }}</span></div>
                        <div class="flex justify-between text-gray-600"><span>{{ __('حالة الدفع') }}</span><x-badge type="success">{{ __($order['payment_status']) }}</x-badge></div>
                        <div class="flex justify-between text-gray-600"><span>{{ __('الموظف') }}</span><span class="font-medium text-gray-800">{{ $order['employee'] }}</span></div>
                        <div class="flex justify-between text-gray-600"><span>{{ __('الفرع') }}</span><span class="font-medium text-gray-800">{{ $order['branch'] }}</span></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-layouts::pos>
