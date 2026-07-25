<x-layouts::admin :title="__('تفاصيل الطلب')">

    @php
        $order = \App\Support\Demo::orderDetails(request()->route('id')); abort_if(empty($order), 404);
        abort_if(empty($order), 404);
    @endphp

    <x-page-header :title="__('الطلب :id', ['id' => $order['id']])" subtitle="{{ $order['date'] }}"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('الطلبات') => route('admin.orders.index'), $order['id'] => '#']">
        <x-slot:actions>
            <x-button variant="outline" icon="file-text" :href="route('admin.orders.pdf', $order['id'])" target="_blank">{{ __('تصدير PDF') }}</x-button>
            <x-button variant="outline" icon="landmark" :href="route('admin.orders.taxInvoice', $order['id'])" target="_blank">{{ __('فاتورة ضريبية') }}</x-button>
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- التفاصيل والملخص المالي --}}
        <div class="lg:col-span-2 space-y-6">
            <div>
                <h3 class="font-bold text-gray-800 mb-3">{{ __('تفاصيل المنتجات') }}</h3>
                <x-table :headers="[__('المنتج'), __('الكمية'), __('السعر'), __('الإجمالي')]">
                    @foreach ($order['items'] as $line)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium text-gray-800">{{ $line['name'] }}</td>
                            <td class="px-4 py-3 text-gray-700 whitespace-nowrap">{{ $line['qty'] }}</td>
                            <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ \App\Support\Demo::money($line['price']) }}</td>
                            <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ \App\Support\Demo::money($line['total']) }}</td>
                        </tr>
                    @endforeach
                </x-table>
            </div>

            {{-- الملخص المالي --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="font-bold text-gray-800 mb-4">{{ __('الملخص المالي') }}</h3>
                <dl class="space-y-3 text-sm">
                    <div class="flex items-center justify-between">
                        <dt class="text-gray-500">{{ __('المجموع الفرعي') }}</dt>
                        <dd class="font-medium text-gray-800">{{ \App\Support\Demo::money($order['subtotal']) }}</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-gray-500">{{ __('الخصم') }}</dt>
                        <dd class="font-medium text-danger-600">- {{ \App\Support\Demo::money($order['discount']) }}</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-gray-500">{{ __('الضريبة') }}</dt>
                        <dd class="font-medium text-gray-800">{{ \App\Support\Demo::money($order['tax']) }}</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-gray-500">{{ __('رسوم التوصيل') }}</dt>
                        <dd class="font-medium text-gray-800">{{ \App\Support\Demo::money($order['delivery']) }}</dd>
                    </div>
                    <div class="flex items-center justify-between pt-3 border-t border-gray-100">
                        <dt class="font-bold text-gray-800">{{ __('الإجمالي') }}</dt>
                        <dd class="text-lg font-bold text-primary-600">{{ \App\Support\Demo::money($order['total']) }}</dd>
                    </div>
                </dl>
            </div>
        </div>

        {{-- العمود الجانبي --}}
        <div class="space-y-6">
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="font-bold text-gray-800 mb-4">{{ __('بيانات العميل') }}</h3>
                <div class="flex items-center gap-3 mb-4">
                    <span class="w-11 h-11 rounded-full bg-primary-50 text-primary-600 flex items-center justify-center font-bold">
                        {{ mb_substr($order['customer'], 0, 1) }}
                    </span>
                    <div>
                        <p class="font-medium text-gray-800">{{ $order['customer'] }}</p>
                        <p class="text-xs text-gray-400">{{ $order['branch'] }}</p>
                    </div>
                </div>
                <dl class="space-y-2.5 text-sm">
                    <div class="flex items-center gap-2 text-gray-600">
                        <x-icon name="user" class="w-4 h-4 text-gray-400" /> {{ __('الموظف: :name', ['name' => $order['employee']]) }}
                    </div>
                    <div class="flex items-center gap-2 text-gray-600">
                        <x-icon name="calendar" class="w-4 h-4 text-gray-400" /> {{ $order['date'] }}
                    </div>
                </dl>
            </div>

            {{-- حالة الدفع --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="font-bold text-gray-800 mb-4">{{ __('حالة الدفع') }}</h3>
                <div class="flex items-center justify-between mb-3">
                    <span class="text-sm text-gray-500">{{ __('وسيلة الدفع') }}</span>
                    <x-badge :text="$order['payment'] === 'بطاقة' ? 'فيزا' : $order['payment']" />
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-500">{{ __('حالة الدفع') }}</span>
                    <x-badge type="success">{{ __($order['payment_status']) }}</x-badge>
                </div>
            </div>

            @if ($order['notes'])
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                    <h3 class="font-bold text-gray-800 mb-3">{{ __('ملاحظات الطلب') }}</h3>
                    <p class="text-sm text-gray-600 leading-relaxed">{{ $order['notes'] }}</p>
                </div>
            @endif
        </div>
    </div>

</x-layouts::admin>
