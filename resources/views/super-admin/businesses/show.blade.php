<x-layouts::super-admin title="ملف الشركة">

    @php
        $b = \App\Support\Demo::business(request()->route('id'));
        $sub = \App\Support\Demo::subscriptions()[0];
    @endphp

    <x-page-header title="ملف الشركة" :subtitle="$b['name']"
        :breadcrumbs="['الرئيسية' => route('super-admin.dashboard'), 'الشركات' => route('super-admin.businesses.index'), $b['name'] => '#']">
        <x-slot:actions>
            <x-button variant="outline" icon="pencil" :href="route('super-admin.businesses.edit', $b['id'])">تعديل</x-button>
            <x-button variant="danger" type="button" icon="ban" @click="$store.toasts.add('تم تعطيل الشركة', 'warning')">تعطيل</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- رأس ملف الشركة --}}
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 mb-6">
        <div class="flex flex-col sm:flex-row sm:items-center gap-5">
            <img src="{{ $b['logo'] }}" alt="{{ $b['name'] }}" class="w-24 h-24 rounded-2xl object-cover border border-gray-100" />
            <div class="flex-1">
                <div class="flex items-center gap-3 flex-wrap">
                    <h2 class="text-xl font-bold text-gray-800">{{ $b['name'] }}</h2>
                    <x-badge :text="$b['status']" />
                </div>
                <p class="mt-1 text-sm text-gray-500">{{ $b['type'] }} — {{ $b['city'] }}، {{ $b['country'] }}</p>
                <div class="mt-3 flex items-center gap-4 text-sm text-gray-500 flex-wrap">
                    <span class="flex items-center gap-1.5"><x-icon name="user" class="w-4 h-4" /> {{ $b['owner'] }}</span>
                    <span class="flex items-center gap-1.5" dir="ltr"><x-icon name="phone" class="w-4 h-4" /> {{ $b['phone'] }}</span>
                    <span class="flex items-center gap-1.5"><x-icon name="layers" class="w-4 h-4" /> باقة {{ $b['plan'] }}</span>
                </div>
            </div>
        </div>
    </div>

    {{-- بطاقات إحصائية سريعة --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <x-stat-card label="الفروع" :value="$b['branches']" icon="git-branch" color="primary" />
        <x-stat-card label="الموظفون" value="14" icon="users" color="info" />
        <x-stat-card label="المنتجات" value="128" icon="package" color="secondary" />
        <x-stat-card label="الطلبات" value="642" icon="shopping-bag" color="success" />
    </div>

    {{-- بطاقات المعلومات --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <h3 class="font-bold text-gray-800 mb-4">بيانات الشركة</h3>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-gray-500">الاسم</dt><dd class="font-medium text-gray-800">{{ $b['name'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-500">النوع</dt><dd class="font-medium text-gray-800">{{ $b['type'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-500">المدينة</dt><dd class="font-medium text-gray-800">{{ $b['city'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-500">الدولة</dt><dd class="font-medium text-gray-800">{{ $b['country'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-500">تاريخ التسجيل</dt><dd class="font-medium text-gray-800">{{ $b['registered'] }}</dd></div>
            </dl>
        </div>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <h3 class="font-bold text-gray-800 mb-4">بيانات المالك</h3>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-gray-500">الاسم</dt><dd class="font-medium text-gray-800">{{ $b['owner'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-500">الهاتف</dt><dd class="font-medium text-gray-800" dir="ltr">{{ $b['phone'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-500">البريد</dt><dd class="font-medium text-gray-800" dir="ltr">{{ $b['email'] }}</dd></div>
            </dl>
        </div>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <h3 class="font-bold text-gray-800 mb-4">الاشتراك الحالي</h3>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-gray-500">الباقة</dt><dd class="font-medium text-gray-800">{{ $sub['plan'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-500">تاريخ البداية</dt><dd class="font-medium text-gray-800">{{ $sub['start'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-500">تاريخ الانتهاء</dt><dd class="font-medium text-gray-800">{{ $sub['end'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-500">المبلغ</dt><dd class="font-medium text-gray-800">{{ \App\Support\Demo::money($sub['amount']) }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-500">حالة الدفع</dt><dd><x-badge :text="$sub['payment']" /></dd></div>
            </dl>
        </div>
    </div>

    {{-- التبويبات --}}
    <div x-data="{ tab: 'overview' }" class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
        <div class="border-b border-gray-100 px-4">
            <nav class="flex gap-1 -mb-px overflow-x-auto">
                <button type="button" @click="tab = 'overview'"
                    :class="tab === 'overview' ? 'border-primary-600 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                    class="px-4 py-3 text-sm font-medium border-b-2 whitespace-nowrap transition">نظرة عامة</button>
                <button type="button" @click="tab = 'branches'"
                    :class="tab === 'branches' ? 'border-primary-600 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                    class="px-4 py-3 text-sm font-medium border-b-2 whitespace-nowrap transition">الفروع</button>
                <button type="button" @click="tab = 'orders'"
                    :class="tab === 'orders' ? 'border-primary-600 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                    class="px-4 py-3 text-sm font-medium border-b-2 whitespace-nowrap transition">آخر الطلبات</button>
            </nav>
        </div>

        <div class="p-5">
            {{-- نظرة عامة --}}
            <div x-show="tab === 'overview'">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="rounded-xl bg-gray-50 p-4">
                        <p class="text-sm text-gray-500">إجمالي المبيعات</p>
                        <p class="mt-1 text-lg font-bold text-gray-800">{{ \App\Support\Demo::money(18420.5) }}</p>
                    </div>
                    <div class="rounded-xl bg-gray-50 p-4">
                        <p class="text-sm text-gray-500">عدد الطلبات</p>
                        <p class="mt-1 text-lg font-bold text-gray-800">642</p>
                    </div>
                    <div class="rounded-xl bg-gray-50 p-4">
                        <p class="text-sm text-gray-500">متوسط قيمة الطلب</p>
                        <p class="mt-1 text-lg font-bold text-gray-800">{{ \App\Support\Demo::money(28.7) }}</p>
                    </div>
                </div>
                <p class="mt-4 text-sm text-gray-500 leading-relaxed">
                    شركة {{ $b['name'] }} مسجلة منذ {{ $b['registered'] }} وتعمل في مجال {{ $b['type'] }} بمدينة {{ $b['city'] }}.
                    تمتلك {{ $b['branches'] }} فروع وتشترك حاليًا في {{ $b['plan'] }}.
                </p>
            </div>

            {{-- الفروع --}}
            <div x-show="tab === 'branches'" x-cloak>
                <x-table :headers="['الفرع', 'المدينة', 'المدير', 'عدد الموظفين', 'الحالة']">
                    @for ($i = 1; $i <= $b['branches']; $i++)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ $i === 1 ? 'الفرع الرئيسي' : 'فرع ' . $b['city'] . ' ' . $i }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $b['city'] }}</td>
                            <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ ['أحمد محمد', 'سارة حسن', 'خالد علي'][$i % 3] }}</td>
                            <td class="px-4 py-3 text-gray-600 text-center">{{ rand(2, 8) }}</td>
                            <td class="px-4 py-3"><x-badge text="نشط" /></td>
                        </tr>
                    @endfor
                </x-table>
            </div>

            {{-- آخر الطلبات --}}
            <div x-show="tab === 'orders'" x-cloak>
                <x-table :headers="['رقم الطلب', 'العميل', 'العناصر', 'الإجمالي', 'الدفع', 'الحالة', 'التاريخ']">
                    @foreach (array_slice(\App\Support\Demo::orders(), 0, 8) as $o)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ $o['id'] }}</td>
                            <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $o['customer'] }}</td>
                            <td class="px-4 py-3 text-gray-600 text-center">{{ $o['items_count'] }}</td>
                            <td class="px-4 py-3 font-medium text-gray-800 whitespace-nowrap">{{ \App\Support\Demo::money($o['total']) }}</td>
                            <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $o['payment'] }}</td>
                            <td class="px-4 py-3"><x-badge :text="$o['status']" /></td>
                            <td class="px-4 py-3 text-gray-500 whitespace-nowrap">{{ $o['date'] }}</td>
                        </tr>
                    @endforeach
                </x-table>
            </div>
        </div>
    </div>

</x-layouts::super-admin>
