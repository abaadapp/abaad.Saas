<x-layouts::super-admin title="الإعدادات">
    <x-page-header
        title="الإعدادات"
        subtitle="إدارة إعدادات المنصة العامة والاشتراكات والضرائب والإشعارات"
        :breadcrumbs="['الرئيسية' => route('super-admin.dashboard'), 'الإعدادات' => '#']"
    />

    <div x-data="{ tab: 'general' }" class="grid grid-cols-1 lg:grid-cols-4 gap-6">
        {{-- قائمة التبويبات الجانبية --}}
        <aside class="lg:col-span-1">
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-2 lg:sticky lg:top-6">
                @php
                    $tabs = [
                        'general' => ['label' => 'عامة', 'icon' => 'settings'],
                        'platform' => ['label' => 'بيانات المنصة', 'icon' => 'globe'],
                        'subscriptions' => ['label' => 'الاشتراكات', 'icon' => 'refresh-cw'],
                        'taxes' => ['label' => 'الضرائب', 'icon' => 'percent'],
                        'currencies' => ['label' => 'العملات', 'icon' => 'coins'],
                        'notifications' => ['label' => 'الإشعارات', 'icon' => 'bell'],
                        'mail' => ['label' => 'البريد', 'icon' => 'mail'],
                        'terms' => ['label' => 'الشروط والأحكام', 'icon' => 'file-text'],
                        'privacy' => ['label' => 'سياسة الخصوصية', 'icon' => 'shield-check'],
                    ];
                @endphp
                <nav class="flex lg:flex-col gap-1 overflow-x-auto">
                    @foreach ($tabs as $key => $t)
                        <button
                            @click="tab = '{{ $key }}'"
                            :class="tab === '{{ $key }}' ? 'bg-primary-50 text-primary-700' : 'text-gray-600 hover:bg-gray-50'"
                            class="flex items-center gap-2.5 px-3.5 py-2.5 rounded-xl text-sm font-medium whitespace-nowrap transition-colors text-right"
                        >
                            <x-icon :name="$t['icon']" class="w-4 h-4" />
                            {{ $t['label'] }}
                        </button>
                    @endforeach
                </nav>
            </div>
        </aside>

        {{-- محتوى التبويبات --}}
        <form method="POST" action="{{ route('super-admin.settings.update') }}" class="lg:col-span-3">
            @csrf
            {{-- عامة --}}
            <div x-show="tab === 'general'" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-6">الإعدادات العامة</h3>
                <div class="space-y-5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-input label="اسم المنصة" name="app_name" value="Abad POS" />
                        <x-select label="اللغة الافتراضية" name="locale" :options="['ar' => 'العربية', 'en' => 'English']" selected="ar" />
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-select label="المنطقة الزمنية" name="timezone" :options="['Asia/Muscat' => 'مسقط (GMT+4)', 'Asia/Riyadh' => 'الرياض (GMT+3)']" selected="Asia/Muscat" />
                        <x-select label="تنسيق التاريخ" name="date_format" :options="['Y-m-d' => '2026-07-17', 'd/m/Y' => '17/07/2026']" selected="Y-m-d" />
                    </div>
                    <label class="flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3">
                        <div>
                            <span class="text-sm font-medium text-gray-700">وضع الصيانة</span>
                            <p class="text-xs text-gray-400">إيقاف الوصول للمنصة مؤقتًا</p>
                        </div>
                        <input type="checkbox" class="w-5 h-5 rounded text-primary-600 focus:ring-primary-500 border-gray-300" />
                    </label>
                </div>
                <div class="mt-6 flex justify-end">
                    <x-button variant="primary" size="md" icon="save" type="submit">حفظ التغييرات</x-button>
                </div>
            </div>

            {{-- بيانات المنصة --}}
            <div x-show="tab === 'platform'" x-cloak class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-6">بيانات المنصة</h3>
                <div class="space-y-5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-input label="اسم الشركة المالكة" name="company" value="شركة أبعاد للتقنية" />
                        <x-input label="البريد الرسمي" name="official_email" type="email" value="info@abad.om" icon="mail" />
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-input label="رقم الهاتف" name="phone" value="+968 24000000" icon="phone" />
                        <x-input label="الموقع الإلكتروني" name="website" value="https://abad.om" icon="globe" />
                    </div>
                    <x-input label="العنوان" name="address" value="مسقط، سلطنة عُمان" icon="map-pin" />
                    <x-input label="السجل التجاري" name="cr" value="1234567" icon="hash" />
                </div>
                <div class="mt-6 flex justify-end">
                    <x-button variant="primary" size="md" icon="save" type="submit">حفظ التغييرات</x-button>
                </div>
            </div>

            {{-- الاشتراكات --}}
            <div x-show="tab === 'subscriptions'" x-cloak class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-6">إعدادات الاشتراكات</h3>
                <div class="space-y-5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-input label="مدة الفترة التجريبية (أيام)" name="trial_days" type="number" value="14" />
                        <x-input label="مهلة السماح بعد الانتهاء (أيام)" name="grace_days" type="number" value="7" />
                    </div>
                    <x-select label="الباقة الافتراضية عند التسجيل" name="default_plan" :options="['أساسية' => 'الباقة الأساسية', 'احترافية' => 'الباقة الاحترافية']" selected="أساسية" />
                    <label class="flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3">
                        <span class="text-sm font-medium text-gray-700">التجديد التلقائي للاشتراكات</span>
                        <input type="checkbox" checked class="w-5 h-5 rounded text-primary-600 focus:ring-primary-500 border-gray-300" />
                    </label>
                    <label class="flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3">
                        <span class="text-sm font-medium text-gray-700">تعطيل الحساب تلقائيًا عند انتهاء المهلة</span>
                        <input type="checkbox" checked class="w-5 h-5 rounded text-primary-600 focus:ring-primary-500 border-gray-300" />
                    </label>
                </div>
                <div class="mt-6 flex justify-end">
                    <x-button variant="primary" size="md" icon="save" type="submit">حفظ التغييرات</x-button>
                </div>
            </div>

            {{-- الضرائب --}}
            <div x-show="tab === 'taxes'" x-cloak class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-6">إعدادات الضرائب</h3>
                <div class="space-y-5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-input label="نسبة ضريبة القيمة المضافة (%)" name="vat_rate" type="number" value="5" hint="النسبة المطبقة في سلطنة عُمان" />
                        <x-input label="الرقم الضريبي" name="tax_number" value="OM100234567" icon="hash" />
                    </div>
                    <x-select label="طريقة احتساب الضريبة" name="tax_mode" :options="['inclusive' => 'شاملة السعر', 'exclusive' => 'مضافة على السعر']" selected="exclusive" />
                    <label class="flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3">
                        <span class="text-sm font-medium text-gray-700">تفعيل ضريبة القيمة المضافة</span>
                        <input type="checkbox" checked class="w-5 h-5 rounded text-primary-600 focus:ring-primary-500 border-gray-300" />
                    </label>
                </div>
                <div class="mt-6 flex justify-end">
                    <x-button variant="primary" size="md" icon="save" type="submit">حفظ التغييرات</x-button>
                </div>
            </div>

            {{-- العملات --}}
            <div x-show="tab === 'currencies'" x-cloak class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-6">إعدادات العملات</h3>
                <div class="space-y-5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-select label="العملة الأساسية" name="base_currency" :options="['OMR' => 'ريال عماني (OMR)', 'AED' => 'درهم إماراتي (AED)', 'SAR' => 'ريال سعودي (SAR)']" selected="OMR" />
                        <x-input label="رمز العملة" name="currency_symbol" value="ر.ع" />
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-input label="عدد الخانات العشرية" name="decimals" type="number" value="3" hint="الريال العماني يستخدم 3 خانات عشرية" />
                        <x-select label="موضع الرمز" name="symbol_position" :options="['after' => 'بعد المبلغ (12.500 ر.ع)', 'before' => 'قبل المبلغ (ر.ع 12.500)']" selected="after" />
                    </div>
                </div>
                <div class="mt-6 flex justify-end">
                    <x-button variant="primary" size="md" icon="save" type="submit">حفظ التغييرات</x-button>
                </div>
            </div>

            {{-- الإشعارات --}}
            <div x-show="tab === 'notifications'" x-cloak class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-6">إعدادات الإشعارات</h3>
                <div class="space-y-3">
                    @php
                        $notes = [
                            'إشعار عند تسجيل شركة جديدة' => true,
                            'إشعار عند دفع فاتورة' => true,
                            'إشعار قرب انتهاء الاشتراك' => true,
                            'إشعار عند انتهاء الاشتراك' => true,
                            'إشعارات عبر البريد الإلكتروني' => true,
                            'إشعارات عبر الرسائل النصية' => false,
                        ];
                    @endphp
                    @foreach ($notes as $note => $on)
                        <label class="flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3">
                            <span class="text-sm font-medium text-gray-700">{{ $note }}</span>
                            <input type="checkbox" @checked($on) class="w-5 h-5 rounded text-primary-600 focus:ring-primary-500 border-gray-300" />
                        </label>
                    @endforeach
                </div>
                <div class="mt-6 flex justify-end">
                    <x-button variant="primary" size="md" icon="save" type="submit">حفظ التغييرات</x-button>
                </div>
            </div>

            {{-- البريد --}}
            <div x-show="tab === 'mail'" x-cloak class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-6">إعدادات البريد الإلكتروني</h3>
                <div class="space-y-5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-select label="مزود الإرسال" name="mailer" :options="['smtp' => 'SMTP', 'ses' => 'Amazon SES', 'mailgun' => 'Mailgun']" selected="smtp" />
                        <x-input label="خادم SMTP" name="mail_host" value="smtp.mailtrap.io" />
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-input label="المنفذ" name="mail_port" type="number" value="587" />
                        <x-select label="التشفير" name="mail_encryption" :options="['tls' => 'TLS', 'ssl' => 'SSL', 'none' => 'بدون']" selected="tls" />
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-input label="بريد المُرسِل" name="from_address" type="email" value="no-reply@abad.om" icon="mail" />
                        <x-input label="اسم المُرسِل" name="from_name" value="Abad POS" />
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <x-button variant="outline" size="md" icon="send" type="submit" formaction="{{ route('super-admin.settings.testEmail') }}" formmethod="POST">اختبار الإرسال</x-button>
                    <x-button variant="primary" size="md" icon="save" type="submit">حفظ التغييرات</x-button>
                </div>
            </div>

            {{-- الشروط والأحكام --}}
            <div x-show="tab === 'terms'" x-cloak class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-6">الشروط والأحكام</h3>
                <div class="space-y-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">نص الشروط والأحكام</label>
                    <textarea name="terms" rows="12" class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:outline-none transition">باستخدامك منصة Abad POS فإنك توافق على الالتزام بكامل الشروط والأحكام الموضحة أدناه. تحتفظ المنصة بحق تعديل هذه الشروط في أي وقت، ويسري التعديل فور نشره. يلتزم المشترك بتقديم بيانات صحيحة وبعدم إساءة استخدام الخدمة...</textarea>
                </div>
                <div class="mt-6 flex justify-end">
                    <x-button variant="primary" size="md" icon="save" type="submit">حفظ التغييرات</x-button>
                </div>
            </div>

            {{-- سياسة الخصوصية --}}
            <div x-show="tab === 'privacy'" x-cloak class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-6">سياسة الخصوصية</h3>
                <div class="space-y-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">نص سياسة الخصوصية</label>
                    <textarea name="privacy" rows="12" class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:outline-none transition">تحرص منصة Abad POS على حماية خصوصية مستخدميها والحفاظ على سرية بياناتهم. نقوم بجمع البيانات الضرورية لتقديم الخدمة فقط، ولا تتم مشاركتها مع أي طرف ثالث دون موافقة مسبقة، ما لم يكن ذلك مطلوبًا بموجب القانون. يحق للمستخدم طلب حذف بياناته في أي وقت...</textarea>
                </div>
                <div class="mt-6 flex justify-end">
                    <x-button variant="primary" size="md" icon="save" type="submit">حفظ التغييرات</x-button>
                </div>
            </div>
        </form>
    </div>
</x-layouts::super-admin>
