<x-layouts::admin :title="__('أوامر الشراء')">

    <x-page-header :title="__('أوامر الشراء')" :subtitle="__('طلبات التزويد من المورّدين واستلام البضاعة')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('أوامر الشراء') => '#']">
        <x-slot:actions>
            <x-button variant="outline" icon="truck" :href="route('admin.suppliers.index')">{{ __('المورّدون') }}</x-button>
            <x-button variant="primary" icon="plus" :href="route('admin.purchases.create')">{{ __('أمر شراء جديد') }}</x-button>
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
        <x-stat-card :label="__('إجمالي الأوامر')" value="{{ $stats['total'] }}" icon="clipboard-list" color="primary" />
        <x-stat-card :label="__('قيد التنفيذ')" value="{{ $stats['pending'] }}" icon="clock" color="warning" />
        <x-stat-card :label="__('مستلمة')" value="{{ $stats['received'] }}" icon="package-check" color="success" />
        <x-stat-card :label="__('قيمة قيد الاستلام')" :value="\App\Support\Demo::money($stats['value'])" icon="wallet" color="info" />
    </div>

    {{-- اقتراح إعادة الطلب --}}
    @if (count($reorder))
        <div class="bg-white rounded-2xl border border-warning-200 shadow-sm overflow-hidden mb-6">
            <div class="px-5 py-4 border-b border-warning-100 bg-warning-50/50 flex items-center gap-2">
                <x-icon name="alert-triangle" class="w-5 h-5 text-warning-600" />
                <h3 class="font-bold text-gray-800">{{ __('اقتراح إعادة الطلب — :count منتج وصل حدّ التنبيه', ['count' => count($reorder)]) }}</h3>
                <a href="{{ route('admin.purchases.create') }}?from=reorder" class="mr-auto text-sm text-primary-600 hover:text-primary-700 font-medium">{{ __('إنشاء أمر شراء لها ←') }}</a>
            </div>
            <x-table :headers="[__('المنتج'), 'SKU', __('المتبقّي'), __('حد التنبيه'), __('الكمية المقترحة'), __('التكلفة التقديرية')]">
                @foreach ($reorder as $r)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ $r['name'] }}</td>
                        <td class="px-4 py-3 text-gray-500 font-mono whitespace-nowrap">{{ $r['sku'] }}</td>
                        <td class="px-4 py-3"><span class="font-semibold {{ $r['qty'] == 0 ? 'text-danger-600' : 'text-warning-600' }}">{{ $r['qty'] }}</span></td>
                        <td class="px-4 py-3 text-gray-500">{{ $r['alert'] }}</td>
                        <td class="px-4 py-3 font-semibold text-primary-600">{{ __(':count وحدة', ['count' => $r['suggested']]) }}</td>
                        <td class="px-4 py-3 text-gray-700 whitespace-nowrap">{{ \App\Support\Demo::money($r['suggested'] * $r['cost']) }}</td>
                    </tr>
                @endforeach
            </x-table>
        </div>
    @endif

    {{-- قائمة أوامر الشراء --}}
    @if (count($orders))
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden" x-data="listFilter()" x-ref="list" @filter-change="setTag($event.detail.key, $event.detail.value)">
            <div class="px-5 py-4 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <h3 class="font-bold text-gray-800">{{ __('أوامر الشراء') }}</h3>
                <div class="flex flex-col sm:flex-row gap-2">
                    <div class="w-full sm:w-56">
                        <x-input name="po-search" :placeholder="__('ابحث بالرقم أو المورّد...')" icon="search" x-model="q" @input="apply()" />
                    </div>
                    <div class="w-full sm:w-44">
                        <x-select name="po-status" :placeholder="__('كل الحالات')" :options="['مسودة' => __('مسودة'), 'مُرسل' => __('مُرسل'), 'مستلم جزئيًا' => __('مستلم جزئيًا'), 'مستلم' => __('مستلم'), 'ملغي' => __('ملغي')]" on-select="$dispatch('filter-change', {key:'status', value})" icon="filter" />
                    </div>
                </div>
            </div>
            <x-table :headers="[__('الرقم'), __('الفرع'), __('المورّد'), __('الأصناف'), __('الإجمالي'), __('الحالة'), __('إيصال الدفع'), __('تاريخ الطلب'), __('إجراء')]">
                @foreach ($orders as $o)
                    <tr class="hover:bg-gray-50" data-row data-search="{{ $o['number'] }} {{ $o['supplier'] }} {{ $o['branch'] }}" data-tag-status="{{ $o['status'] }}">
                        <td class="px-4 py-3 font-mono text-gray-700 whitespace-nowrap">{{ $o['number'] }}</td>
                        <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $o['branch'] }}</td>
                        <td class="px-4 py-3 text-gray-800 whitespace-nowrap">{{ $o['supplier'] }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $o['items_count'] }}</td>
                        <td class="px-4 py-3 font-semibold text-gray-800 whitespace-nowrap">{{ \App\Support\Demo::money($o['total']) }}</td>
                        <td class="px-4 py-3"><x-badge :type="$statusColors[$o['status']] ?? 'gray'" :text="__($o['status'])" /></td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            @if ($o['receipt'])
                                <a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($o['receipt']) }}" target="_blank"
                                   class="inline-flex items-center gap-1.5 text-sm text-gray-700 hover:text-gray-900 hover:underline"
                                   title="{{ $o['receipt_name'] }}">
                                    <x-icon name="paperclip" class="w-4 h-4 text-gray-400" /> {{ __('عرض') }}
                                </a>
                            @else
                                <form method="POST" action="{{ route('admin.purchases.receipt', $o['id']) }}" enctype="multipart/form-data" x-data>
                                    @csrf
                                    <label class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-800 cursor-pointer">
                                        <x-icon name="upload" class="w-4 h-4" /> {{ __('رفع') }}
                                        <input type="file" name="receipt" class="hidden"
                                               accept=".jpg,.jpeg,.png,.pdf,.webp,.heic"
                                               @change="$el.form.submit()" />
                                    </label>
                                </form>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-500 whitespace-nowrap" dir="ltr">{{ $o['ordered'] }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <div class="flex items-center gap-1.5">
                                @if ($o['status'] !== 'مستلم' && $o['status'] !== 'ملغي')
                                    <form method="POST" action="{{ route('admin.purchases.receive', $o['id']) }}" onsubmit="return confirm(@js(__('تأكيد استلام البضاعة ورفع المخزون؟')))">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center gap-1 text-xs px-2.5 py-1 rounded-full bg-success-600 text-white hover:bg-success-700"><x-icon name="package-check" class="w-3.5 h-3.5" /> {{ __('استلام') }}</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('admin.purchases.destroy', $o['id']) }}" onsubmit="return confirm(@js(__('حذف أمر الشراء؟')))">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="text-xs px-2 py-1 rounded-full text-danger-600 hover:bg-danger-50"><x-icon name="trash-2" class="w-4 h-4" /></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-table>
            <div x-ref="empty" style="display:none" class="text-center text-sm text-gray-400 py-8">{{ __('لا نتائج مطابقة') }}</div>
        </div>
    @else
        <x-empty-state icon="clipboard-list" :title="__('لا توجد أوامر شراء بعد')" :message="__('أنشئ أول أمر شراء لتزويد مخزونك من المورّدين.')">
            <x-button variant="primary" icon="plus" :href="route('admin.purchases.create')">{{ __('أمر شراء جديد') }}</x-button>
        </x-empty-state>
    @endif

</x-layouts::admin>
