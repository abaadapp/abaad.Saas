@php
    $activeTab = ($filters['tab'] ?? 'expenses') === 'types' ? 'types' : 'expenses';
    $typeOptions = collect($types)->pluck('name', 'name')->toArray();
    $statusColors = ['مدفوع' => 'success', 'غير مدفوع' => 'warning', 'ملغي' => 'danger'];
@endphp

<x-layouts::admin :title="__('المصروفات')">
    <x-page-header
        :title="__('المصروفات')"
        :subtitle="__('إدارة المصروفات وأنواع المصروفات')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('المصروفات') => '#']"
    >
        <x-slot:actions>
            <x-button variant="primary" size="md" icon="plus" x-on:click="$dispatch('open-modal','add-expense')">{{ __('مصروف جديد') }}</x-button>
        </x-slot:actions>
    </x-page-header>

    <div x-data="{ tab: '{{ $activeTab }}' }">
        {{-- التبويبات --}}
        <div class="flex items-center gap-1 border-b border-gray-200 mb-6">
            <button type="button" @click="tab = 'expenses'"
                :class="tab === 'expenses' ? 'text-gray-900 border-gray-900' : 'text-gray-500 border-transparent hover:text-gray-700'"
                class="px-4 py-3 text-sm font-medium border-b-2 -mb-px transition-colors">{{ __('المصروفات') }}</button>
            <button type="button" @click="tab = 'types'"
                :class="tab === 'types' ? 'text-gray-900 border-gray-900' : 'text-gray-500 border-transparent hover:text-gray-700'"
                class="px-4 py-3 text-sm font-medium border-b-2 -mb-px transition-colors">{{ __('أنواع المصروفات') }}</button>
        </div>

        {{-- ============ تبويب المصروفات ============ --}}
        <div x-show="tab === 'expenses'" x-data="{ showFilter: {{ (!empty($filters['type']) || !empty($filters['status'])) ? 'true' : 'false' }} }">
            {{-- شريط الأدوات --}}
            <form method="GET" class="mb-4">
                <input type="hidden" name="tab" value="expenses">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <div class="w-full sm:w-72">
                            <x-input name="q" value="{{ $filters['q'] ?? '' }}" :placeholder="__('البحث بالمرجع')" icon="search" />
                        </div>
                        <x-button variant="outline" size="md" icon="sliders-horizontal" type="button" x-on:click="showFilter = !showFilter">{{ __('تصفية') }}</x-button>
                    </div>
                    @include('partials.export-menu', ['xlsx' => route('admin.expenses.xlsx'), 'pdf' => route('admin.expenses.exportPdf'), 'csv' => route('admin.export.expenses')])
                </div>

                {{-- لوحة التصفية --}}
                <div x-show="showFilter" x-cloak class="mt-3 bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-end">
                        <x-select name="type" :placeholder="__('كل الأنواع')" :options="$typeOptions" selected="{{ $filters['type'] ?? '' }}" :label="__('نوع المصروف')" />
                        <x-select name="status" :placeholder="__('كل الحالات')" :options="['مدفوع' => __('مدفوع'), 'غير مدفوع' => __('غير مدفوع')]" selected="{{ $filters['status'] ?? '' }}" :label="__('الحالة')" />
                        <div class="flex items-center gap-2">
                            <x-button type="submit" size="md" icon="filter">{{ __('تطبيق') }}</x-button>
                            <x-button variant="outline" size="md" :href="route('admin.expenses.index')">{{ __('عرض الكل') }}</x-button>
                        </div>
                    </div>
                </div>
            </form>

            @if ($expenses->total())
                <x-table :headers="[__('الرقم المرجعي'), __('تاريخ الإستحقاق'), __('أنواع المصروفات'), __('المبلغ'), __('الحالة'), __('المرفق'), __('ملاحظات'), '']">
                    @foreach ($expenses as $e)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap font-mono">{{ $e->reference ?: '—' }}</td>
                            <td class="px-4 py-3 text-gray-600 whitespace-nowrap" dir="ltr">{{ optional($e->due_date)->format('Y-m-d') ?? '—' }}</td>
                            <td class="px-4 py-3 whitespace-nowrap"><x-badge type="secondary" :text="$e->type" /></td>
                            <td class="px-4 py-3 font-semibold text-gray-800 whitespace-nowrap">{{ \App\Support\Demo::money($e->amount) }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <x-badge :type="$statusColors[$e->status] ?? 'gray'" :text="__($e->status)" />
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if ($e->attachment)
                                    <a href="{{ \Illuminate\Support\Facades\Storage::url($e->attachment) }}" target="_blank"
                                       title="{{ $e->attachment_name }}"
                                       class="inline-flex items-center gap-1.5 text-sm text-gray-700 hover:text-gray-900 underline decoration-gray-300">
                                        <x-icon name="paperclip" class="w-4 h-4 text-gray-400" /> {{ __('عرض') }}
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
                                    <form method="POST" action="{{ route('admin.expenses.destroy', $e->id) }}" @submit.prevent="if(confirm(@js(__('حذف هذا المصروف؟')))) $el.submit()">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-danger-600 hover:bg-danger-50">
                                            <x-icon name="trash-2" class="w-4 h-4" /> {{ __('حذف') }}
                                        </button>
                                    </form>
                                </x-dropdown>
                            </td>
                        </tr>
                    @endforeach

                    <x-slot:footer>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="text-sm text-gray-500">
                                {{ __('المصروفات: :count — الإجمالي:', ['count' => $totalCount]) }} <span class="font-semibold text-gray-800">{{ \App\Support\Demo::money($totalAmount) }}</span>
                            </p>
                            <x-pagination :paginator="$expenses" />
                        </div>
                    </x-slot:footer>
                </x-table>
            @else
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm">
                    <x-empty-state icon="folder-open" :title="__('لا توجد مصروفات')" :message="__('أنشئ أول مصروف')">
                        <x-button variant="primary" size="md" x-on:click="$dispatch('open-modal','add-expense')">{{ __('مصروف جديد') }}</x-button>
                    </x-empty-state>
                </div>
            @endif
        </div>

        {{-- ============ تبويب أنواع المصروفات ============ --}}
        <div x-show="tab === 'types'" x-cloak x-data="listFilter()" x-ref="list">
            {{-- شريط الأدوات --}}
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
                <div class="w-full sm:w-72">
                    <x-input name="type-search" :placeholder="__('بحث')" icon="search" x-model="q" @input="apply()" />
                </div>
                <x-button variant="primary" size="md" icon="plus" type="button" x-on:click="$dispatch('open-modal','add-type')">{{ __('إضافة نوع') }}</x-button>
            </div>

            @if (count($types))
                <x-table :headers="[__('الاسم'), __('الوصف'), __('الاستخدام'), '']">
                    @foreach ($types as $t)
                        <tr class="hover:bg-gray-50" data-row data-search="{{ $t['name'] }} {{ $t['description'] }}">
                            <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ $t['name'] }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ $t['description'] ?: '—' }}</td>
                            <td class="px-4 py-3 text-gray-600 whitespace-nowrap">
                                {{ __(':count مصروف', ['count' => $t['count']]) }}
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
                                          @submit.prevent="if(confirm(@js(__('حذف نوع «:name»؟ لن تتأثر المصروفات المسجّلة سابقًا.', ['name' => $t['name']])))) $el.submit()">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-danger-600 hover:bg-danger-50">
                                            <x-icon name="trash-2" class="w-4 h-4" /> {{ __('حذف') }}
                                        </button>
                                    </form>
                                </x-dropdown>
                            </td>
                        </tr>
                    @endforeach

                    <x-slot:footer>
                        <p class="text-sm text-gray-500">{{ __('أنواع المصروفات: :count', ['count' => count($types)]) }}</p>
                    </x-slot:footer>
                </x-table>
                <div x-ref="empty" style="display:none" class="text-center text-sm text-gray-400 py-8">{{ __('لا نتائج مطابقة') }}</div>
            @else
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm">
                    <x-empty-state icon="tags" :title="__('لا توجد أنواع')" :message="__('أضف أول نوع مصروف')">
                        <x-button variant="primary" size="md" x-on:click="$dispatch('open-modal','add-type')">{{ __('إضافة نوع') }}</x-button>
                    </x-empty-state>
                </div>
            @endif
        </div>
    </div>

    {{-- نافذة إضافة مصروف --}}
    <x-modal name="add-expense" :title="__('مصروف جديد')" maxWidth="max-w-lg">
        <form id="add-expense-form" method="POST" action="{{ route('admin.expenses.store') }}" enctype="multipart/form-data" class="space-y-4">
            @csrf
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-select :label="__('نوع المصروف')" name="type" :placeholder="__('اختر النوع...')" :options="$typeOptions" :required="true" />
                <x-input :label="__('المبلغ (ر.ع)')" name="amount" type="number" step="0.001" placeholder="0.000" icon="wallet" :required="true" />
            </div>
            <x-input :label="__('ملاحظات')" name="description" :placeholder="__('مثال: إيجار المحل لشهر يوليو')" icon="file-text" />
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-input :label="__('تاريخ الصرف')" name="spent_at" type="date" value="{{ now()->format('Y-m-d') }}" :required="true" />
                <x-select :label="__('طريقة الدفع')" name="method" :options="['نقدي' => __('نقدي'), 'بطاقة' => __('بطاقة'), 'تحويل بنكي' => __('تحويل بنكي')]" selected="نقدي" />
            </div>
            <x-select :label="__('الحالة')" name="status" :options="['مدفوع' => __('مدفوع'), 'غير مدفوع' => __('غير مدفوع')]" selected="مدفوع" />

            {{-- المرفق --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">{{ __('المرفق') }}</label>
                <input type="file" name="attachment" accept=".jpg,.jpeg,.png,.pdf,.webp,.heic,image/jpeg,image/png,application/pdf,image/webp,image/heic"
                       class="block w-full text-sm text-gray-600 file:mr-0 file:ml-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-gray-900 file:text-white hover:file:bg-black cursor-pointer border border-gray-200 rounded-xl p-2" />
                <p class="text-xs text-gray-400 mt-1.5">{{ __('الصيغ المدعومة: JPG، PNG، PDF، WEBP، HEIC — بحد أقصى 10 ميجابايت.') }}</p>
                @error('attachment')<p class="text-xs text-danger-600 mt-1">{{ $message }}</p>@enderror
            </div>
        </form>

        <x-slot:footer>
            <x-button variant="ghost" size="md" x-on:click="$dispatch('close-modal')">{{ __('إلغاء') }}</x-button>
            <x-button variant="primary" size="md" type="submit" form="add-expense-form" icon="check">{{ __('حفظ المصروف') }}</x-button>
        </x-slot:footer>
    </x-modal>

    {{-- نافذة إضافة نوع مصروف --}}
    <x-modal name="add-type" :title="__('إضافة نوع مصروف')" maxWidth="max-w-md">
        <form id="add-type-form" method="POST" action="{{ route('admin.expenseTypes.store') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm text-gray-600 mb-1">{{ __('الاسم') }} <span class="text-danger-500">*</span></label>
                <input type="text" name="name" required placeholder="{{ __('مثال: اشتراكات وبرمجيات') }}"
                       class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200" />
                @error('name')<p class="text-xs text-danger-600 mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-sm text-gray-600 mb-1">{{ __('الوصف') }}</label>
                <input type="text" name="description" placeholder="{{ __('وصف مختصر للنوع') }}"
                       class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200" />
            </div>
        </form>

        <x-slot:footer>
            <x-button variant="ghost" size="md" x-on:click="$dispatch('close-modal')">{{ __('إلغاء') }}</x-button>
            <x-button variant="primary" size="md" type="submit" form="add-type-form" icon="check">{{ __('حفظ') }}</x-button>
        </x-slot:footer>
    </x-modal>
</x-layouts::admin>
