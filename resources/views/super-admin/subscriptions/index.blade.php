<x-layouts::super-admin title="الاشتراكات">
    <x-page-header
        title="الاشتراكات"
        subtitle="إدارة اشتراكات الشركات في المنصة ومتابعة حالة الدفع والتجديد"
        :breadcrumbs="['الرئيسية' => route('super-admin.dashboard'), 'الاشتراكات' => '#']"
    >
        <x-slot:actions>
            <x-button variant="outline" size="md" icon="layers" :href="route('super-admin.subscriptions.plans')">الباقات</x-button>
            <x-button variant="outline" size="md" icon="file-text" :href="route('super-admin.subscriptions.invoices')">الفواتير</x-button>
            <x-button variant="primary" size="md" icon="plus">اشتراك جديد</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- بطاقات إحصائية --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <x-stat-card label="اشتراكات نشطة" value="96" icon="badge-check" trend="+6%" :up="true" color="success" />
        <x-stat-card label="اشتراكات منتهية" value="24" icon="badge-x" trend="-3%" :up="false" color="danger" />
        <x-stat-card label="الإيراد الشهري" value="4,820.000 ر.ع" icon="wallet" trend="+14%" :up="true" color="warning" />
        <x-stat-card label="الإيراد السنوي" value="52,640.000 ر.ع" icon="trending-up" trend="+21%" :up="true" color="primary" />
    </div>

    {{-- شريط الفلاتر --}}
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
            <x-input name="search" placeholder="ابحث باسم الشركة..." icon="search" />
            <x-select name="plan" placeholder="كل الباقات" :options="['أساسية' => 'أساسية', 'احترافية' => 'احترافية', 'مؤسسات' => 'مؤسسات']" />
            <x-select name="payment" placeholder="كل حالات الدفع" :options="['مدفوع' => 'مدفوع', 'غير مدفوع' => 'غير مدفوع']" />
            <x-select name="status" placeholder="كل الحالات" :options="['نشط' => 'نشط', 'منتهي' => 'منتهي', 'معطل' => 'معطل']" />
        </div>
    </div>

    {{-- جدول الاشتراكات --}}
    <x-table :headers="['الشركة', 'الباقة', 'تاريخ البداية', 'تاريخ الانتهاء', 'المبلغ', 'حالة الدفع', 'حالة الاشتراك']">
        @foreach (\App\Support\Demo::subscriptions() as $sub)
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ $sub['business'] }}</td>
                <td class="px-4 py-3 text-gray-600 whitespace-nowrap">
                    <x-badge type="primary" :text="$sub['plan']" />
                </td>
                <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $sub['start'] }}</td>
                <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $sub['end'] }}</td>
                <td class="px-4 py-3 font-semibold text-gray-800 whitespace-nowrap">{{ \App\Support\Demo::money($sub['amount']) }}</td>
                <td class="px-4 py-3 whitespace-nowrap"><x-badge :text="$sub['payment']" /></td>
                <td class="px-4 py-3 whitespace-nowrap"><x-badge :text="$sub['status']" /></td>
            </tr>
        @endforeach

        <x-slot:footer>
            <x-pagination :total="128" :perPage="12" :current="1" />
        </x-slot:footer>
    </x-table>
</x-layouts::super-admin>
