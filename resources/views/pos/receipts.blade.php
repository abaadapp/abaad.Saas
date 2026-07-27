<x-layouts::pos :title="__('الفواتير')">
    @php
        // فواتير حقيقية بأصنافها الفعلية كما اشتراها العميل (بلا اختلاق بيانات)
        $data = \App\Support\Demo::receipts();
        $pdfBase = route('pos.receipt.pdf', '__NUMBER__');
        // ترويسة الفاتورة من إعدادات النشاط الفعلية (بلا بيانات ثابتة)
        $biz = \App\Models\Business::find(\App\Support\Demo::bid());
        $branchName = \App\Support\Demo::currentBranchName();
    @endphp

    <div class="h-full flex flex-col lg:flex-row"
         x-data="{
            q: '',
            list: @js($data),
            sel: 0,
            money(v) { return Number(v).toFixed(3) + ' ' + @js(__('ر.ع')); },
            get filtered() {
                const t = this.q.trim().toLowerCase();
                if (!t) return this.list;
                return this.list.filter((r) =>
                    (r.number || '').toLowerCase().includes(t) ||
                    (r.customer || '').toLowerCase().includes(t) ||
                    (r.phone || '').toLowerCase().includes(t));
            },
            get current() { return this.filtered[this.sel] || this.filtered[0] || {}; },
         }">

        {{-- ======= قائمة الفواتير (يمين) ======= --}}
        <div class="lg:w-2/5 xl:w-1/3 border-l border-gray-100 flex flex-col min-h-0 no-print">
            <div class="p-4 border-b border-gray-100 shrink-0">
                <div class="flex items-center gap-3 mb-3">
                    <span class="w-10 h-10 rounded-xl bg-gray-100 text-gray-700 flex items-center justify-center">
                        <x-icon name="receipt" class="w-5 h-5" />
                    </span>
                    <div>
                        <h1 class="text-lg font-bold text-gray-800">{{ __('الفواتير') }}</h1>
                        <p class="text-xs text-gray-400">{{ __('اختر فاتورة لمعاينتها وطباعتها') }}</p>
                    </div>
                </div>
                <x-input name="rec-search" :placeholder="__('ابحث برقم الفاتورة أو العميل أو الهاتف...')" icon="search"
                         x-model="q" @input="sel = 0" />
            </div>
            <div class="flex-1 overflow-y-auto p-3 space-y-2">
                <template x-for="(rec, idx) in filtered" :key="rec.number">
                    <div @click="sel = idx"
                            :class="sel === idx ? 'border-gray-900 bg-gray-100' : 'border-gray-100 bg-white hover:bg-gray-50'"
                            class="w-full text-right border rounded-xl p-3 transition-colors cursor-pointer">
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-gray-800" x-text="rec.number"></span>
                            <span class="text-sm font-bold text-gray-700" x-text="money(rec.total)"></span>
                        </div>
                        <div class="flex items-center justify-between mt-1 text-xs text-gray-400">
                            <span class="flex items-center gap-1.5 min-w-0">
                                <span class="truncate" x-text="rec.customer"></span>
                                <span x-show="rec.phone" class="font-mono text-gray-400 shrink-0" dir="ltr" x-text="rec.phone"></span>
                            </span>
                            <span class="shrink-0" x-text="rec.time"></span>
                        </div>
                        <div class="mt-2 flex justify-end gap-3">
                            <a :href="'{{ route('pos.order-details', '__NUMBER__') }}'.replace('__NUMBER__', rec.number)" @click.stop
                               class="inline-flex items-center gap-1 text-xs text-gray-600 hover:text-gray-800 font-medium">
                                <x-icon name="eye" class="w-3.5 h-3.5" /> {{ __('التفاصيل') }}
                            </a>
                            <a :href="'{{ $pdfBase }}'.replace('__NUMBER__', rec.number)" target="_blank" @click.stop
                               class="inline-flex items-center gap-1 text-xs text-primary-600 hover:text-primary-700 font-medium">
                                <x-icon name="file-text" class="w-3.5 h-3.5" /> PDF
                            </a>
                        </div>
                    </div>
                </template>
                <p x-show="!filtered.length" x-cloak class="text-center text-sm text-gray-400 py-10">{{ __('لا نتائج مطابقة') }}</p>
            </div>
        </div>

        {{-- ======= معاينة الفاتورة الحرارية (يسار) ======= --}}
        <div class="flex-1 overflow-y-auto bg-gray-100 p-4 sm:p-8 flex flex-col items-center">
            <div class="mb-4 no-print">
                <x-button variant="dark" icon="printer" onclick="window.print()">{{ __('طباعة الفاتورة') }}</x-button>
            </div>

            <div x-show="filtered.length" class="thermal-receipt bg-white shadow-lg rounded-lg p-5 text-gray-800" style="width: 300px; font-family: 'Tajawal', monospace;">
                {{-- الترويسة --}}
                <div class="text-center border-b border-dashed border-gray-300 pb-3">
                    <div class="w-14 h-14 mx-auto rounded-full bg-gray-200 text-gray-700 flex items-center justify-center mb-2">
                        <x-icon name="flower" class="w-8 h-8" />
                    </div>
                    <h2 class="text-lg font-extrabold">{{ $biz->name ?? __('نظام Abad POS') }}</h2>
                    @if ($biz?->type)<p class="text-xs text-gray-500">{{ $biz->type }}</p>@endif
                    <p class="text-xs text-gray-500">{{ $branchName }}@if ($biz?->city) — {{ $biz->city }}@endif</p>
                </div>

                {{-- بيانات الفاتورة --}}
                <div class="text-xs py-2 border-b border-dashed border-gray-300 space-y-1">
                    <div class="flex justify-between"><span class="text-gray-500">{{ __('رقم الفاتورة') }}</span><span class="font-bold" x-text="current.number"></span></div>
                    <div class="flex justify-between"><span class="text-gray-500">{{ __('التاريخ/الوقت') }}</span><span x-text="current.time"></span></div>
                    <div class="flex justify-between"><span class="text-gray-500">{{ __('العميل') }}</span><span x-text="current.customer"></span></div>
                    <div class="flex justify-between"><span class="text-gray-500">{{ __('الموظف') }}</span><span x-text="current.employee"></span></div>
                </div>

                {{-- المنتجات --}}
                <table class="w-full text-xs my-2">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-500">
                            <th class="text-right font-medium py-1">{{ __('الصنف') }}</th>
                            <th class="text-center font-medium py-1">{{ __('كمية') }}</th>
                            <th class="text-left font-medium py-1">{{ __('السعر') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="line in (current.lines || [])" :key="line.name">
                            <tr class="border-b border-dashed border-gray-100">
                                <td class="py-1.5 text-right" x-text="line.name"></td>
                                <td class="py-1.5 text-center" x-text="line.qty"></td>
                                <td class="py-1.5 text-left" x-text="money(line.total)"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>

                {{-- الإجماليات --}}
                <div class="text-xs py-2 border-t border-dashed border-gray-300 space-y-1">
                    <div class="flex justify-between"><span class="text-gray-500">{{ __('المجموع الفرعي') }}</span><span x-text="money(current.subtotal)"></span></div>
                    <div class="flex justify-between"><span class="text-gray-500">{{ __('الخصم') }}</span><span x-text="'- ' + money(current.discount)"></span></div>
                    <div class="flex justify-between"><span class="text-gray-500">{{ __('الضريبة (5%)') }}</span><span x-text="money(current.tax)"></span></div>
                    <div class="flex justify-between text-sm font-extrabold pt-1.5 border-t border-gray-300 mt-1">
                        <span>{{ __('الإجمالي') }}</span><span x-text="money(current.total)"></span>
                    </div>
                    <div class="flex justify-between pt-1"><span class="text-gray-500">{{ __('وسيلة الدفع') }}</span><span class="font-medium" x-text="current.payment"></span></div>
                </div>

                {{-- التذييل --}}
                <div class="text-center text-xs text-gray-500 pt-3 border-t border-dashed border-gray-300 space-y-1">
                    @if ($biz?->phone)<p class="flex items-center justify-center gap-1"><x-icon name="phone" class="w-3 h-3" /> {{ $biz->phone }}</p>@endif
                    @if ($biz?->address)<p class="flex items-center justify-center gap-1"><x-icon name="map-pin" class="w-3 h-3" /> {{ $biz->address }}</p>@endif
                    <p class="font-bold text-gray-700 pt-2">{{ __('شكرًا لزيارتكم') }} 🌹</p>
                    <p class="text-[10px] text-gray-400">Abad POS — {{ __('نظام نقاط البيع') }}</p>
                </div>
            </div>
        </div>
    </div>
</x-layouts::pos>
