<x-layouts::super-admin title="الشركات">

    <x-page-header title="الشركات" subtitle="إدارة جميع الشركات المسجلة في المنصة"
        :breadcrumbs="['الرئيسية' => route('super-admin.dashboard'), 'الشركات' => '#']">
        <x-slot:actions>
            <x-button variant="outline" icon="download" :href="route('super-admin.export.businesses')">تصدير CSV</x-button>
            <x-button variant="primary" icon="plus" :href="route('super-admin.businesses.create')">إضافة شركة جديدة</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- شريط الفلاتر --}}
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 mb-6">
        <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3 items-end">
            <div class="lg:col-span-2">
                <x-input label="بحث" name="q" icon="search" placeholder="ابحث بالاسم أو المالك أو البريد..." :value="$filters['q'] ?? ''" />
            </div>
            <x-select label="نوع النشاط" name="type" placeholder="كل الأنواع" selected="{{ $filters['type'] ?? '' }}"
                :options="['محل ورود' => 'محل ورود', 'مطعم' => 'مطعم', 'كافيه' => 'كافيه', 'بقالة' => 'بقالة', 'صيدلية' => 'صيدلية', 'متجر ملابس' => 'متجر ملابس']" />
            <x-select label="الباقة" name="plan" placeholder="كل الباقات" selected="{{ $filters['plan'] ?? '' }}"
                :options="!empty(\App\Models\Plan::pluck('name','name')->toArray()) ? \App\Models\Plan::pluck('name','name')->toArray() : ['أساسية' => 'أساسية', 'احترافية' => 'احترافية', 'مؤسسات' => 'مؤسسات']" />
            <x-select label="الحالة" name="status" placeholder="كل الحالات" selected="{{ $filters['status'] ?? '' }}"
                :options="['نشط' => 'نشط', 'منتهي' => 'منتهي', 'معطل' => 'معطل']" />
            <div class="flex items-end gap-2">
                <x-button variant="primary" type="submit" icon="filter">تصفية</x-button>
                <x-button variant="outline" :href="url()->current()">تفريغ</x-button>
            </div>
        </form>
    </div>

    {{-- جدول الشركات --}}
    <x-table :headers="['الشركة', 'النوع', 'المالك', 'الهاتف', 'البريد', 'الباقة', 'الحالة', 'التسجيل', 'الفروع', 'إجراءات']">
        @foreach ($businesses as $b)
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3">
                    <div class="flex items-center gap-3">
                        <img src="{{ $b['logo'] }}" alt="{{ $b['name'] }}" class="w-9 h-9 rounded-lg object-cover border border-gray-100" />
                        <span class="font-medium text-gray-800 whitespace-nowrap">{{ $b['name'] }}</span>
                    </div>
                </td>
                <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $b['type'] }}</td>
                <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $b['owner'] }}</td>
                <td class="px-4 py-3 text-gray-500 whitespace-nowrap" dir="ltr">{{ $b['phone'] }}</td>
                <td class="px-4 py-3 text-gray-500 whitespace-nowrap" dir="ltr">{{ $b['email'] }}</td>
                <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $b['plan'] }}</td>
                <td class="px-4 py-3"><x-badge :text="$b['status']" /></td>
                <td class="px-4 py-3 text-gray-500 whitespace-nowrap">{{ $b['registered'] }}</td>
                <td class="px-4 py-3 text-gray-600 text-center">{{ $b['branches'] }}</td>
                <td class="px-4 py-3">
                    <x-dropdown align="left" width="w-44">
                        <x-slot:trigger>
                            <button class="w-9 h-9 flex items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100">
                                <x-icon name="ellipsis-vertical" class="w-4 h-4" />
                            </button>
                        </x-slot:trigger>
                        <a href="{{ route('super-admin.businesses.show', $b['id']) }}" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                            <x-icon name="eye" class="w-4 h-4" /> عرض
                        </a>
                        <a href="{{ route('super-admin.businesses.edit', $b['id']) }}" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                            <x-icon name="pencil" class="w-4 h-4" /> تعديل
                        </a>
                        <form method="POST" action="{{ route('super-admin.businesses.destroy', $b['id']) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-danger-600 hover:bg-gray-50 text-right">
                                <x-icon name="ban" class="w-4 h-4" /> تعطيل
                            </button>
                        </form>
                    </x-dropdown>
                </td>
            </tr>
        @endforeach

        <x-slot:footer>
            <x-pagination :paginator="$businesses" />
        </x-slot:footer>
    </x-table>

</x-layouts::super-admin>
