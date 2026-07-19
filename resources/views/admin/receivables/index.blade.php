<x-layouts::admin title="الذمم (البيع الآجل)">

    <x-page-header title="الذمم — البيع الآجل" subtitle="متابعة مستحقّات العملاء وحدود الائتمان والسداد"
        :breadcrumbs="['الرئيسية' => route('admin.dashboard'), 'الذمم' => '#']">
        <x-slot:actions>
            <x-button variant="primary" icon="plus" x-data @click="$dispatch('open-modal','record-debt')">تسجيل دين</x-button>
        </x-slot:actions>
    </x-page-header>

    @php
        $stats = \App\Support\Demo::receivablesStats();
        $rows = \App\Support\Demo::receivables();
        $customers = \App\Support\Demo::customers();
    @endphp

    <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-6">
        <x-stat-card label="إجمالي المستحقّات" :value="\App\Support\Demo::money($stats['total_due'])" icon="hand-coins" color="primary" />
        <x-stat-card label="عدد المدينين" value="{{ $stats['debtors'] }}" icon="users" color="info" />
        <x-stat-card label="لديهم متأخرات" value="{{ $stats['overdue'] }}" icon="alarm-clock" color="danger" />
        <x-stat-card label="تجاوزوا حدّ الائتمان" value="{{ $stats['over_limit'] }}" icon="alert-octagon" color="warning" />
    </div>

    @if (count($rows))
        <div x-data="{ pay: { id: '', name: '', balance: 0 }, lim: { id: '', name: '', limit: 0 }, open: null }">
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                <x-table :headers="['العميل', 'الهاتف', 'الرصيد المستحقّ', 'حدّ الائتمان', 'الحالة', 'إجراءات']">
                    @foreach ($rows as $r)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ $r['name'] }}</td>
                            <td class="px-4 py-3 text-gray-500 whitespace-nowrap" dir="ltr">{{ $r['phone'] ?: '—' }}</td>
                            <td class="px-4 py-3 font-bold text-danger-600 whitespace-nowrap">{{ \App\Support\Demo::money($r['balance']) }}</td>
                            <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $r['limit'] > 0 ? \App\Support\Demo::money($r['limit']) : '—' }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if ($r['has_overdue'])<x-badge type="danger" text="متأخر" />@endif
                                @if ($r['over_limit'])<x-badge type="warning" text="تجاوز الحدّ" />@endif
                                @if (!$r['has_overdue'] && !$r['over_limit'])<x-badge type="success" text="منتظم" />@endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="flex items-center gap-1.5">
                                    <button type="button" @click="pay = { id: {{ $r['id'] }}, name: @js($r['name']), balance: {{ $r['balance'] }} }; $dispatch('open-modal','record-payment')" class="inline-flex items-center gap-1 text-xs px-2.5 py-1 rounded-lg bg-success-600 text-white hover:bg-success-700"><x-icon name="banknote" class="w-3.5 h-3.5" /> سداد</button>
                                    <button type="button" @click="lim = { id: {{ $r['id'] }}, name: @js($r['name']), limit: {{ $r['limit'] }} }; $dispatch('open-modal','set-limit')" class="text-xs px-2.5 py-1 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50">حدّ الائتمان</button>
                                    <button type="button" @click="open = (open === {{ $r['id'] }} ? null : {{ $r['id'] }})" class="text-xs px-2 py-1 rounded-lg text-primary-600 hover:bg-primary-50">الدفتر</button>
                                </div>
                            </td>
                        </tr>
                        {{-- دفتر الحساب --}}
                        <tr x-show="open === {{ $r['id'] }}" x-cloak>
                            <td colspan="6" class="px-4 py-3 bg-gray-50/70">
                                @php $ledger = \App\Support\Demo::customerLedger($r['id']); @endphp
                                <table class="w-full text-xs">
                                    <thead class="text-gray-500"><tr><th class="text-right py-1">التاريخ</th><th class="text-right py-1">النوع</th><th class="text-right py-1">البيان</th><th class="text-right py-1">الاستحقاق</th><th class="text-left py-1">المبلغ</th></tr></thead>
                                    <tbody>
                                        @foreach ($ledger as $e)
                                            <tr class="border-t border-gray-100">
                                                <td class="py-1.5 text-gray-500" dir="ltr">{{ $e['date'] }}</td>
                                                <td class="py-1.5"><span class="px-1.5 py-0.5 rounded {{ $e['type'] === 'دين' ? 'bg-danger-50 text-danger-600' : 'bg-success-50 text-success-600' }}">{{ $e['type'] }}</span></td>
                                                <td class="py-1.5 text-gray-600">{{ $e['note'] ?: ($e['order'] ? 'طلب ' . $e['order'] : '—') }}{{ $e['method'] ? ' · ' . $e['method'] : '' }}</td>
                                                <td class="py-1.5 text-gray-400" dir="ltr">{{ $e['due'] ?: '—' }}</td>
                                                <td class="py-1.5 text-left font-semibold {{ $e['type'] === 'دين' ? 'text-danger-600' : 'text-success-600' }}">{{ $e['type'] === 'دين' ? '+' : '-' }}{{ \App\Support\Demo::money($e['amount']) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    @endforeach
                </x-table>
            </div>

            {{-- نافذة تسجيل سداد --}}
            <x-modal name="record-payment" title="تسجيل سداد">
                <form :action="`{{ url('admin/receivables') }}/${pay.id}/payment`" method="POST" id="payment-form" class="space-y-4">
                    @csrf
                    <div class="rounded-xl bg-gray-50 border border-gray-100 p-3 text-sm">
                        العميل: <span class="font-semibold" x-text="pay.name"></span> — المستحقّ: <span class="font-bold text-danger-600" x-text="pay.balance.toLocaleString('ar',{minimumFractionDigits:3}) + ' ر.ع'"></span>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600 mb-1">المبلغ المسدَّد <span class="text-danger-500">*</span></label>
                        <input type="number" step="0.001" min="0.001" :max="pay.balance" name="amount" required class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200" />
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600 mb-1">طريقة السداد</label>
                        <select name="method" class="w-full rounded-lg border-gray-200"><option>نقدي</option><option>تحويل بنكي</option><option>بطاقة</option></select>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600 mb-1">ملاحظة</label>
                        <input type="text" name="note" class="w-full rounded-lg border-gray-200" />
                    </div>
                </form>
                <x-slot:footer>
                    <x-button variant="light" @click="$dispatch('close-modal')">إلغاء</x-button>
                    <button type="submit" form="payment-form" class="bg-success-600 hover:bg-success-700 text-white text-sm font-medium rounded-lg px-4 py-2">تسجيل السداد</button>
                </x-slot:footer>
            </x-modal>

            {{-- نافذة حدّ الائتمان --}}
            <x-modal name="set-limit" title="حدّ الائتمان">
                <form :action="`{{ url('admin/receivables') }}/${lim.id}/limit`" method="POST" id="limit-form" class="space-y-4">
                    @csrf
                    <p class="text-sm text-gray-600">العميل: <span class="font-semibold" x-text="lim.name"></span></p>
                    <div>
                        <label class="block text-sm text-gray-600 mb-1">حدّ الائتمان المسموح (ر.ع)</label>
                        <input type="number" step="0.001" min="0" name="credit_limit" :value="lim.limit" class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200" />
                        <p class="text-xs text-gray-400 mt-1">اجعله 0 لعدم فرض حدّ.</p>
                    </div>
                </form>
                <x-slot:footer>
                    <x-button variant="light" @click="$dispatch('close-modal')">إلغاء</x-button>
                    <button type="submit" form="limit-form" class="bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-lg px-4 py-2">حفظ</button>
                </x-slot:footer>
            </x-modal>
        </div>
    @else
        <x-empty-state icon="hand-coins" title="لا توجد مستحقّات" message="لا يوجد عملاء عليهم ديون حاليًا. سجّل دينًا لبدء المتابعة.">
            <x-button variant="primary" icon="plus" x-data @click="$dispatch('open-modal','record-debt')">تسجيل دين</x-button>
        </x-empty-state>
    @endif

    {{-- نافذة تسجيل دين (لأي عميل) --}}
    <x-modal name="record-debt" title="تسجيل دين على عميل">
        <form method="POST" id="debt-form" x-data="{ cid: '' }" :action="`{{ url('admin/receivables') }}/${cid}/debt`" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm text-gray-600 mb-1">العميل <span class="text-danger-500">*</span></label>
                <select x-model="cid" required class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200">
                    <option value="">— اختر عميلًا —</option>
                    @foreach ($customers as $c)
                        <option value="{{ $c['id'] }}">{{ $c['name'] }}{{ $c['phone'] ? ' — ' . $c['phone'] : '' }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="block text-sm text-gray-600 mb-1">المبلغ (ر.ع) <span class="text-danger-500">*</span></label><input type="number" step="0.001" min="0.001" name="amount" required class="w-full rounded-lg border-gray-200" /></div>
                <div><label class="block text-sm text-gray-600 mb-1">تاريخ الاستحقاق</label><input type="date" name="due_at" class="w-full rounded-lg border-gray-200" /></div>
            </div>
            <div><label class="block text-sm text-gray-600 mb-1">رقم الطلب (اختياري)</label><input type="text" name="order_number" class="w-full rounded-lg border-gray-200" /></div>
            <div><label class="block text-sm text-gray-600 mb-1">ملاحظة</label><input type="text" name="note" class="w-full rounded-lg border-gray-200" /></div>
        </form>
        <x-slot:footer>
            <x-button variant="light" @click="$dispatch('close-modal')">إلغاء</x-button>
            <button type="submit" form="debt-form" class="bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-lg px-4 py-2">تسجيل الدين</button>
        </x-slot:footer>
    </x-modal>

</x-layouts::admin>
