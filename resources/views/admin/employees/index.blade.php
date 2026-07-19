<x-layouts::admin title="الموظفون">
    <x-page-header
        title="الموظفون"
        subtitle="إدارة فريق العمل وصلاحياتهم ومتابعة أدائهم"
        :breadcrumbs="['الرئيسية' => route('admin.dashboard'), 'الموظفون' => '#']"
    >
        <x-slot:actions>
            <x-button variant="outline" size="md" icon="download">تصدير</x-button>
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
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 mb-6">
        <div class="flex flex-col md:flex-row md:items-center gap-3">
            <div class="flex-1">
                <x-input name="search" placeholder="ابحث عن موظف بالاسم أو البريد..." icon="search" />
            </div>
            <div class="w-full md:w-56">
                <x-select name="role" placeholder="كل الأدوار" :options="[
                    'مدير' => 'مدير',
                    'كاشير' => 'كاشير',
                    'موظف مبيعات' => 'موظف مبيعات',
                    'محاسب' => 'محاسب',
                    'مسؤول مخزون' => 'مسؤول مخزون',
                    'مندوب توصيل' => 'مندوب توصيل',
                ]" />
            </div>
            <x-button variant="light" size="md" icon="filter">تصفية</x-button>
        </div>
    </div>

    {{-- شبكة بطاقات الموظفين --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach (\App\Support\Demo::employees() as $employee)
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 hover:shadow-md transition-shadow">
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
                    <li class="flex items-center justify-between">
                        <span class="text-gray-500 flex items-center gap-2"><x-icon name="trending-up" class="w-4 h-4" /> المبيعات</span>
                        <span class="font-semibold text-gray-800">{{ \App\Support\Demo::money($employee['sales']) }}</span>
                    </li>
                </ul>

                <div class="mt-4 flex items-center gap-2">
                    <x-button variant="light" size="sm" icon="eye" :href="route('admin.employees.show', $employee['id'])" class="flex-1">عرض الملف</x-button>
                    <x-button variant="outline" size="sm" icon="pencil" :href="route('admin.employees.edit', $employee['id'])">تعديل</x-button>
                </div>
            </div>
        @endforeach
    </div>
</x-layouts::admin>
