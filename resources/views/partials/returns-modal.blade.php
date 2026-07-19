{{-- نافذة الاسترجاع — تتطلب متغيّر $order (شكل Demo::orderDetails) --}}
@php $returnable = collect($order['items'])->filter(fn ($i) => $i['remaining'] > 0)->values(); @endphp

<div x-data="{ open: false }" x-on:open-returns.window="open = true" x-cloak>
    <div x-show="open" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div @click="open = false" class="absolute inset-0 bg-black/40"></div>

        <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                <h3 class="font-bold text-gray-800 flex items-center gap-2">
                    <x-icon name="undo-2" class="w-5 h-5 text-danger-600" /> استرجاع الطلب {{ $order['id'] }}
                </h3>
                <button type="button" @click="open = false" class="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-gray-100 text-gray-500">
                    <x-icon name="x" class="w-4 h-4" />
                </button>
            </div>

            @if ($returnable->isEmpty())
                <div class="p-8 text-center text-gray-400">
                    <x-icon name="package-check" class="w-10 h-10 mx-auto mb-2" />
                    <p>تم استرجاع كل أصناف هذا الطلب.</p>
                </div>
            @else
                <form method="POST" action="{{ route('orders.return', $order['id']) }}"
                      x-data="{
                          qty: {},
                          prices: {{ Js::from($returnable->pluck('price', 'id')) }},
                          get total() {
                              return Object.entries(this.qty).reduce((s, [id, q]) => s + (Number(q) || 0) * (this.prices[id] || 0), 0);
                          },
                          money(v){ return Number(v).toFixed(3) + ' ر.ع'; }
                      }">
                    @csrf
                    <div class="p-6 space-y-4">
                        <p class="text-sm text-gray-500">حدّد الكمية المراد استرجاعها لكل صنف (تُعاد إلى المخزون تلقائيًا).</p>

                        <div class="space-y-2">
                            @foreach ($returnable as $item)
                                <div class="flex items-center justify-between gap-3 rounded-xl border border-gray-100 p-3">
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-gray-800 truncate">{{ $item['name'] }}</p>
                                        <p class="text-xs text-gray-400">{{ \App\Support\Demo::money($item['price']) }} · متبقٍ للاسترجاع: {{ $item['remaining'] }}</p>
                                    </div>
                                    <div class="flex items-center gap-1.5 shrink-0">
                                        <button type="button" @click="qty[{{ $item['id'] }}] = Math.max(0, (Number(qty[{{ $item['id'] }}])||0) - 1)" class="w-7 h-7 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-600">−</button>
                                        <input type="number" name="items[{{ $item['id'] }}]" min="0" max="{{ $item['remaining'] }}" value="0"
                                               x-model="qty[{{ $item['id'] }}]"
                                               @input="if(Number($event.target.value) > {{ $item['remaining'] }}) $event.target.value = {{ $item['remaining'] }}"
                                               class="w-14 text-center rounded-lg border border-gray-200 py-1.5 text-sm" />
                                        <button type="button" @click="qty[{{ $item['id'] }}] = Math.min({{ $item['remaining'] }}, (Number(qty[{{ $item['id'] }}])||0) + 1)" class="w-7 h-7 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-600">+</button>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5">سبب الاسترجاع (اختياري)</label>
                            <textarea name="reason" rows="2" placeholder="مثال: منتج تالف / طلب العميل الإلغاء"
                                      class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2.5 text-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:outline-none"></textarea>
                        </div>

                        <div class="flex items-center justify-between rounded-xl bg-danger-50 px-4 py-3">
                            <span class="text-sm text-gray-600">مبلغ الاسترجاع</span>
                            <span class="font-bold text-danger-600" x-text="money(total)"></span>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-2 px-6 py-4 border-t border-gray-100">
                        <x-button variant="outline" type="button" x-on:click="open = false">إلغاء</x-button>
                        <x-button variant="danger" type="submit" icon="undo-2">تأكيد الاسترجاع</x-button>
                    </div>
                </form>
            @endif
        </div>
    </div>
</div>
