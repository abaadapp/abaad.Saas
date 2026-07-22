<x-layouts::admin :title="__('الطلبات')">

    <x-page-header :title="__('الطلبات')" :subtitle="__('متابعة وإدارة طلبات العملاء')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('الطلبات') => '#']">
        <x-slot:actions>
            <x-dropdown align="left" width="w-56">
                <x-slot:trigger>
                    <span class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium rounded-full border border-gray-200 text-gray-700 hover:bg-gray-50 cursor-pointer">
                        <x-icon name="download" class="w-4 h-4" /> {{ __('تصدير') }}
                    </span>
                </x-slot:trigger>
                <a href="{{ route('admin.orders.xlsx') }}" class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50">
                    <x-icon name="file-spreadsheet" class="w-4 h-4 text-gray-400" /> {{ __('تصدير كملف إكسل') }}
                </a>
                <a href="{{ route('admin.orders.exportPdf') }}" target="_blank" class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50">
                    <x-icon name="file-text" class="w-4 h-4 text-gray-400" /> {{ __('تصدير كملف PDF') }}
                </a>
                <a href="{{ route('admin.export.orders') }}" class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50">
                    <x-icon name="file-down" class="w-4 h-4 text-gray-400" /> {{ __('تصدير كملف CSV') }}
                </a>
            </x-dropdown>
            <x-button variant="primary" icon="plus" :href="route('pos.index')">{{ __('إنشاء طلب') }}</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- شريط الفلاتر --}}
    <form method="GET" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
            <x-input name="q" value="{{ $filters['q'] ?? '' }}" :placeholder="__('ابحث برقم الطلب أو العميل...')" icon="search" />
            <x-select name="payment" :options="['نقدي' => __('نقدي'), 'بطاقة' => __('فيزا'), 'تحويل بنكي' => __('تحويل بنكي')]" :placeholder="__('كل وسائل الدفع')" selected="{{ $filters['payment'] ?? '' }}" />
            <x-input name="date" type="date" value="{{ $filters['date'] ?? '' }}" />
        </div>
        <div class="flex items-center gap-2 mt-4">
            <x-button type="submit" icon="filter">{{ __('تصفية') }}</x-button>
            <x-button variant="outline" :href="url()->current()">{{ __('تفريغ') }}</x-button>
        </div>
    </form>

    {{-- جدول الطلبات --}}
    <x-table :headers="[__('رقم الطلب'), __('العميل'), __('الموظف'), __('الفرع'), __('المنتجات'), __('الإجمالي'), __('الدفع'), __('التاريخ'), __('إجراءات')]">
        @foreach ($orders as $o)
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ $o['id'] }}</td>
                <td class="px-4 py-3 text-gray-700 whitespace-nowrap">{{ $o['customer'] }}</td>
                <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $o['employee'] }}</td>
                <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $o['branch'] }}</td>
                <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ __(':count منتج', ['count' => $o['items_count']]) }}</td>
                <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ \App\Support\Demo::money($o['total']) }}</td>
                <td class="px-4 py-3"><x-badge :text="$o['payment'] === 'بطاقة' ? 'فيزا' : $o['payment']" /></td>
                <td class="px-4 py-3 text-gray-500 whitespace-nowrap">{{ $o['date'] }}</td>
                <td class="px-4 py-3">
                    <x-button variant="ghost" size="sm" icon="eye" :href="route('admin.orders.show', $o['id'])">{{ __('عرض') }}</x-button>
                </td>
            </tr>
        @endforeach
        <x-slot:footer>
            <x-pagination :paginator="$orders" />
        </x-slot:footer>
    </x-table>

</x-layouts::admin>
