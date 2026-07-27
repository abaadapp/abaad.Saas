<x-layouts::super-admin :title="__('الاشتراكات')">
    <x-page-header
        :title="__('الاشتراكات')"
        :subtitle="__('إدارة اشتراكات الشركات في المنصة ومتابعة حالة الدفع والتجديد')"
        :breadcrumbs="[__('الرئيسية') => route('super-admin.dashboard'), __('الاشتراكات') => '#']"
    >
        <x-slot:actions>
            <x-button variant="outline" size="md" icon="layers" :href="route('super-admin.subscriptions.plans')">{{ __('الباقات') }}</x-button>
            <x-button variant="outline" size="md" icon="file-text" :href="route('super-admin.subscriptions.invoices')">{{ __('الفواتير') }}</x-button>
            <x-button variant="primary" size="md" icon="plus" :href="route('super-admin.businesses.create')">{{ __('اشتراك جديد') }}</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- بطاقات إحصائية --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        @php $ss = \App\Support\Demo::subscriptionStats(); @endphp
        <x-stat-card :label="__('اشتراكات نشطة')" :value="(string) $ss['active']" icon="badge-check" color="success" />
        <x-stat-card :label="__('اشتراكات منتهية')" :value="(string) $ss['expired']" icon="badge-x" color="danger" />
        <x-stat-card :label="__('الإيراد الشهري')" :value="\App\Support\Demo::money($ss['monthly_revenue'])" icon="wallet" color="warning" />
        <x-stat-card :label="__('الإيراد السنوي')" :value="\App\Support\Demo::money($ss['yearly_revenue'])" icon="trending-up" color="primary" />
    </div>

    <div x-data="listFilter()" x-ref="list" @filter-change="setTag($event.detail.key, $event.detail.value)">
    {{-- شريط الفلاتر (تصفية لحظية) --}}
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
            <x-input name="search" :placeholder="__('ابحث باسم الشركة...')" icon="search" x-model="q" @input="apply()" />
            <x-select name="plan" :placeholder="__('كل الباقات')" :options="['أساسية' => __('أساسية'), 'احترافية' => __('احترافية'), 'مؤسسات' => __('مؤسسات')]" on-select="$dispatch('filter-change', {key:'plan', value})" />
            <x-select name="payment" :placeholder="__('كل حالات الدفع')" :options="['مدفوع' => __('مدفوع'), 'غير مدفوع' => __('غير مدفوع')]" on-select="$dispatch('filter-change', {key:'payment', value})" />
            <x-select name="status" :placeholder="__('كل الحالات')" :options="['نشط' => __('نشط'), 'منتهي' => __('منتهي'), 'معطل' => __('معطل')]" on-select="$dispatch('filter-change', {key:'status', value})" />
        </div>
    </div>

    {{-- جدول الاشتراكات --}}
    <x-table :headers="[__('الشركة'), __('الباقة'), __('تاريخ البداية'), __('تاريخ الانتهاء'), __('المبلغ'), __('حالة الدفع'), __('حالة الاشتراك')]">
        @foreach (\App\Support\Demo::subscriptions() as $sub)
            <tr class="hover:bg-gray-50" data-row data-search="{{ $sub['business'] }}" data-tag-plan="{{ $sub['plan'] }}" data-tag-payment="{{ $sub['payment'] }}" data-tag-status="{{ $sub['status'] }}">
                <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ $sub['business'] }}</td>
                <td class="px-4 py-3 text-gray-600 whitespace-nowrap">
                    <x-badge type="primary" :text="__($sub['plan'])" />
                </td>
                <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $sub['start'] }}</td>
                <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $sub['end'] }}</td>
                <td class="px-4 py-3 font-semibold text-gray-800 whitespace-nowrap">{{ \App\Support\Demo::money($sub['amount']) }}</td>
                <td class="px-4 py-3 whitespace-nowrap"><x-badge :text="__($sub['payment'])" /></td>
                <td class="px-4 py-3 whitespace-nowrap"><x-badge :text="__($sub['status'])" /></td>
            </tr>
        @endforeach
    </x-table>
    <div x-ref="empty" style="display:none" class="text-center text-sm text-gray-400 py-8">{{ __('لا نتائج مطابقة') }}</div>
    </div>
</x-layouts::super-admin>
