@php $employee = \App\Support\Demo::employee(request()->route('id')); @endphp

<x-layouts::admin :title="$employee['name']">
    <x-page-header
        :title="__('ملف الموظف')"
        :subtitle="__('عرض بيانات الموظف وأدائه وصلاحياته')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('الموظفون') => route('admin.employees.index'), $employee['name'] => '#']"
    >
        <x-slot:actions>
            <x-button variant="outline" size="md" icon="arrow-right" :href="route('admin.employees.index')">{{ __('رجوع') }}</x-button>
            <x-button variant="primary" size="md" icon="pencil" :href="route('admin.employees.edit', $employee['id'])">{{ __('تعديل') }}</x-button>
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- بطاقة الملف --}}
        <div class="lg:col-span-1 space-y-6">
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 text-center">
                <img src="{{ $employee['avatar'] }}" alt="{{ $employee['name'] }}" class="w-24 h-24 rounded-full object-cover mx-auto ring-4 ring-primary-50" />
                <h2 class="mt-4 text-lg font-bold text-gray-800">{{ $employee['name'] }}</h2>
                <div class="mt-2 flex items-center justify-center gap-2">
                    <x-badge type="primary" :text="__($employee['role'])" />
                    <x-badge :text="__($employee['status'])" />
                </div>
                <div class="mt-5 flex flex-wrap items-center justify-center gap-2">
                    <x-button variant="light" size="sm" icon="mail" :href="'mailto:' . $employee['email']">{{ __('مراسلة') }}</x-button>
                    <form method="POST" action="{{ route('admin.employees.toggle', $employee['id']) }}">
                        @csrf
                        @if ($employee['status'] === 'نشط')
                            <x-button variant="outline" size="sm" type="submit" icon="lock">{{ __('تعطيل الحساب') }}</x-button>
                        @else
                            <x-button variant="success" size="sm" type="submit" icon="lock-open">{{ __('تفعيل الحساب') }}</x-button>
                        @endif
                    </form>
                    @php $resetConfirm = __('توليد كلمة مرور مؤقتة جديدة؟'); @endphp
                    <form method="POST" action="{{ route('admin.employees.resetPassword', $employee['id']) }}"
                          @submit="if(!confirm(@js($resetConfirm))) $event.preventDefault()">
                        @csrf
                        <x-button variant="light" size="sm" type="submit" icon="key-round">{{ __('إعادة تعيين كلمة المرور') }}</x-button>
                    </form>
                </div>
            </div>

            {{-- بيانات الاتصال --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-sm font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <x-icon name="contact" class="w-4 h-4 text-primary-600" /> {{ __('بيانات الاتصال') }}
                </h3>
                <ul class="space-y-3 text-sm">
                    <li class="flex items-center justify-between">
                        <span class="text-gray-500">{{ __('البريد الإلكتروني') }}</span>
                        <span class="text-gray-800" dir="ltr">{{ $employee['email'] }}</span>
                    </li>
                    <li class="flex items-center justify-between">
                        <span class="text-gray-500">{{ __('الهاتف') }}</span>
                        <span class="text-gray-800" dir="ltr">{{ $employee['phone'] }}</span>
                    </li>
                </ul>
            </div>

            {{-- معلومات الوظيفة --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-sm font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <x-icon name="briefcase" class="w-4 h-4 text-primary-600" /> {{ __('معلومات الوظيفة') }}
                </h3>
                <ul class="space-y-3 text-sm">
                    <li class="flex items-center justify-between">
                        <span class="text-gray-500">{{ __('الدور') }}</span>
                        <span class="text-gray-800">{{ __($employee['role']) }}</span>
                    </li>
                    <li class="flex items-center justify-between">
                        <span class="text-gray-500">{{ __('الفرع') }}</span>
                        <span class="text-gray-800">{{ $employee['branch'] }}</span>
                    </li>
                    <li class="flex items-center justify-between">
                        <span class="text-gray-500">{{ __('تاريخ الالتحاق') }}</span>
                        <span class="text-gray-800" dir="ltr">2025-03-01</span>
                    </li>
                    <li class="flex items-center justify-between">
                        <span class="text-gray-500">{{ __('رقم الموظف') }}</span>
                        <span class="text-gray-800 font-mono">#{{ $employee['id'] }}</span>
                    </li>
                </ul>
            </div>

            {{-- ملخص الأداء --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-sm font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <x-icon name="target" class="w-4 h-4 text-primary-600" /> {{ __('ملخص الأداء') }}
                </h3>
                <div class="space-y-4">
                    <div>
                        <div class="flex items-center justify-between text-sm mb-1.5">
                            <span class="text-gray-500">{{ __('رضا العملاء') }}</span>
                            <span class="font-semibold text-gray-800">94%</span>
                        </div>
                        <div class="h-2 rounded-full bg-gray-100 overflow-hidden">
                            <div class="h-full bg-success-600 rounded-full" style="width: 94%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- المحتوى --}}
        <div class="lg:col-span-2 space-y-6">
            {{-- الإحصائيات --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <x-stat-card :label="__('إجمالي المبيعات')" :value="\App\Support\Demo::money($employee['sales'])" icon="trending-up" color="success" />
                <x-stat-card :label="__('عدد الطلبات')" value="128" icon="shopping-bag" color="primary" />
                <x-stat-card :label="__('متوسط الطلب')" :value="\App\Support\Demo::money($employee['sales'] / 128)" icon="calculator" color="info" />
                <x-stat-card :label="__('التقييم')" value="4.7 / 5" icon="star" color="warning" />
            </div>

            {{-- التبويبات --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm" x-data="{ tab: 'sales' }">
                <div class="flex items-center gap-1 border-b border-gray-100 px-4 overflow-x-auto">
                    <button type="button"
                        @click="tab = 'sales'"
                        :class="tab === 'sales' ? 'text-primary-600 border-primary-600' : 'text-gray-500 border-transparent hover:text-gray-700'"
                        class="px-4 py-3.5 text-sm font-medium border-b-2 whitespace-nowrap transition-colors"
                    >{{ __('المبيعات') }}</button>
                    <button type="button"
                        @click="tab = 'activity'"
                        :class="tab === 'activity' ? 'text-primary-600 border-primary-600' : 'text-gray-500 border-transparent hover:text-gray-700'"
                        class="px-4 py-3.5 text-sm font-medium border-b-2 whitespace-nowrap transition-colors"
                    >{{ __('سجل النشاط') }}</button>
                    <button type="button"
                        @click="tab = 'permissions'"
                        :class="tab === 'permissions' ? 'text-primary-600 border-primary-600' : 'text-gray-500 border-transparent hover:text-gray-700'"
                        class="px-4 py-3.5 text-sm font-medium border-b-2 whitespace-nowrap transition-colors"
                    >{{ __('الصلاحيات') }}</button>
                </div>

                {{-- المبيعات --}}
                <div x-show="tab === 'sales'" class="p-6">
                    <h4 class="font-bold text-gray-800 mb-4">{{ __('مبيعات الموظف خلال آخر 12 شهرًا') }}</h4>
                    <div x-data='apexChart({
                        chart: { type: "area", height: 300, fontFamily: "IBM Plex Sans Arabic", toolbar: { show: false } },
                        series: [{ name: "{{ __('المبيعات') }} (ر.ع)", data: [1200, 1450, 1380, 1600, 1720, 1550, 1890, 2100, 1980, 2240, 2380, 2560] }],
                        xaxis: { categories: ["{{ __('يناير') }}","{{ __('فبراير') }}","{{ __('مارس') }}","{{ __('أبريل') }}","{{ __('مايو') }}","{{ __('يونيو') }}","{{ __('يوليو') }}","{{ __('أغسطس') }}","{{ __('سبتمبر') }}","{{ __('أكتوبر') }}","{{ __('نوفمبر') }}","{{ __('ديسمبر') }}"] },
                        colors: ["#7c3aed"],
                        dataLabels: { enabled: false },
                        stroke: { curve: "smooth", width: 2 },
                        fill: { type: "gradient", gradient: { opacityFrom: 0.35, opacityTo: 0.05 } },
                        grid: { borderColor: "#f1f5f9" }
                    })'></div>
                </div>

                {{-- سجل النشاط --}}
                <div x-show="tab === 'activity'" x-cloak class="p-6">
                    @php
                        $log = [
                            ['text' => 'أتمّ طلب بيع بقيمة 45.000 ر.ع', 'time' => 'قبل 20 دقيقة', 'icon' => 'shopping-bag', 'color' => 'success'],
                            ['text' => 'سجّل دخولًا إلى نقطة البيع', 'time' => 'قبل ساعتين', 'icon' => 'log-in', 'color' => 'info'],
                            ['text' => 'طبّق خصمًا 10% على طلب', 'time' => 'أمس 4:20 م', 'icon' => 'percent', 'color' => 'warning'],
                            ['text' => 'أضاف عميلًا جديدًا', 'time' => 'أمس 11:05 ص', 'icon' => 'user-plus', 'color' => 'primary'],
                            ['text' => 'أنهى وردية العمل', 'time' => 'قبل يومين', 'icon' => 'log-out', 'color' => 'secondary'],
                        ];
                    @endphp
                    <ul class="space-y-5">
                        @foreach ($log as $item)
                            <li class="flex items-start gap-3">
                                <span class="shrink-0 w-9 h-9 rounded-xl flex items-center justify-center bg-{{ $item['color'] }}-50 text-{{ $item['color'] }}-600">
                                    <x-icon :name="$item['icon']" class="w-4 h-4" />
                                </span>
                                <div>
                                    <p class="text-sm text-gray-700">{{ __($item['text']) }}</p>
                                    <p class="text-xs text-gray-400 mt-0.5">{{ __($item['time']) }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>

                {{-- الصلاحيات --}}
                <div x-show="tab === 'permissions'" x-cloak class="p-6">
                    <p class="text-sm text-gray-500 mb-4">{{ __('تحكم في صلاحيات هذا الموظف داخل النظام.') }}</p>
                    @php
                        $permissions = [
                            'فتح نقطة البيع' => true,
                            'إنشاء طلب جديد' => true,
                            'تطبيق خصومات' => true,
                            'إلغاء طلب' => false,
                            'إدارة المنتجات' => false,
                            'إدارة المخزون' => true,
                            'عرض التقارير' => true,
                            'إدارة الموظفين' => false,
                            'إدارة المصروفات' => false,
                            'تعديل الإعدادات' => false,
                        ];
                        $permToast = "\$store.toasts.add('" . addslashes(__('تم تحديث الصلاحيات بنجاح')) . "', 'success')";
                    @endphp
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        @foreach ($permissions as $perm => $granted)
                            <label class="flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3 cursor-pointer hover:bg-gray-50">
                                <span class="text-sm font-medium text-gray-700">{{ __($perm) }}</span>
                                <input type="checkbox" @checked($granted) class="w-5 h-5 rounded text-primary-600 focus:ring-primary-500 border-gray-300" />
                            </label>
                        @endforeach
                    </div>
                    <div class="mt-6 flex justify-end">
                        <x-button variant="primary" size="md" icon="save" :x-on:click="$permToast">{{ __('حفظ الصلاحيات') }}</x-button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-layouts::admin>
