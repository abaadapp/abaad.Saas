<x-layouts::admin title="الموظفون">
    <x-page-header
        title="الموظفون"
        subtitle="إدارة فريق العمل وصلاحياتهم ومتابعة أدائهم"
        :breadcrumbs="['الرئيسية' => route('admin.dashboard'), 'الموظفون' => '#']"
    >
        <x-slot:actions>
            <x-button variant="outline" size="md" icon="download" :href="route('admin.export.employees')">تصدير CSV</x-button>
            <x-button variant="primary" size="md" icon="user-plus" :href="route('admin.employees.create')">إضافة موظف</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- البطاقات الإحصائية --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <x-stat-card label="إجمالي الموظفين" value="7" icon="users" color="primary" />
        <x-stat-card label="نشطون" value="6" icon="user-check" color="success" />
        <x-stat-card label="موقوفون" value="1" icon="user-x" color="danger" />
        <x-stat-card label="إجمالي المبيعات" value="{{ \App\Support\Demo::money(31480.000) }}" icon="trending-up" trend="+14%" :up="true" color="info" />
    </div>

    {{-- شريط الفلاتر --}}
    <div x-data="listFilter()" x-ref="list">
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

    {{-- شبكة بطاقات الموظفين --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach (\App\Support\Demo::employees() as $employee)
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 hover:shadow-md transition-shadow" x-data="{ editGoal: false }" data-row data-tag="{{ $employee['role'] }}" data-search="{{ $employee['name'] }} {{ $employee['email'] }} {{ $employee['phone'] }}">
                <div class="flex items-start justify-between">
                    <div class="flex items-center gap-3">
                        <img src="{{ $employee['avatar'] }}" alt="{{ $employee['name'] }}" class="w-12 h-12 rounded-full object-cover ring-2 ring-gray-100" />
                        <div>
                            <p class="font-bold text-gray-800">{{ $employee['name'] }}</p>
                            <x-badge type="primary" :text="$employee['role']" />
                        </div>
                    </div>
                    <x-badge :text="$employee['status']" />
                </div>

                <ul class="mt-4 space-y-2.5 text-sm border-t border-gray-50 pt-4">
                    <li class="flex items-center justify-between">
                        <span class="text-gray-500 flex items-center gap-2"><x-icon name="store" class="w-4 h-4" /> الفرع</span>
                        <span class="text-gray-700">{{ $employee['branch'] }}</span>
                    </li>
                    <li class="flex items-center justify-between">
                        <span class="text-gray-500 flex items-center gap-2"><x-icon name="phone" class="w-4 h-4" /> الهاتف</span>
                        <span class="text-gray-700" dir="ltr">{{ $employee['phone'] }}</span>
                    </li>
                    <li class="flex items-center justify-between">
                        <span class="text-gray-500 flex items-center gap-2"><x-icon name="mail" class="w-4 h-4" /> البريد</span>
                        <span class="text-gray-700 truncate max-w-[140px]" dir="ltr">{{ $employee['email'] }}</span>
                    </li>
                </ul>

                {{-- هدف الموظف الشهري والعمولة --}}
                <div class="mt-4 border-t border-gray-50 pt-4">
                    <div class="flex items-center justify-between mb-1.5">
                        <span class="text-sm text-gray-500 flex items-center gap-2"><x-icon name="target" class="w-4 h-4" /> هدف الشهر</span>
                        <button type="button" @click="editGoal = !editGoal" class="text-xs text-primary-600 hover:text-primary-700 font-medium">تعديل</button>
                    </div>

                    @if ($employee['target'] > 0)
                        <div class="flex items-center justify-between text-sm mb-1">
                            <span class="font-semibold text-gray-800">{{ \App\Support\Demo::money($employee['achieved']) }}</span>
                            <span class="text-gray-400">من {{ \App\Support\Demo::money($employee['target']) }}</span>
                        </div>
                        <div class="w-full bg-gray-100 rounded-full h-2 overflow-hidden">
                            <div class="h-2 rounded-full {{ $employee['pct'] >= 100 ? 'bg-success-500' : ($employee['pct'] >= 60 ? 'bg-primary-500' : 'bg-warning-500') }}" style="width: {{ $employee['pct'] }}%"></div>
                        </div>
                        <div class="flex items-center justify-between mt-1.5 text-xs">
                            <span class="text-gray-400">الإنجاز {{ $employee['pct'] }}%</span>
                            <span class="text-secondary-600 font-medium">عمولة {{ $employee['commission_rate'] }}% = {{ \App\Support\Demo::money($employee['commission']) }}</span>
                        </div>
                    @else
                        <p class="text-xs text-gray-400">لم يُحدَّد هدف شهري لهذا الموظف بعد.</p>
                    @endif

                    <div x-show="editGoal" x-cloak x-collapse class="mt-3">
                        <form method="POST" action="{{ route('admin.employees.goal', $employee['id']) }}" class="grid grid-cols-2 gap-2">
                            @csrf
                            <div>
                                <label class="block text-[11px] text-gray-500 mb-1">الهدف الشهري (ر.ع)</label>
                                <input type="number" step="0.001" min="0" name="monthly_target" value="{{ $employee['target'] }}" class="w-full rounded-lg border-gray-200 text-sm py-1.5 px-2 focus:border-primary-400 focus:ring-primary-200" />
                            </div>
                            <div>
                                <label class="block text-[11px] text-gray-500 mb-1">نسبة العمولة %</label>
                                <input type="number" step="0.01" min="0" max="100" name="commission_rate" value="{{ $employee['commission_rate'] }}" class="w-full rounded-lg border-gray-200 text-sm py-1.5 px-2 focus:border-primary-400 focus:ring-primary-200" />
                            </div>
                            <div class="col-span-2">
                                <button type="submit" class="w-full bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-lg py-2">حفظ الهدف والعمولة</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="mt-4 flex items-center gap-2">
                    <x-button variant="light" size="sm" icon="eye" :href="route('admin.employees.show', $employee['id'])" class="flex-1">عرض الملف</x-button>
                    <x-button variant="outline" size="sm" icon="pencil" :href="route('admin.employees.edit', $employee['id'])">تعديل</x-button>
                </div>
            </div>
        @endforeach
    </div>
    </div>
</x-layouts::admin>
