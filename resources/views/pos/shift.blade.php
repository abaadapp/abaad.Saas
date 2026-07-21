<x-layouts::pos title="الوردية">
    @php
        $shift = \App\Support\Demo::currentShift();
        $stats = [
            ['label' => 'الرصيد الافتتاحي', 'value' => $shift['opening'], 'icon' => 'wallet', 'color' => 'primary'],
            ['label' => 'المبيعات النقدية', 'value' => $shift['cash_sales'], 'icon' => 'banknote', 'color' => 'success'],
            ['label' => 'مبيعات البطاقة', 'value' => $shift['card_sales'], 'icon' => 'credit-card', 'color' => 'info'],
            ['label' => 'المرتجعات', 'value' => $shift['returns'], 'icon' => 'undo-2', 'color' => 'warning'],
            ['label' => 'المصروفات', 'value' => $shift['expenses'], 'icon' => 'arrow-down-circle', 'color' => 'danger'],
            ['label' => 'الرصيد المتوقع', 'value' => $shift['expected'], 'icon' => 'calculator', 'color' => 'secondary'],
        ];
    @endphp

    <div class="h-full overflow-y-auto p-4 sm:p-6"
         x-data="{
            expected: {{ $shift['expected'] }},
            actual: 0,
            elapsed: '00:00:00',
            get diff() { return this.actual - this.expected; },
            money(v) { return Number(v).toFixed(3) + ' ر.ع'; },
            init() {
                const start = new Date(); start.setHours(8, 0, 0, 0);
                const tick = () => {
                    let s = Math.floor((Date.now() - start.getTime()) / 1000);
                    if (s < 0) s = 0;
                    const h = String(Math.floor(s / 3600)).padStart(2, '0');
                    const m = String(Math.floor((s % 3600) / 60)).padStart(2, '0');
                    const sec = String(s % 60).padStart(2, '0');
                    this.elapsed = `${h}:${m}:${sec}`;
                };
                tick();
                setInterval(tick, 1000);
            }
         }">

        {{-- عنوان --}}
        <div class="flex items-center gap-3 mb-6">
            <span class="w-11 h-11 rounded-xl bg-gray-100 text-gray-900 flex items-center justify-center">
                <x-icon name="clock" class="w-6 h-6" />
            </span>
            <div>
                <h1 class="text-xl font-bold text-gray-800">الوردية الحالية</h1>
                <p class="text-sm text-gray-400">إدارة ومتابعة وردية العمل</p>
            </div>
        </div>

        @if (! $shift['exists'])
            {{-- فتح وردية جديدة --}}
            <form method="POST" action="{{ route('pos.shift.open') }}" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 max-w-lg">
                @csrf
                <div class="flex items-center gap-2 mb-4">
                    <x-icon name="log-in" class="w-5 h-5 text-success-500" />
                    <h2 class="font-bold text-gray-800">فتح وردية جديدة</h2>
                </div>
                <p class="text-sm text-gray-500 mb-4">أدخل الرصيد النقدي الافتتاحي في الصندوق لبدء الوردية.</p>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">الرصيد الافتتاحي (ر.ع)</label>
                <input type="number" step="0.001" min="0" name="opening_balance" value="0" required
                    class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2.5 text-lg font-bold text-gray-800 focus:border-gray-900 focus:ring-2 focus:ring-gray-200 focus:outline-none" />
                <button type="submit" class="mt-5 w-full bg-gray-900 hover:bg-black text-white font-medium rounded-full py-3">فتح الوردية</button>
            </form>
        @else
        {{-- حالة الوردية --}}
        <div class="bg-gray-900 rounded-2xl shadow-sm p-5 text-white mb-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <img src="https://picsum.photos/seed/posuser/80/80" class="w-14 h-14 rounded-xl object-cover ring-2 ring-white/30" alt="الموظف" />
                    <div>
                        <p class="text-lg font-bold">{{ $shift['employee'] }}</p>
                        <p class="text-sm text-white/70">كاشير — الفرع الرئيسي</p>
                    </div>
                </div>
                <div class="flex items-center gap-6">
                    <div class="text-center">
                        <p class="text-xs text-white/70">وقت البداية</p>
                        <p class="font-bold flex items-center gap-1"><x-icon name="log-in" class="w-4 h-4" /> {{ $shift['opened_at'] }}</p>
                    </div>
                    <div class="text-center">
                        <p class="text-xs text-white/70">مدة الوردية</p>
                        <p class="font-bold text-xl tabular-nums" x-text="elapsed"></p>
                    </div>
                    <span class="flex items-center gap-1.5 bg-white/15 rounded-full px-3 py-1.5 text-sm font-medium">
                        <span class="w-2 h-2 rounded-full bg-success-400 animate-pulse"></span> مفتوحة
                    </span>
                </div>
            </div>
        </div>

        {{-- بطاقات الأرقام --}}
        <div class="grid grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
            @foreach ($stats as $s)
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
                    <div class="flex items-center justify-between mb-3">
                        <span class="w-10 h-10 rounded-xl bg-{{ $s['color'] }}-50 text-{{ $s['color'] }}-600 flex items-center justify-center">
                            <x-icon name="{{ $s['icon'] }}" class="w-5 h-5" />
                        </span>
                    </div>
                    <p class="text-sm text-gray-400">{{ $s['label'] }}</p>
                    <p class="text-lg font-extrabold text-gray-800 mt-0.5">{{ \App\Support\Demo::money($s['value']) }}</p>
                </div>
            @endforeach
        </div>

        {{-- إغلاق الوردية --}}
        <form method="POST" action="{{ route('pos.shift.close') }}">
        @csrf
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 max-w-2xl">
            <div class="flex items-center gap-2 mb-4">
                <x-icon name="lock" class="w-5 h-5 text-danger-500" />
                <h2 class="font-bold text-gray-800">إغلاق الوردية</h2>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-end">
                <div class="bg-gray-50 rounded-xl px-4 py-3">
                    <p class="text-xs text-gray-400">الرصيد المتوقع</p>
                    <p class="font-bold text-gray-800 text-lg" x-text="money(expected)"></p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">الرصيد الفعلي</label>
                    <input type="number" step="0.001" name="actual_balance" x-model.number="actual"
                           class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2.5 text-lg font-bold text-gray-800 focus:border-gray-900 focus:ring-2 focus:ring-gray-200 focus:outline-none" />
                </div>
                <div class="rounded-xl px-4 py-3"
                     :class="diff >= 0 ? 'bg-success-50' : 'bg-danger-50'">
                    <p class="text-xs" :class="diff >= 0 ? 'text-success-600' : 'text-danger-600'">الفرق</p>
                    <p class="font-bold text-lg" :class="diff >= 0 ? 'text-success-700' : 'text-danger-700'"
                       x-text="(diff >= 0 ? '+ ' : '- ') + money(Math.abs(diff))"></p>
                </div>
            </div>
            <div class="flex items-center justify-end gap-2 mt-5 pt-4 border-t border-gray-100">
                <x-button variant="outline" icon="printer" onclick="window.print()">طباعة التقرير</x-button>
                <x-button variant="danger" icon="lock"
                          @click="$dispatch('open-modal','close-shift')">إغلاق الوردية</x-button>
            </div>
        </div>

        {{-- تأكيد الإغلاق --}}
        <x-modal name="close-shift" title="تأكيد إغلاق الوردية" maxWidth="max-w-md">
            <div class="text-center py-2">
                <div class="w-16 h-16 mx-auto rounded-full bg-danger-50 flex items-center justify-center text-danger-500 mb-3">
                    <x-icon name="alert-triangle" class="w-8 h-8" />
                </div>
                <p class="text-gray-700">هل أنت متأكد من إغلاق الوردية الحالية؟</p>
                <p class="text-sm text-gray-400 mt-1">لن تتمكن من تسجيل عمليات بيع جديدة بعد الإغلاق.</p>
            </div>
            <x-slot:footer>
                <x-button variant="outline" @click="$dispatch('close-modal')">تراجع</x-button>
                <x-button variant="danger" type="submit" icon="lock">تأكيد الإغلاق</x-button>
            </x-slot:footer>
        </x-modal>
        </form>
        @endif

        {{-- سجل الورديات المغلقة --}}
        @php $history = \App\Support\Demo::shiftHistory(); @endphp
        @if (count($history))
            <div class="mt-8">
                <h2 class="font-bold text-gray-800 mb-3 flex items-center gap-2"><x-icon name="history" class="w-5 h-5 text-gray-400" /> سجل الورديات السابقة</h2>
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-500">
                            <tr>
                                <th class="px-4 py-2.5 text-right font-medium">الموظف</th>
                                <th class="px-4 py-2.5 text-right font-medium">الفتح</th>
                                <th class="px-4 py-2.5 text-right font-medium">الإغلاق</th>
                                <th class="px-4 py-2.5 text-right font-medium">نقدي</th>
                                <th class="px-4 py-2.5 text-right font-medium">المتوقع</th>
                                <th class="px-4 py-2.5 text-right font-medium">الفعلي</th>
                                <th class="px-4 py-2.5 text-right font-medium">الفرق</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @foreach ($history as $h)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ $h['employee'] }}</td>
                                    <td class="px-4 py-3 text-gray-500 whitespace-nowrap" dir="ltr">{{ $h['opened'] }}</td>
                                    <td class="px-4 py-3 text-gray-500 whitespace-nowrap" dir="ltr">{{ $h['closed'] }}</td>
                                    <td class="px-4 py-3 text-gray-700 whitespace-nowrap">{{ \App\Support\Demo::money($h['cash_sales']) }}</td>
                                    <td class="px-4 py-3 text-gray-700 whitespace-nowrap">{{ \App\Support\Demo::money($h['expected']) }}</td>
                                    <td class="px-4 py-3 text-gray-700 whitespace-nowrap">{{ \App\Support\Demo::money($h['actual']) }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap font-semibold {{ $h['difference'] == 0 ? 'text-gray-500' : ($h['difference'] > 0 ? 'text-success-600' : 'text-danger-600') }}">
                                        {{ $h['difference'] > 0 ? '+' : '' }}{{ \App\Support\Demo::money($h['difference']) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</x-layouts::pos>
