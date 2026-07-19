<x-layouts::pos title="تفاصيل الطلب">
    @php
        $products = \App\Support\Demo::products();
        $lines = array_slice($products, 0, 4);
        $subtotal = 0;
        foreach ($lines as $i => $l) { $subtotal += $l['price'] * ($i + 1); }
        $discount = round($subtotal * 0.10, 3);
        $tax = round(($subtotal - $discount) * 0.05, 3);
        $delivery = 2.000;
        $total = $subtotal - $discount + $tax + $delivery;
    @endphp

    <div class="h-full overflow-y-auto p-4 sm:p-6">
        {{-- عنوان --}}
        <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
            <div class="flex items-center gap-3">
                <a href="{{ route('pos.orders') }}" class="w-10 h-10 flex items-center justify-center rounded-xl bg-white border border-gray-100 text-gray-500 hover:bg-gray-50">
                    <x-icon name="arrow-right" class="w-5 h-5" />
                </a>
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-xl font-bold text-gray-800">طلب #POS-1042</h1>
                        <x-badge type="success" dot>مكتمل</x-badge>
                    </div>
                    <p class="text-sm text-gray-400">2026-07-17 — 14:32</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <x-button variant="outline" icon="printer" onclick="window.print()">طباعة</x-button>
                <x-button variant="dark" icon="play" :href="route('pos.index')">استكمال</x-button>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {{-- المنتجات + الملخص --}}
            <div class="lg:col-span-2 space-y-6">
                <x-table :headers="['المنتج', 'الكمية', 'السعر', 'الإجمالي']">
                    @foreach ($lines as $i => $l)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <img src="{{ $l['image'] }}" class="w-11 h-11 rounded-lg object-cover" alt="{{ $l['name'] }}" />
                                    <div>
                                        <p class="font-semibold text-gray-800">{{ $l['name'] }}</p>
                                        <p class="text-xs text-gray-400">{{ $l['sku'] }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-gray-600">{{ $i + 1 }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ \App\Support\Demo::money($l['price']) }}</td>
                            <td class="px-4 py-3 font-semibold text-gray-800">{{ \App\Support\Demo::money($l['price'] * ($i + 1)) }}</td>
                        </tr>
                    @endforeach
                </x-table>

                {{-- الملخص المالي --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 max-w-sm mr-auto ml-0 w-full">
                    <h3 class="font-bold text-gray-800 mb-3">الملخص المالي</h3>
                    <div class="space-y-2.5 text-sm">
                        <div class="flex justify-between text-gray-600"><span>المجموع الفرعي</span><span class="font-medium text-gray-800">{{ \App\Support\Demo::money($subtotal) }}</span></div>
                        <div class="flex justify-between text-gray-600"><span>الخصم (10%)</span><span class="text-danger-500">- {{ \App\Support\Demo::money($discount) }}</span></div>
                        <div class="flex justify-between text-gray-600"><span>الضريبة (5%)</span><span class="font-medium text-gray-800">{{ \App\Support\Demo::money($tax) }}</span></div>
                        <div class="flex justify-between text-gray-600"><span>رسوم التوصيل</span><span class="font-medium text-gray-800">{{ \App\Support\Demo::money($delivery) }}</span></div>
                        <div class="flex justify-between pt-2.5 border-t border-dashed border-gray-200">
                            <span class="font-bold text-gray-800">الإجمالي</span>
                            <span class="text-lg font-extrabold text-gray-900">{{ \App\Support\Demo::money($total) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- بيانات جانبية --}}
            <div class="space-y-6">
                {{-- العميل --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <h3 class="font-bold text-gray-800 mb-3">بيانات العميل</h3>
                    <div class="flex items-center gap-3 mb-3">
                        <img src="{{ \App\Support\Demo::image('cust0', 100, 100) }}" class="w-12 h-12 rounded-xl object-cover" alt="العميل" />
                        <div>
                            <p class="font-semibold text-gray-800">محمد سالم</p>
                            <p class="text-xs text-gray-400">عميل مميز · 320 نقطة</p>
                        </div>
                    </div>
                    <div class="space-y-2 text-sm text-gray-600">
                        <p class="flex items-center gap-2"><x-icon name="phone" class="w-4 h-4 text-gray-400" /> +968 91234567</p>
                        <p class="flex items-center gap-2"><x-icon name="map-pin" class="w-4 h-4 text-gray-400" /> مسقط — الخوير</p>
                    </div>
                </div>

                {{-- وسيلة الدفع --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <h3 class="font-bold text-gray-800 mb-3">الدفع</h3>
                    <div class="space-y-2.5 text-sm">
                        <div class="flex items-center justify-between text-gray-600">
                            <span>وسيلة الدفع</span>
                            <span class="flex items-center gap-1.5 font-medium text-gray-800"><x-icon name="banknote" class="w-4 h-4 text-success-600" /> نقدي</span>
                        </div>
                        <div class="flex justify-between text-gray-600"><span>حالة الدفع</span><x-badge type="success">مدفوع</x-badge></div>
                        <div class="flex justify-between text-gray-600"><span>الموظف</span><span class="font-medium text-gray-800">سارة حسن</span></div>
                        <div class="flex justify-between text-gray-600"><span>الفرع</span><span class="font-medium text-gray-800">الفرع الرئيسي</span></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-layouts::pos>
