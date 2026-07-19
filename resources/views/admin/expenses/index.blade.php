@php
    $expenses = \App\Support\Demo::expenses();
    $total = collect($expenses)->sum('amount');
    $count = count($expenses);
    $avg = $count ? $total / $count : 0;
@endphp

<x-layouts::admin title="المصروفات">
    <x-page-header
        title="المصروفات"
        subtitle="تسجيل ومتابعة مصروفات المحل التشغيلية"
        :breadcrumbs="['الرئيسية' => route('admin.dashboard'), 'المصروفات' => '#']"
    >
        <x-slot:actions>
            <x-button variant="outline" size="md" icon="download">تصدير</x-button>
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

    {{-- شريط الفلاتر --}}
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 mb-6">
        <div class="flex flex-col md:flex-row md:items-center gap-3">
            <div class="flex-1">
                <x-input name="search" placeholder="ابحث في وصف المصروف..." icon="search" />
            </div>
            <div class="w-full md:w-48">
                <x-select name="type" placeholder="كل الأنواع" :options="[
                    'إيجار' => 'إيجار',
                    'رواتب' => 'رواتب',
                    'كهرباء وماء' => 'كهرباء وماء',
                    'مواد خام' => 'مواد خام',
                    'تسويق' => 'تسويق',
                    'صيانة' => 'صيانة',
                    'نقل وتوصيل' => 'نقل وتوصيل',
                ]" />
            </div>
            <div class="w-full md:w-44">
                <input type="date" class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-700 focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:outline-none transition" />
            </div>
            <x-button variant="light" size="md" icon="filter">تصفية</x-button>
        </div>
    </div>

    {{-- جدول المصروفات --}}
    @php
        $methodColors = ['نقدي' => 'success', 'بطاقة' => 'info', 'تحويل بنكي' => 'primary'];
    @endphp
    <x-table :headers="['نوع المصروف', 'الوصف', 'المبلغ', 'التاريخ', 'الموظف', 'طريقة الدفع']">
        @foreach ($expenses as $expense)
            <tr class="hover:bg-gray-50">
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

    {{-- نافذة إضافة مصروف --}}
    <x-modal name="add-expense" title="إضافة مصروف جديد" maxWidth="max-w-lg">
        <form id="add-expense-form" method="POST" action="{{ route('admin.expenses.store') }}" class="space-y-4">
            @csrf
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-select label="نوع المصروف" name="type" placeholder="اختر النوع..." :options="[
                    'إيجار' => 'إيجار',
                    'رواتب' => 'رواتب',
                    'كهرباء وماء' => 'كهرباء وماء',
                    'مواد خام' => 'مواد خام',
                    'تسويق' => 'تسويق',
                    'صيانة' => 'صيانة',
                    'نقل وتوصيل' => 'نقل وتوصيل',
                ]" :required="true" />
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
