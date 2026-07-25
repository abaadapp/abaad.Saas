<x-layouts::super-admin :title="__('تفاصيل محل الورود')">

    @php
        $shop = \App\Support\Demo::flowerShop(request()->route('id'));
        $sub = collect(\App\Support\Demo::subscriptions())->firstWhere('business', $shop['name']);
    @endphp

    <x-page-header :title="__('تفاصيل محل الورود')" :subtitle="$shop['name']"
        :breadcrumbs="[__('الرئيسية') => route('super-admin.dashboard'), __('محلات الورود') => route('super-admin.flower-shops.index'), $shop['name'] => '#']">
        <x-slot:actions>
            <x-button variant="outline" icon="pencil" :href="route('super-admin.flower-shops.edit', $shop['id'])">{{ __('تعديل') }}</x-button>
            <form method="POST" action="{{ route('super-admin.businesses.destroy', $shop['id']) }}" @submit="if(!confirm(@js(__('تعطيل هذا المحل؟')))) $event.preventDefault()">
                @csrf @method('DELETE')
                <x-button variant="danger" type="submit" icon="ban">{{ __('تعطيل') }}</x-button>
            </form>
        </x-slot:actions>
    </x-page-header>

    {{-- رأس المحل --}}
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden mb-6">
        <div class="h-28 bg-gradient-to-l from-secondary-200 via-secondary-100 to-primary-50"></div>
        <div class="px-6 pb-6 -mt-12">
            <div class="flex flex-col sm:flex-row sm:items-end gap-4">
                <img src="{{ $shop['logo'] }}" alt="{{ $shop['name'] }}" class="w-24 h-24 rounded-2xl object-cover border-4 border-white shadow-sm" />
                <div class="flex-1 sm:pb-1">
                    <div class="flex items-center gap-3 flex-wrap">
                        <h2 class="text-xl font-bold text-gray-800">{{ $shop['name'] }}</h2>
                        <x-badge :text="__($shop['status'])" />
                    </div>
                    <p class="mt-1 text-sm text-gray-500 flex items-center gap-3 flex-wrap">
                        <span class="flex items-center gap-1"><x-icon name="map-pin" class="w-4 h-4" /> {{ $shop['city'] }}</span>
                        <span class="flex items-center gap-1"><x-icon name="user" class="w-4 h-4" /> {{ $shop['owner'] }}</span>
                        <span class="flex items-center gap-1"><x-icon name="layers" class="w-4 h-4" /> {{ __('باقة :plan', ['plan' => $shop['plan']]) }}</span>
                    </p>
                </div>
            </div>
        </div>
    </div>

    {{-- إحصائيات سريعة --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <x-stat-card :label="__('الفروع')" :value="$shop['branches']" icon="git-branch" color="primary" />
        <x-stat-card :label="__('الموظفون')" :value="$shop['employees']" icon="users" color="info" />
        <x-stat-card :label="__('المنتجات')" :value="$shop['products']" icon="flower" color="secondary" />
        <x-stat-card :label="__('الطلبات')" :value="$shop['orders']" icon="shopping-bag" color="success" />
    </div>

    {{-- بطاقات المعلومات --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <h3 class="font-bold text-gray-800 mb-4">{{ __('معلومات المحل') }}</h3>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-gray-500">{{ __('الاسم') }}</dt><dd class="font-medium text-gray-800">{{ $shop['name'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-500">{{ __('المدينة') }}</dt><dd class="font-medium text-gray-800">{{ $shop['city'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-500">{{ __('عدد الفروع') }}</dt><dd class="font-medium text-gray-800">{{ $shop['branches'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-500">{{ __('إجمالي المبيعات') }}</dt><dd class="font-medium text-gray-800">{{ \App\Support\Demo::money($shop['sales']) }}</dd></div>
            </dl>
        </div>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <h3 class="font-bold text-gray-800 mb-4">{{ __('بيانات المالك') }}</h3>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-gray-500">{{ __('الاسم') }}</dt><dd class="font-medium text-gray-800">{{ $shop['owner'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-500">{{ __('الهاتف') }}</dt><dd class="font-medium text-gray-800" dir="ltr">+968 91234567</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-500">{{ __('البريد') }}</dt><dd class="font-medium text-gray-800" dir="ltr">info@flower.com</dd></div>
            </dl>
        </div>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <h3 class="font-bold text-gray-800 mb-4">{{ __('الاشتراك الحالي') }}</h3>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-gray-500">{{ __('الباقة') }}</dt><dd class="font-medium text-gray-800">{{ $shop['plan'] }}</dd></div>
                @if ($sub)
                <div class="flex justify-between gap-3"><dt class="text-gray-500">{{ __('تاريخ البداية') }}</dt><dd class="font-medium text-gray-800">{{ $sub['start'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-500">{{ __('تاريخ الانتهاء') }}</dt><dd class="font-medium text-gray-800">{{ $sub['end'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-500">{{ __('حالة الدفع') }}</dt><dd><x-badge :text="__($sub['payment'])" /></dd></div>
                @else
                <p class="text-center text-sm text-gray-400">{{ __('لا يوجد اشتراك') }}</p>
                @endif
            </dl>
        </div>
    </div>

    {{-- التبويبات --}}
    <div x-data="{ tab: 'branches' }" class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
        <div class="border-b border-gray-100 px-4">
            <nav class="flex gap-1 -mb-px overflow-x-auto">
                <button type="button" @click="tab = 'branches'"
                    :class="tab === 'branches' ? 'border-secondary-600 text-secondary-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                    class="px-4 py-3 text-sm font-medium border-b-2 whitespace-nowrap transition">{{ __('الفروع') }}</button>
                <button type="button" @click="tab = 'employees'"
                    :class="tab === 'employees' ? 'border-secondary-600 text-secondary-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                    class="px-4 py-3 text-sm font-medium border-b-2 whitespace-nowrap transition">{{ __('الموظفون') }}</button>
                <button type="button" @click="tab = 'products'"
                    :class="tab === 'products' ? 'border-secondary-600 text-secondary-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                    class="px-4 py-3 text-sm font-medium border-b-2 whitespace-nowrap transition">{{ __('المنتجات') }}</button>
                <button type="button" @click="tab = 'sales'"
                    :class="tab === 'sales' ? 'border-secondary-600 text-secondary-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                    class="px-4 py-3 text-sm font-medium border-b-2 whitespace-nowrap transition">{{ __('المبيعات') }}</button>
                <button type="button" @click="tab = 'orders'"
                    :class="tab === 'orders' ? 'border-secondary-600 text-secondary-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                    class="px-4 py-3 text-sm font-medium border-b-2 whitespace-nowrap transition">{{ __('آخر الطلبات') }}</button>
            </nav>
        </div>

        <div class="p-5">
            {{-- الفروع --}}
            <div x-show="tab === 'branches'">
                <x-table :headers="[__('الفرع'), __('المدينة'), __('المدير'), __('عدد الموظفين'), __('الحالة')]">
                    @for ($i = 1; $i <= $shop['branches']; $i++)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ $i === 1 ? __('الفرع الرئيسي') : __('فرع :city :n', ['city' => $shop['city'], 'n' => $i]) }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $shop['city'] }}</td>
                            <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ ['أحمد محمد', 'سارة حسن', 'خالد علي'][$i % 3] }}</td>
                            <td class="px-4 py-3 text-gray-600 text-center">{{ rand(2, 8) }}</td>
                            <td class="px-4 py-3"><x-badge :text="__('نشط')" /></td>
                        </tr>
                    @endfor
                </x-table>
            </div>

            {{-- الموظفون --}}
            <div x-show="tab === 'employees'" x-cloak>
                <x-table :headers="[__('الموظف'), __('الدور'), __('الفرع'), __('الهاتف'), __('المبيعات'), __('الحالة')]">
                    @foreach (\App\Support\Demo::employees() as $emp)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <img src="{{ $emp['avatar'] }}" alt="{{ $emp['name'] }}" class="w-9 h-9 rounded-full object-cover border border-gray-100" />
                                    <span class="font-medium text-gray-800 whitespace-nowrap">{{ $emp['name'] }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ __($emp['role']) }}</td>
                            <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $emp['branch'] }}</td>
                            <td class="px-4 py-3 text-gray-500 whitespace-nowrap" dir="ltr">{{ $emp['phone'] }}</td>
                            <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ \App\Support\Demo::money($emp['sales']) }}</td>
                            <td class="px-4 py-3"><x-badge :text="__($emp['status'])" /></td>
                        </tr>
                    @endforeach
                </x-table>
            </div>

            {{-- المنتجات --}}
            <div x-show="tab === 'products'" x-cloak>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    @foreach (\App\Support\Demo::products() as $p)
                        <x-product-card :product="$p" />
                    @endforeach
                </div>
            </div>

            {{-- المبيعات --}}
            <div x-show="tab === 'sales'" x-cloak>
                @php
                    $salesMonths = [__('يناير'), __('فبراير'), __('مارس'), __('أبريل'), __('مايو'), __('يونيو'), __('يوليو'), __('أغسطس'), __('سبتمبر'), __('أكتوبر'), __('نوفمبر'), __('ديسمبر')];
                @endphp
                <div class="flex items-center justify-between mb-4">
                    <h4 class="font-semibold text-gray-800">{{ __('مبيعات آخر 12 شهرًا') }}</h4>
                    <span class="text-xs text-gray-400">{{ __('بالريال العماني') }}</span>
                </div>
                <div x-data='apexChart({
                    chart: { type: "area", height: 300, fontFamily: "IBM Plex Sans Arabic", toolbar: { show: false } },
                    series: [{ name: @json(__('المبيعات')), data: [1200,1450,1300,1800,1650,2100,2400,2200,2500,2750,2900,3100] }],
                    xaxis: { categories: @json($salesMonths) },
                    colors: ["#db2777"],
                    dataLabels: { enabled: false },
                    stroke: { curve: "smooth", width: 2 },
                    fill: { type: "gradient", gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.05 } },
                    grid: { borderColor: "#f1f5f9" }
                })'></div>
            </div>

            {{-- آخر الطلبات --}}
            <div x-show="tab === 'orders'" x-cloak>
                <x-table :headers="[__('رقم الطلب'), __('العميل'), __('العناصر'), __('الإجمالي'), __('الدفع'), __('الحالة'), __('التاريخ')]">
                    @foreach (array_slice(\App\Support\Demo::orders(), 0, 8) as $o)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ $o['id'] }}</td>
                            <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $o['customer'] }}</td>
                            <td class="px-4 py-3 text-gray-600 text-center">{{ $o['items_count'] }}</td>
                            <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ \App\Support\Demo::money($o['total']) }}</td>
                            <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ __($o['payment']) }}</td>
                            <td class="px-4 py-3"><x-badge :text="__($o['status'])" /></td>
                            <td class="px-4 py-3 text-gray-500 whitespace-nowrap">{{ $o['date'] }}</td>
                        </tr>
                    @endforeach
                </x-table>
            </div>
        </div>
    </div>

</x-layouts::super-admin>
