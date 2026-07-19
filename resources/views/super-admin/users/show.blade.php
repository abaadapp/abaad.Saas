@php $user = \App\Support\Demo::platformUser(request()->route('id')); @endphp

<x-layouts::super-admin :title="$user['name']">
    <x-page-header
        title="ملف المستخدم"
        subtitle="عرض تفاصيل المستخدم وصلاحياته ونشاطه"
        :breadcrumbs="['الرئيسية' => route('super-admin.dashboard'), 'المستخدمون' => route('super-admin.users.index'), $user['name'] => '#']"
    >
        <x-slot:actions>
            <x-button variant="outline" size="md" icon="arrow-right" :href="route('super-admin.users.index')">رجوع</x-button>
            <x-button variant="primary" size="md" icon="pencil">تعديل</x-button>
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- بطاقة الملف الشخصي --}}
        <div class="lg:col-span-1 space-y-6">
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 text-center">
                <img src="{{ $user['avatar'] }}" alt="{{ $user['name'] }}" class="w-24 h-24 rounded-full object-cover mx-auto ring-4 ring-primary-50" />
                <h2 class="mt-4 text-lg font-bold text-gray-800">{{ $user['name'] }}</h2>
                <div class="mt-2 flex items-center justify-center gap-2">
                    <x-badge type="info" :text="$user['role']" />
                    <x-badge :text="$user['status']" />
                </div>
                <div class="mt-5 flex items-center justify-center gap-2">
                    <x-button variant="light" size="sm" icon="mail">مراسلة</x-button>
                    <x-button variant="outline" size="sm" icon="lock">تعطيل</x-button>
                </div>
            </div>

            {{-- بيانات الاتصال --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-sm font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <x-icon name="contact" class="w-4 h-4 text-primary-600" /> بيانات الاتصال
                </h3>
                <ul class="space-y-3 text-sm">
                    <li class="flex items-center justify-between">
                        <span class="text-gray-500">البريد الإلكتروني</span>
                        <span class="text-gray-800" dir="ltr">{{ $user['email'] }}</span>
                    </li>
                    <li class="flex items-center justify-between">
                        <span class="text-gray-500">الهاتف</span>
                        <span class="text-gray-800" dir="ltr">{{ $user['phone'] }}</span>
                    </li>
                </ul>
            </div>

            {{-- الشركة المرتبطة --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-sm font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <x-icon name="building-2" class="w-4 h-4 text-primary-600" /> الشركة المرتبطة
                </h3>
                <div class="flex items-center gap-3">
                    <span class="w-10 h-10 rounded-xl bg-secondary-50 text-secondary-600 flex items-center justify-center">
                        <x-icon name="store" class="w-5 h-5" />
                    </span>
                    <div>
                        <p class="font-medium text-gray-800">{{ $user['business'] }}</p>
                        <p class="text-xs text-gray-400">الباقة الاحترافية</p>
                    </div>
                </div>
            </div>

            {{-- معلومات الحساب --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-sm font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <x-icon name="info" class="w-4 h-4 text-primary-600" /> معلومات الحساب
                </h3>
                <ul class="space-y-3 text-sm">
                    <li class="flex items-center justify-between">
                        <span class="text-gray-500">آخر تسجيل دخول</span>
                        <span class="text-gray-800" dir="ltr">{{ $user['last_login'] }}</span>
                    </li>
                    <li class="flex items-center justify-between">
                        <span class="text-gray-500">تاريخ الإنشاء</span>
                        <span class="text-gray-800" dir="ltr">2025-01-12</span>
                    </li>
                    <li class="flex items-center justify-between">
                        <span class="text-gray-500">رقم المستخدم</span>
                        <span class="text-gray-800 font-mono">#{{ $user['id'] }}</span>
                    </li>
                </ul>
            </div>
        </div>

        {{-- التبويبات --}}
        <div class="lg:col-span-2">
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm" x-data="{ tab: 'activities' }">
                <div class="flex items-center gap-1 border-b border-gray-100 px-4">
                    <button
                        @click="tab = 'activities'"
                        :class="tab === 'activities' ? 'text-primary-600 border-primary-600' : 'text-gray-500 border-transparent hover:text-gray-700'"
                        class="px-4 py-3.5 text-sm font-medium border-b-2 transition-colors"
                    >النشاطات</button>
                    <button
                        @click="tab = 'permissions'"
                        :class="tab === 'permissions' ? 'text-primary-600 border-primary-600' : 'text-gray-500 border-transparent hover:text-gray-700'"
                        class="px-4 py-3.5 text-sm font-medium border-b-2 transition-colors"
                    >الصلاحيات</button>
                </div>

                {{-- تبويب النشاطات --}}
                <div x-show="tab === 'activities'" class="p-6">
                    <ul class="space-y-5">
                        @foreach (\App\Support\Demo::activities() as $act)
                            <li class="flex items-start gap-3">
                                <span class="shrink-0 w-9 h-9 rounded-xl flex items-center justify-center bg-{{ $act['color'] }}-50 text-{{ $act['color'] }}-600">
                                    <x-icon :name="$act['icon']" class="w-4 h-4" />
                                </span>
                                <div>
                                    <p class="text-sm text-gray-700">{{ $act['text'] }}</p>
                                    <p class="text-xs text-gray-400 mt-0.5">{{ $act['time'] }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>

                {{-- تبويب الصلاحيات --}}
                <div x-show="tab === 'permissions'" x-cloak class="p-6">
                    <p class="text-sm text-gray-500 mb-4">تحكم في صلاحيات هذا المستخدم داخل المنصة.</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        @php
                            $permissions = [
                                'إدارة الشركات' => true,
                                'إدارة الاشتراكات' => true,
                                'إدارة الباقات' => false,
                                'عرض الفواتير' => true,
                                'إدارة المستخدمين' => false,
                                'عرض التقارير' => true,
                                'تصدير البيانات' => true,
                                'إدارة الإعدادات' => false,
                            ];
                        @endphp
                        @foreach ($permissions as $perm => $granted)
                            <label class="flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3 cursor-pointer hover:bg-gray-50">
                                <span class="text-sm font-medium text-gray-700">{{ $perm }}</span>
                                <input type="checkbox" @checked($granted) class="w-5 h-5 rounded text-primary-600 focus:ring-primary-500 border-gray-300" />
                            </label>
                        @endforeach
                    </div>
                    <div class="mt-6 flex justify-end">
                        <x-button variant="primary" size="md" icon="save" x-on:click="$store.toasts.add('تم تحديث الصلاحيات بنجاح', 'success')">حفظ الصلاحيات</x-button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-layouts::super-admin>
