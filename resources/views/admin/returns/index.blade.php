<x-layouts::admin title="المرتجعات">

    <x-page-header title="المرتجعات" subtitle="سجل عمليات الاسترجاع وإحصائياتها"
        :breadcrumbs="['الرئيسية' => route('admin.dashboard'), 'المرتجعات' => '#']" />

    {{-- الإحصائيات --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        @foreach (\App\Support\Demo::returnsStats() as $s)
            <x-stat-card :label="$s['label']" :value="$s['value']" :icon="$s['icon']" :trend="$s['trend']" :up="$s['up']" :color="$s['color']" />
        @endforeach
    </div>

    {{-- الجدول --}}
    @php $returns = \App\Support\Demo::returns(); @endphp
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-bold text-gray-800">سجل المرتجعات</h3>
            <span class="text-sm text-gray-400">{{ count($returns) }} عملية</span>
        </div>
        @if (count($returns))
            <x-table :headers="['الطلب', 'النوع', 'الأصناف', 'المبلغ', 'السبب', 'الموظف', 'التاريخ', '']">
                @foreach ($returns as $r)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-800 font-mono whitespace-nowrap">{{ $r['order'] }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <x-badge :type="$r['type'] === 'كلي' ? 'danger' : 'warning'" :text="'مرتجع ' . $r['type']" />
                        </td>
                        <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $r['items'] }}</td>
                        <td class="px-4 py-3 font-semibold text-danger-600 whitespace-nowrap">{{ \App\Support\Demo::money($r['amount']) }}</td>
                        <td class="px-4 py-3 text-gray-500 max-w-40 truncate">{{ $r['reason'] ?: '—' }}</td>
                        <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $r['employee'] }}</td>
                        <td class="px-4 py-3 text-gray-500 whitespace-nowrap" dir="ltr">{{ $r['date'] }}</td>
                        <td class="px-4 py-3">
                            <x-button variant="ghost" size="sm" icon="eye" :href="route('admin.orders.show', $r['order'])">الطلب</x-button>
                        </td>
                    </tr>
                @endforeach
            </x-table>
        @else
            <x-empty-state icon="undo-2" title="لا توجد مرتجعات" message="لم تُسجّل أي عملية استرجاع بعد. يمكنك استرجاع طلب من صفحة تفاصيله." />
        @endif
    </div>

</x-layouts::admin>
