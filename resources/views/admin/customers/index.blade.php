<x-layouts::admin title="العملاء">
    <x-page-header
        title="العملاء"
        subtitle="إدارة قاعدة عملاء المحل وسجل مشترياتهم ونقاط ولائهم"
        :breadcrumbs="['الرئيسية' => route('admin.dashboard'), 'العملاء' => '#']"
    >
        <x-slot:actions>
            {{-- زر «المزيد» (تصدير/استيراد) --}}
            <x-dropdown align="left" width="w-60">
                <x-slot:trigger>
                    <span title="المزيد" aria-label="المزيد"
                          class="inline-flex items-center justify-center w-10 h-10 rounded-xl border border-gray-200 text-gray-600 hover:bg-gray-50 hover:text-gray-900 transition cursor-pointer">
                        <x-icon name="ellipsis-vertical" class="w-5 h-5" />
                    </span>
                </x-slot:trigger>

                <p class="px-4 pt-2 pb-1 text-[11px] font-semibold text-gray-400">تصدير</p>
                <a href="{{ route('admin.customers.export.xlsx') }}" class="flex items-center gap-2.5 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                    <x-icon name="file-spreadsheet" class="w-4 h-4 text-green-600" /> تصدير Excel (xlsx)
                </a>
                <a href="{{ route('admin.customers.export.pdf') }}" target="_blank" class="flex items-center gap-2.5 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                    <x-icon name="file-text" class="w-4 h-4 text-red-600" /> تصدير PDF
                </a>
                <a href="{{ route('admin.export.customers') }}" class="flex items-center gap-2.5 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                    <x-icon name="file-down" class="w-4 h-4 text-gray-500" /> تصدير CSV
                </a>

                <div class="my-1 border-t border-gray-100"></div>
                <p class="px-4 pt-1 pb-1 text-[11px] font-semibold text-gray-400">استيراد</p>
                <button type="button" x-on:click="$dispatch('open-modal','import-customers')" class="w-full flex items-center gap-2.5 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                    <x-icon name="upload" class="w-4 h-4 text-primary-600" /> استيراد من ملف…
                </button>
            </x-dropdown>

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

    {{-- نافذة استيراد العملاء --}}
    @php $importBranches = \App\Support\Demo::branches(); @endphp
    <x-modal name="import-customers" title="استيراد العملاء من ملف" maxWidth="max-w-lg">
        <form id="import-customers-form" method="POST" action="{{ route('admin.customers.import.upload') }}" enctype="multipart/form-data" class="space-y-4">
            @csrf

            {{-- القسم الأول: اختيار الملف --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">ملف العملاء</label>
                <input type="file" name="file" required accept=".csv,.xls,.xlsx,.xlsm"
                    class="block w-full text-sm text-gray-600 file:mr-0 file:ml-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-gray-900 file:text-white hover:file:bg-black cursor-pointer border border-gray-200 rounded-xl p-2" />
                <p class="text-xs text-gray-400 mt-1.5">الصيغ المدعومة: CSV، XLS، XLSX، XLSM — الأعمدة: الاسم، الهاتف، البريد، العنوان، الفرع، النقاط. يمكنك تصدير ملف ثم تعديله وإعادة استيراده.</p>
            </div>

            {{-- القسم الثاني: الفرع الافتراضي --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">الفرع الافتراضي</label>
                <select name="branch_id" class="w-full rounded-xl border-gray-200 focus:border-primary-400 focus:ring-primary-200 text-sm">
                    <option value="">بدون فرع (عام)</option>
                    @foreach ($importBranches as $b)
                        <option value="{{ $b['id'] }}">{{ $b['name'] }}</option>
                    @endforeach
                </select>
                <p class="text-xs text-gray-400 mt-1.5">يُستخدم فقط للصفوف التي لا تحوي عمود «الفرع» في الملف — وإلا يُعتمد فرع الملف.</p>
            </div>

            <div class="flex items-start gap-2 bg-primary-50 text-primary-700 rounded-xl px-3 py-2.5 text-xs">
                <x-icon name="repeat" class="w-4 h-4 shrink-0 mt-0.5" />
                <span>العملاء الموجودون مسبقًا (بنفس الهاتف) <strong>يُحدَّثون بدل تكرارهم</strong>، مع الحفاظ على فروعهم. وستظهر <strong>معاينة كاملة</strong> قبل التأكيد.</span>
            </div>
        </form>

        <x-slot:footer>
            <x-button variant="ghost" size="md" x-on:click="$dispatch('close-modal')">إلغاء</x-button>
            <x-button variant="primary" size="md" type="submit" form="import-customers-form" icon="eye">معاينة الملف</x-button>
        </x-slot:footer>
    </x-modal>
</x-layouts::admin>
