<x-layouts::admin :title="__('الجرد الفعلي')">
    <x-page-header
        :title="__('الجرد الفعلي')"
        :subtitle="__('أدخل الكمية المعدودة فعليًا لكل صنف — يعرض النظام الفرق ويسجّل تسوية تلقائية')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('المخزون') => route('admin.inventory.index'), __('الجرد الفعلي') => '#']"
    />

    @include('partials.inventory-tabs')

    <form method="POST" action="{{ route('admin.inventory.stocktake.apply') }}"
          x-data="{
              counts: {},
              variance(id, book) {
                  const c = this.counts[id];
                  if (c === undefined || c === '' || c === null) return null;
                  return Number(c) - Number(book);
              }
          }">
        @csrf

        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 mb-6">
            <div class="flex flex-col sm:flex-row sm:items-end gap-3">
                <div class="w-full sm:w-72">
                    <x-select :label="__('الفرع')" name="branch_id" :placeholder="__('اختر الفرع...')" :required="true"
                        :options="collect($branches)->pluck('name', 'id')->toArray()"
                        :selected="$currentBranch" />
                </div>
                <p class="text-xs text-gray-400 sm:pb-2.5">{{ __('اترك الحقل فارغًا للأصناف التي لم تُعَدّ (لن تتغيّر).') }}</p>
            </div>
            @error('branch_id')<p class="mt-2 text-xs text-danger-500">{{ $message }}</p>@enderror
        </div>

        <x-table :headers="[__('المنتج'), 'SKU', __('الكمية الدفترية'), __('الكمية المعدودة'), __('الفرق')]">
            @foreach ($items as $item)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ $item['name'] }}</td>
                    <td class="px-4 py-3 text-gray-500 whitespace-nowrap font-mono">{{ $item['sku'] }}</td>
                    <td class="px-4 py-3 text-gray-700 whitespace-nowrap font-semibold">{{ $item['qty'] }}</td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        <input type="text" inputmode="numeric" name="counts[{{ $item['id'] }}]"
                               x-model="counts[{{ $item['id'] }}]"
                               placeholder="{{ __('لم يُعَدّ') }}"
                               class="w-28 rounded-lg border border-gray-200 px-2 py-1.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-200 focus:outline-none" />
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        <template x-if="variance({{ $item['id'] }}, {{ $item['qty'] }}) === null">
                            <span class="text-gray-300">—</span>
                        </template>
                        <template x-if="variance({{ $item['id'] }}, {{ $item['qty'] }}) !== null">
                            <span class="font-semibold"
                                  :class="variance({{ $item['id'] }}, {{ $item['qty'] }}) === 0 ? 'text-gray-500' : (variance({{ $item['id'] }}, {{ $item['qty'] }}) > 0 ? 'text-success-600' : 'text-danger-600')"
                                  x-text="(variance({{ $item['id'] }}, {{ $item['qty'] }}) > 0 ? '+' : '') + variance({{ $item['id'] }}, {{ $item['qty'] }})"></span>
                        </template>
                    </td>
                </tr>
            @endforeach
        </x-table>

        <div class="flex items-center gap-3 mt-5">
            <x-button variant="primary" type="submit" icon="check-check">{{ __('تطبيق الجرد والتسوية') }}</x-button>
            <x-button variant="outline" type="button" :href="route('admin.inventory.index')">{{ __('إلغاء') }}</x-button>
        </div>
    </form>

</x-layouts::admin>
