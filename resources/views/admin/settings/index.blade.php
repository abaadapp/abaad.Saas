<x-layouts::admin title="الإعدادات">
    @php
        // مساعد لقراءة قيمة إعداد محفوظ (كل الحقول المسمّاة تُحفظ عبر SettingController@update)
        $sget = fn ($k, $default = '1') => \App\Models\Setting::where('business_id', auth()->user()->business_id)->where('key', $k)->value('value') ?? $default;
    @endphp
    <x-page-header
        title="الإعدادات"
        subtitle="إدارة إعدادات المحل والفروع والضرائب وطرق الدفع والطباعة"
        :breadcrumbs="['الرئيسية' => route('admin.dashboard'), 'الإعدادات' => '#']"
    />

    <div x-data="{ tab: 'business' }" class="grid grid-cols-1 lg:grid-cols-4 gap-6">
        {{-- القائمة الجانبية --}}
        <aside class="lg:col-span-1">
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-2 lg:sticky lg:top-6">
                @php
                    $tabs = [
                        'business' => ['label' => 'بيانات النشاط', 'icon' => 'store'],
                        'taxes' => ['label' => 'الضرائب', 'icon' => 'percent'],
                        'currency' => ['label' => 'العملة', 'icon' => 'coins'],
                        'payments' => ['label' => 'طرق الدفع', 'icon' => 'credit-card'],
                        'invoices' => ['label' => 'الفواتير', 'icon' => 'file-text'],
                        'printing' => ['label' => 'الطباعة', 'icon' => 'printer'],
                        'permissions' => ['label' => 'صلاحيات الموظفين', 'icon' => 'shield-check'],
                        'notifications' => ['label' => 'الإشعارات', 'icon' => 'bell'],
                        'orders' => ['label' => 'الطلبات', 'icon' => 'shopping-cart'],
                        'delivery' => ['label' => 'التوصيل', 'icon' => 'truck'],
                        'backup' => ['label' => 'النسخ الاحتياطي', 'icon' => 'database-backup'],
                    ];
                @endphp
                <nav class="flex lg:flex-col gap-1 overflow-x-auto">
                    @foreach ($tabs as $key => $t)
                        <button type="button"
                            @click="tab = '{{ $key }}'"
                            :class="tab === '{{ $key }}' ? 'bg-primary-50 text-primary-700' : 'text-gray-600 hover:bg-gray-50'"
                            class="flex items-center gap-2.5 px-3.5 py-2.5 rounded-xl text-sm font-medium whitespace-nowrap transition-colors text-right"
                        >
                            <x-icon :name="$t['icon']" class="w-4 h-4" />
                            {{ $t['label'] }}
                        </button>
                    @endforeach

                    {{-- صفحات مستقلة تُفتح كما هي --}}
                    @foreach ([
                        ['label' => 'الفروع', 'icon' => 'git-branch', 'url' => route('admin.branches.index')],
                        ['label' => 'الموظفون', 'icon' => 'user-cog', 'url' => route('admin.employees.index')],
                        ['label' => 'سجل النشاط', 'icon' => 'history', 'url' => route('admin.activity.index')],
                    ] as $link)
                        <a href="{{ $link['url'] }}"
                           class="flex items-center gap-2.5 px-3.5 py-2.5 rounded-xl text-sm font-medium whitespace-nowrap transition-colors text-right text-gray-600 hover:bg-gray-50">
                            <x-icon :name="$link['icon']" class="w-4 h-4" />
                            {{ $link['label'] }}
                            <x-icon name="chevron-left" class="w-3.5 h-3.5 lg:mr-auto text-gray-300" />
                        </a>
                    @endforeach
                </nav>
            </div>
        </aside>

        {{-- محتوى التبويبات --}}
        <form method="POST" action="{{ route('admin.settings.update') }}" class="lg:col-span-3">
            @csrf
            {{-- بيانات النشاط --}}
            <div x-show="tab === 'business'" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-6">بيانات النشاط</h3>
                <div class="space-y-5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-input label="اسم المحل" name="shop_name" value="محل ورود الأناقة" />
                        <x-input label="النشاط التجاري" name="activity" value="بيع وتنسيق الورود والهدايا" />
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-input label="رقم الهاتف" name="phone" value="+968 24123456" icon="phone" />
                        <x-input label="البريد الإلكتروني" name="email" value="info@wardshop.om" icon="mail" />
                    </div>
                    <x-input label="العنوان" name="address" value="مسقط - الخوير - شارع 18 نوفمبر" icon="map-pin" />
                </div>
                <div class="mt-6 flex justify-end">
                    <x-button variant="primary" size="md" icon="save" type="submit">حفظ التغييرات</x-button>
                </div>
            </div>

            {{-- الفروع تُدار في كتلة مستقلة خارج النموذج (بالأسفل) --}}

            {{-- الضرائب --}}
            <div x-show="tab === 'taxes'" x-cloak class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-6">إعدادات الضرائب</h3>
                <div class="space-y-5">
                    <label class="flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3">
                        <div>
                            <span class="text-sm font-medium text-gray-700">تفعيل ضريبة القيمة المضافة (VAT)</span>
                            <p class="text-xs text-gray-400">إضافة الضريبة تلقائيًا على الفواتير</p>
                        </div>
                        <span><input type="hidden" name="vat_enabled" value="0" /><input type="checkbox" name="vat_enabled" value="1" @checked($sget('vat_enabled') !== '0') class="w-5 h-5 rounded text-primary-600 focus:ring-primary-500 border-gray-300" /></span>
                    </label>
                    @php
                        $bid = auth()->user()->business_id;
                        $vatRate = \App\Models\Setting::where('business_id', $bid)->where('key', 'vat_rate')->value('value') ?? '5';
                        $vatNumber = \App\Models\Setting::where('business_id', $bid)->where('key', 'vat_number')->value('value') ?? '';
                    @endphp
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-input label="نسبة الضريبة (%)" name="vat_rate" type="number" :value="$vatRate" icon="percent" />
                        <x-input label="الرقم الضريبي (TRN)" name="vat_number" :value="$vatNumber" placeholder="OM1100XXXXXX" icon="hash" />
                    </div>
                    <p class="text-xs text-gray-400">يظهران في الفاتورة الضريبية وإقرار VAT. <a href="{{ route('admin.vat.index') }}" class="text-primary-600 hover:underline">عرض إقرار الضريبة ←</a></p>
                    <x-select label="طريقة احتساب الضريبة" name="tax_mode" :options="[
                        'exclusive' => 'مضافة على السعر',
                        'inclusive' => 'مشمولة في السعر',
                    ]" selected="exclusive" />
                </div>
                <div class="mt-6 flex justify-end">
                    <x-button variant="primary" size="md" icon="save" type="submit">حفظ التغييرات</x-button>
                </div>
            </div>

            {{-- العملة --}}
            <div x-show="tab === 'currency'" x-cloak class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-6">إعدادات العملة</h3>
                <div class="space-y-5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-select label="العملة" name="currency" :options="[
                            'OMR' => 'ريال عماني (ر.ع)',
                            'AED' => 'درهم إماراتي (د.إ)',
                            'SAR' => 'ريال سعودي (ر.س)',
                        ]" selected="OMR" />
                        <x-select label="عدد الخانات العشرية" name="decimals" :options="['3' => '3 خانات', '2' => 'خانتان']" selected="3" />
                    </div>
                    <x-select label="موضع رمز العملة" name="symbol_pos" :options="[
                        'after' => 'بعد المبلغ (12.500 ر.ع)',
                        'before' => 'قبل المبلغ (ر.ع 12.500)',
                    ]" selected="after" />
                </div>
                <div class="mt-6 flex justify-end">
                    <x-button variant="primary" size="md" icon="save" type="submit">حفظ التغييرات</x-button>
                </div>
            </div>

            {{-- طرق الدفع --}}
            <div x-show="tab === 'payments'" x-cloak class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-6">طرق الدفع</h3>
                @php
                    $methods = [
                        ['key' => 'cash', 'label' => 'نقدي', 'desc' => 'الدفع النقدي عند الشراء', 'icon' => 'banknote', 'on' => true],
                        ['key' => 'card', 'label' => 'بطاقة (فيزا)', 'desc' => 'الدفع عبر بطاقات الصراف والائتمان', 'icon' => 'credit-card', 'on' => true],
                        ['key' => 'transfer', 'label' => 'تحويل بنكي', 'desc' => 'التحويل المباشر للحساب البنكي', 'icon' => 'arrow-left-right', 'on' => true],
                    ];
                @endphp
                <div class="space-y-3">
                    @foreach ($methods as $method)
                        <label class="flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3.5 cursor-pointer hover:bg-gray-50">
                            <div class="flex items-center gap-3">
                                <span class="w-10 h-10 rounded-xl bg-primary-50 text-primary-600 flex items-center justify-center">
                                    <x-icon :name="$method['icon']" class="w-5 h-5" />
                                </span>
                                <div>
                                    <span class="text-sm font-medium text-gray-700">{{ $method['label'] }}</span>
                                    <p class="text-xs text-gray-400">{{ $method['desc'] }}</p>
                                </div>
                            </div>
                            <span><input type="hidden" name="pay_{{ $method['key'] }}" value="0" /><input type="checkbox" name="pay_{{ $method['key'] }}" value="1" @checked($sget('pay_' . $method['key'], $method['on'] ? '1' : '0') !== '0') class="w-5 h-5 rounded text-primary-600 focus:ring-primary-500 border-gray-300" /></span>
                        </label>
                    @endforeach
                </div>
                <div class="mt-6 flex justify-end">
                    <x-button variant="primary" size="md" icon="save" type="submit">حفظ التغييرات</x-button>
                </div>
            </div>

            {{-- الفواتير --}}
            <div x-show="tab === 'invoices'" x-cloak class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-6">إعدادات الفواتير</h3>
                <div class="space-y-5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-input label="بادئة رقم الفاتورة" name="inv_prefix" value="INV-" icon="hash" />
                        <x-input label="رقم البداية" name="inv_start" type="number" value="1001" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">ملاحظة أسفل الفاتورة</label>
                        <textarea rows="2" class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:outline-none transition">شكرًا لتسوقكم من محل ورود الأناقة — نتمنى لكم يومًا سعيدًا</textarea>
                    </div>
                    <label class="flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3">
                        <span class="text-sm font-medium text-gray-700">إظهار الشعار على الفاتورة</span>
                        <span><input type="hidden" name="invoice_show_logo" value="0" /><input type="checkbox" name="invoice_show_logo" value="1" @checked($sget('invoice_show_logo') !== '0') class="w-5 h-5 rounded text-primary-600 focus:ring-primary-500 border-gray-300" /></span>
                    </label>
                </div>
                <div class="mt-6 flex justify-end">
                    <x-button variant="primary" size="md" icon="save" type="submit">حفظ التغييرات</x-button>
                </div>
            </div>

            {{-- الطباعة --}}
            <div x-show="tab === 'printing'" x-cloak class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-6">إعدادات الطباعة</h3>
                <div class="space-y-5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-select label="حجم الورق" name="paper" :options="[
                            '80mm' => 'حراري 80 مم',
                            '58mm' => 'حراري 58 مم',
                            'A4' => 'A4',
                        ]" selected="80mm" />
                        <x-select label="عدد النسخ" name="copies" :options="['1' => 'نسخة واحدة', '2' => 'نسختان']" selected="1" />
                    </div>
                    <label class="flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3">
                        <span class="text-sm font-medium text-gray-700">طباعة تلقائية بعد إتمام الطلب</span>
                        <span><input type="hidden" name="print_auto" value="0" /><input type="checkbox" name="print_auto" value="1" @checked($sget('print_auto') !== '0') class="w-5 h-5 rounded text-primary-600 focus:ring-primary-500 border-gray-300" /></span>
                    </label>
                    <label class="flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3">
                        <span class="text-sm font-medium text-gray-700">طباعة نسخة للمطبخ / قسم التجهيز</span>
                        <span><input type="hidden" name="print_kitchen" value="0" /><input type="checkbox" name="print_kitchen" value="1" @checked($sget('print_kitchen', '0') !== '0') class="w-5 h-5 rounded text-primary-600 focus:ring-primary-500 border-gray-300" /></span>
                    </label>
                </div>
                <div class="mt-6 flex justify-end">
                    <x-button variant="primary" size="md" icon="save" type="submit">حفظ التغييرات</x-button>
                </div>
            </div>

            {{-- صلاحيات الموظفين --}}
            <div x-show="tab === 'permissions'" x-cloak class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-2">صلاحيات الموظفين الافتراضية</h3>
                <p class="text-sm text-gray-500 mb-6">حدد الصلاحيات الافتراضية للموظفين الجدد حسب الدور.</p>
                @php
                    $rolePerms = [
                        'كاشير' => ['فتح نقطة البيع', 'إنشاء طلب', 'طباعة فاتورة'],
                        'موظف مبيعات' => ['إنشاء طلب', 'إدارة العملاء', 'تطبيق خصومات'],
                        'مسؤول مخزون' => ['إدارة المخزون', 'حركات المخزون', 'الجرد'],
                    ];
                @endphp
                <div class="space-y-5">
                    @foreach ($rolePerms as $role => $perms)
                        <div class="rounded-xl border border-gray-200 p-4">
                            <h4 class="text-sm font-semibold text-gray-800 mb-3">{{ $role }}</h4>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                                @foreach ($perms as $perm)
                                    @php $pk = 'perm_' . $loop->parent->index . '_' . $loop->index; @endphp
                                    <label class="flex items-center gap-2.5 cursor-pointer">
                                        <input type="hidden" name="{{ $pk }}" value="0" />
                                        <input type="checkbox" name="{{ $pk }}" value="1" @checked($sget($pk) !== '0') class="w-4 h-4 rounded text-primary-600 focus:ring-primary-500 border-gray-300" />
                                        <span class="text-sm text-gray-700">{{ $perm }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-6 flex justify-end">
                    <x-button variant="primary" size="md" icon="save" type="submit">حفظ التغييرات</x-button>
                </div>
            </div>

            {{-- الإشعارات --}}
            <div x-show="tab === 'notifications'" x-cloak class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-6">الإشعارات</h3>

                {{-- إشعار البريد عند طلب جديد (فعّال) --}}
                @php $notifyNewOrder = \App\Models\Setting::where('business_id', auth()->user()->business_id)->where('key', 'notify_new_order')->value('value'); @endphp
                <label class="flex items-center justify-between rounded-xl border border-primary-100 bg-primary-50/40 px-4 py-3.5 cursor-pointer mb-3">
                    <div class="flex items-center gap-3">
                        <span class="w-9 h-9 rounded-xl bg-primary-100 text-primary-600 flex items-center justify-center"><x-icon name="mail" class="w-5 h-5" /></span>
                        <div>
                            <span class="text-sm font-semibold text-gray-800">إرسال بريد إلكتروني عند كل طلب جديد</span>
                            <p class="text-xs text-gray-500">يُرسل إلى بريد صاحب النشاط عند إتمام أي عملية بيع.</p>
                        </div>
                    </div>
                    <span>
                        <input type="hidden" name="notify_new_order" value="0" />
                        <input type="checkbox" name="notify_new_order" value="1" @checked($notifyNewOrder !== '0') class="w-5 h-5 rounded text-primary-600 focus:ring-primary-500 border-gray-300" />
                    </span>
                </label>

                {{-- التنبيهات الذكية بالبريد (فعّالة عبر أمر alerts:smart المجدول) --}}
                @php $notifySmart = \App\Models\Setting::where('business_id', auth()->user()->business_id)->where('key', 'notify_smart_alerts')->value('value'); @endphp
                <label class="flex items-center justify-between rounded-xl border border-secondary-100 bg-secondary-50/40 px-4 py-3.5 cursor-pointer mb-5">
                    <div class="flex items-center gap-3">
                        <span class="w-9 h-9 rounded-xl bg-secondary-100 text-secondary-600 flex items-center justify-center"><x-icon name="sparkles" class="w-5 h-5" /></span>
                        <div>
                            <span class="text-sm font-semibold text-gray-800">تنبيهات ذكية يومية بالبريد</span>
                            <p class="text-xs text-gray-500">تراجع المبيعات، المنتجات الراكدة، والعملاء المتعثّرون — تُرسَل صباح كل يوم.</p>
                        </div>
                    </div>
                    <span>
                        <input type="hidden" name="notify_smart_alerts" value="0" />
                        <input type="checkbox" name="notify_smart_alerts" value="1" @checked($notifySmart !== '0') class="w-5 h-5 rounded text-secondary-600 focus:ring-secondary-500 border-gray-300" />
                    </span>
                </label>

                @php
                    $notifs = [
                        'تنبيه انخفاض المخزون' => true,
                        'إشعار طلب جديد' => true,
                        'إشعار إتمام طلب' => true,
                        'تقرير المبيعات اليومي' => true,
                        'إشعار مصروف جديد' => false,
                        'تنبيه الفواتير الآجلة المستحقة' => true,
                    ];
                @endphp
                <div class="space-y-3">
                    @foreach ($notifs as $notif => $on)
                        @php $nk = 'notif_opt_' . $loop->index; @endphp
                        <label class="flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3 cursor-pointer hover:bg-gray-50">
                            <span class="text-sm font-medium text-gray-700">{{ $notif }}</span>
                            <span><input type="hidden" name="{{ $nk }}" value="0" /><input type="checkbox" name="{{ $nk }}" value="1" @checked($sget($nk, $on ? '1' : '0') !== '0') class="w-5 h-5 rounded text-primary-600 focus:ring-primary-500 border-gray-300" /></span>
                        </label>
                    @endforeach
                </div>
                <div class="mt-6 flex justify-end">
                    <x-button variant="primary" size="md" icon="save" type="submit">حفظ التغييرات</x-button>
                </div>
            </div>

            {{-- الطلبات --}}
            <div x-show="tab === 'orders'" x-cloak class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-6">إعدادات الطلبات</h3>
                <div class="space-y-5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-input label="بادئة رقم الطلب" name="order_prefix" value="ORD-" icon="hash" />
                        <x-select label="الحالة الافتراضية للطلب الجديد" name="default_status" :options="[
                            'جديد' => 'جديد',
                            'قيد التجهيز' => 'قيد التجهيز',
                        ]" selected="جديد" />
                    </div>
                    <label class="flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3">
                        <span class="text-sm font-medium text-gray-700">السماح بتعديل الطلب بعد إنشائه</span>
                        <span><input type="hidden" name="order_allow_edit" value="0" /><input type="checkbox" name="order_allow_edit" value="1" @checked($sget('order_allow_edit') !== '0') class="w-5 h-5 rounded text-primary-600 focus:ring-primary-500 border-gray-300" /></span>
                    </label>
                    <label class="flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3">
                        <span class="text-sm font-medium text-gray-700">طلب تأكيد قبل إلغاء الطلب</span>
                        <span><input type="hidden" name="order_confirm_cancel" value="0" /><input type="checkbox" name="order_confirm_cancel" value="1" @checked($sget('order_confirm_cancel') !== '0') class="w-5 h-5 rounded text-primary-600 focus:ring-primary-500 border-gray-300" /></span>
                    </label>
                </div>
                <div class="mt-6 flex justify-end">
                    <x-button variant="primary" size="md" icon="save" type="submit">حفظ التغييرات</x-button>
                </div>
            </div>

            {{-- التوصيل --}}
            <div x-show="tab === 'delivery'" x-cloak class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-lg font-bold text-gray-800">إعدادات التوصيل</h3>
                    <x-button variant="primary" size="sm" icon="plus" x-on:click="$store.toasts.add('إضافة منطقة توصيل', 'info')">إضافة منطقة</x-button>
                </div>
                <div class="space-y-5">
                    <label class="flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3">
                        <div>
                            <span class="text-sm font-medium text-gray-700">تفعيل خدمة التوصيل</span>
                            <p class="text-xs text-gray-400">إتاحة التوصيل للطلبات الخارجية</p>
                        </div>
                        <span><input type="hidden" name="delivery_enabled" value="0" /><input type="checkbox" name="delivery_enabled" value="1" @checked($sget('delivery_enabled') !== '0') class="w-5 h-5 rounded text-primary-600 focus:ring-primary-500 border-gray-300" /></span>
                    </label>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-input label="رسوم التوصيل الافتراضية (ر.ع)" name="delivery_fee" type="number" value="1.500" icon="truck" />
                        <x-input label="حد التوصيل المجاني (ر.ع)" name="free_threshold" type="number" value="25.000" icon="gift" />
                    </div>

                    <div x-data="{
                            zones: [
                                { name: 'الخوير', fee: 1.000 },
                                { name: 'روي', fee: 1.500 },
                                { name: 'السيب', fee: 2.000 },
                                { name: 'بوشر', fee: 2.500 },
                            ],
                            form: { name: '', fee: '' },
                            editIndex: null,
                            save() {
                                if (!this.form.name || this.form.fee === '') return;
                                const z = { name: this.form.name, fee: parseFloat(this.form.fee) || 0 };
                                if (this.editIndex === null) this.zones.push(z);
                                else this.zones[this.editIndex] = z;
                                this.form = { name: '', fee: '' }; this.editIndex = null;
                            },
                            edit(i) { this.form = { name: this.zones[i].name, fee: this.zones[i].fee }; this.editIndex = i; },
                            remove(i) { this.zones.splice(i, 1); if (this.editIndex === i) { this.editIndex = null; this.form = { name: '', fee: '' }; } },
                        }">
                        <h4 class="text-sm font-semibold text-gray-800 mb-3">مناطق التوصيل ورسومها</h4>
                        <div class="overflow-x-auto rounded-xl border border-gray-100">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-50 text-gray-500">
                                    <tr>
                                        <th class="px-4 py-2.5 text-right font-medium">المنطقة</th>
                                        <th class="px-4 py-2.5 text-right font-medium">رسوم التوصيل (ر.ع)</th>
                                        <th class="px-4 py-2.5 text-right font-medium">إجراءات</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-50">
                                    <template x-for="(zone, i) in zones" :key="i">
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-4 py-3 font-medium text-gray-800" x-text="zone.name"></td>
                                            <td class="px-4 py-3 text-gray-700" x-text="Number(zone.fee).toFixed(3)"></td>
                                            <td class="px-4 py-3">
                                                <div class="flex items-center gap-1">
                                                    <button type="button" @click="edit(i)" class="p-1.5 rounded-lg text-gray-500 hover:bg-gray-100" title="تعديل"><x-icon name="pencil" class="w-4 h-4" /></button>
                                                    <button type="button" @click="remove(i)" class="p-1.5 rounded-lg text-danger-600 hover:bg-danger-50" title="حذف"><x-icon name="trash-2" class="w-4 h-4" /></button>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                    <tr x-show="!zones.length"><td colspan="3" class="px-4 py-6 text-center text-gray-400">لا توجد مناطق. أضف منطقة جديدة أدناه.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        {{-- إضافة / تعديل منطقة --}}
                        <div class="mt-3 flex flex-col sm:flex-row gap-2 items-end">
                            <div class="flex-1 w-full">
                                <label class="block text-xs text-gray-500 mb-1">اسم المنطقة</label>
                                <input type="text" x-model="form.name" placeholder="مثال: المعبيلة" class="w-full rounded-lg border-gray-200 text-sm" />
                            </div>
                            <div class="w-full sm:w-40">
                                <label class="block text-xs text-gray-500 mb-1">الرسوم</label>
                                <input type="number" step="0.001" min="0" x-model="form.fee" class="w-full rounded-lg border-gray-200 text-sm" />
                            </div>
                            <button type="button" @click="save()" class="bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-lg px-4 py-2 whitespace-nowrap" x-text="editIndex === null ? 'إضافة منطقة' : 'حفظ التعديل'"></button>
                        </div>
                        {{-- إرسال القيم مع النموذج --}}
                        <template x-for="(zone, i) in zones" :key="'h'+i">
                            <span>
                                <input type="hidden" :name="'delivery_zones['+i+'][name]'" :value="zone.name" />
                                <input type="hidden" :name="'delivery_zones['+i+'][fee]'" :value="zone.fee" />
                            </span>
                        </template>
                    </div>
                </div>
                <div class="mt-6 flex justify-end">
                    <x-button variant="primary" size="md" icon="save" type="submit">حفظ التغييرات</x-button>
                </div>
            </div>
        </form>


        {{-- النسخ الاحتياطي والاستعادة (نماذج مستقلة خارج نموذج الإعدادات) --}}
        <div x-show="tab === 'backup'" x-cloak class="lg:col-span-3 lg:col-start-2 space-y-6">
            {{-- تنزيل نسخة احتياطية --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <div class="flex items-center gap-2 mb-2">
                    <span class="w-9 h-9 rounded-xl bg-primary-50 text-primary-600 flex items-center justify-center"><x-icon name="download" class="w-5 h-5" /></span>
                    <h3 class="text-lg font-bold text-gray-800">تنزيل نسخة احتياطية</h3>
                </div>
                <p class="text-sm text-gray-500 mb-5">يشمل الملف كامل بيانات متجرك: المنتجات، التصنيفات، العملاء، الطلبات، المصروفات، المعاملات، حركات المخزون، والإعدادات — بصيغة JSON.</p>
                <x-button variant="primary" size="md" icon="database-backup" :href="route('admin.backup.download')">تنزيل النسخة الآن</x-button>
            </div>

            {{-- استعادة من نسخة --}}
            <div class="bg-white rounded-2xl border border-danger-100 shadow-sm p-6">
                <div class="flex items-center gap-2 mb-2">
                    <span class="w-9 h-9 rounded-xl bg-danger-50 text-danger-600 flex items-center justify-center"><x-icon name="database" class="w-5 h-5" /></span>
                    <h3 class="text-lg font-bold text-gray-800">استعادة من نسخة احتياطية</h3>
                </div>
                <div class="flex items-start gap-2 bg-danger-50 text-danger-700 text-sm rounded-xl p-3 mb-5">
                    <x-icon name="alert-triangle" class="w-5 h-5 shrink-0 mt-0.5" />
                    <span>تحذير: ستحل بيانات النسخة محل بيانات متجرك الحالية بالكامل. لا يمكن التراجع — نوصي بتنزيل نسخة حديثة أولًا.</span>
                </div>
                <form method="POST" action="{{ route('admin.backup.restore') }}" enctype="multipart/form-data"
                      x-data="{ fileName: '' }" @submit="if(!fileName){ $event.preventDefault(); $store.toasts.add('اختر ملف النسخة أولًا','warning'); }">
                    @csrf
                    <label class="flex flex-col items-center justify-center gap-2 border-2 border-dashed border-gray-200 rounded-2xl p-6 text-center cursor-pointer hover:border-danger-300 transition">
                        <x-icon name="upload-cloud" class="w-8 h-8 text-gray-400" />
                        <span class="text-sm font-medium text-gray-700" x-text="fileName || 'اختر ملف النسخة الاحتياطية (JSON)'"></span>
                        <input type="file" name="backup" accept=".json,application/json" class="hidden"
                               @change="fileName = $event.target.files[0]?.name || ''" />
                    </label>
                    @error('backup')<p class="mt-2 text-xs text-danger-500">{{ $message }}</p>@enderror
                    <div class="mt-5 flex justify-end">
                        <x-button variant="danger" size="md" icon="database" type="submit">استعادة البيانات</x-button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-layouts::admin>
