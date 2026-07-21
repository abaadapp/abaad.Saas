<x-layouts::admin title="كشف الحساب البنكي">
    <x-page-header title="كشف الحساب البنكي" subtitle="حساب الشركة البنكي ومطابقته مع معاملات النظام"
        :breadcrumbs="['الرئيسية' => route('admin.dashboard'), 'المالية' => route('admin.finance.index'), 'كشف الحساب' => '#']" />

    @php
        $account = \App\Support\Demo::bankAccount();
        $statement = \App\Support\Demo::bankStatement();
        $lines = \App\Support\Demo::bankLines();
        $sum = \App\Support\Demo::reconciliationSummary();
    @endphp

    <div x-data="{ tab: 'statement' }">
        {{-- التبويبات --}}
        <div class="flex items-center gap-1 border-b border-gray-200 mb-6 overflow-x-auto">
            @foreach ([['statement', 'كشف الحساب'], ['reconcile', 'المطابقة مع البنك'], ['account', 'بيانات الحساب']] as [$key, $label])
                <button type="button" @click="tab = '{{ $key }}'"
                    x-bind:class="tab === '{{ $key }}' ? 'text-gray-900 border-gray-900' : 'text-gray-500 border-transparent hover:text-gray-700'"
                    class="px-4 py-3 text-sm font-medium border-b-2 -mb-px whitespace-nowrap transition-colors">{{ $label }}</button>
            @endforeach
        </div>

        {{-- ===== كشف الحساب المحسوب ===== --}}
        <div x-show="tab === 'statement'">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                <x-stat-card label="الرصيد الافتتاحي" :value="\App\Support\Demo::money($statement['opening'])" icon="wallet" color="secondary" />
                <x-stat-card label="عدد الحركات" :value="count($statement['rows'])" icon="list" color="info" />
                <x-stat-card label="الرصيد الحالي" :value="\App\Support\Demo::money($statement['closing'])" icon="landmark" color="primary" />
            </div>

            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-right">
                        <thead class="bg-gray-50 text-gray-500">
                            <tr>
                                <th class="px-4 py-3 font-medium">المرجع</th>
                                <th class="px-4 py-3 font-medium">التاريخ</th>
                                <th class="px-4 py-3 font-medium">البيان</th>
                                <th class="px-4 py-3 font-medium">مدين</th>
                                <th class="px-4 py-3 font-medium">دائن</th>
                                <th class="px-4 py-3 font-medium">الرصيد</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr class="bg-gray-50/60">
                                <td class="px-4 py-3 text-gray-400" colspan="5">رصيد افتتاحي {{ $account['opening_date'] ? '— ' . $account['opening_date'] : '' }}</td>
                                <td class="px-4 py-3 font-semibold text-gray-800">{{ \App\Support\Demo::money($statement['opening']) }}</td>
                            </tr>
                            @foreach ($statement['rows'] as $r)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 font-mono text-xs text-gray-600 whitespace-nowrap">{{ $r['reference'] }}</td>
                                    <td class="px-4 py-3 text-gray-500 whitespace-nowrap" dir="ltr">{{ $r['date'] }}</td>
                                    <td class="px-4 py-3 text-gray-800">{{ $r['description'] }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-danger-600">{{ $r['debit'] ? \App\Support\Demo::money($r['debit']) : '—' }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-success-600">{{ $r['credit'] ? \App\Support\Demo::money($r['credit']) : '—' }}</td>
                                    <td class="px-4 py-3 font-semibold text-gray-800 whitespace-nowrap">{{ \App\Support\Demo::money($r['balance']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-gray-50 font-semibold text-gray-800">
                            <tr>
                                <td class="px-4 py-3" colspan="5">الرصيد الختامي</td>
                                <td class="px-4 py-3">{{ \App\Support\Demo::money($statement['closing']) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <p class="mt-3 text-xs text-gray-400">الرصيد يُحتسب تلقائيًا: الرصيد الافتتاحي + الإيرادات − المصروفات، مرتّبًا بالتاريخ.</p>
        </div>

        {{-- ===== المطابقة مع كشف البنك ===== --}}
        <div x-show="tab === 'reconcile'" x-cloak>
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 mb-6">
                <h3 class="font-bold text-gray-800 mb-2">استيراد كشف البنك</h3>
                <p class="text-sm text-gray-500 mb-4">
                    ارفع ملف الكشف من بنكك ليطابقه النظام تلقائيًا مع معاملاته.
                    يجب أن يحتوي الملف على عمودَي <strong>التاريخ</strong> و<strong>المبلغ</strong> (وأعمدة البيان والمرجع اختيارية).
                    المبلغ الموجب = وارد، والسالب = صادر.
                </p>
                <form method="POST" action="{{ route('admin.bank.import') }}" enctype="multipart/form-data" x-data="{ fileName: '' }"
                      @submit="if(!fileName){ $event.preventDefault(); $store.toasts.add('اختر ملف الكشف أولًا','warning'); }">
                    @csrf
                    <label class="flex flex-col items-center justify-center gap-2 border-2 border-dashed border-gray-200 rounded-2xl p-6 text-center cursor-pointer hover:border-gray-300 transition">
                        <x-icon name="upload-cloud" class="w-8 h-8 text-gray-400" />
                        <span class="text-sm text-gray-600" x-text="fileName || 'اختر ملف كشف البنك'"></span>
                        <span class="text-xs text-gray-400">XLSX · XLS · XLSM · CSV — حتى 10 ميجابايت</span>
                        <input type="file" name="statement" class="hidden" accept=".xlsx,.xls,.xlsm,.csv"
                               @change="fileName = $event.target.files[0]?.name || ''" />
                    </label>
                    @error('statement')<p class="mt-2 text-xs text-danger-500">{{ $message }}</p>@enderror
                    <div class="mt-4 flex items-center gap-2">
                        <x-button variant="primary" size="md" icon="upload" type="submit">استيراد ومطابقة</x-button>
                        @if ($sum['lines'])
                            <form method="POST" action="{{ route('admin.bank.rematch') }}">@csrf
                                <x-button variant="outline" size="md" icon="refresh-cw" type="submit">إعادة المطابقة</x-button>
                            </form>
                            <form method="POST" action="{{ route('admin.bank.clear') }}" @submit.prevent="if(confirm('حذف الكشف المستورد؟')) $el.submit()">
                                @csrf @method('DELETE')
                                <x-button variant="ghost" size="md" icon="trash-2" type="submit" class="text-danger-600">حذف الكشف</x-button>
                            </form>
                        @endif
                    </div>
                </form>
            </div>

            @if ($sum['lines'])
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                    <x-stat-card label="أسطر الكشف" :value="$sum['lines']" icon="list" color="secondary" />
                    <x-stat-card label="مطابَقة" :value="$sum['matched']" icon="check-circle" color="success" />
                    <x-stat-card label="في البنك ولا يقابلها معاملة" :value="$sum['unmatched_bank']" icon="alert-triangle" color="warning" />
                    <x-stat-card label="في النظام ولا يقابلها سطر بنكي" :value="$sum['unmatched_system']" icon="alert-circle" color="danger" />
                </div>

                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-right">
                            <thead class="bg-gray-50 text-gray-500">
                                <tr>
                                    <th class="px-4 py-3 font-medium">التاريخ</th>
                                    <th class="px-4 py-3 font-medium">البيان</th>
                                    <th class="px-4 py-3 font-medium">المرجع</th>
                                    <th class="px-4 py-3 font-medium">المبلغ</th>
                                    <th class="px-4 py-3 font-medium">الحالة</th>
                                    <th class="px-4 py-3 font-medium">المعاملة المطابِقة</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($lines as $l)
                                    <tr class="hover:bg-gray-50 {{ $l['matched'] ? '' : 'bg-warning-50/40' }}">
                                        <td class="px-4 py-3 text-gray-500 whitespace-nowrap" dir="ltr">{{ $l['date'] }}</td>
                                        <td class="px-4 py-3 text-gray-800">{{ $l['description'] }}</td>
                                        <td class="px-4 py-3 font-mono text-xs text-gray-500">{{ $l['reference'] }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap font-semibold {{ $l['amount'] >= 0 ? 'text-success-600' : 'text-danger-600' }}">
                                            {{ \App\Support\Demo::money(abs($l['amount'])) }}
                                            <span class="text-xs text-gray-400">{{ $l['amount'] >= 0 ? 'وارد' : 'صادر' }}</span>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <x-badge :type="$l['matched'] ? 'success' : 'warning'" :text="$l['status']" />
                                        </td>
                                        <td class="px-4 py-3 font-mono text-xs text-gray-600">{{ $l['transaction'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                <p class="mt-3 text-xs text-gray-400">
                    قاعدة المطابقة: نفس المبلغ (بفارق لا يتجاوز 0.005 ر.ع) وتاريخ ضمن ±3 أيام، وكل معاملة تُستخدم مرة واحدة.
                </p>
            @else
                <x-empty-state icon="landmark" title="لم يُستورد كشف بنك بعد"
                               message="ارفع ملف كشف الحساب من بنكك ليطابقه النظام تلقائيًا مع معاملاته ويُظهر الفروقات." />
            @endif
        </div>

        {{-- ===== بيانات الحساب ===== --}}
        <div x-show="tab === 'account'" x-cloak>
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 max-w-2xl">
                <h3 class="font-bold text-gray-800 mb-5">بيانات حساب الشركة البنكي</h3>
                <form method="POST" action="{{ route('admin.bank.account') }}" class="space-y-4">
                    @csrf
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-input label="اسم البنك" name="bank_name" :value="$account['bank_name']" placeholder="بنك مسقط" icon="landmark" />
                        <x-input label="اسم الحساب" name="account_name" :value="$account['account_name']" placeholder="اسم الشركة" icon="user" />
                    </div>
                    <x-input label="رقم الآيبان (IBAN)" name="iban" :value="$account['iban']" placeholder="OM00 0000 0000 0000 0000 0000" icon="hash" />
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-input label="الرصيد الافتتاحي (ر.ع)" name="opening_balance" type="number" step="0.001"
                                 :value="$account['opening_balance']" icon="wallet" />
                        <x-input label="تاريخ الرصيد الافتتاحي" name="opening_date" type="date" :value="$account['opening_date']" />
                    </div>
                    <div class="flex justify-end">
                        <x-button variant="primary" size="md" icon="save" type="submit">حفظ البيانات</x-button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-layouts::admin>
