<x-layouts::super-admin :title="__('محلات الورود')">

    <x-page-header :title="__('محلات الورود')" :subtitle="__('إدارة محلات الورود المسجلة في المنصة')"
        :breadcrumbs="[__('الرئيسية') => route('super-admin.dashboard'), __('محلات الورود') => '#']">
        <x-slot:actions>
            <x-button variant="secondary" icon="plus" :href="route('super-admin.flower-shops.create')">{{ __('إضافة محل ورود') }}</x-button>
        </x-slot:actions>
    </x-page-header>

    <div x-data="listFilter()" x-ref="list" @filter-change="setTag($event.detail.key, $event.detail.value)">
    {{-- شريط البحث والفلترة (تصفية لحظية) --}}
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 mb-6">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end">
            <div class="lg:col-span-2">
                <x-input :label="__('بحث')" name="search" icon="search" :placeholder="__('ابحث عن محل ورود...')" x-model="q" @input="apply()" />
            </div>
            <x-select :label="__('المدينة')" name="city" :placeholder="__('كل المدن')"
                :options="['مسقط' => __('مسقط'), 'صلالة' => __('صلالة'), 'صحار' => __('صحار'), 'نزوى' => __('نزوى'), 'صور' => __('صور')]" on-select="$dispatch('filter-change', {key:'city', value})" />
            <x-select :label="__('الحالة')" name="status" :placeholder="__('كل الحالات')"
                :options="['نشط' => __('نشط'), 'منتهي' => __('منتهي')]" on-select="$dispatch('filter-change', {key:'status', value})" />
        </div>
    </div>

    {{-- بطاقات المحلات --}}
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
        @foreach (\App\Support\Demo::flowerShops() as $shop)
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden hover:shadow-md transition-shadow flex flex-col"
                 data-row data-search="{{ $shop['name'] }} {{ $shop['owner'] }} {{ $shop['city'] }}" data-tag-city="{{ $shop['city'] }}" data-tag-status="{{ $shop['status'] }}">
                <div class="relative h-32 bg-gradient-to-l from-secondary-100 to-primary-50">
                    <img src="{{ $shop['logo'] }}" alt="{{ $shop['name'] }}" class="w-full h-full object-cover opacity-90" />
                    <div class="absolute top-3 right-3">
                        <x-badge :text="__($shop['status'])" />
                    </div>
                </div>
                <div class="p-5 flex-1 flex flex-col">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h3 class="font-bold text-gray-800 truncate">{{ $shop['name'] }}</h3>
                            <p class="mt-0.5 text-sm text-gray-500 flex items-center gap-1">
                                <x-icon name="map-pin" class="w-3.5 h-3.5" /> {{ $shop['city'] }}
                            </p>
                        </div>
                        <span class="shrink-0 inline-flex items-center gap-1 text-xs font-medium text-secondary-700 bg-secondary-50 px-2 py-1 rounded-lg">
                            <x-icon name="layers" class="w-3.5 h-3.5" /> {{ __($shop['plan']) }}
                        </span>
                    </div>

                    <p class="mt-2 text-sm text-gray-500 flex items-center gap-1.5">
                        <x-icon name="user" class="w-4 h-4" /> {{ $shop['owner'] }}
                    </p>

                    {{-- شارات الأعداد --}}
                    <div class="mt-4 grid grid-cols-4 gap-2 text-center">
                        <div class="rounded-xl bg-gray-50 py-2">
                            <p class="text-sm font-bold text-gray-800">{{ $shop['branches'] }}</p>
                            <p class="text-[11px] text-gray-400">{{ __('فروع') }}</p>
                        </div>
                        <div class="rounded-xl bg-gray-50 py-2">
                            <p class="text-sm font-bold text-gray-800">{{ $shop['employees'] }}</p>
                            <p class="text-[11px] text-gray-400">{{ __('موظفين') }}</p>
                        </div>
                        <div class="rounded-xl bg-gray-50 py-2">
                            <p class="text-sm font-bold text-gray-800">{{ $shop['products'] }}</p>
                            <p class="text-[11px] text-gray-400">{{ __('منتجات') }}</p>
                        </div>
                        <div class="rounded-xl bg-gray-50 py-2">
                            <p class="text-sm font-bold text-gray-800">{{ $shop['orders'] }}</p>
                            <p class="text-[11px] text-gray-400">{{ __('طلبات') }}</p>
                        </div>
                    </div>

                    <div class="mt-4 pt-4 border-t border-gray-50 flex items-center justify-between">
                        <span class="text-sm text-gray-500">{{ __('المبيعات:') }} <span class="font-bold text-gray-800">{{ \App\Support\Demo::money($shop['sales']) }}</span></span>
                    </div>

                    <div class="mt-4">
                        <x-button variant="light" size="sm" icon="arrow-left" :href="route('super-admin.flower-shops.show', $shop['id'])" class="w-full">{{ __('عرض التفاصيل') }}</x-button>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
    <div x-ref="empty" style="display:none" class="text-center text-sm text-gray-400 py-12">{{ __('لا نتائج مطابقة') }}</div>
    </div>

</x-layouts::super-admin>
