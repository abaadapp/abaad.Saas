<x-layouts::admin :title="__('إضافة موظف')">
    <x-page-header
        :title="__('إضافة موظف جديد')"
        :subtitle="__('أدخل بيانات الموظف وحدد دوره وصلاحياته')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('الموظفون') => route('admin.employees.index'), __('إضافة موظف') => '#']"
    >
        <x-slot:actions>
            <x-button variant="outline" size="md" icon="arrow-right" :href="route('admin.employees.index')">{{ __('رجوع') }}</x-button>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('admin.employees.store') }}" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        @csrf
        {{-- البيانات الأساسية --}}
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-6 flex items-center gap-2">
                    <x-icon name="user" class="w-5 h-5 text-primary-600" /> {{ __('البيانات الأساسية') }}
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-input :label="__('الاسم الكامل')" name="name" :placeholder="__('مثال: أحمد محمد')" icon="user" :required="true" />
                    <x-select :label="__('الوظيفة / الدور')" name="job_title" :placeholder="__('اختر الوظيفة...')" :required="true"
                        :options="\App\Models\JobTitle::where('business_id', \App\Support\Demo::bid())->orderBy('name')->get()->mapWithKeys(fn ($jt) => [$jt->name => __($jt->name)])->toArray()" />
                    <x-select :label="__('الفرع')" name="branch"
                        :options="collect(\App\Support\Demo::branches())->pluck('name', 'name')->toArray()" :selected="\App\Support\Demo::currentBranchName()" />
                    <x-input :label="__('رقم الهاتف')" name="phone" type="tel" placeholder="+968 9xxxxxxx" icon="phone" :required="true" />
                    <x-input :label="__('البريد الإلكتروني')" name="email" type="email" placeholder="name@example.com" icon="mail" :required="true" />
                    <x-input :label="__('كلمة المرور')" name="password" type="password" placeholder="********" icon="lock" :hint="__('8 أحرف على الأقل')" :required="true" />
                    <x-input :label="__('رمز الدخول السريع (٤ أرقام)')" name="pin" type="text" inputmode="numeric" maxlength="4" pattern="\d{4}" placeholder="مثال: 1234" icon="scan-barcode" :hint="__('يدخل به الموظف نقطة البيع مباشرة بلا بريد أو كلمة مرور')" />
                </div>
                @error('pin')<p class="mt-2 text-xs text-danger-500">{{ $message }}</p>@enderror
                @error('email')<p class="mt-2 text-xs text-danger-500">{{ $message }}</p>@enderror
            </div>

            {{-- الصلاحيات --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-2 flex items-center gap-2">
                    <x-icon name="shield-check" class="w-5 h-5 text-primary-600" /> {{ __('الصلاحيات') }}
                </h3>
                <p class="text-sm text-gray-500 mb-5">{{ __('حدد ما يستطيع الموظف الوصول إليه داخل النظام.') }}</p>

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
                            <h4 class="text-sm font-semibold text-gray-700 mb-3">{{ __($group) }}</h4>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                                @foreach ($perms as $perm)
                                    <label class="flex items-center gap-2.5 rounded-xl border border-gray-200 px-4 py-2.5 cursor-pointer hover:bg-gray-50">
                                        <input type="checkbox" class="w-4 h-4 rounded text-primary-600 focus:ring-primary-500 border-gray-300" />
                                        <span class="text-sm text-gray-700">{{ __($perm) }}</span>
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
                <h3 class="text-sm font-bold text-gray-800 mb-4">{{ __('صورة الموظف') }}</h3>
                <div class="flex flex-col items-center">
                    <div class="w-28 h-28 rounded-full bg-gray-50 border-2 border-dashed border-gray-200 flex items-center justify-center text-gray-300">
                        <x-icon name="user" class="w-12 h-12" />
                    </div>
                    <label class="mt-4 w-full">
                        <input type="file" accept="image/*" class="hidden" />
                        <span class="flex items-center justify-center gap-2 rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 cursor-pointer">
                            <x-icon name="upload" class="w-4 h-4" /> {{ __('رفع صورة') }}
                        </span>
                    </label>
                    <p class="text-xs text-gray-400 mt-2 text-center">{{ __('PNG أو JPG بحد أقصى 2 ميجابايت') }}</p>
                </div>
            </div>

            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-sm font-bold text-gray-800 mb-4">{{ __('حالة الحساب') }}</h3>
                <label class="flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3">
                    <div>
                        <span class="text-sm font-medium text-gray-700">{{ __('تفعيل الحساب') }}</span>
                        <p class="text-xs text-gray-400">{{ __('السماح للموظف بتسجيل الدخول') }}</p>
                    </div>
                    <input type="checkbox" checked class="w-5 h-5 rounded text-primary-600 focus:ring-primary-500 border-gray-300" />
                </label>
            </div>
        </div>

        {{-- أزرار الحفظ --}}
        <div class="lg:col-span-3 flex items-center justify-end gap-2">
            <x-button variant="outline" size="md" :href="route('admin.employees.index')">{{ __('إلغاء') }}</x-button>
            <x-button variant="primary" size="md" type="submit" icon="save">{{ __('حفظ الموظف') }}</x-button>
        </div>
    </form>
</x-layouts::admin>
