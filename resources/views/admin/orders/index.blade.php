<x-layouts::admin title="الطلبات">

    <x-page-header title="الطلبات" subtitle="متابعة وإدارة طلبات العملاء"
        :breadcrumbs="['الرئيسية' => route('admin.dashboard'), 'الطلبات' => '#']">
        <x-slot:actions>
            <x-button variant="outline" icon="download" :href="route('admin.export.orders')">تصدير CSV</x-button>
            <x-button variant="primary" icon="plus" :href="route('pos.index')">إنشاء طلب</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- بطاقات ملخص الحالات --}}
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
        @php
            $summary = [
                ['label' => 'جديد', 'count' => 24, 'icon' => 'sparkles', 'color' => 'info'],
                ['label' => 'قيد التجهيز', 'count' => 12, 'icon' => 'loader', 'color' => 'warning'],
                ['label' => 'جاهز', 'count' => 8, 'icon' => 'package-check', 'color' => 'success'],
                ['label' => 'مكتمل', 'count' => 246, 'icon' => 'circle-check', 'color' => 'primary'],
                ['label' => 'ملغي', 'count' => 5, 'icon' => 'circle-x', 'color' => 'danger'],
            ];
            $cardColors = [
                'primary' => 'bg-primary-50 text-primary-600',
                'secondary' => 'bg-secondary-50 text-secondary-600',
                'success' => 'bg-success-50 text-success-600',
                'warning' => 'bg-warning-50 text-warning-600',
                'danger' => 'bg-danger-50 text-danger-600',
                'info' => 'bg-info-50 text-info-600',
            ];
        @endphp
        @foreach ($summary as $card)
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 flex items-center gap-3">
                <span class="w-11 h-11 rounded-xl flex items-center justify-center shrink-0 {{ $cardColors[$card['color']] }}">
                    <x-icon :name="$card['icon']" class="w-5 h-5" />
                </span>
                <div class="min-w-0">
                    <p class="text-xs text-gray-500 truncate">{{ $card['label'] }}</p>
                    <p class="text-xl font-bold text-gray-800">{{ $card['count'] }}</p>
                </div>
            </div>
        @endforeach
    </div>

    {{-- شريط الفلاتر --}}
    <form method="GET" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
            <x-input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="ابحث برقم الطلب أو العميل..." icon="search" />
            <x-select name="payment" :options="['نقدي' => 'نقدي', 'بطاقة' => 'بطاقة', 'تحويل بنكي' => 'تحويل بنكي', 'آجل' => 'آجل']" placeholder="كل وسائل الدفع" selected="{{ $filters['payment'] ?? '' }}" />
            <x-input name="date" type="date" value="{{ $filters['date'] ?? '' }}" />
        </div>
        <div class="flex items-center gap-2 mt-4">
            <x-button type="submit" icon="filter">تصفية</x-button>
            <x-button variant="outline" :href="url()->current()">تفريغ</x-button>
        </div>
    </form>

    {{-- جدول الطلبات --}}
    <x-table :headers="['رقم الطلب', 'العميل', 'الموظف', 'الفرع', 'المنتجات', 'الإجمالي', 'الدفع', 'التاريخ', 'إجراءات']">
        @foreach ($orders as $o)
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ $o['id'] }}</td>
                <td class="px-4 py-3 text-gray-700 whitespace-nowrap">{{ $o['customer'] }}</td>
                <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $o['employee'] }}</td>
                <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $o['branch'] }}</td>
                <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $o['items_count'] }} منتج</td>
                <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ \App\Support\Demo::money($o['total']) }}</td>
                <td class="px-4 py-3"><x-badge :text="$o['payment']" /></td>
                <td class="px-4 py-3 text-gray-500 whitespace-nowrap">{{ $o['date'] }}</td>
                <td class="px-4 py-3">
                    <x-button variant="ghost" size="sm" icon="eye" :href="route('admin.orders.show', $o['id'])">عرض</x-button>
                </td>
            </tr>
        @endforeach
        <x-slot:footer>
            <x-pagination :paginator="$orders" />
        </x-slot:footer>
    </x-table>

</x-layouts::admin>
