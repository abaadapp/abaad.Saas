@php
    $activeTab = ($filters['tab'] ?? 'expenses') === 'types' ? 'types' : 'expenses';
    $typeOptions = collect($types)->pluck('name', 'name')->toArray();
    $statusColors = ['مدفوع' => 'success', 'غير مدفوع' => 'warning', 'ملغي' => 'danger'];
@endphp

<x-layouts::admin title="المصروفات">
    <x-page-header
        title="المصروفات"
        subtitle="إدارة المصروفات وأنواع المصروفات"
        :breadcrumbs="['الرئيسية' => route('admin.dashboard'), 'المصروفات' => '#']"
    >
        <x-slot:actions>
            <x-button variant="primary" size="md" icon="plus" x-on:click="$dispatch('open-modal','add-expense')">مصروف جديد</x-button>
        </x-slot:actions>
    </x-page-header>

    <div x-data="{ tab: '{{ $activeTab }}' }">
        {{-- التبويبات --}}
        <div class="flex items-center gap-1 border-b border-gray-200 mb-6">
            <button type="button" @click="tab = 'expenses'"
                :class="tab === 'expenses' ? 'text-gray-900 border-gray-900' : 'text-gray-500 border-transparent hover:text-gray-700'"
                class="px-4 py-3 text-sm font-medium border-b-2 -mb-px transition-colors">المصروفات</button>
            <button type="button" @click="tab = 'types'"
                :class="tab === 'types' ? 'text-gray-900 border-gray-900' : 'text-gray-500 border-transparent hover:text-gray-700'"
                class="px-4 py-3 text-sm font-medium border-b-2 -mb-px transition-colors">أنواع المصروفات</button>
        </div>

        {{-- ============ تبويب المصروفات ============ --}}
        <div x-show="tab === 'expenses'" x-data="{ showFilter: {{ (!empty($filters['type']) || !empty($filters['status'])) ? 'true' : 'false' }} }">
            {{-- شريط الأدوات --}}
            <form method="GET" class="mb-4">
                <input type="hidden" name="tab" value="expenses">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <div class="w-full sm:w-72">
                            <x-input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="البحث بالمرجع" icon="search" />
                        </div>
                        <x-button variant="outline" size="md" icon="sliders-horizontal" type="button" x-on:click="showFilter = !showFilter">تصفية</x-button>
                    </div>
                    <x-button variant="outline" size="md" icon="download" :href="route('admin.export.expenses')">تصدير بصيغة إكسل</x-button>
                </div>

                {{-- لوحة التصفية --}}
                <div x-show="showFilter" x-cloak class="mt-3 bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-end">
                        <x-select name="type" placeholder="كل الأنواع" :options="$typeOptions" selected="{{ $filters['type'] ?? '' }}" label="نوع المصروف" />
                        <x-select name="status" placeholder="كل الحالات" :options="['مدفوع' => 'مدفوع', 'غير مدفوع' => 'غير مدفوع']" selected="{{ $filters['status'] ?? '' }}" label="الحالة" />
                        <div class="flex items-center gap-2">
                            <x-button type="submit" size="md" icon="filter">تطبيق</x-button>
                            <x-button variant="outline" size="md" :href="route('admin.expenses.index')">تفريغ</x-button>
                        </div>
                    </div>
                </div>
            </form>

            @if ($expenses->total())
                <x-table :headers="['الرقم المرجعي', 'تاريخ الإستحقاق', 'أنواع المصروفات', 'المبلغ', 'الحالة', 'المرفق', 'ملاحظات', '']">
                    @foreach ($expenses as $e)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap font-mono">{{ $e->reference ?: '—' }}</td>
                            <td class="px-4 py-3 text-gray-600 whitespace-nowrap" dir="ltr">{{ optional($e->due_date)->format('Y-m-d') ?? '—' }}</td>
                            <td class="px-4 py-3 whitespace-nowrap"><x-badge type="secondary" :text="$e->type" /></td>
                            <td class="px-4 py-3 font-semibold text-gray-800 whitespace-nowrap">{{ \App\Support\Demo::money($e->amount) }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <x-badge :type="$statusColors[$e->status] ?? 'gray'" :text="$e->status" />
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if ($e->attachment)
                                    <a href="{{ \Illuminate\Support\Facades\Storage::url($e->attachment) }}" target="_blank"
                                       title="{{ $e->attachment_name }}"
                                       class="inline-flex items-center gap-1.5 text-sm text-gray-700 hover:text-gray-900 underline decoration-gray-300">
                                        <x-icon name="paperclip" class="w-4 h-4 text-gray-400" /> عرض
                                    </a>
                                @else
                                    <span class="text-gray-300">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-600">{{ $e->description ?: '—' }}</td>
                            <td class="px-4 py-3 whitespace-nowrap text-left">
                                <x-dropdown align="left" width="w-36">
                                    <x-slot:trigger>
                                        <button type="button" class="w-8 h-8 rounded-full hover:bg-gray-100 text-gray-400 flex items-center justify-center">
                                            <x-icon name="ellipsis-vertical" class="w-5 h-5" />
                                        </button>
                                    </x-slot:trigger>
                                    <form method="POST" action="{{ route('admin.expenses.destroy', $e->id) }}" @submit.prevent="if(confirm('حذف هذا المصروف؟')) $el.submit()">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-danger-600 hover:bg-danger-50">
                                            <x-icon name="trash-2" class="w-4 h-4" /> حذف
                                        </button>
                                    </form>
                                </x-dropdown>
                            </td>
                        </tr>
                    @endforeach

                    <x-slot:footer>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="text-sm text-gray-500">
                                المصروفات: {{ $totalCount }} — الإجمالي: <span class="font-semibold text-gray-800">{{ \App\Support\Demo::money($totalAmount) }}</span>
                            </p>
                            <x-pagination :paginator="$expenses" />
                        </div>
                    </x-slot:footer>
                </x-table>
            @else
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm">
                    <x-empty-state icon="folder-open" title="لا توجد مصروفات" message="أنشئ أول مصروف">
                        <x-button variant="primary" size="md" x-on:click="$dispatch('open-modal','add-expense')">مصروف جديد</x-button>
                    </x-empty-state>
                </div>
            @endif
        </div>

        {{-- ============ تبويب أنواع المصروفات ============ --}}
        <div x-show="tab === 'types'" x-cloak x-data="listFilter()" x-ref="list">
            {{-- شريط الأدوات --}}
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
                <div class="w-full sm:w-72">
                    <x-input name="type-search" placeholder="بحث" icon="search" x-model="q" @input="apply()" />
                </div>
                <x-button variant="primary" size="md" icon="plus" type="button" x-on:click="$dispatch('open-modal','add-type')">إضافة نوع</x-button>
            </div>

            @if (count($types))
                <x-table :headers="['الاسم', 'الوصف', 'الاستخدام', '']">
                    @foreach ($types as $t)
                        <tr class="hover:bg-gray-50" data-row data-search="{{ $t['name'] }} {{ $t['description'] }}">
                            <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ $t['name'] }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ $t['description'] ?: '—' }}</td>
                            <td class="px-4 py-3 text-gray-600 whitespace-nowrap">
                                {{ $t['count'] }} مصروف
                                @if ($t['total'] > 0)
                                    <span class="text-gray-400">— {{ \App\Support\Demo::money($t['total']) }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-left">
                                <x-dropdown align="left" width="w-36">
                                    <x-slot:trigger>
                                        <button type="button" class="w-8 h-8 rounded-full hover:bg-gray-100 text-gray-400 flex items-center justify-center">
                                            <x-icon name="ellipsis-vertical" class="w-5 h-5" />
                                        </button>
                                    </x-slot:trigger>
                                    <form method="POST" action="{{ route('admin.expenseTypes.destroy', $t['id']) }}"
                                          @submit.prevent="if(confirm('حذف نوع «{{ $t['name'] }}»؟ لن تتأثر المصروفات المسجّلة سابقًا.')) $el.submit()">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-danger-600 hover:bg-danger-50">
                                            <x-icon name="trash-2" class="w-4 h-4" /> حذف
                                        </button>
                                    </form>
                                </x-dropdown>
                            </td>
                        </tr>
                    @endforeach

                    <x-slot:footer>
                        <p class="text-sm text-gray-500">أنواع المصروفات: {{ count($types) }}</p>
                    </x-slot:footer>
                </x-table>
            @else
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm">
                    <x-empty-state icon="tags" title="لا توجد أنواع" message="أضف أول نوع مصروف">
                        <x-button variant="primary" size="md" x-on:click="$dispatch('open-modal','add-type')">إضافة نوع</x-button>
                    </x-empty-state>
                </div>
            @endif
        </div>
    </div>

    {{-- نافذة إضافة مصروف --}}
    <x-modal name="add-expense" title="مصروف جديد" maxWidth="max-w-lg">
        <form id="add-expense-form" method="POST" action="{{ route('admin.expenses.store') }}" enctype="multipart/form-data" class="space-y-4">
            @csrf
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-select label="نوع المصروف" name="type" placeholder="اختر النوع..." :options="$typeOptions" :required="true" />
                <x-input label="المبلغ (ر.ع)" name="amount" type="number" step="0.001" placeholder="0.000" icon="wallet" :required="true" />
            </div>
            <x-input label="ملاحظات" name="description" placeholder="مثال: إيجار المحل لشهر يوليو" icon="file-text" />
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-input label="تاريخ الصرف" name="spent_at" type="date" value="{{ now()->format('Y-m-d') }}" :required="true" />
                <x-select label="طريقة الدفع" name="method" :options="['نقدي' => 'نقدي', 'بطاقة' => 'بطاقة', 'تحويل بنكي' => 'تحويل بنكي']" selected="نقدي" />
            </div>
            <x-select label="الحالة" name="status" :options="['مدفوع' => 'مدفوع', 'غير مدفوع' => 'غير مدفوع']" selected="مدفوع" />

            {{-- المرفق --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">المرفق</label>
                <input type="file" name="attachment" accept=".jpg,.jpeg,.png,.pdf,.webp,.heic,image/jpeg,image/png,application/pdf,image/webp,image/heic"
                       class="block w-full text-sm text-gray-600 file:mr-0 file:ml-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-gray-900 file:text-white hover:file:bg-black cursor-pointer border border-gray-200 rounded-xl p-2" />
                <p class="text-xs text-gray-400 mt-1.5">الصيغ المدعومة: JPG، PNG، PDF، WEBP، HEIC — بحد أقصى 10 ميجابايت.</p>
                @error('attachment')<p class="text-xs text-danger-600 mt-1">{{ $message }}</p>@enderror
            </div>
        </form>

        <x-slot:footer>
            <x-button variant="ghost" size="md" x-on:click="$dispatch('close-modal')">إلغاء</x-button>
            <x-button variant="primary" size="md" type="submit" form="add-expense-form" icon="check">حفظ المصروف</x-button>
        </x-slot:footer>
    </x-modal>

    {{-- نافذة إضافة نوع مصروف --}}
    <x-modal name="add-type" title="إضافة نوع مصروف" maxWidth="max-w-md">
        <form id="add-type-form" method="POST" action="{{ route('admin.expenseTypes.store') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm text-gray-600 mb-1">الاسم <span class="text-danger-500">*</span></label>
                <input type="text" name="name" required placeholder="مثال: اشتراكات وبرمجيات"
                       class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200" />
                @error('name')<p class="text-xs text-danger-600 mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-sm text-gray-600 mb-1">الوصف</label>
                <input type="text" name="description" placeholder="وصف مختصر للنوع"
                       class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200" />
            </div>
        </form>

        <x-slot:footer>
            <x-button variant="ghost" size="md" x-on:click="$dispatch('close-modal')">إلغاء</x-button>
            <x-button variant="primary" size="md" type="submit" form="add-type-form" icon="check">حفظ</x-button>
        </x-slot:footer>
    </x-modal>
</x-layouts::admin>
