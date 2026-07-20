<x-layouts::admin title="الموظفون">
    <x-page-header
        title="الموظفون"
        subtitle="إدارة فريق العمل وصلاحياتهم ومتابعة أدائهم"
        :breadcrumbs="['الرئيسية' => route('admin.dashboard'), 'الموظفون' => '#']"
    >
        <x-slot:actions>
            <x-button variant="outline" size="md" icon="trophy" :href="route('admin.employees.performance')">لوحة الأداء</x-button>
            <x-button variant="outline" size="md" icon="download" :href="route('admin.export.employees')">تصدير CSV</x-button>
            <x-button variant="primary" size="md" icon="user-plus" :href="route('admin.employees.create')">إضافة موظف</x-button>
        </x-slot:actions>
    </x-page-header>

    @php $employees = \App\Support\Demo::employees(); @endphp

    {{-- البطاقات الإحصائية --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <x-stat-card label="إجمالي الموظفين" value="{{ count($employees) }}" icon="users" color="primary" />
        <x-stat-card label="نشطون" value="{{ collect($employees)->where('status', 'نشط')->count() }}" icon="user-check" color="success" />
        <x-stat-card label="موقوفون" value="{{ collect($employees)->where('status', '!=', 'نشط')->count() }}" icon="user-x" color="danger" />
        <x-stat-card label="إجمالي المبيعات" value="{{ \App\Support\Demo::money(collect($employees)->sum('achieved')) }}" icon="trending-up" color="info" />
    </div>

    <div x-data="listFilter()" x-ref="list">
        {{-- شريط الفلاتر --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 mb-6">
            <div class="flex flex-col md:flex-row md:items-center gap-3">
                <div class="flex-1">
                    <x-input name="search" placeholder="ابحث عن موظف بالاسم أو البريد..." icon="search" x-model="q" @input="apply()" />
                </div>
                <div class="w-full md:w-56">
                    <x-select name="role" placeholder="كل الأدوار" x-model="tag" @change="apply()" :options="[
                        'مدير' => 'مدير',
                        'كاشير' => 'كاشير',
                        'موظف مبيعات' => 'موظف مبيعات',
                        'محاسب' => 'محاسب',
                        'مسؤول مخزون' => 'مسؤول مخزون',
                        'مندوب توصيل' => 'مندوب توصيل',
                    ]" />
                </div>
                <x-button variant="light" size="md" icon="filter" @click="apply()">تصفية</x-button>
            </div>
        </div>

        {{-- قائمة الموظفين --}}
        <div x-data="{ sel: { id: '', name: '', target: 0, rate: 0 } }">
            @if (count($employees))
                <x-table :headers="['الموظف', 'الدور', 'الفرع', 'الهاتف', 'البريد', 'هدف الشهر', 'الحالة', 'إجراءات']">
                    @foreach ($employees as $employee)
                        <tr class="hover:bg-gray-50" data-row data-tag="{{ $employee['role'] }}"
                            data-search="{{ $employee['name'] }} {{ $employee['email'] }} {{ $employee['phone'] }}">
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="flex items-center gap-3">
                                    <img src="{{ $employee['avatar'] }}" alt="{{ $employee['name'] }}" class="w-9 h-9 rounded-full object-cover ring-2 ring-gray-100" />
                                    <div class="min-w-0">
                                        <p class="font-medium text-gray-800">{{ $employee['name'] }}</p>
                                        <p class="text-xs text-gray-400 font-mono">#{{ $employee['id'] }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap"><x-badge type="primary" :text="$employee['role']" /></td>
                            <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $employee['branch'] }}</td>
                            <td class="px-4 py-3 text-gray-600 whitespace-nowrap" dir="ltr">{{ $employee['phone'] }}</td>
                            <td class="px-4 py-3 text-gray-500 whitespace-nowrap" dir="ltr">{{ $employee['email'] }}</td>

                            {{-- هدف الشهر --}}
                            <td class="px-4 py-3 min-w-[190px]">
                                @if ($employee['target'] > 0)
                                    <div class="flex items-center justify-between text-xs mb-1">
                                        <span class="font-semibold text-gray-800">{{ \App\Support\Demo::money($employee['achieved']) }}</span>
                                        <span class="text-gray-400">من {{ \App\Support\Demo::money($employee['target']) }}</span>
                                    </div>
                                    <div class="w-full bg-gray-100 rounded-full h-1.5 overflow-hidden">
                                        <div class="h-1.5 rounded-full {{ $employee['pct'] >= 100 ? 'bg-success-500' : ($employee['pct'] >= 60 ? 'bg-primary-500' : 'bg-warning-500') }}" style="width: {{ $employee['pct'] }}%"></div>
                                    </div>
                                    <div class="flex items-center justify-between mt-1 text-[11px]">
                                        <span class="text-gray-400">{{ $employee['pct'] }}%</span>
                                        <span class="text-secondary-600 font-medium">عمولة {{ \App\Support\Demo::money($employee['commission']) }}</span>
                                    </div>
                                @else
                                    <span class="text-xs text-gray-400">لا يوجد هدف</span>
                                @endif
                            </td>

                            <td class="px-4 py-3 whitespace-nowrap"><x-badge :text="$employee['status']" /></td>

                            <td class="px-4 py-3 whitespace-nowrap">
                                <x-dropdown align="left" width="w-44">
                                    <x-slot:trigger>
                                        <button type="button" class="w-8 h-8 rounded-lg hover:bg-gray-100 text-gray-500 flex items-center justify-center">
                                            <x-icon name="ellipsis-vertical" class="w-5 h-5" />
                                        </button>
                                    </x-slot:trigger>
                                    <a href="{{ route('admin.employees.show', $employee['id']) }}" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                        <x-icon name="eye" class="w-4 h-4" /> عرض الملف
                                    </a>
                                    <a href="{{ route('admin.employees.edit', $employee['id']) }}" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                        <x-icon name="pencil" class="w-4 h-4" /> تعديل
                                    </a>
                                    <button type="button" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50"
                                        x-on:click="sel = { id: {{ $employee['id'] }}, name: @js($employee['name']), target: {{ $employee['target'] }}, rate: {{ $employee['commission_rate'] }} }; $dispatch('open-modal','edit-goal')">
                                        <x-icon name="target" class="w-4 h-4" /> الهدف والعمولة
                                    </button>
                                </x-dropdown>
                            </td>
                        </tr>
                    @endforeach
                </x-table>
            @else
                <x-empty-state icon="users" title="لا يوجد موظفون" message="أضِف أول موظف لفريق عملك." />
            @endif

            {{-- نافذة تعديل الهدف والعمولة --}}
            <x-modal name="edit-goal" title="الهدف الشهري والعمولة" maxWidth="max-w-md">
                <form id="goal-form" method="POST" :action="'{{ url('admin/employees') }}/' + sel.id + '/goal'" class="space-y-4">
                    @csrf
                    <div class="rounded-xl bg-gray-50 border border-gray-100 p-3 text-sm text-gray-600">
                        الموظف: <span class="font-semibold text-gray-800" x-text="sel.name"></span>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600 mb-1">الهدف الشهري (ر.ع)</label>
                        <input type="number" step="0.001" min="0" name="monthly_target" x-bind:value="sel.target"
                            class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200" />
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600 mb-1">نسبة العمولة %</label>
                        <input type="number" step="0.01" min="0" max="100" name="commission_rate" x-bind:value="sel.rate"
                            class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200" />
                    </div>
                </form>
                <x-slot:footer>
                    <x-button variant="ghost" size="md" x-on:click="$dispatch('close-modal')">إلغاء</x-button>
                    <x-button variant="primary" size="md" icon="check" type="submit" form="goal-form">حفظ</x-button>
                </x-slot:footer>
            </x-modal>
        </div>
    </div>
</x-layouts::admin>
