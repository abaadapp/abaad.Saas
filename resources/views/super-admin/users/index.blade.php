<x-layouts::super-admin :title="__('المستخدمون')">
    <x-page-header
        :title="__('المستخدمون')"
        :subtitle="__('إدارة مستخدمي المنصة وأدوارهم وصلاحياتهم')"
        :breadcrumbs="[__('الرئيسية') => route('super-admin.dashboard'), __('المستخدمون') => '#']"
    >
        <x-slot:actions>
            <x-button variant="primary" size="md" icon="user-plus" @click="$dispatch('open-modal','add-user')">{{ __('إضافة مستخدم') }}</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- نافذة إضافة مستخدم --}}
    <x-modal name="add-user" :title="__('إضافة مستخدم جديد')">
        <form id="add-user-form" method="POST" action="{{ route('super-admin.users.store') }}" class="space-y-4">
            @csrf
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm text-gray-600 mb-1">{{ __('الاسم') }} <span class="text-danger-500">*</span></label>
                    <input type="text" name="name" required class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200" />
                </div>
                <div>
                    <label class="block text-sm text-gray-600 mb-1">{{ __('الهاتف') }}</label>
                    <input type="text" name="phone" dir="ltr" class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200" />
                </div>
            </div>
            <div>
                <label class="block text-sm text-gray-600 mb-1">{{ __('البريد الإلكتروني') }} <span class="text-danger-500">*</span></label>
                <input type="email" name="email" required dir="ltr" class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200" />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm text-gray-600 mb-1">{{ __('الدور') }} <span class="text-danger-500">*</span></label>
                    <select name="role" required class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200">
                        <option value="super_admin">{{ __('مدير المنصة') }}</option>
                        <option value="admin">{{ __('مدير نشاط') }}</option>
                        <option value="manager">{{ __('مدير فرع') }}</option>
                        <option value="cashier">{{ __('كاشير') }}</option>
                        <option value="sales">{{ __('موظف مبيعات') }}</option>
                        <option value="accountant">{{ __('محاسب') }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-gray-600 mb-1">{{ __('النشاط التجاري') }}</label>
                    <select name="business_id" class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200">
                        <option value="">{{ __('— المنصة —') }}</option>
                        @foreach (\App\Support\Demo::businesses() as $biz)
                            <option value="{{ $biz['id'] }}">{{ $biz['name'] }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div>
                <label class="block text-sm text-gray-600 mb-1">{{ __('كلمة المرور') }}</label>
                <input type="text" name="password" placeholder="{{ __('افتراضي: password') }}" class="w-full rounded-lg border-gray-200 focus:border-primary-400 focus:ring-primary-200" />
            </div>
        </form>
        <x-slot:footer>
            <x-button variant="light" @click="$dispatch('close-modal')">{{ __('إلغاء') }}</x-button>
            <button type="submit" form="add-user-form" class="bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-full px-4 py-2">{{ __('إضافة') }}</button>
        </x-slot:footer>
    </x-modal>

    {{-- شريط الفلاتر --}}
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 mb-6">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
            <x-input name="q" :placeholder="__('ابحث بالاسم أو البريد...')" icon="search" :value="$filters['q'] ?? ''" />
            <x-select name="role" :placeholder="__('كل الأدوار')" selected="{{ $filters['role'] ?? '' }}"
                :options="['super_admin' => __('مدير المنصة'), 'admin' => __('مدير'), 'manager' => __('مشرف'), 'cashier' => __('كاشير'), 'accountant' => __('محاسب'), 'inventory' => __('مسؤول مخزون'), 'sales' => __('موظف مبيعات'), 'delivery' => __('مندوب توصيل')]" />
            <x-select name="status" :placeholder="__('كل الحالات')" selected="{{ $filters['status'] ?? '' }}"
                :options="['نشط' => __('نشط'), 'موقوف' => __('موقوف')]" />
            <div class="flex items-end gap-2">
                <x-button variant="primary" type="submit" icon="filter">{{ __('تصفية') }}</x-button>
                <x-button variant="outline" :href="url()->current()">{{ __('عرض الكل') }}</x-button>
            </div>
        </form>
    </div>

    {{-- جدول المستخدمين --}}
    <x-table :headers="[__('المستخدم'), __('البريد الإلكتروني'), __('الهاتف'), __('الشركة'), __('الدور'), __('الحالة'), __('آخر تسجيل دخول'), __('إجراءات')]">
        @foreach ($users as $user)
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 whitespace-nowrap">
                    <div class="flex items-center gap-3">
                        <img src="{{ $user['avatar'] }}" alt="{{ $user['name'] }}" class="w-9 h-9 rounded-full object-cover" />
                        <span class="font-medium text-gray-800">{{ $user['name'] }}</span>
                    </div>
                </td>
                <td class="px-4 py-3 text-gray-600 whitespace-nowrap" dir="ltr">{{ $user['email'] }}</td>
                <td class="px-4 py-3 text-gray-600 whitespace-nowrap" dir="ltr">{{ $user['phone'] }}</td>
                <td class="px-4 py-3 text-gray-700 whitespace-nowrap">{{ $user['business'] }}</td>
                <td class="px-4 py-3 whitespace-nowrap"><x-badge type="info" :text="__($user['role'])" /></td>
                <td class="px-4 py-3 whitespace-nowrap"><x-badge :text="__($user['status'])" /></td>
                <td class="px-4 py-3 text-gray-500 whitespace-nowrap" dir="ltr">{{ $user['last_login'] }}</td>
                <td class="px-4 py-3 whitespace-nowrap">
                    <x-button variant="light" size="sm" icon="eye" :href="route('super-admin.users.show', $user['id'])">{{ __('عرض') }}</x-button>
                </td>
            </tr>
        @endforeach

        <x-slot:footer>
            <x-pagination :paginator="$users" />
        </x-slot:footer>
    </x-table>
</x-layouts::super-admin>
