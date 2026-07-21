<x-layouts::admin title="أوامر الشراء">

    <x-page-header title="أوامر الشراء" subtitle="طلبات التزويد من المورّدين واستلام البضاعة"
        :breadcrumbs="['الرئيسية' => route('admin.dashboard'), 'أوامر الشراء' => '#']">
        <x-slot:actions>
            <x-button variant="outline" icon="truck" :href="route('admin.suppliers.index')">المورّدون</x-button>
            <x-button variant="primary" icon="plus" :href="route('admin.purchases.create')">أمر شراء جديد</x-button>
        </x-slot:actions>
    </x-page-header>

    @include('partials.inventory-tabs')

    @php
        $stats = \App\Support\Demo::purchaseOrderStats();
        $orders = \App\Support\Demo::purchaseOrders();
        $reorder = \App\Support\Demo::reorderSuggestions();
        $statusColors = ['مسودة' => 'gray', 'مُرسل' => 'info', 'مستلم جزئيًا' => 'warning', 'مستلم' => 'success', 'ملغي' => 'danger'];
    @endphp

    <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-6">
        <x-stat-card label="إجمالي الأوامر" value="{{ $stats['total'] }}" icon="clipboard-list" color="primary" />
        <x-stat-card label="قيد التنفيذ" value="{{ $stats['pending'] }}" icon="clock" color="warning" />
        <x-stat-card label="مستلمة" value="{{ $stats['received'] }}" icon="package-check" color="success" />
        <x-stat-card label="قيمة قيد الاستلام" :value="\App\Support\Demo::money($stats['value'])" icon="wallet" color="info" />
    </div>

    {{-- اقتراح إعادة الطلب --}}
    @if (count($reorder))
        <div class="bg-white rounded-2xl border border-warning-200 shadow-sm overflow-hidden mb-6">
            <div class="px-5 py-4 border-b border-warning-100 bg-warning-50/50 flex items-center gap-2">
                <x-icon name="alert-triangle" class="w-5 h-5 text-warning-600" />
                <h3 class="font-bold text-gray-800">اقتراح إعادة الطلب — {{ count($reorder) }} منتج وصل حدّ التنبيه</h3>
                <a href="{{ route('admin.purchases.create') }}?from=reorder" class="mr-auto text-sm text-primary-600 hover:text-primary-700 font-medium">إنشاء أمر شراء لها ←</a>
            </div>
            <x-table :headers="['المنتج', 'SKU', 'المتبقّي', 'حد التنبيه', 'الكمية المقترحة', 'التكلفة التقديرية']">
                @foreach ($reorder as $r)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ $r['name'] }}</td>
                        <td class="px-4 py-3 text-gray-500 font-mono whitespace-nowrap">{{ $r['sku'] }}</td>
                        <td class="px-4 py-3"><span class="font-semibold {{ $r['qty'] == 0 ? 'text-danger-600' : 'text-warning-600' }}">{{ $r['qty'] }}</span></td>
                        <td class="px-4 py-3 text-gray-500">{{ $r['alert'] }}</td>
                        <td class="px-4 py-3 font-semibold text-primary-600">{{ $r['suggested'] }} وحدة</td>
                        <td class="px-4 py-3 text-gray-700 whitespace-nowrap">{{ \App\Support\Demo::money($r['suggested'] * $r['cost']) }}</td>
                    </tr>
                @endforeach
            </x-table>
        </div>
    @endif

    {{-- قائمة أوامر الشراء --}}
    @if (count($orders))
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100"><h3 class="font-bold text-gray-800">أوامر الشراء</h3></div>
            <x-table :headers="['الرقم', 'الفرع', 'المورّد', 'الأصناف', 'الإجمالي', 'الحالة', 'تاريخ الطلب', 'إجراء']">
                @foreach ($orders as $o)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-mono text-gray-700 whitespace-nowrap">{{ $o['number'] }}</td>
                        <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $o['branch'] }}</td>
                        <td class="px-4 py-3 text-gray-800 whitespace-nowrap">{{ $o['supplier'] }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $o['items_count'] }}</td>
                        <td class="px-4 py-3 font-semibold text-gray-800 whitespace-nowrap">{{ \App\Support\Demo::money($o['total']) }}</td>
                        <td class="px-4 py-3"><x-badge :type="$statusColors[$o['status']] ?? 'gray'" :text="$o['status']" /></td>
                        <td class="px-4 py-3 text-gray-500 whitespace-nowrap" dir="ltr">{{ $o['ordered'] }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <div class="flex items-center gap-1.5">
                                @if ($o['status'] !== 'مستلم' && $o['status'] !== 'ملغي')
                                    <form method="POST" action="{{ route('admin.purchases.receive', $o['id']) }}" onsubmit="return confirm('تأكيد استلام البضاعة ورفع المخزون؟')">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center gap-1 text-xs px-2.5 py-1 rounded-full bg-success-600 text-white hover:bg-success-700"><x-icon name="package-check" class="w-3.5 h-3.5" /> استلام</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('admin.purchases.destroy', $o['id']) }}" onsubmit="return confirm('حذف أمر الشراء؟')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="text-xs px-2 py-1 rounded-full text-danger-600 hover:bg-danger-50"><x-icon name="trash-2" class="w-4 h-4" /></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-table>
        </div>
    @else
        <x-empty-state icon="clipboard-list" title="لا توجد أوامر شراء بعد" message="أنشئ أول أمر شراء لتزويد مخزونك من المورّدين.">
            <x-button variant="primary" icon="plus" :href="route('admin.purchases.create')">أمر شراء جديد</x-button>
        </x-empty-state>
    @endif

</x-layouts::admin>
