<x-layouts::pos title="الطلبات المعلقة">
    @php $held = \App\Support\Demo::heldOrders(); @endphp

    <div class="h-full overflow-y-auto p-4 sm:p-6">
        {{-- عنوان الصفحة --}}
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-3">
                <span class="w-11 h-11 rounded-xl bg-warning-50 text-warning-600 flex items-center justify-center">
                    <x-icon name="pause-circle" class="w-6 h-6" />
                </span>
                <div>
                    <h1 class="text-xl font-bold text-gray-800">الطلبات المعلقة</h1>
                    <p class="text-sm text-gray-400">الطلبات المحفوظة مؤقتًا بانتظار الاستكمال</p>
                </div>
            </div>
            <x-button variant="dark" icon="plus" :href="route('pos.index')">طلب جديد</x-button>
        </div>

        @if (count($held))
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                @foreach ($held as $o)
                    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm hover:shadow-md transition-all p-4 flex flex-col">
                        <div class="flex items-center justify-between mb-3">
                            <span class="font-bold text-gray-800">{{ $o['id'] }}</span>
                            <x-badge type="warning" dot>معلّق</x-badge>
                        </div>
                        <div class="flex items-center gap-2 text-sm text-gray-600 mb-2">
                            <x-icon name="user" class="w-4 h-4 text-gray-900" />
                            <span>{{ $o['customer'] }}</span>
                        </div>
                        <div class="grid grid-cols-2 gap-2 text-sm mb-3">
                            <div class="bg-gray-50 rounded-xl px-3 py-2">
                                <p class="text-xs text-gray-400">عدد المنتجات</p>
                                <p class="font-semibold text-gray-800">{{ $o['items'] }}</p>
                            </div>
                            <div class="bg-gray-50 rounded-xl px-3 py-2">
                                <p class="text-xs text-gray-400">المبلغ</p>
                                <p class="font-semibold text-gray-900">{{ \App\Support\Demo::money($o['total']) }}</p>
                            </div>
                        </div>
                        <div class="flex items-center justify-between text-xs text-gray-400 mb-4">
                            <span class="flex items-center gap-1"><x-icon name="clock" class="w-3.5 h-3.5" /> {{ $o['time'] }}</span>
                            <span class="flex items-center gap-1"><x-icon name="user-round" class="w-3.5 h-3.5" /> {{ $o['employee'] }}</span>
                        </div>
                        <x-button variant="dark" size="sm" icon="play" class="w-full mt-auto" :href="route('pos.index')">استكمال الطلب</x-button>
                    </div>
                @endforeach
            </div>
        @else
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm">
                <x-empty-state icon="pause-circle" title="لا توجد طلبات معلقة"
                               message="لم يتم تعليق أي طلب حتى الآن. يمكنك تعليق الطلبات من شاشة نقطة البيع.">
                    <x-slot:action>
                        <x-button variant="dark" icon="plus" :href="route('pos.index')">بدء طلب جديد</x-button>
                    </x-slot:action>
                </x-empty-state>
            </div>
        @endif
    </div>
</x-layouts::pos>
