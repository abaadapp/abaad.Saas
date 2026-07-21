<x-layouts::pos title="الطلبات">
    @php $held = \App\Support\Demo::heldOrders(); @endphp

    <div class="h-full overflow-y-auto p-4 sm:p-6">
        {{-- عنوان الصفحة --}}
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-3">
                <span class="w-11 h-11 rounded-xl bg-gray-100 text-gray-700 flex items-center justify-center">
                    <x-icon name="receipt-text" class="w-6 h-6" />
                </span>
                <div>
                    <h1 class="text-xl font-bold text-gray-800">الطلبات</h1>
                    <p class="text-sm text-gray-400">الطلبات المعلّقة والمحفوظة بانتظار الاستكمال</p>
                </div>
            </div>
            <x-button variant="dark" icon="plus" :href="route('pos.index')">طلب جديد</x-button>
        </div>

        @php
            $heldCount = collect($held)->where('saved', false)->count();
            $savedCount = collect($held)->where('saved', true)->count();
        @endphp

        @if (count($held))
            {{-- تصفية حسب النوع --}}
            <div x-data="{ f: 'all' }">
            <div class="flex items-center gap-2 mb-5 flex-wrap">
                @foreach ([['all', 'الكل', count($held)], ['hold', 'معلّقة', $heldCount], ['save', 'محفوظة', $savedCount]] as [$key, $label, $n])
                    <button type="button" @click="f = '{{ $key }}'"
                        :class="f === '{{ $key }}' ? 'bg-gray-900 text-white' : 'bg-white text-gray-600 hover:bg-gray-50 border border-gray-200'"
                        class="px-4 h-9 rounded-full text-sm font-medium transition-colors">
                        {{ $label }} <span class="opacity-70">({{ $n }})</span>
                    </button>
                @endforeach
            </div>

            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-right">
                        <thead class="bg-gray-50 text-gray-500">
                            <tr>
                                <th class="px-4 py-3 font-medium">رقم الطلب</th>
                                <th class="px-4 py-3 font-medium">الحالة</th>
                                <th class="px-4 py-3 font-medium">العميل</th>
                                <th class="px-4 py-3 font-medium">المنتجات</th>
                                <th class="px-4 py-3 font-medium">المبلغ</th>
                                <th class="px-4 py-3 font-medium">الوقت</th>
                                <th class="px-4 py-3 font-medium">الموظف</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($held as $o)
                                <tr class="hover:bg-gray-50"
                                    x-show="f === 'all' || f === '{{ $o['saved'] ? 'save' : 'hold' }}'">
                                    <td class="px-4 py-3 font-semibold text-gray-800 whitespace-nowrap">{{ $o['id'] }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <x-badge :type="$o['saved'] ? 'info' : 'warning'" dot>{{ $o['status'] }}</x-badge>
                                    </td>
                                    <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $o['customer'] }}</td>
                                    <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $o['items'] }}</td>
                                    <td class="px-4 py-3 font-semibold text-gray-900 whitespace-nowrap">{{ \App\Support\Demo::money($o['total']) }}</td>
                                    <td class="px-4 py-3 text-gray-500 whitespace-nowrap" dir="ltr">{{ $o['time'] }}</td>
                                    <td class="px-4 py-3 text-gray-500 whitespace-nowrap">{{ $o['employee'] }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-left">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <x-button variant="dark" size="sm" icon="play"
                                                      :href="route('pos.orders.resume', $o['order_id'])">استكمال</x-button>
                                            <form method="POST" action="{{ route('pos.orders.discard', $o['order_id']) }}"
                                                  @submit.prevent="if(confirm('حذف الطلب {{ $o['id'] }}؟')) $el.submit()">
                                                @csrf @method('DELETE')
                                                <x-button variant="ghost" size="sm" icon="trash-2" type="submit" class="text-danger-600">حذف</x-button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="px-4 py-3 text-xs text-gray-400 border-t border-gray-100">{{ count($held) }} طلب</div>
            </div>
        @else
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm">
                <x-empty-state icon="receipt-text" title="لا توجد طلبات"
                               message="لم يتم تعليق أو حفظ أي طلب حتى الآن. يمكنك تعليق الطلبات أو حفظها من شاشة نقطة البيع.">
                    <x-slot:action>
                        <x-button variant="dark" icon="plus" :href="route('pos.index')">بدء طلب جديد</x-button>
                    </x-slot:action>
                </x-empty-state>
            </div>
        @endif
    </div>
</x-layouts::pos>
