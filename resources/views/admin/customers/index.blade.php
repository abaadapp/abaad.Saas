<x-layouts::admin title="العملاء">
    <x-page-header
        title="العملاء"
        subtitle="إدارة قاعدة عملاء المحل وسجل مشترياتهم ونقاط ولائهم"
        :breadcrumbs="['الرئيسية' => route('admin.dashboard'), 'العملاء' => '#']"
    >
        <x-slot:actions>
            <x-button variant="outline" size="md" icon="download" :href="route('admin.export.customers')">تصدير CSV</x-button>
            <x-button variant="primary" size="md" icon="user-plus" x-on:click="$dispatch('open-modal','add-customer')">إضافة عميل</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- البطاقات الإحصائية --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <x-stat-card label="إجمالي العملاء" value="214" icon="users" trend="+7%" :up="true" color="primary" />
        <x-stat-card label="عملاء جدد هذا الشهر" value="28" icon="user-plus" trend="+12%" :up="true" color="success" />
        <x-stat-card label="إجمالي المشتريات" value="{{ \App\Support\Demo::money(48260.500) }}" icon="wallet" trend="+15%" :up="true" color="info" />
        <x-stat-card label="متوسط الإنفاق" value="{{ \App\Support\Demo::money(225.500) }}" icon="calculator" trend="+3%" :up="true" color="secondary" />
    </div>

    {{-- شريط الفلاتر --}}
    <form method="GET" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 mb-6">
        <div class="flex flex-col md:flex-row md:items-center gap-3">
            <div class="flex-1">
                <x-input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="ابحث بالاسم أو رقم الهاتف أو البريد..." icon="search" />
            </div>
            <div class="w-full md:w-56">
                <x-select name="sort" :options="[
                    'recent' => 'الأحدث تسجيلًا',
                    'spent' => 'الأعلى إنفاقًا',
                    'orders' => 'الأكثر طلبات',
                    'points' => 'الأعلى نقاطًا',
                    'name' => 'الاسم (أ - ي)',
                ]" selected="{{ $filters['sort'] ?? 'recent' }}" />
            </div>
            <x-button type="submit" icon="filter">تصفية</x-button>
            <x-button variant="outline" :href="url()->current()">تفريغ</x-button>
        </div>
    </form>

    {{-- جدول العملاء --}}
    <x-table :headers="['العميل', 'الهاتف', 'البريد', 'عدد الطلبات', 'إجمالي المشتريات', 'آخر طلب', 'نقاط الولاء', 'إجراءات']">
        @foreach ($customers as $customer)
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 whitespace-nowrap">
                    <div class="flex items-center gap-3">
                        <img src="{{ $customer['avatar'] }}" alt="{{ $customer['name'] }}" class="w-9 h-9 rounded-full object-cover ring-2 ring-gray-100" />
                        <div class="min-w-0">
                            <p class="font-medium text-gray-800">{{ $customer['name'] }}</p>
                            <p class="text-xs text-gray-400 font-mono">#{{ $customer['id'] }}</p>
                        </div>
                    </div>
                </td>
                <td class="px-4 py-3 text-gray-600 whitespace-nowrap" dir="ltr">{{ $customer['phone'] }}</td>
                <td class="px-4 py-3 text-gray-500 whitespace-nowrap" dir="ltr">{{ $customer['email'] }}</td>
                <td class="px-4 py-3 text-gray-700 whitespace-nowrap">{{ $customer['orders'] }} طلب</td>
                <td class="px-4 py-3 font-semibold text-gray-800 whitespace-nowrap">{{ \App\Support\Demo::money($customer['total_spent']) }}</td>
                <td class="px-4 py-3 text-gray-500 whitespace-nowrap" dir="ltr">{{ $customer['last_order'] }}</td>
                <td class="px-4 py-3 whitespace-nowrap">
                    <x-badge type="warning" :text="$customer['points'] . ' نقطة'" />
                </td>
                <td class="px-4 py-3 whitespace-nowrap">
                    <x-button variant="light" size="sm" icon="eye" :href="route('admin.customers.show', $customer['id'])">عرض</x-button>
                </td>
            </tr>
        @endforeach

        <x-slot:footer>
            <x-pagination :paginator="$customers" />
        </x-slot:footer>
    </x-table>

    {{-- نافذة إضافة عميل --}}
    <x-modal name="add-customer" title="إضافة عميل جديد" maxWidth="max-w-lg">
        <form id="add-customer-form" method="POST" action="{{ route('admin.customers.store') }}" class="space-y-4">
            @csrf
            <x-input label="اسم العميل" name="name" placeholder="مثال: محمد سالم" icon="user" :required="true" />
            <x-input label="رقم الهاتف" name="phone" type="tel" placeholder="+968 9xxxxxxx" icon="phone" :required="true" />
            <x-input label="البريد الإلكتروني" name="email" type="email" placeholder="name@example.com" icon="mail" hint="اختياري — لإرسال الفواتير والعروض" />
            <x-input label="العنوان" name="address" placeholder="مثال: مسقط، السيب" icon="map-pin" />
        </form>

        <x-slot:footer>
            <x-button variant="ghost" size="md" x-on:click="$dispatch('close-modal')">إلغاء</x-button>
            <x-button variant="primary" size="md" type="submit" form="add-customer-form" icon="check">حفظ العميل</x-button>
        </x-slot:footer>
    </x-modal>
</x-layouts::admin>
