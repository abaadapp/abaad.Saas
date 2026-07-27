<x-layouts::admin :title="__('الإعدادات')">
    @php
        // مساعد لقراءة قيمة إعداد محفوظ (كل الحقول المسمّاة تُحفظ عبر SettingController@update)
        $sget = fn ($k, $default = '1') => \App\Models\Setting::where('business_id', auth()->user()->business_id)->where('key', $k)->value('value') ?? $default;
    @endphp
    <x-page-header
        :title="__('الإعدادات')"
        :subtitle="__('إدارة إعدادات المحل والفروع والضرائب وطرق الدفع والطباعة')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('الإعدادات') => '#']"
    />

    <div x-data="{ tab: 'business' }" class="grid grid-cols-1 lg:grid-cols-4 gap-6">
        {{-- القائمة الجانبية --}}
        <aside class="lg:col-span-1">
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-2 lg:sticky lg:top-6">
                @php
                    $tabs = [
                        'business' => ['label' => __('بيانات النشاط'), 'icon' => 'store'],
                        'taxes' => ['label' => __('الضرائب'), 'icon' => 'percent'],
                        'currency' => ['label' => __('العملة'), 'icon' => 'coins'],
                        'language' => ['label' => __('اللغة'), 'icon' => 'languages'],
                        'payments' => ['label' => __('طرق الدفع'), 'icon' => 'credit-card'],
                        'invoices' => ['label' => __('الفواتير'), 'icon' => 'file-text'],
                        'printing' => ['label' => __('الطباعة'), 'icon' => 'printer'],
                        'permissions' => ['label' => __('صلاحيات الموظفين'), 'icon' => 'shield-check'],
                        'notifications' => ['label' => __('الإشعارات'), 'icon' => 'bell'],
                        'notifications-log' => ['label' => __('التنبيهات المرسلة'), 'icon' => 'bell-ring'],
                        'orders' => ['label' => __('الطلبات'), 'icon' => 'shopping-cart'],
                        'loyalty' => ['label' => __('برنامج الولاء'), 'icon' => 'award'],
                        'delivery' => ['label' => __('التوصيل'), 'icon' => 'truck'],
                        'backup' => ['label' => __('النسخ الاحتياطي'), 'icon' => 'database-backup'],
                    ];
                @endphp
                <nav class="flex lg:flex-col gap-1 overflow-x-auto">
                    @foreach ($tabs as $key => $t)
                        <button type="button"
                            @click="tab = '{{ $key }}'"
                            :class="tab === '{{ $key }}' ? 'bg-primary-50 text-primary-700' : 'text-gray-600 hover:bg-gray-50'"
                            class="flex items-center gap-2.5 px-3.5 py-2.5 rounded-full text-sm font-medium whitespace-nowrap transition-colors text-right"
                        >
                            <x-icon :name="$t['icon']" class="w-4 h-4" />
                            {{ $t['label'] }}
                        </button>
                    @endforeach

                    {{-- صفحات مستقلة تُفتح كما هي --}}
                    @foreach ([
                        ['label' => __('الفروع'), 'icon' => 'git-branch', 'url' => route('admin.branches.index')],
                        ['label' => __('الموظفون'), 'icon' => 'user-cog', 'url' => route('admin.employees.index')],
                        ['label' => __('سجل النشاط'), 'icon' => 'history', 'url' => route('admin.activity.index')],
                    ] as $link)
                        <a href="{{ $link['url'] }}"
                           class="flex items-center gap-2.5 px-3.5 py-2.5 rounded-full text-sm font-medium whitespace-nowrap transition-colors text-right text-gray-600 hover:bg-gray-50">
                            <x-icon :name="$link['icon']" class="w-4 h-4" />
                            {{ $link['label'] }}
                            <x-icon name="chevron-left" class="w-3.5 h-3.5 lg:mr-auto text-gray-300" />
                        </a>
                    @endforeach
                </nav>
            </div>
        </aside>

        {{-- عمود المحتوى: يضمّ نموذج الإعدادات ولوحتَي اللغة والنسخ الاحتياطي معًا --}}
        <div class="lg:col-span-3">
        {{-- محتوى التبويبات --}}
        <form method="POST" action="{{ route('admin.settings.update') }}">
            @csrf
            {{-- بيانات النشاط --}}
            <div x-show="tab === 'business'" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-6">{{ __('بيانات النشاط') }}</h3>
                <div class="space-y-5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-input :label="__('اسم المحل')" name="shop_name" value="محل ورود الأناقة" />
                        <x-input :label="__('النشاط التجاري')" name="activity" value="بيع وتنسيق الورود والهدايا" />
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-input :label="__('رقم الهاتف')" name="phone" value="+968 24123456" icon="phone" />
                        <x-input :label="__('البريد الإلكتروني')" name="email" value="info@wardshop.om" icon="mail" />
                    </div>
                    <x-input :label="__('العنوان')" name="address" value="مسقط - الخوير - شارع 18 نوفمبر" icon="map-pin" />
                </div>
                <div class="mt-6 flex justify-end">
                    <x-button variant="primary" size="md" icon="save" type="submit">{{ __('حفظ التغييرات') }}</x-button>
                </div>
            </div>

            {{-- الفروع تُدار في كتلة مستقلة خارج النموذج (بالأسفل) --}}

            {{-- الضرائب --}}
            <div x-show="tab === 'taxes'" x-cloak class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-6">{{ __('إعدادات الضرائب') }}</h3>
                <div class="space-y-5">
                    <label class="flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3">
                        <div>
                            <span class="text-sm font-medium text-gray-700">{{ __('تفعيل ضريبة القيمة المضافة (VAT)') }}</span>
                            <p class="text-xs text-gray-400">{{ __('إضافة الضريبة تلقائيًا على الفواتير') }}</p>
                        </div>
                        <span><input type="hidden" name="vat_enabled" value="0" /><input type="checkbox" name="vat_enabled" value="1" @checked($sget('vat_enabled') !== '0') class="w-5 h-5 rounded text-primary-600 focus:ring-primary-500 border-gray-300" /></span>
                    </label>
                    @php
                        $bid = auth()->user()->business_id;
                        $vatRate = \App\Models\Setting::where('business_id', $bid)->where('key', 'vat_rate')->value('value') ?? '5';
                        $vatNumber = \App\Models\Setting::where('business_id', $bid)->where('key', 'vat_number')->value('value') ?? '';
                    @endphp
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-input :label="__('نسبة الضريبة (%)')" name="vat_rate" type="number" :value="$vatRate" icon="percent" />
                        <x-input :label="__('الرقم الضريبي (TRN)')" name="vat_number" :value="$vatNumber" placeholder="OM1100XXXXXX" icon="hash" />
                    </div>
                    <p class="text-xs text-gray-400">{{ __('يظهران في الفاتورة الضريبية وإقرار VAT.') }} <a href="{{ route('admin.vat.index') }}" class="text-primary-600 hover:underline">{{ __('عرض إقرار الضريبة ←') }}</a></p>
                    <x-select :label="__('طريقة احتساب الضريبة')" name="tax_mode" :options="[
                        'exclusive' => __('مضافة على السعر'),
                        'inclusive' => __('مشمولة في السعر'),
                    ]" selected="exclusive" />
                </div>
                <div class="mt-6 flex justify-end">
                    <x-button variant="primary" size="md" icon="save" type="submit">{{ __('حفظ التغييرات') }}</x-button>
                </div>
            </div>

            {{-- العملة --}}
            <div x-show="tab === 'currency'" x-cloak class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-6">{{ __('إعدادات العملة') }}</h3>
                <div class="space-y-5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        {{-- عملة واحدة فقط: الريال العماني --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5">{{ __('العملة') }}</label>
                            <div class="flex items-center gap-2 w-full rounded-xl border border-gray-200 bg-gray-50 px-3 py-2.5 text-sm text-gray-700">
                                <x-icon name="coins" class="w-4 h-4 text-gray-400" />
                                <span>{{ __('ريال عماني (ر.ع)') }}</span>
                            </div>
                            <input type="hidden" name="currency" value="OMR" />
                        </div>
                        <x-select :label="__('عدد الخانات العشرية')" name="decimals" :options="['3' => __('3 خانات'), '2' => __('خانتان')]" selected="3" />
                    </div>
                    <x-select :label="__('موضع رمز العملة')" name="symbol_pos" :options="[
                        'after' => __('بعد المبلغ (12.500 ر.ع)'),
                        'before' => __('قبل المبلغ (ر.ع 12.500)'),
                    ]" selected="after" />
                </div>
                <div class="mt-6 flex justify-end">
                    <x-button variant="primary" size="md" icon="save" type="submit">{{ __('حفظ التغييرات') }}</x-button>
                </div>
            </div>

            {{-- طرق الدفع --}}
            <div x-show="tab === 'payments'" x-cloak class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-6">{{ __('طرق الدفع') }}</h3>
                @php
                    $methods = [
                        ['key' => 'cash', 'label' => __('نقدي'), 'desc' => __('الدفع النقدي عند الشراء'), 'icon' => 'banknote', 'on' => true],
                        ['key' => 'card', 'label' => __('بطاقة (فيزا)'), 'desc' => __('الدفع عبر بطاقات الصراف والائتمان'), 'icon' => 'credit-card', 'on' => true],
                        ['key' => 'transfer', 'label' => __('تحويل بنكي'), 'desc' => __('التحويل المباشر للحساب البنكي'), 'icon' => 'arrow-left-right', 'on' => true],
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
                    <x-button variant="primary" size="md" icon="save" type="submit">{{ __('حفظ التغييرات') }}</x-button>
                </div>
            </div>

            {{-- الفواتير --}}
            <div x-show="tab === 'invoices'" x-cloak class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-6">{{ __('إعدادات الفواتير') }}</h3>
                <div class="space-y-5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-input :label="__('بادئة رقم الفاتورة')" name="inv_prefix" value="INV-" icon="hash" />
                        <x-input :label="__('رقم البداية')" name="inv_start" type="number" value="1001" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">{{ __('ملاحظة أسفل الفاتورة') }}</label>
                        <textarea rows="2" class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:outline-none transition">شكرًا لتسوقكم من محل ورود الأناقة — نتمنى لكم يومًا سعيدًا</textarea>
                    </div>
                    <label class="flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3">
                        <span class="text-sm font-medium text-gray-700">{{ __('إظهار الشعار على الفاتورة') }}</span>
                        <span><input type="hidden" name="invoice_show_logo" value="0" /><input type="checkbox" name="invoice_show_logo" value="1" @checked($sget('invoice_show_logo') !== '0') class="w-5 h-5 rounded text-primary-600 focus:ring-primary-500 border-gray-300" /></span>
                    </label>
                </div>
                <div class="mt-6 flex justify-end">
                    <x-button variant="primary" size="md" icon="save" type="submit">{{ __('حفظ التغييرات') }}</x-button>
                </div>
            </div>

            {{-- الطباعة --}}
            <div x-show="tab === 'printing'" x-cloak class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-6">{{ __('إعدادات الطباعة') }}</h3>
                <div class="space-y-5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-select :label="__('حجم الورق')" name="paper" :options="[
                            '80mm' => __('حراري 80 مم'),
                            '58mm' => __('حراري 58 مم'),
                            'A4' => 'A4',
                        ]" selected="80mm" />
                        <x-select :label="__('عدد النسخ')" name="copies" :options="['1' => __('نسخة واحدة'), '2' => __('نسختان')]" selected="1" />
                    </div>
                    <label class="flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3">
                        <span class="text-sm font-medium text-gray-700">{{ __('طباعة تلقائية بعد إتمام الطلب') }}</span>
                        <span><input type="hidden" name="print_auto" value="0" /><input type="checkbox" name="print_auto" value="1" @checked($sget('print_auto') !== '0') class="w-5 h-5 rounded text-primary-600 focus:ring-primary-500 border-gray-300" /></span>
                    </label>
                    <label class="flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3">
                        <span class="text-sm font-medium text-gray-700">{{ __('طباعة نسخة للمطبخ / قسم التجهيز') }}</span>
                        <span><input type="hidden" name="print_kitchen" value="0" /><input type="checkbox" name="print_kitchen" value="1" @checked($sget('print_kitchen', '0') !== '0') class="w-5 h-5 rounded text-primary-600 focus:ring-primary-500 border-gray-300" /></span>
                    </label>
                </div>
                <div class="mt-6 flex justify-end">
                    <x-button variant="primary" size="md" icon="save" type="submit">{{ __('حفظ التغييرات') }}</x-button>
                </div>
            </div>

            {{-- صلاحيات الموظفين --}}
            <div x-show="tab === 'permissions'" x-cloak class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-2">{{ __('صلاحيات الموظفين الافتراضية') }}</h3>
                <p class="text-sm text-gray-500 mb-6">{{ __('حدد الصلاحيات الافتراضية للموظفين الجدد حسب الدور.') }}</p>
                @php
                    $rolePerms = [
                        'كاشير' => ['فتح نقطة البيع', 'إنشاء طلب', 'طباعة فاتورة'],
                        'موظف مبيعات' => ['إنشاء طلب', 'إدارة العملاء', 'تطبيق خصومات'],
                        'مسؤول مخزون' => ['إدارة المخزون', 'حركات المخزون', 'الجرد'],
                    ];
                @endphp
                {{-- ملاحظة: أسماء الأدوار والصلاحيات تُترجم عند العرض عبر __() --}}
                <div class="space-y-5">
                    @foreach ($rolePerms as $role => $perms)
                        <div class="rounded-xl border border-gray-200 p-4">
                            <h4 class="text-sm font-semibold text-gray-800 mb-3">{{ __($role) }}</h4>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                                @foreach ($perms as $perm)
                                    @php $pk = 'perm_' . $loop->parent->index . '_' . $loop->index; @endphp
                                    <label class="flex items-center gap-2.5 cursor-pointer">
                                        <input type="hidden" name="{{ $pk }}" value="0" />
                                        <input type="checkbox" name="{{ $pk }}" value="1" @checked($sget($pk) !== '0') class="w-4 h-4 rounded text-primary-600 focus:ring-primary-500 border-gray-300" />
                                        <span class="text-sm text-gray-700">{{ __($perm) }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-6 flex justify-end">
                    <x-button variant="primary" size="md" icon="save" type="submit">{{ __('حفظ التغييرات') }}</x-button>
                </div>
            </div>

            {{-- الإشعارات --}}
            <div x-show="tab === 'notifications'" x-cloak class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-6">{{ __('الإشعارات') }}</h3>

                {{-- إشعار البريد عند طلب جديد (فعّال) --}}
                @php $notifyNewOrder = \App\Models\Setting::where('business_id', auth()->user()->business_id)->where('key', 'notify_new_order')->value('value'); @endphp
                <label class="flex items-center justify-between rounded-xl border border-primary-100 bg-primary-50/40 px-4 py-3.5 cursor-pointer mb-3">
                    <div class="flex items-center gap-3">
                        <span class="w-9 h-9 rounded-xl bg-primary-100 text-primary-600 flex items-center justify-center"><x-icon name="mail" class="w-5 h-5" /></span>
                        <div>
                            <span class="text-sm font-semibold text-gray-800">{{ __('إرسال بريد إلكتروني عند كل طلب جديد') }}</span>
                            <p class="text-xs text-gray-500">{{ __('يُرسل إلى بريد صاحب النشاط عند إتمام أي عملية بيع.') }}</p>
                        </div>
                    </div>
                    <span>
                        <input type="hidden" name="notify_new_order" value="0" />
                        <input type="checkbox" name="notify_new_order" value="1" @checked($notifyNewOrder !== '0') class="w-5 h-5 rounded text-primary-600 focus:ring-primary-500 border-gray-300" />
                    </span>
                </label>

                {{-- التنبيهات الذكية بالبريد (فعّالة عبر أمر alerts:smart المجدول) --}}
                @php $notifySmart = \App\Models\Setting::where('business_id', auth()->user()->business_id)->where('key', 'notify_smart_alerts')->value('value'); @endphp
                <label class="flex items-center justify-between rounded-xl border border-secondary-100 bg-secondary-50/40 px-4 py-3.5 cursor-pointer mb-3">
                    <div class="flex items-center gap-3">
                        <span class="w-9 h-9 rounded-xl bg-secondary-100 text-secondary-600 flex items-center justify-center"><x-icon name="sparkles" class="w-5 h-5" /></span>
                        <div>
                            <span class="text-sm font-semibold text-gray-800">{{ __('تنبيهات ذكية يومية بالبريد') }}</span>
                            <p class="text-xs text-gray-500">{{ __('تراجع المبيعات، المنتجات الراكدة، والعملاء المتعثّرون — تُرسَل صباح كل يوم.') }}</p>
                        </div>
                    </div>
                    <span>
                        <input type="hidden" name="notify_smart_alerts" value="0" />
                        <input type="checkbox" name="notify_smart_alerts" value="1" @checked($notifySmart !== '0') class="w-5 h-5 rounded text-secondary-600 focus:ring-secondary-500 border-gray-300" />
                    </span>
                </label>

                {{-- تشغيل يدوي فوري للتنبيهات الذكية (لا ينتظر الجدولة الصباحية) --}}
                <div x-data="{ sending: false,
                        async send() {
                            this.sending = true;
                            try {
                                const res = await fetch('{{ route('admin.notifications.send-smart') }}', {
                                    method: 'POST',
                                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                                });
                                const data = await res.json().catch(() => ({}));
                                $store.toasts.add(data.message || @js(__('تعذّر التنفيذ')), data.ok ? (data.count ? 'success' : 'info') : 'danger', 4000);
                            } catch (e) {
                                $store.toasts.add(@js(__('تعذّر الاتصال. حاول مرة أخرى.')), 'danger', 3000);
                            }
                            this.sending = false;
                        } }"
                     class="flex items-center justify-between gap-3 rounded-xl border border-dashed border-secondary-200 px-4 py-3 mb-5">
                    <p class="text-xs text-gray-500">{{ __('جرّب التنبيهات الآن دون انتظار الإرسال الصباحي.') }}</p>
                    <button type="button" @click="send()" :disabled="sending"
                            class="shrink-0 inline-flex items-center gap-1.5 rounded-full bg-secondary-600 hover:bg-secondary-700 text-white text-xs font-semibold px-4 py-2 transition disabled:opacity-50">
                        <x-icon name="send" class="w-3.5 h-3.5" x-show="!sending" />
                        <x-icon name="loader-circle" class="w-3.5 h-3.5 animate-spin" x-show="sending" x-cloak />
                        <span x-text="sending ? @js(__('جارٍ الإرسال...')) : @js(__('إرسال تنبيهات الآن'))"></span>
                    </button>
                </div>

                {{-- الملخّص اليومي (بريد نهاية اليوم + بطاقة في جرس الإشعارات) --}}
                @php $notifyDaily = \App\Models\Setting::where('business_id', auth()->user()->business_id)->where('key', 'notify_daily_summary')->value('value'); @endphp
                <label class="flex items-center justify-between rounded-xl border border-success-100 bg-success-50/40 px-4 py-3.5 cursor-pointer mb-5">
                    <div class="flex items-center gap-3">
                        <span class="w-9 h-9 rounded-xl bg-success-100 text-success-600 flex items-center justify-center"><x-icon name="bar-chart-3" class="w-5 h-5" /></span>
                        <div>
                            <span class="text-sm font-semibold text-gray-800">{{ __('ملخّص الأداء اليومي') }}</span>
                            <p class="text-xs text-gray-500">{{ __('مبيعات اليوم والطلبات وصافي الأرباح والأكثر مبيعًا — بريد نهاية كل يوم وبطاقة في جرس الإشعارات.') }}</p>
                        </div>
                    </div>
                    <span>
                        <input type="hidden" name="notify_daily_summary" value="0" />
                        <input type="checkbox" name="notify_daily_summary" value="1" @checked($notifyDaily !== '0') class="w-5 h-5 rounded text-success-600 focus:ring-success-500 border-gray-300" />
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
                            <span class="text-sm font-medium text-gray-700">{{ __($notif) }}</span>
                            <span><input type="hidden" name="{{ $nk }}" value="0" /><input type="checkbox" name="{{ $nk }}" value="1" @checked($sget($nk, $on ? '1' : '0') !== '0') class="w-5 h-5 rounded text-primary-600 focus:ring-primary-500 border-gray-300" /></span>
                        </label>
                    @endforeach
                </div>
                <div class="mt-6 flex justify-end">
                    <x-button variant="primary" size="md" icon="save" type="submit">{{ __('حفظ التغييرات') }}</x-button>
                </div>
            </div>

            {{-- الطلبات --}}
            <div x-show="tab === 'orders'" x-cloak class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-6">{{ __('إعدادات الطلبات') }}</h3>
                <div class="space-y-5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-input :label="__('بادئة رقم الطلب')" name="order_prefix" value="ORD-" icon="hash" />
                        <x-select :label="__('الحالة الافتراضية للطلب الجديد')" name="default_status" :options="[
                            'جديد' => __('جديد'),
                            'قيد التجهيز' => __('قيد التجهيز'),
                        ]" selected="جديد" />
                    </div>
                    <label class="flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3">
                        <span class="text-sm font-medium text-gray-700">{{ __('السماح بتعديل الطلب بعد إنشائه') }}</span>
                        <span><input type="hidden" name="order_allow_edit" value="0" /><input type="checkbox" name="order_allow_edit" value="1" @checked($sget('order_allow_edit') !== '0') class="w-5 h-5 rounded text-primary-600 focus:ring-primary-500 border-gray-300" /></span>
                    </label>
                    <label class="flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3">
                        <span class="text-sm font-medium text-gray-700">{{ __('طلب تأكيد قبل إلغاء الطلب') }}</span>
                        <span><input type="hidden" name="order_confirm_cancel" value="0" /><input type="checkbox" name="order_confirm_cancel" value="1" @checked($sget('order_confirm_cancel') !== '0') class="w-5 h-5 rounded text-primary-600 focus:ring-primary-500 border-gray-300" /></span>
                    </label>
                </div>
                <div class="mt-6 flex justify-end">
                    <x-button variant="primary" size="md" icon="save" type="submit">{{ __('حفظ التغييرات') }}</x-button>
                </div>
            </div>

            {{-- التوصيل --}}
            <div x-show="tab === 'delivery'" x-cloak class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-lg font-bold text-gray-800">{{ __('إعدادات التوصيل') }}</h3>
                    <x-button variant="primary" size="sm" icon="plus" x-on:click="$store.toasts.add('{{ __('إضافة منطقة توصيل') }}', 'info')">{{ __('إضافة منطقة') }}</x-button>
                </div>
                <div class="space-y-5">
                    <label class="flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3">
                        <div>
                            <span class="text-sm font-medium text-gray-700">{{ __('تفعيل خدمة التوصيل') }}</span>
                            <p class="text-xs text-gray-400">{{ __('إتاحة التوصيل للطلبات الخارجية') }}</p>
                        </div>
                        <span><input type="hidden" name="delivery_enabled" value="0" /><input type="checkbox" name="delivery_enabled" value="1" @checked($sget('delivery_enabled') !== '0') class="w-5 h-5 rounded text-primary-600 focus:ring-primary-500 border-gray-300" /></span>
                    </label>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-input :label="__('رسوم التوصيل الافتراضية (ر.ع)')" name="delivery_fee" type="number" value="1.500" icon="truck" />
                        <x-input :label="__('حد التوصيل المجاني (ر.ع)')" name="free_threshold" type="number" value="25.000" icon="gift" />
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
                        <h4 class="text-sm font-semibold text-gray-800 mb-3">{{ __('مناطق التوصيل ورسومها') }}</h4>
                        <div class="overflow-x-auto rounded-xl border border-gray-100">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-50 text-gray-500">
                                    <tr>
                                        <th class="px-4 py-2.5 text-right font-medium">{{ __('المنطقة') }}</th>
                                        <th class="px-4 py-2.5 text-right font-medium">{{ __('رسوم التوصيل (ر.ع)') }}</th>
                                        <th class="px-4 py-2.5 text-right font-medium">{{ __('إجراءات') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-50">
                                    <template x-for="(zone, i) in zones" :key="i">
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-4 py-3 font-medium text-gray-800" x-text="zone.name"></td>
                                            <td class="px-4 py-3 text-gray-700" x-text="Number(zone.fee).toFixed(3)"></td>
                                            <td class="px-4 py-3">
                                                <div class="flex items-center gap-1">
                                                    <button type="button" @click="edit(i)" class="p-1.5 rounded-full text-gray-500 hover:bg-gray-100" title="{{ __('تعديل') }}"><x-icon name="pencil" class="w-4 h-4" /></button>
                                                    <button type="button" @click="remove(i)" class="p-1.5 rounded-full text-danger-600 hover:bg-danger-50" title="{{ __('حذف') }}"><x-icon name="trash-2" class="w-4 h-4" /></button>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                    <tr x-show="!zones.length"><td colspan="3" class="px-4 py-6 text-center text-gray-400">{{ __('لا توجد مناطق. أضف منطقة جديدة أدناه.') }}</td></tr>
                                </tbody>
                            </table>
                        </div>
                        {{-- إضافة / تعديل منطقة --}}
                        <div class="mt-3 flex flex-col sm:flex-row gap-2 items-end">
                            <div class="flex-1 w-full">
                                <label class="block text-xs text-gray-500 mb-1">{{ __('اسم المنطقة') }}</label>
                                <input type="text" x-model="form.name" placeholder="{{ __('مثال: المعبيلة') }}" class="w-full rounded-lg border-gray-200 text-sm" />
                            </div>
                            <div class="w-full sm:w-40">
                                <label class="block text-xs text-gray-500 mb-1">{{ __('الرسوم') }}</label>
                                <input type="text" inputmode="decimal" data-money min="0" x-model="form.fee" class="w-full rounded-lg border-gray-200 text-sm" />
                            </div>
                            <button type="button" @click="save()" class="bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-full px-4 py-2 whitespace-nowrap" x-text="editIndex === null ? @js(__('إضافة منطقة')) : @js(__('حفظ التعديل'))"></button>
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
                    <x-button variant="primary" size="md" icon="save" type="submit">{{ __('حفظ التغييرات') }}</x-button>
                </div>
            </div>

            {{-- برنامج الولاء --}}
            @php $loyaltyEnabled = $sget('loyalty_enabled', '1'); $earnRate = $sget('loyalty_earn_rate', '5'); $redeemMaxPct = $sget('loyalty_redeem_max_pct', '50'); $redeemMin = $sget('loyalty_redeem_min', '100'); @endphp
            <div x-show="tab === 'loyalty'" x-cloak class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-2">{{ __('برنامج الولاء') }}</h3>
                <p class="text-sm text-gray-400 mb-6">{{ __('امنح عملاءك نقاطًا عند الشراء يستبدلونها كخصم لاحقًا') }}</p>

                <label class="flex items-center justify-between py-3 border-b border-gray-100">
                    <span class="text-sm font-medium text-gray-700">{{ __('تفعيل نقاط الولاء عند الشراء') }}</span>
                    <span>
                        <input type="hidden" name="loyalty_enabled" value="0" />
                        <input type="checkbox" name="loyalty_enabled" value="1" @checked($loyaltyEnabled !== '0') class="w-5 h-5 rounded text-secondary-600 focus:ring-secondary-500 border-gray-300" />
                    </span>
                </label>

                <div class="mt-5 grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-2xl">
                    <x-input :label="__('نقاط لكل 1 ر.ع من قيمة الشراء')" name="loyalty_earn_rate" data-money value="{{ $earnRate }}" icon="award"
                             :hint="__('مثال: 5 = خمس نقاط لكل ريال · الاستبدال: 100 نقطة = 1 ر.ع خصم')" />
                    <x-input :label="__('أقصى نسبة يُغطّيها الاستبدال من كل فاتورة (%)')" name="loyalty_redeem_max_pct" type="number" min="0" max="100" value="{{ $redeemMaxPct }}" icon="percent"
                             :hint="__('يبقى العميل يدفع الباقي نقدًا · مثال: 50% تعني ألا يخصم بالنقاط أكثر من نصف الفاتورة')" />
                    <x-input :label="__('الحد الأدنى لبدء الاستبدال (نقطة)')" name="loyalty_redeem_min" type="number" min="0" value="{{ $redeemMin }}" icon="trending-up"
                             :hint="__('تتراكم النقاط حتى تبلغ هذا الحد ثم يُتاح خصمها · مثال: 100 نقطة = 1 ر.ع')" />
                </div>

                <div class="mt-6 flex justify-end">
                    <x-button variant="primary" size="md" icon="save" type="submit">{{ __('حفظ التغييرات') }}</x-button>
                </div>
            </div>
        </form>

        {{-- اللغة (نموذج مستقل بنفس شكل بيانات النشاط) --}}
        <div x-show="tab === 'language'" x-cloak>
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-6">{{ __('لغة النظام') }}</h3>
                @php $currentLocale = app()->getLocale(); @endphp
                <form method="POST" action="{{ route('admin.language.update') }}">
                    @csrf
                    <div class="space-y-5">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            {{-- اسم اللغة يبقى بلغته، أمّا وصف الاتجاه فيتبع لغة الواجهة --}}
                            @foreach ([['ar', 'العربية', __('من اليمين إلى اليسار (RTL)')], ['en', 'English', __('من اليسار إلى اليمين (LTR)')]] as [$code, $label, $hint])
                                <label class="flex items-center justify-between rounded-xl border px-4 py-3.5 cursor-pointer transition
                                              {{ $currentLocale === $code ? 'border-gray-900 bg-gray-50' : 'border-gray-200 hover:bg-gray-50' }}">
                                    <span class="flex items-center gap-3">
                                        <span class="w-10 h-10 rounded-full bg-gray-100 text-gray-700 flex items-center justify-center font-bold text-xs">
                                            {{ strtoupper($code) }}
                                        </span>
                                        <span>
                                            <span class="block text-sm font-medium text-gray-800">{{ $label }}</span>
                                            <span class="block text-xs text-gray-400">{{ $hint }}</span>
                                        </span>
                                    </span>
                                    <input type="radio" name="locale" value="{{ $code }}" @checked($currentLocale === $code)
                                           class="w-5 h-5 text-gray-900 focus:ring-gray-300 border-gray-300" />
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end">
                        <x-button variant="primary" size="md" icon="save" type="submit">{{ __('حفظ اللغة') }}</x-button>
                    </div>
                </form>
            </div>
        </div>

        {{-- النسخ الاحتياطي (نماذج مستقلة بنفس شكل بيانات النشاط) --}}
        <div x-show="tab === 'backup'" x-cloak class="space-y-6">
            {{-- تنزيل نسخة احتياطية --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-6">{{ __('تنزيل نسخة احتياطية') }}</h3>
                <p class="text-sm text-gray-500 mb-5">{{ __('يشمل الملف كامل بيانات متجرك: المنتجات، الأقسام، العملاء، الطلبات، المصروفات، المعاملات، حركات المخزون، والإعدادات — بصيغة JSON.') }}</p>
                <div class="flex justify-end">
                    <x-button variant="primary" size="md" icon="database-backup" :href="route('admin.backup.download')">{{ __('تنزيل النسخة الآن') }}</x-button>
                </div>
            </div>

            {{-- استعادة من نسخة --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-6">{{ __('استعادة من نسخة احتياطية') }}</h3>
                <div class="flex items-start gap-2 bg-danger-50 text-danger-700 text-sm rounded-xl p-3 mb-5">
                    <x-icon name="alert-triangle" class="w-5 h-5 shrink-0 mt-0.5" />
                    <span>{{ __('تحذير: ستحل بيانات النسخة محل بيانات متجرك الحالية بالكامل. لا يمكن التراجع — نوصي بتنزيل نسخة حديثة أولًا.') }}</span>
                </div>
                <form method="POST" action="{{ route('admin.backup.restore') }}" enctype="multipart/form-data"
                      x-data="{ fileName: '' }" @submit="if(!fileName){ $event.preventDefault(); $store.toasts.add(@js(__('اختر ملف النسخة أولًا')),'warning'); }">
                    @csrf
                    <label class="flex flex-col items-center justify-center gap-2 border-2 border-dashed border-gray-200 rounded-2xl p-6 text-center cursor-pointer hover:border-danger-300 transition">
                        <x-icon name="upload-cloud" class="w-8 h-8 text-gray-400" />
                        <span class="text-sm font-medium text-gray-700" x-text="fileName || @js(__('اختر ملف النسخة الاحتياطية (JSON)'))"></span>
                        <input type="file" name="backup" accept=".json,application/json" class="hidden"
                               @change="fileName = $event.target.files[0]?.name || ''" />
                    </label>
                    @error('backup')<p class="mt-2 text-xs text-danger-500">{{ $message }}</p>@enderror
                    <div class="mt-6 flex justify-end">
                        <x-button variant="danger" size="md" icon="database" type="submit">{{ __('استعادة البيانات') }}</x-button>
                    </div>
                </form>
            </div>
        </div>

        {{-- التنبيهات المرسلة (عرض كامل قائمة التنبيهات) --}}
        @php
            $allNotifications = \App\Support\Demo::allNotifications();
            $notifColors = [
                'danger' => 'bg-danger-50 text-danger-600',
                'warning' => 'bg-warning-50 text-warning-600',
                'info' => 'bg-info-50 text-info-600',
                'success' => 'bg-success-50 text-success-600',
            ];
        @endphp
        <div x-show="tab === 'notifications-log'" x-cloak
             x-data="{
                count: {{ count($allNotifications) }},
                dismiss(key, row) {
                    fetch('{{ route('admin.notifications.dismiss') }}', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                        body: JSON.stringify({ key })
                    }).then(() => { row.remove(); this.count--; $store.toasts?.add(@js(__('تم حذف التنبيه')), 'success', 1500); });
                },
                clearAll() {
                    if (!confirm(@js(__('حذف جميع التنبيهات المرسلة؟')))) return;
                    fetch('{{ route('admin.notifications.clear') }}', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' }
                    }).then(() => { this.$refs.list?.replaceChildren(); this.count = 0; $store.toasts?.add(@js(__('تم حذف جميع التنبيهات')), 'success', 1500); });
                }
             }">
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <div class="flex items-center justify-between mb-6 gap-3">
                    <div>
                        <h3 class="text-lg font-bold text-gray-800">{{ __('التنبيهات المرسلة') }}</h3>
                        <p class="text-sm text-gray-500 mt-1">{{ __('جميع التنبيهات التي أُرسلت إليك — مخزون منخفض وطلبات بانتظار التجهيز.') }}</p>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-gray-100 text-gray-700 text-sm font-medium">
                            <x-icon name="bell-ring" class="w-4 h-4" /> <span x-text="count"></span> {{ __('تنبيه') }}
                        </span>
                        <button type="button" x-show="count > 0" @click="clearAll()"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full border border-danger-200 text-danger-600 hover:bg-danger-50 text-sm font-medium transition">
                            <x-icon name="trash-2" class="w-4 h-4" /> {{ __('حذف الكل') }}
                        </button>
                    </div>
                </div>

                <div x-ref="list" x-show="count > 0" class="divide-y divide-gray-100">
                    @foreach ($allNotifications as $n)
                        <div data-notif class="flex items-start gap-3 py-3 -mx-2 px-2 rounded-xl hover:bg-gray-50 transition">
                            <a href="{{ $n['url'] ?? '#' }}" class="flex items-start gap-3 flex-1 min-w-0">
                                <span class="w-9 h-9 rounded-full flex items-center justify-center shrink-0 {{ $notifColors[$n['color']] ?? $notifColors['info'] }}">
                                    <x-icon :name="$n['icon']" class="w-[18px] h-[18px]" />
                                </span>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm text-gray-800">{{ $n['text'] }}</p>
                                    <p class="text-xs text-gray-400 mt-0.5">{{ $n['time'] }}</p>
                                </div>
                            </a>
                            <button type="button" title="{{ __('حذف') }}"
                                    @click="dismiss(@js($n['key']), $el.closest('[data-notif]'))"
                                    class="w-8 h-8 rounded-full text-gray-300 hover:text-danger-600 hover:bg-danger-50 flex items-center justify-center shrink-0 transition">
                                <x-icon name="trash-2" class="w-4 h-4" />
                            </button>
                        </div>
                    @endforeach
                </div>
                <div x-show="count === 0" x-cloak>
                    <x-empty-state icon="bell-off" :title="__('لا توجد تنبيهات')" :message="__('لا توجد تنبيهات مُرسلة حاليًا. ستظهر هنا عند انخفاض المخزون أو ورود طلبات جديدة.')" />
                </div>
            </div>
        </div>
        </div>{{-- نهاية عمود المحتوى --}}
    </div>
</x-layouts::admin>
