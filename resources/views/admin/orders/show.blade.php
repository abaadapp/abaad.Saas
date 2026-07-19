<x-layouts::admin title="تفاصيل الطلب">

    @php
        $order = \App\Support\Demo::orderDetails(request()->route('id'));
        abort_if(empty($order), 404);

        $steps = ['جديد', 'قيد التجهيز', 'جاهز', 'خرج للتوصيل', 'مكتمل'];
        $currentStep = array_search($order['status'], $steps);
        if ($currentStep === false) { $currentStep = 0; }
    @endphp

    <x-page-header title="الطلب {{ $order['id'] }}" subtitle="{{ $order['date'] }}"
        :breadcrumbs="['الرئيسية' => route('admin.dashboard'), 'الطلبات' => route('admin.orders.index'), $order['id'] => '#']">
        <x-slot:actions>
            <x-badge :text="$order['status']" />
            <x-button variant="outline" icon="file-text" :href="route('admin.orders.pdf', $order['id'])" target="_blank">تصدير PDF</x-button>
            <x-button variant="outline" icon="landmark" :href="route('admin.orders.taxInvoice', $order['id'])" target="_blank">فاتورة ضريبية</x-button>
            <x-dropdown align="left" width="w-48">
                <x-slot:trigger>
                    <span class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium rounded-xl bg-primary-600 hover:bg-primary-700 text-white cursor-pointer">
                        <x-icon name="refresh-cw" class="w-4 h-4" /> تغيير الحالة
                    </span>
                </x-slot:trigger>
                @foreach (['قيد التجهيز', 'جاهز', 'خرج للتوصيل', 'مكتمل', 'ملغي'] as $st)
                    <form method="POST" action="{{ route('admin.orders.updateStatus', $order['id']) }}">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="status" value="{{ $st }}">
                        <button type="submit" class="w-full text-right px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">{{ $st }}</button>
                    </form>
                @endforeach
            </x-dropdown>
        </x-slot:actions>
    </x-page-header>

    {{-- شريط تقدم الحالة --}}
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 mb-6">
        <div x-data="{ current: {{ $currentStep }} }" class="flex items-center justify-between">
                @foreach ($steps as $i => $step)
                    <div class="flex items-center flex-1 last:flex-none">
                        <div class="flex flex-col items-center gap-2">
                            <span :class="{{ $i }} <= current ? 'bg-primary-600 text-white' : 'bg-gray-100 text-gray-400'"
                                class="w-10 h-10 rounded-full flex items-center justify-center font-bold transition">
                                <template x-if="{{ $i }} < current"><i data-lucide="check" class="w-5 h-5"></i></template>
                                <span x-show="{{ $i }} >= current">{{ $i + 1 }}</span>
                            </span>
                            <span :class="{{ $i }} <= current ? 'text-gray-800 font-medium' : 'text-gray-400'" class="text-xs whitespace-nowrap">{{ $step }}</span>
                        </div>
                        @if (! $loop->last)
                            <div class="flex-1 h-0.5 mx-2 rounded" :class="{{ $i }} < current ? 'bg-primary-600' : 'bg-gray-100'"></div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- التفاصيل والملخص المالي --}}
        <div class="lg:col-span-2 space-y-6">
            <div>
                <h3 class="font-bold text-gray-800 mb-3">تفاصيل المنتجات</h3>
                <x-table :headers="['المنتج', 'الكمية', 'السعر', 'الإجمالي']">
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
                <h3 class="font-bold text-gray-800 mb-4">الملخص المالي</h3>
                <dl class="space-y-3 text-sm">
                    <div class="flex items-center justify-between">
                        <dt class="text-gray-500">المجموع الفرعي</dt>
                        <dd class="font-medium text-gray-800">{{ \App\Support\Demo::money($order['subtotal']) }}</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-gray-500">الخصم</dt>
                        <dd class="font-medium text-danger-600">- {{ \App\Support\Demo::money($order['discount']) }}</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-gray-500">الضريبة</dt>
                        <dd class="font-medium text-gray-800">{{ \App\Support\Demo::money($order['tax']) }}</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-gray-500">رسوم التوصيل</dt>
                        <dd class="font-medium text-gray-800">{{ \App\Support\Demo::money($order['delivery']) }}</dd>
                    </div>
                    <div class="flex items-center justify-between pt-3 border-t border-gray-100">
                        <dt class="font-bold text-gray-800">الإجمالي</dt>
                        <dd class="text-lg font-bold text-primary-600">{{ \App\Support\Demo::money($order['total']) }}</dd>
                    </div>
                </dl>
            </div>
        </div>

        {{-- العمود الجانبي --}}
        <div class="space-y-6">
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="font-bold text-gray-800 mb-4">بيانات العميل</h3>
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
                        <x-icon name="user" class="w-4 h-4 text-gray-400" /> الموظف: {{ $order['employee'] }}
                    </div>
                    <div class="flex items-center gap-2 text-gray-600">
                        <x-icon name="calendar" class="w-4 h-4 text-gray-400" /> {{ $order['date'] }}
                    </div>
                </dl>
            </div>

            {{-- حالة الدفع --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="font-bold text-gray-800 mb-4">حالة الدفع</h3>
                <div class="flex items-center justify-between mb-3">
                    <span class="text-sm text-gray-500">وسيلة الدفع</span>
                    <x-badge :text="$order['payment']" />
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-500">حالة الدفع</span>
                    <x-badge type="success">{{ $order['payment_status'] }}</x-badge>
                </div>
            </div>

            @if ($order['notes'])
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                    <h3 class="font-bold text-gray-800 mb-3">ملاحظات الطلب</h3>
                    <p class="text-sm text-gray-600 leading-relaxed">{{ $order['notes'] }}</p>
                </div>
            @endif
        </div>
    </div>

</x-layouts::admin>
