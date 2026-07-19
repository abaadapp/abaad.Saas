<x-layouts::admin title="إضافة موظف">
    <x-page-header
        title="إضافة موظف جديد"
        subtitle="أدخل بيانات الموظف وحدد دوره وصلاحياته"
        :breadcrumbs="['الرئيسية' => route('admin.dashboard'), 'الموظفون' => route('admin.employees.index'), 'إضافة موظف' => '#']"
    >
        <x-slot:actions>
            <x-button variant="outline" size="md" icon="arrow-right" :href="route('admin.employees.index')">رجوع</x-button>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('admin.employees.store') }}" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        @csrf
        {{-- البيانات الأساسية --}}
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-6 flex items-center gap-2">
                    <x-icon name="user" class="w-5 h-5 text-primary-600" /> البيانات الأساسية
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-input label="الاسم الكامل" name="name" placeholder="مثال: أحمد محمد" icon="user" :required="true" />
                    <x-select label="الوظيفة / الدور" name="role" placeholder="اختر الدور..." :options="[
                        'manager' => 'مدير',
                        'cashier' => 'كاشير',
                        'sales' => 'موظف مبيعات',
                        'accountant' => 'محاسب',
                        'inventory' => 'مسؤول مخزون',
                        'delivery' => 'مندوب توصيل',
                    ]" :required="true" />
                    <x-select label="الفرع" name="branch" :options="[
                        'الفرع الرئيسي' => 'الفرع الرئيسي - مسقط',
                        'فرع الخوير' => 'فرع الخوير',
                        'فرع روي' => 'فرع روي',
                        'فرع صحار' => 'فرع صحار',
                    ]" selected="الفرع الرئيسي" />
                    <x-input label="رقم الهاتف" name="phone" type="tel" placeholder="+968 9xxxxxxx" icon="phone" :required="true" />
                    <x-input label="البريد الإلكتروني" name="email" type="email" placeholder="name@example.com" icon="mail" :required="true" />
                    <x-input label="كلمة المرور" name="password" type="password" placeholder="********" icon="lock" hint="8 أحرف على الأقل" :required="true" />
                </div>
            </div>

            {{-- الصلاحيات --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-2 flex items-center gap-2">
                    <x-icon name="shield-check" class="w-5 h-5 text-primary-600" /> الصلاحيات
                </h3>
                <p class="text-sm text-gray-500 mb-5">حدد ما يستطيع الموظف الوصول إليه داخل النظام.</p>

                @php
                    $permissionGroups = [
                        'المبيعات ونقطة البيع' => ['فتح نقطة البيع', 'إنشاء طلب جديد', 'تطبيق خصومات', 'إلغاء طلب', 'إدارة المرتجعات'],
                        'المنتجات والمخزون' => ['عرض المنتجات', 'إضافة / تعديل منتج', 'إدارة حركات المخزون', 'الجرد'],
                        'العملاء والتقارير' => ['عرض العملاء', 'إدارة العملاء', 'عرض التقارير', 'تصدير البيانات'],
                        'الإدارة' => ['إدارة الموظفين', 'إدارة المصروفات', 'تعديل الإعدادات'],
                    ];
                @endphp
                <div class="space-y-6">
                    @foreach ($permissionGroups as $group => $perms)
                        <div>
                            <h4 class="text-sm font-semibold text-gray-700 mb-3">{{ $group }}</h4>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                                @foreach ($perms as $perm)
                                    <label class="flex items-center gap-2.5 rounded-xl border border-gray-200 px-4 py-2.5 cursor-pointer hover:bg-gray-50">
                                        <input type="checkbox" class="w-4 h-4 rounded text-primary-600 focus:ring-primary-500 border-gray-300" />
                                        <span class="text-sm text-gray-700">{{ $perm }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- الصورة والحالة --}}
        <div class="lg:col-span-1 space-y-6">
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-sm font-bold text-gray-800 mb-4">صورة الموظف</h3>
                <div class="flex flex-col items-center">
                    <div class="w-28 h-28 rounded-full bg-gray-50 border-2 border-dashed border-gray-200 flex items-center justify-center text-gray-300">
                        <x-icon name="user" class="w-12 h-12" />
                    </div>
                    <label class="mt-4 w-full">
                        <input type="file" accept="image/*" class="hidden" />
                        <span class="flex items-center justify-center gap-2 rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 cursor-pointer">
                            <x-icon name="upload" class="w-4 h-4" /> رفع صورة
                        </span>
                    </label>
                    <p class="text-xs text-gray-400 mt-2 text-center">PNG أو JPG بحد أقصى 2 ميجابايت</p>
                </div>
            </div>

            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-sm font-bold text-gray-800 mb-4">حالة الحساب</h3>
                <label class="flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3">
                    <div>
                        <span class="text-sm font-medium text-gray-700">تفعيل الحساب</span>
                        <p class="text-xs text-gray-400">السماح للموظف بتسجيل الدخول</p>
                    </div>
                    <input type="checkbox" checked class="w-5 h-5 rounded text-primary-600 focus:ring-primary-500 border-gray-300" />
                </label>
            </div>
        </div>

        {{-- أزرار الحفظ --}}
        <div class="lg:col-span-3 flex items-center justify-end gap-2">
            <x-button variant="outline" size="md" :href="route('admin.employees.index')">إلغاء</x-button>
            <x-button variant="primary" size="md" type="submit" icon="save">حفظ الموظف</x-button>
        </div>
    </form>
</x-layouts::admin>
