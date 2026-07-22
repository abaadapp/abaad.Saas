<x-layouts::super-admin :title="__('الشركات')">

    <x-page-header :title="__('الشركات')" :subtitle="__('إدارة جميع الشركات المسجلة في المنصة')"
        :breadcrumbs="[__('الرئيسية') => route('super-admin.dashboard'), __('الشركات') => '#']">
        <x-slot:actions>
            @include('partials.export-menu', ['xlsx' => route('super-admin.businesses.xlsx'), 'pdf' => route('super-admin.businesses.exportPdf'), 'csv' => route('super-admin.export.businesses')])
            <x-button variant="primary" icon="plus" :href="route('super-admin.businesses.create')">{{ __('إضافة شركة جديدة') }}</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- شريط الفلاتر --}}
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 mb-6">
        <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3 items-end">
            <div class="lg:col-span-2">
                <x-input :label="__('بحث')" name="q" icon="search" :placeholder="__('ابحث بالاسم أو المالك أو البريد...')" :value="$filters['q'] ?? ''" />
            </div>
            <x-select :label="__('نوع النشاط')" name="type" :placeholder="__('كل الأنواع')" selected="{{ $filters['type'] ?? '' }}"
                :options="['محل ورود' => __('محل ورود'), 'مطعم' => __('مطعم'), 'كافيه' => __('كافيه'), 'بقالة' => __('بقالة'), 'صيدلية' => __('صيدلية'), 'متجر ملابس' => __('متجر ملابس')]" />
            <x-select :label="__('الباقة')" name="plan" :placeholder="__('كل الباقات')" selected="{{ $filters['plan'] ?? '' }}"
                :options="!empty(\App\Models\Plan::pluck('name','name')->toArray()) ? \App\Models\Plan::pluck('name','name')->toArray() : ['أساسية' => 'أساسية', 'احترافية' => 'احترافية', 'مؤسسات' => 'مؤسسات']" />
            <x-select :label="__('الحالة')" name="status" :placeholder="__('كل الحالات')" selected="{{ $filters['status'] ?? '' }}"
                :options="['نشط' => __('نشط'), 'منتهي' => __('منتهي'), 'معطل' => __('معطل')]" />
            <div class="flex items-end gap-2">
                <x-button variant="primary" type="submit" icon="filter">{{ __('تصفية') }}</x-button>
                <x-button variant="outline" :href="url()->current()">{{ __('تفريغ') }}</x-button>
            </div>
        </form>
    </div>

    {{-- جدول الشركات --}}
    <x-table :headers="[__('الشركة'), __('النوع'), __('المالك'), __('الهاتف'), __('البريد'), __('الباقة'), __('الحالة'), __('التسجيل'), __('الفروع'), __('إجراءات')]">
        @foreach ($businesses as $b)
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3">
                    <div class="flex items-center gap-3">
                        <img src="{{ $b['logo'] }}" alt="{{ $b['name'] }}" class="w-9 h-9 rounded-lg object-cover border border-gray-100" />
                        <span class="font-medium text-gray-800 whitespace-nowrap">{{ $b['name'] }}</span>
                    </div>
                </td>
                <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ __($b['type']) }}</td>
                <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $b['owner'] }}</td>
                <td class="px-4 py-3 text-gray-500 whitespace-nowrap" dir="ltr">{{ $b['phone'] }}</td>
                <td class="px-4 py-3 text-gray-500 whitespace-nowrap" dir="ltr">{{ $b['email'] }}</td>
                <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ __($b['plan']) }}</td>
                <td class="px-4 py-3"><x-badge :text="__($b['status'])" /></td>
                <td class="px-4 py-3 text-gray-500 whitespace-nowrap">{{ $b['registered'] }}</td>
                <td class="px-4 py-3 text-gray-600 text-center">{{ $b['branches'] }}</td>
                <td class="px-4 py-3">
                    <x-dropdown align="left" width="w-44">
                        <x-slot:trigger>
                            <button class="w-9 h-9 flex items-center justify-center rounded-full text-gray-500 hover:bg-gray-100">
                                <x-icon name="ellipsis-vertical" class="w-4 h-4" />
                            </button>
                        </x-slot:trigger>
                        <a href="{{ route('super-admin.businesses.show', $b['id']) }}" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                            <x-icon name="eye" class="w-4 h-4" /> {{ __('عرض') }}
                        </a>
                        <a href="{{ route('super-admin.businesses.edit', $b['id']) }}" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                            <x-icon name="pencil" class="w-4 h-4" /> {{ __('تعديل') }}
                        </a>
                        <form method="POST" action="{{ route('super-admin.businesses.destroy', $b['id']) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-danger-600 hover:bg-gray-50 text-right">
                                <x-icon name="ban" class="w-4 h-4" /> {{ __('تعطيل') }}
                            </button>
                        </form>
                    </x-dropdown>
                </td>
            </tr>
        @endforeach

        <x-slot:footer>
            <x-pagination :paginator="$businesses" />
        </x-slot:footer>
    </x-table>

</x-layouts::super-admin>
