<x-layouts::admin :title="__('الموظفون')">
    <x-page-header
        :title="__('الموظفون')"
        :subtitle="__('إدارة فريق العمل وصلاحياتهم ومتابعة أدائهم')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('الموظفون') => '#']"
    >
        <x-slot:actions>
            <x-button variant="primary" size="md" icon="user-plus" :href="route('admin.employees.create')">{{ __('إضافة موظف') }}</x-button>
        </x-slot:actions>
    </x-page-header>

    @php
        $employees = \App\Support\Demo::employees();
        $jobTitles = \App\Models\JobTitle::where('business_id', \App\Support\Demo::bid())->orderBy('name')->get();
        $titleUsage = \App\Models\User::where('business_id', \App\Support\Demo::bid())
            ->selectRaw('job_title, COUNT(*) as c')->groupBy('job_title')->pluck('c', 'job_title');
    @endphp

    <div x-data="{ tab: 'employees' }">
    {{-- تبويبات --}}
    <div class="flex items-center gap-1 border-b border-gray-200 mb-6">
        <button type="button" @click="tab = 'employees'" x-bind:class="tab === 'employees' ? 'border-[#111] text-[#111]' : 'border-transparent text-gray-500 hover:text-gray-700'"
            class="px-4 py-2.5 -mb-px text-sm font-medium border-b-2 transition">{{ __('الموظفون') }}</button>
        <button type="button" @click="tab = 'titles'" x-bind:class="tab === 'titles' ? 'border-[#111] text-[#111]' : 'border-transparent text-gray-500 hover:text-gray-700'"
            class="px-4 py-2.5 -mb-px text-sm font-medium border-b-2 transition">{{ __('الوظائف') }}</button>
    </div>

    <div x-show="tab === 'employees'">

    {{-- البطاقات الإحصائية --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <x-stat-card :label="__('إجمالي الموظفين')" value="{{ count($employees) }}" icon="users" color="primary" />
        <x-stat-card :label="__('نشطون')" value="{{ collect($employees)->where('status', 'نشط')->count() }}" icon="user-check" color="success" />
        <x-stat-card :label="__('موقوفون')" value="{{ collect($employees)->where('status', '!=', 'نشط')->count() }}" icon="user-x" color="danger" />
        <x-stat-card :label="__('إجمالي المبيعات')" value="{{ \App\Support\Demo::money(collect($employees)->sum('achieved')) }}" icon="trending-up" color="info" />
    </div>

    <div x-data="listFilter()" x-ref="list" @filter-change="tag = $event.detail; apply()">
        {{-- شريط الفلاتر --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 mb-6">
            <div class="flex flex-col md:flex-row md:items-center gap-3">
                <div class="flex-1">
                    <x-input name="search" :placeholder="__('ابحث عن موظف بالاسم أو البريد...')" icon="search" x-model="q" @input="apply()" />
                </div>
                <div class="w-full md:w-56">
                    <x-select name="role" :placeholder="__('كل الوظائف')" on-select="$dispatch('filter-change', value)"
                        :options="$jobTitles->mapWithKeys(fn ($jt) => [$jt->name => __($jt->name)])->toArray()" />
                </div>
                <x-button variant="light" size="md" icon="filter" @click="apply()">{{ __('تصفية') }}</x-button>
            </div>
        </div>

        {{-- قائمة الموظفين --}}
        <div>
            @if (count($employees))
                <x-table :headers="[__('الموظف'), __('الوظيفة'), __('الفرع'), __('الهاتف'), __('البريد'), __('الحالة'), __('إجراءات')]">
                    @foreach ($employees as $employee)
                        <tr class="hover:bg-gray-50" data-row data-tag="{{ $employee['role'] }}"
                            data-search="{{ $employee['name'] }} {{ $employee['email'] }} {{ $employee['phone'] }}">
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="flex items-center gap-3">
                                    <img src="{{ $employee['avatar'] }}" alt="{{ $employee['name'] }}" class="w-9 h-9 rounded-full object-cover ring-2 ring-gray-100" />
                                    <div class="min-w-0">
                                        <p class="font-medium text-gray-800 flex items-center gap-1.5">
                                            {{ $employee['name'] }}
                                            @if (! empty($employee['has_pin']))
                                                <span class="inline-flex items-center gap-1 text-[10px] font-medium text-success-700 bg-success-50 px-1.5 py-0.5 rounded-full" title="{{ __('لديه رمز دخول سريع') }}">
                                                    <x-icon name="scan-barcode" class="w-3 h-3" /> {{ __('رمز') }}
                                                </span>
                                            @endif
                                        </p>
                                        <p class="text-xs text-gray-400 font-mono">#{{ $employee['id'] }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap"><x-badge type="primary" :text="__($employee['role'])" /></td>
                            <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $employee['branch'] }}</td>
                            <td class="px-4 py-3 text-gray-600 whitespace-nowrap" dir="ltr">{{ $employee['phone'] }}</td>
                            <td class="px-4 py-3 text-gray-500 whitespace-nowrap" dir="ltr">{{ $employee['email'] }}</td>

                            <td class="px-4 py-3 whitespace-nowrap"><x-badge :text="__($employee['status'])" /></td>

                            <td class="px-4 py-3 whitespace-nowrap">
                                <x-dropdown align="left" width="w-44">
                                    <x-slot:trigger>
                                        <button type="button" class="w-8 h-8 rounded-full hover:bg-gray-100 text-gray-500 flex items-center justify-center">
                                            <x-icon name="ellipsis-vertical" class="w-5 h-5" />
                                        </button>
                                    </x-slot:trigger>
                                    <a href="{{ route('admin.employees.show', $employee['id']) }}" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                        <x-icon name="eye" class="w-4 h-4" /> {{ __('عرض الملف') }}
                                    </a>
                                    <a href="{{ route('admin.employees.edit', $employee['id']) }}" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                        <x-icon name="pencil" class="w-4 h-4" /> {{ __('تعديل') }}
                                    </a>
                                </x-dropdown>
                            </td>
                        </tr>
                    @endforeach
                </x-table>
            @else
                <x-empty-state icon="users" :title="__('لا يوجد موظفون')" :message="__('أضِف أول موظف لفريق عملك.')" />
            @endif
        </div>
    </div>
    </div>{{-- نهاية تبويب الموظفين --}}

    {{-- ===== تبويب الوظائف ===== --}}
    <div x-show="tab === 'titles'" x-cloak>
        <div class="flex justify-end mb-4">
            <x-button variant="primary" size="md" icon="plus" x-on:click="$dispatch('open-modal','add-title')">{{ __('إضافة وظيفة') }}</x-button>
        </div>

        @if ($jobTitles->count())
            <x-table :headers="[__('الوظيفة'), __('الصلاحيات المكافئة'), __('الوصف'), __('الاستخدام'), '']">
                @foreach ($jobTitles as $t)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ __($t->name) }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <x-badge type="secondary" :text="__(\App\Models\JobTitle::ROLES[$t->role] ?? $t->role)" />
                        </td>
                        <td class="px-4 py-3 text-gray-500">{{ $t->description ?: '—' }}</td>
                        <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ __(':n موظف', ['n' => $titleUsage[$t->name] ?? 0]) }}</td>
                        <td class="px-4 py-3 whitespace-nowrap text-left">
                            @php $deleteTitleConfirm = __('حذف الوظيفة «:name»؟', ['name' => $t->name]); @endphp
                            <form method="POST" action="{{ route('admin.jobTitles.destroy', $t->id) }}"
                                  @submit="if(!confirm(@js($deleteTitleConfirm))) $event.preventDefault()">
                                @csrf @method('DELETE')
                                <x-button variant="ghost" size="sm" type="submit" icon="trash-2" class="text-danger-600">{{ __('حذف') }}</x-button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                <x-slot:footer>
                    <div class="px-4 py-3 text-xs text-gray-400">{{ __(':n وظيفة', ['n' => $jobTitles->count()]) }}</div>
                </x-slot:footer>
            </x-table>
        @else
            <x-empty-state icon="briefcase" :title="__('لا توجد وظائف')" :message="__('أضِف أول وظيفة لفريق عملك.')" />
        @endif

        {{-- نافذة إضافة وظيفة --}}
        <x-modal name="add-title" :title="__('إضافة وظيفة')" maxWidth="max-w-md">
            <form id="add-title-form" method="POST" action="{{ route('admin.jobTitles.store') }}" class="space-y-4">
                @csrf
                <x-input :label="__('اسم الوظيفة')" name="name" :placeholder="__('مثال: منسّق زهور')" icon="briefcase" :required="true" />
                <x-select :label="__('الصلاحيات المكافئة')" name="role" :placeholder="__('اختر الصلاحيات...')" :required="true"
                    :options="array_map(fn ($label) => __($label), \App\Models\JobTitle::ROLES)" />
                <p class="text-xs text-gray-500 leading-relaxed">
                    {{ __('اسم الوظيفة حرّ تمامًا، أمّا «الصلاحيات المكافئة» فتحدّد ما يستطيع الموظف الوصول إليه داخل النظام.') }}
                </p>
                <x-input :label="__('الوصف')" name="description" :placeholder="__('وصف مختصر (اختياري)')" icon="sticky-note" />
            </form>
            <x-slot:footer>
                <x-button variant="ghost" size="md" x-on:click="$dispatch('close-modal')">{{ __('إلغاء') }}</x-button>
                <x-button variant="primary" size="md" icon="check" type="submit" form="add-title-form">{{ __('إضافة') }}</x-button>
            </x-slot:footer>
        </x-modal>
    </div>
    </div>{{-- نهاية غلاف التبويبات --}}
</x-layouts::admin>
