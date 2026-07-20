<x-layouts::admin title="معاينة استيراد العملاء">
    <x-page-header
        title="معاينة قبل التأكيد"
        subtitle="راجع البيانات المستوردة من الملف قبل حفظها نهائيًا"
        :breadcrumbs="['الرئيسية' => route('admin.dashboard'), 'العملاء' => route('admin.customers.index'), 'معاينة الاستيراد' => '#']"
    >
        <x-slot:actions>
            <form method="POST" action="{{ route('admin.customers.import.cancel') }}">
                @csrf
                <x-button variant="outline" size="md" type="submit" icon="x">إلغاء</x-button>
            </form>
            <form method="POST" action="{{ route('admin.customers.import.confirm') }}"
                  @submit="if(!confirm('تأكيد استيراد {{ $counts['new'] }} عميلًا؟')) $event.preventDefault()">
                @csrf
                <x-button variant="primary" size="md" type="submit" icon="check" :disabled="$counts['new'] === 0">
                    تأكيد الاستيراد ({{ $counts['new'] }})
                </x-button>
            </form>
        </x-slot:actions>
    </x-page-header>

    {{-- ملخص --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
            <p class="text-xs text-gray-400 mb-1">إجمالي الصفوف</p>
            <p class="text-2xl font-bold text-gray-900">{{ $counts['total'] }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
            <p class="text-xs text-gray-400 mb-1">سيُضاف</p>
            <p class="text-2xl font-bold text-green-600">{{ $counts['new'] }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
            <p class="text-xs text-gray-400 mb-1">مكرر (يُتجاهل)</p>
            <p class="text-2xl font-bold text-amber-500">{{ $counts['duplicate'] }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
            <p class="text-xs text-gray-400 mb-1">غير صالح (يُتجاهل)</p>
            <p class="text-2xl font-bold text-red-600">{{ $counts['invalid'] }}</p>
        </div>
    </div>

    {{-- معلومات الملف والفرع --}}
    <div class="flex flex-wrap items-center gap-3 mb-5 text-sm">
        <span class="inline-flex items-center gap-2 bg-gray-100 text-gray-700 rounded-lg px-3 py-1.5">
            <x-icon name="file" class="w-4 h-4 text-gray-500" /> {{ $file }}
        </span>
        <span class="inline-flex items-center gap-2 bg-gray-100 text-gray-700 rounded-lg px-3 py-1.5">
            <x-icon name="git-branch" class="w-4 h-4 text-gray-500" />
            الفرع: <strong>{{ $branch?->name ?? 'بدون فرع (عام)' }}</strong>
        </span>
    </div>

    {{-- جدول المعاينة --}}
    <x-table :headers="['#', 'الاسم', 'الهاتف', 'البريد', 'العنوان', 'النقاط', 'الحالة']">
        @foreach ($rows as $i => $row)
            @php
                $rowClass = match ($row['status']) {
                    'new' => '',
                    'duplicate' => 'bg-amber-50/50',
                    'invalid' => 'bg-red-50/50',
                    default => '',
                };
                $badge = match ($row['status']) {
                    'new' => ['success', 'سيُضاف'],
                    'duplicate' => ['warning', 'مكرر'],
                    'invalid' => ['danger', 'غير صالح'],
                    default => ['info', '—'],
                };
            @endphp
            <tr class="{{ $rowClass }}">
                <td class="px-4 py-2.5 text-gray-400 text-xs">{{ $i + 1 }}</td>
                <td class="px-4 py-2.5 font-medium text-gray-800 whitespace-nowrap">{{ $row['name'] ?: '—' }}</td>
                <td class="px-4 py-2.5 text-gray-600 whitespace-nowrap" dir="ltr">{{ $row['phone'] ?: '—' }}</td>
                <td class="px-4 py-2.5 text-gray-500 whitespace-nowrap" dir="ltr">{{ $row['email'] ?: '—' }}</td>
                <td class="px-4 py-2.5 text-gray-500">{{ $row['address'] ?: '—' }}</td>
                <td class="px-4 py-2.5 text-gray-600 whitespace-nowrap">{{ $row['points'] }}</td>
                <td class="px-4 py-2.5 whitespace-nowrap">
                    <x-badge :type="$badge[0]" :text="$badge[1]" />
                    @if ($row['status'] !== 'new')
                        <span class="text-xs text-gray-400 block mt-0.5">{{ $row['note'] }}</span>
                    @endif
                </td>
            </tr>
        @endforeach
    </x-table>

    @if ($counts['new'] === 0)
        <div class="flex items-center gap-2 bg-amber-50 text-amber-700 rounded-xl px-4 py-3 mt-5 text-sm">
            <x-icon name="alert-triangle" class="w-5 h-5" />
            لا يوجد أي صف صالح للإضافة. راجع الملف وأعد المحاولة.
        </div>
    @endif
</x-layouts::admin>
