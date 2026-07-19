<x-layouts::super-admin title="الفواتير">
    <x-page-header
        title="الفواتير"
        subtitle="سجل فواتير الاشتراكات وحالة السداد لكل شركة"
        :breadcrumbs="['الرئيسية' => route('super-admin.dashboard'), 'الاشتراكات' => route('super-admin.subscriptions.index'), 'الفواتير' => '#']"
    >
        <x-slot:actions>
            <x-button variant="outline" size="md" icon="refresh-cw" :href="route('super-admin.subscriptions.index')">الاشتراكات</x-button>
            <x-button variant="primary" size="md" icon="download" :href="route('super-admin.export.invoices')">تصدير CSV</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- بطاقات الملخص --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <x-stat-card label="إجمالي المدفوع" value="41,280.000 ر.ع" icon="circle-check" trend="+12%" :up="true" color="success" />
        <x-stat-card label="إجمالي غير المدفوع" value="6,940.000 ر.ع" icon="circle-alert" trend="-4%" :up="false" color="danger" />
        <x-stat-card label="عدد الفواتير" value="128" icon="file-text" trend="+9%" :up="true" color="primary" />
    </div>

    {{-- جدول الفواتير --}}
    <x-table :headers="['رقم الفاتورة', 'الشركة', 'الباقة', 'المبلغ', 'التاريخ', 'الحالة', 'إجراءات']">
        @foreach (\App\Support\Demo::invoices() as $inv)
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 font-mono font-medium text-gray-800 whitespace-nowrap">{{ $inv['number'] }}</td>
                <td class="px-4 py-3 text-gray-700 whitespace-nowrap">{{ $inv['business'] }}</td>
                <td class="px-4 py-3 whitespace-nowrap"><x-badge type="primary" :text="$inv['plan']" /></td>
                <td class="px-4 py-3 font-semibold text-gray-800 whitespace-nowrap">{{ \App\Support\Demo::money($inv['amount']) }}</td>
                <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $inv['date'] }}</td>
                <td class="px-4 py-3 whitespace-nowrap"><x-badge :text="$inv['status']" /></td>
                <td class="px-4 py-3 whitespace-nowrap">
                    <div class="flex items-center gap-1">
                        <a href="{{ route('super-admin.invoices.pdf', $inv['number']) }}" target="_blank" class="w-8 h-8 flex items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-primary-600" title="عرض">
                            <x-icon name="eye" class="w-4 h-4" />
                        </a>
                        <a href="{{ route('super-admin.invoices.pdf', $inv['number']) }}" target="_blank" class="w-8 h-8 flex items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-primary-600" title="تحميل">
                            <x-icon name="download" class="w-4 h-4" />
                        </a>
                    </div>
                </td>
            </tr>
        @endforeach

        <x-slot:footer>
            <x-pagination :total="128" :perPage="12" :current="1" />
        </x-slot:footer>
    </x-table>
</x-layouts::super-admin>
