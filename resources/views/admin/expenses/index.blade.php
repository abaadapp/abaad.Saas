@php
    $expenses = \App\Support\Demo::expenses();
    $total = collect($expenses)->sum('amount');
    $count = count($expenses);
    $avg = $count ? $total / $count : 0;

    // أنواع المصروفات (تُغذّي قوائم الاختيار والفلترة)
    $expenseTypes = \App\Support\Demo::expenseTypes();
    $typeOptions = collect($expenseTypes)->pluck('name', 'name')->toArray();
@endphp

<x-layouts::admin title="المصروفات">
    <x-page-header
        title="المصروفات"
        subtitle="تسجيل ومتابعة مصروفات المحل التشغيلية"
        :breadcrumbs="['الرئيسية' => route('admin.dashboard'), 'المصروفات' => '#']"
    >
        <x-slot:actions>
            <x-button variant="outline" size="md" icon="download" :href="route('admin.export.expenses')">تصدير CSV</x-button>
            <x-button variant="primary" size="md" icon="plus" x-on:click="$dispatch('open-modal','add-expense')">إضافة مصروف</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- البطاقات الإحصائية --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <x-stat-card label="إجمالي المصروفات" :value="\App\Support\Demo::money($total)" icon="wallet" trend="-5%" :up="false" color="danger" />
        <x-stat-card label="مصروفات هذا الشهر" :value="\App\Support\Demo::money($total)" icon="calendar" color="warning" />
        <x-stat-card label="متوسط المصروف" :value="\App\Support\Demo::money($avg)" icon="calculator" color="info" />
        <x-stat-card label="عدد المصروفات" :value="$count" icon="receipt" color="primary" />
    </div>

    {{-- قسم أنواع المصروفات --}}
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 mb-6" x-data="{ adding: {{ $errors->any() ? 'true' : 'false' }} }">
        <div class="flex items-center justify-between mb-5">
            <div class="flex items-center gap-2">
                <span class="w-9 h-9 rounded-xl bg-secondary-50 text-secondary-600 flex items-center justify-center">
                    <x-icon name="tags" class="w-5 h-5" />
                </span>
                <div>
                    <h3 class="text-lg font-bold text-gray-800">أنواع المصروفات</h3>
                    <p class="text-xs text-gray-400">تُستخدم عند تسجيل المصروفات وفلترتها</p>
                </div>
            </div>
            <x-button variant="primary" size="sm" icon="plus" type="button" x-on:click="adding = !adding">إضافة نوع</x-button>
        </div>

        {{-- نموذج إضافة نوع --}}
        <form x-show="adding" x-cloak method="POST" action="{{ route('admin.expenseTypes.store') }}"
              class="flex flex-col sm:flex-row gap-3 items-start mb-5 bg-gray-50 rounded-xl p-4">
            @csrf
            <div class="flex-1 w-full">
                <input type="text" name="name" placeholder="مثال: اشتراكات وبرمجيات" required
                       class="w-full rounded-xl border-gray-200 text-sm focus:border-primary-400 focus:ring-primary-200" />
                @error('name')<p class="text-xs text-danger-600 mt-1">{{ $message }}</p>@enderror
            </div>
            <x-button variant="primary" size="md" type="submit" icon="check">حفظ</x-button>
        </form>

        @if (count($expenseTypes))
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                @foreach ($expenseTypes as $t)
                    <div class="flex items-center justify-between gap-3 rounded-xl border border-gray-100 px-4 py-3 hover:bg-gray-50 transition">
                        <div class="min-w-0">
                            <p class="font-medium text-gray-800 truncate">{{ $t['name'] }}</p>
                            <p class="text-xs text-gray-400">
                                {{ $t['count'] }} مصروف
                                @if ($t['total'] > 0)
                                    — {{ \App\Support\Demo::money($t['total']) }}
                                @endif
                            </p>
                        </div>
                        <form method="POST" action="{{ route('admin.expenseTypes.destroy', $t['id']) }}"
                              @submit.prevent="if(confirm('حذف نوع «{{ $t['name'] }}»؟ لن تتأثر المصروفات المسجّلة سابقًا.')) $el.submit()">
                            @csrf @method('DELETE')
                            <button type="submit" class="w-8 h-8 rounded-lg text-gray-400 hover:bg-danger-50 hover:text-danger-600 flex items-center justify-center transition shrink-0">
                                <x-icon name="trash-2" class="w-4 h-4" />
                            </button>
                        </form>
                    </div>
                @endforeach
            </div>
        @else
            <x-empty-state icon="tags" title="لا توجد أنواع" message="أضف أول نوع مصروف لتستخدمه عند التسجيل." />
        @endif
    </div>

    <div x-data="listFilter()" x-ref="list">
    {{-- شريط الفلاتر --}}
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 mb-6">
        <div class="flex flex-col md:flex-row md:items-center gap-3">
            <div class="flex-1">
                <x-input name="search" placeholder="ابحث في وصف المصروف..." icon="search" x-model="q" @input="apply()" />
            </div>
            <div class="w-full md:w-48">
                <x-select name="type" placeholder="كل الأنواع" x-model="tag" @change="apply()" :options="$typeOptions" />
            </div>
            <div class="w-full md:w-44">
                <input type="date" class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-700 focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:outline-none transition" />
            </div>
            <x-button variant="light" size="md" icon="filter" @click="apply()">تصفية</x-button>
        </div>
    </div>

    {{-- جدول المصروفات --}}
    @php
        $methodColors = ['نقدي' => 'success', 'بطاقة' => 'info', 'تحويل بنكي' => 'primary'];
    @endphp
    <x-table :headers="['نوع المصروف', 'الوصف', 'المبلغ', 'التاريخ', 'الموظف', 'طريقة الدفع']">
        @foreach ($expenses as $expense)
            <tr class="hover:bg-gray-50" data-row data-tag="{{ $expense['type'] }}" data-search="{{ $expense['type'] }} {{ $expense['description'] }} {{ $expense['employee'] }}">
                <td class="px-4 py-3 whitespace-nowrap"><x-badge type="secondary" :text="$expense['type']" /></td>
                <td class="px-4 py-3 text-gray-700 whitespace-nowrap">{{ $expense['description'] }}</td>
                <td class="px-4 py-3 font-semibold text-danger-600 whitespace-nowrap">{{ \App\Support\Demo::money($expense['amount']) }}</td>
                <td class="px-4 py-3 text-gray-500 whitespace-nowrap" dir="ltr">{{ $expense['date'] }}</td>
                <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $expense['employee'] }}</td>
                <td class="px-4 py-3 whitespace-nowrap">
                    <x-badge :type="$methodColors[$expense['method']] ?? 'gray'" :text="$expense['method']" />
                </td>
            </tr>
        @endforeach

        <x-slot:footer>
            <x-pagination :total="$count" :perPage="10" :current="1" />
        </x-slot:footer>
    </x-table>
    </div>

    {{-- نافذة إضافة مصروف --}}
    <x-modal name="add-expense" title="إضافة مصروف جديد" maxWidth="max-w-lg">
        <form id="add-expense-form" method="POST" action="{{ route('admin.expenses.store') }}" class="space-y-4">
            @csrf
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-select label="نوع المصروف" name="type" placeholder="اختر النوع..." :options="$typeOptions" :required="true" />
                <x-input label="المبلغ (ر.ع)" name="amount" type="number" placeholder="0.000" icon="wallet" :required="true" />
            </div>
            <x-input label="الوصف" name="description" placeholder="مثال: إيجار المحل لشهر يوليو" icon="file-text" :required="true" />
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-input label="التاريخ" name="spent_at" type="date" value="2026-07-17" :required="true" />
                <x-select label="طريقة الدفع" name="method" :options="[
                    'نقدي' => 'نقدي',
                    'بطاقة' => 'بطاقة',
                    'تحويل بنكي' => 'تحويل بنكي',
                ]" selected="نقدي" />
            </div>
        </form>

        <x-slot:footer>
            <x-button variant="ghost" size="md" x-on:click="$dispatch('close-modal')">إلغاء</x-button>
            <x-button variant="primary" size="md" type="submit" form="add-expense-form" icon="check">حفظ المصروف</x-button>
        </x-slot:footer>
    </x-modal>
</x-layouts::admin>
