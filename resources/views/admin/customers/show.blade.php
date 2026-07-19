@php $customer = \App\Support\Demo::customer(request()->route('id')); @endphp

<x-layouts::admin :title="$customer['name']">
    <x-page-header
        title="ملف العميل"
        subtitle="عرض تفاصيل العميل وسجل طلباته ونقاط ولائه"
        :breadcrumbs="['الرئيسية' => route('admin.dashboard'), 'العملاء' => route('admin.customers.index'), $customer['name'] => '#']"
    >
        <x-slot:actions>
            <x-button variant="outline" size="md" icon="arrow-right" :href="route('admin.customers.index')">رجوع</x-button>
            <x-button variant="primary" size="md" icon="pencil">تعديل البيانات</x-button>
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- بطاقة الملف الشخصي --}}
        <div class="lg:col-span-1 space-y-6">
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 text-center">
                <img src="{{ $customer['avatar'] }}" alt="{{ $customer['name'] }}" class="w-24 h-24 rounded-full object-cover mx-auto ring-4 ring-primary-50" />
                <h2 class="mt-4 text-lg font-bold text-gray-800">{{ $customer['name'] }}</h2>
                <p class="text-xs text-gray-400 font-mono mt-1">عميل رقم #{{ $customer['id'] }}</p>

                <ul class="mt-5 space-y-3 text-sm text-right">
                    <li class="flex items-center justify-between border-t border-gray-50 pt-3">
                        <span class="text-gray-500 flex items-center gap-2"><x-icon name="phone" class="w-4 h-4" /> الهاتف</span>
                        <span class="text-gray-800" dir="ltr">{{ $customer['phone'] }}</span>
                    </li>
                    <li class="flex items-center justify-between">
                        <span class="text-gray-500 flex items-center gap-2"><x-icon name="mail" class="w-4 h-4" /> البريد</span>
                        <span class="text-gray-800" dir="ltr">{{ $customer['email'] }}</span>
                    </li>
                    <li class="flex items-center justify-between">
                        <span class="text-gray-500 flex items-center gap-2"><x-icon name="calendar" class="w-4 h-4" /> آخر طلب</span>
                        <span class="text-gray-800" dir="ltr">{{ $customer['last_order'] }}</span>
                    </li>
                </ul>

                {{-- نقاط الولاء بشكل بارز --}}
                <div class="mt-5 rounded-2xl bg-gradient-to-l from-secondary-50 to-primary-50 border border-secondary-100 p-4">
                    <p class="text-xs text-gray-500 flex items-center justify-center gap-1.5">
                        <x-icon name="award" class="w-4 h-4 text-secondary-500" /> نقاط الولاء
                    </p>
                    <p class="mt-1 text-3xl font-extrabold text-secondary-600">{{ $customer['points'] }}</p>
                    <p class="text-xs text-gray-400 mt-1">تعادل {{ \App\Support\Demo::money($customer['points'] / 100) }} خصم</p>
                </div>

                <div class="mt-5 flex items-center justify-center gap-2">
                    <x-button variant="light" size="sm" icon="mail" :href="'mailto:' . $customer['email']">مراسلة</x-button>
                    @if ($customer['phone'])
                        <x-button variant="light" size="sm" icon="phone" :href="'tel:' . preg_replace('/\s+/', '', $customer['phone'])">اتصال</x-button>
                    @endif
                    <form method="POST" action="{{ route('admin.customers.redeem', $customer['id']) }}"
                          @submit="if(!confirm('صرف كل نقاط العميل ({{ $customer['points'] }})؟')) $event.preventDefault()">
                        @csrf
                        <input type="hidden" name="points" value="{{ $customer['points'] }}" />
                        <x-button variant="outline" size="sm" icon="gift" type="submit" :disabled="! $customer['points']">صرف نقاط</x-button>
                    </form>
                </div>
            </div>
        </div>

        {{-- المحتوى --}}
        <div class="lg:col-span-2 space-y-6">
            {{-- البطاقات الإحصائية --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <x-stat-card label="عدد الطلبات" :value="$customer['orders']" icon="shopping-bag" color="primary" />
                <x-stat-card label="إجمالي المشتريات" :value="\App\Support\Demo::money($customer['total_spent'])" icon="wallet" color="success" />
                <x-stat-card label="متوسط الطلب" :value="\App\Support\Demo::money($customer['total_spent'] / max($customer['orders'], 1))" icon="calculator" color="info" />
                <x-stat-card label="نقاط الولاء" :value="$customer['points']" icon="award" color="secondary" />
            </div>

            {{-- التبويبات --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm" x-data="{ tab: 'orders' }">
                <div class="flex items-center gap-1 border-b border-gray-100 px-4 overflow-x-auto">
                    <button
                        @click="tab = 'orders'"
                        :class="tab === 'orders' ? 'text-primary-600 border-primary-600' : 'text-gray-500 border-transparent hover:text-gray-700'"
                        class="px-4 py-3.5 text-sm font-medium border-b-2 whitespace-nowrap transition-colors"
                    >سجل الطلبات</button>
                    <button
                        @click="tab = 'addresses'"
                        :class="tab === 'addresses' ? 'text-primary-600 border-primary-600' : 'text-gray-500 border-transparent hover:text-gray-700'"
                        class="px-4 py-3.5 text-sm font-medium border-b-2 whitespace-nowrap transition-colors"
                    >العناوين</button>
                    <button
                        @click="tab = 'notes'"
                        :class="tab === 'notes' ? 'text-primary-600 border-primary-600' : 'text-gray-500 border-transparent hover:text-gray-700'"
                        class="px-4 py-3.5 text-sm font-medium border-b-2 whitespace-nowrap transition-colors"
                    >الملاحظات</button>
                </div>

                {{-- سجل الطلبات (طلبات هذا العميل فعليًا) --}}
                <div x-show="tab === 'orders'" class="p-4">
                    @php $customerOrders = \App\Support\Demo::customerOrders($customer['id']); @endphp
                    @if (count($customerOrders))
                        <x-table :headers="['رقم الطلب', 'عدد المنتجات', 'الإجمالي', 'طريقة الدفع', 'الحالة', 'التاريخ', '']">
                            @foreach ($customerOrders as $order)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap font-mono">{{ $order['id'] }}</td>
                                    <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $order['items_count'] }} منتج</td>
                                    <td class="px-4 py-3 font-semibold text-gray-800 whitespace-nowrap">{{ \App\Support\Demo::money($order['total']) }}</td>
                                    <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $order['payment'] }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap"><x-badge :text="$order['status']" /></td>
                                    <td class="px-4 py-3 text-gray-500 whitespace-nowrap" dir="ltr">{{ $order['date'] }}</td>
                                    <td class="px-4 py-3"><x-button variant="ghost" size="sm" icon="eye" :href="route('admin.orders.show', $order['id'])">عرض</x-button></td>
                                </tr>
                            @endforeach
                        </x-table>
                    @else
                        <x-empty-state icon="shopping-bag" title="لا توجد طلبات" message="لم يقم هذا العميل بأي عملية شراء بعد." />
                    @endif
                </div>

                {{-- العناوين --}}
                <div x-show="tab === 'addresses'" x-cloak class="p-6">
                    @php
                        $addresses = [
                            ['label' => 'المنزل', 'city' => 'مسقط', 'area' => 'الخوير', 'street' => 'شارع 18 نوفمبر، مبنى 220', 'default' => true, 'icon' => 'home'],
                            ['label' => 'العمل', 'city' => 'مسقط', 'area' => 'روي', 'street' => 'برج المها، الطابق 5', 'default' => false, 'icon' => 'briefcase'],
                        ];
                    @endphp
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        @foreach ($addresses as $addr)
                            <div class="rounded-2xl border border-gray-200 p-4 hover:border-primary-200 transition">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="flex items-center gap-2 font-semibold text-gray-800">
                                        <x-icon :name="$addr['icon']" class="w-4 h-4 text-primary-600" /> {{ $addr['label'] }}
                                    </span>
                                    @if ($addr['default'])<x-badge type="success" text="افتراضي" />@endif
                                </div>
                                <p class="text-sm text-gray-600">{{ $addr['city'] }} - {{ $addr['area'] }}</p>
                                <p class="text-xs text-gray-400 mt-1">{{ $addr['street'] }}</p>
                                <div class="mt-3 flex items-center gap-2">
                                    <x-button variant="ghost" size="sm" icon="pencil">تعديل</x-button>
                                    <x-button variant="ghost" size="sm" icon="trash-2">حذف</x-button>
                                </div>
                            </div>
                        @endforeach
                        <button class="rounded-2xl border-2 border-dashed border-gray-200 p-4 flex items-center justify-center gap-2 text-sm text-gray-500 hover:border-primary-300 hover:text-primary-600 transition min-h-[120px]">
                            <x-icon name="plus" class="w-5 h-5" /> إضافة عنوان جديد
                        </button>
                    </div>
                </div>

                {{-- الملاحظات --}}
                <div x-show="tab === 'notes'" x-cloak class="p-6">
                    @php $customerModel = \App\Models\Customer::find($customer['id']); @endphp
                    <form method="POST" action="{{ route('admin.customers.note', $customer['id']) }}" class="space-y-4">
                        @csrf
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5">ملاحظات العميل</label>
                            <textarea name="notes" rows="4" placeholder="اكتب ملاحظة حول تفضيلات العميل..." class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-800 placeholder-gray-400 focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:outline-none transition">{{ $customerModel->notes ?? '' }}</textarea>
                        </div>
                        <div class="flex justify-end">
                            <x-button variant="primary" size="md" icon="save" type="submit">حفظ الملاحظة</x-button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-layouts::admin>
