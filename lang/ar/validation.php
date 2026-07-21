<?php

return [

    /*
    |--------------------------------------------------------------------------
    | رسائل التحقق من الصحة (Validation Language Lines)
    |--------------------------------------------------------------------------
    |
    | تحتوي الأسطر التالية على الرسائل الافتراضية التي تعرضها فئة التحقق.
    | بعض القواعد لها أكثر من صيغة (مصفوفة / ملف / رقم / نص).
    |
    */

    'accepted' => 'يجب قبول حقل :attribute.',
    'accepted_if' => 'يجب قبول حقل :attribute عندما يكون :other هو :value.',
    'active_url' => 'يجب أن يكون حقل :attribute رابطًا صحيحًا.',
    'after' => 'يجب أن يكون حقل :attribute تاريخًا بعد :date.',
    'after_or_equal' => 'يجب أن يكون حقل :attribute تاريخًا بعد أو يساوي :date.',
    'alpha' => 'يجب أن يحتوي حقل :attribute على أحرف فقط.',
    'alpha_dash' => 'يجب أن يحتوي حقل :attribute على أحرف وأرقام وشرطات وشرطات سفلية فقط.',
    'alpha_num' => 'يجب أن يحتوي حقل :attribute على أحرف وأرقام فقط.',
    'any_of' => 'حقل :attribute غير صالح.',
    'array' => 'يجب أن يكون حقل :attribute مصفوفة.',
    'ascii' => 'يجب أن يحتوي حقل :attribute على أحرف ورموز أحادية البايت فقط.',
    'before' => 'يجب أن يكون حقل :attribute تاريخًا قبل :date.',
    'before_or_equal' => 'يجب أن يكون حقل :attribute تاريخًا قبل أو يساوي :date.',

    'between' => [
        'array' => 'يجب أن يحتوي حقل :attribute على عدد عناصر بين :min و :max.',
        'file' => 'يجب أن يكون حجم ملف :attribute بين :min و :max كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة حقل :attribute بين :min و :max.',
        'string' => 'يجب أن يكون عدد أحرف حقل :attribute بين :min و :max حرفًا.',
    ],

    'boolean' => 'يجب أن تكون قيمة حقل :attribute إما صح أو خطأ.',
    'can' => 'يحتوي حقل :attribute على قيمة غير مصرّح بها.',
    'confirmed' => 'تأكيد حقل :attribute غير مطابق.',
    'contains' => 'حقل :attribute تنقصه قيمة مطلوبة.',
    'current_password' => 'كلمة المرور غير صحيحة.',
    'date' => 'يجب أن يكون حقل :attribute تاريخًا صحيحًا.',
    'date_equals' => 'يجب أن يكون حقل :attribute تاريخًا مساويًا لـ :date.',
    'date_format' => 'يجب أن يطابق حقل :attribute الصيغة :format.',
    'decimal' => 'يجب أن يحتوي حقل :attribute على :decimal منزلة عشرية.',
    'declined' => 'يجب رفض حقل :attribute.',
    'declined_if' => 'يجب رفض حقل :attribute عندما يكون :other هو :value.',
    'different' => 'يجب أن يكون حقل :attribute مختلفًا عن :other.',
    'digits' => 'يجب أن يتكون حقل :attribute من :digits رقمًا.',
    'digits_between' => 'يجب أن يتكون حقل :attribute من عدد أرقام بين :min و :max.',
    'dimensions' => 'أبعاد صورة حقل :attribute غير صالحة.',
    'distinct' => 'يحتوي حقل :attribute على قيمة مكررة.',
    'doesnt_contain' => 'يجب ألا يحتوي حقل :attribute على أيٍّ مما يلي: :values.',
    'doesnt_end_with' => 'يجب ألا ينتهي حقل :attribute بأيٍّ مما يلي: :values.',
    'doesnt_start_with' => 'يجب ألا يبدأ حقل :attribute بأيٍّ مما يلي: :values.',
    'email' => 'يجب أن يكون حقل :attribute بريدًا إلكترونيًا صحيحًا.',
    'encoding' => 'يجب أن يكون ترميز حقل :attribute بصيغة :encoding.',
    'ends_with' => 'يجب أن ينتهي حقل :attribute بأحد القيم التالية: :values.',
    'enum' => 'القيمة المحددة في حقل :attribute غير صالحة.',
    'exists' => 'القيمة المحددة في حقل :attribute غير موجودة.',
    'extensions' => 'يجب أن يكون امتداد ملف :attribute أحد التالي: :values.',
    'file' => 'يجب أن يكون حقل :attribute ملفًا.',
    'filled' => 'يجب ألا يكون حقل :attribute فارغًا.',

    'gt' => [
        'array' => 'يجب أن يحتوي حقل :attribute على أكثر من :value عنصرًا.',
        'file' => 'يجب أن يكون حجم ملف :attribute أكبر من :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة حقل :attribute أكبر من :value.',
        'string' => 'يجب أن يكون عدد أحرف حقل :attribute أكثر من :value حرفًا.',
    ],

    'gte' => [
        'array' => 'يجب أن يحتوي حقل :attribute على :value عنصرًا أو أكثر.',
        'file' => 'يجب أن يكون حجم ملف :attribute أكبر من أو يساوي :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة حقل :attribute أكبر من أو تساوي :value.',
        'string' => 'يجب أن يكون عدد أحرف حقل :attribute :value حرفًا أو أكثر.',
    ],

    'hex_color' => 'يجب أن يكون حقل :attribute لونًا سداسيًا عشريًا صحيحًا.',
    'image' => 'يجب أن يكون حقل :attribute صورة.',
    'in' => 'القيمة المحددة في حقل :attribute غير صالحة.',
    'in_array' => 'يجب أن يكون حقل :attribute موجودًا ضمن :other.',
    'in_array_keys' => 'يجب أن يحتوي حقل :attribute على واحد على الأقل من المفاتيح التالية: :values.',
    'integer' => 'يجب أن يكون حقل :attribute رقمًا صحيحًا.',
    'ip' => 'يجب أن يكون حقل :attribute عنوان IP صحيحًا.',
    'ipv4' => 'يجب أن يكون حقل :attribute عنوان IPv4 صحيحًا.',
    'ipv6' => 'يجب أن يكون حقل :attribute عنوان IPv6 صحيحًا.',
    'json' => 'يجب أن يكون حقل :attribute نص JSON صحيحًا.',
    'list' => 'يجب أن يكون حقل :attribute قائمة.',
    'lowercase' => 'يجب أن يكون حقل :attribute بأحرف صغيرة.',

    'lt' => [
        'array' => 'يجب أن يحتوي حقل :attribute على أقل من :value عنصرًا.',
        'file' => 'يجب أن يكون حجم ملف :attribute أقل من :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة حقل :attribute أقل من :value.',
        'string' => 'يجب أن يكون عدد أحرف حقل :attribute أقل من :value حرفًا.',
    ],

    'lte' => [
        'array' => 'يجب ألا يحتوي حقل :attribute على أكثر من :value عنصرًا.',
        'file' => 'يجب أن يكون حجم ملف :attribute أقل من أو يساوي :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة حقل :attribute أقل من أو تساوي :value.',
        'string' => 'يجب أن يكون عدد أحرف حقل :attribute :value حرفًا أو أقل.',
    ],

    'mac_address' => 'يجب أن يكون حقل :attribute عنوان MAC صحيحًا.',

    'max' => [
        'array' => 'يجب ألا يحتوي حقل :attribute على أكثر من :max عنصرًا.',
        'file' => 'يجب ألا يتجاوز حجم ملف :attribute :max كيلوبايت.',
        'numeric' => 'يجب ألا تتجاوز قيمة حقل :attribute :max.',
        'string' => 'يجب ألا يتجاوز عدد أحرف حقل :attribute :max حرفًا.',
    ],

    'max_digits' => 'يجب ألا يحتوي حقل :attribute على أكثر من :max رقمًا.',
    'mimes' => 'يجب أن يكون ملف :attribute من نوع: :values.',
    'mimetypes' => 'يجب أن يكون ملف :attribute من نوع: :values.',

    'min' => [
        'array' => 'يجب أن يحتوي حقل :attribute على :min عنصرًا على الأقل.',
        'file' => 'يجب أن يكون حجم ملف :attribute :min كيلوبايت على الأقل.',
        'numeric' => 'يجب أن تكون قيمة حقل :attribute :min على الأقل.',
        'string' => 'يجب أن يحتوي حقل :attribute على :min حرفًا على الأقل.',
    ],

    'min_digits' => 'يجب أن يحتوي حقل :attribute على :min رقمًا على الأقل.',
    'missing' => 'يجب أن يكون حقل :attribute غير موجود.',
    'missing_if' => 'يجب أن يكون حقل :attribute غير موجود عندما يكون :other هو :value.',
    'missing_unless' => 'يجب أن يكون حقل :attribute غير موجود ما لم يكن :other هو :value.',
    'missing_with' => 'يجب أن يكون حقل :attribute غير موجود عند وجود :values.',
    'missing_with_all' => 'يجب أن يكون حقل :attribute غير موجود عند وجود :values.',
    'multiple_of' => 'يجب أن تكون قيمة حقل :attribute من مضاعفات :value.',
    'not_in' => 'القيمة المحددة في حقل :attribute غير صالحة.',
    'not_regex' => 'صيغة حقل :attribute غير صالحة.',
    'numeric' => 'يجب أن يكون حقل :attribute رقمًا.',

    'password' => [
        'letters' => 'يجب أن يحتوي حقل :attribute على حرف واحد على الأقل.',
        'mixed' => 'يجب أن يحتوي حقل :attribute على حرف كبير وحرف صغير على الأقل.',
        'numbers' => 'يجب أن يحتوي حقل :attribute على رقم واحد على الأقل.',
        'symbols' => 'يجب أن يحتوي حقل :attribute على رمز واحد على الأقل.',
        'uncompromised' => 'ظهر :attribute المُدخل في تسريب بيانات. الرجاء اختيار :attribute مختلف.',
    ],

    'present' => 'يجب أن يكون حقل :attribute موجودًا.',
    'present_if' => 'يجب أن يكون حقل :attribute موجودًا عندما يكون :other هو :value.',
    'present_unless' => 'يجب أن يكون حقل :attribute موجودًا ما لم يكن :other هو :value.',
    'present_with' => 'يجب أن يكون حقل :attribute موجودًا عند وجود :values.',
    'present_with_all' => 'يجب أن يكون حقل :attribute موجودًا عند وجود :values.',
    'prohibited' => 'حقل :attribute محظور.',
    'prohibited_if' => 'حقل :attribute محظور عندما يكون :other هو :value.',
    'prohibited_if_accepted' => 'حقل :attribute محظور عند قبول :other.',
    'prohibited_if_declined' => 'حقل :attribute محظور عند رفض :other.',
    'prohibited_unless' => 'حقل :attribute محظور ما لم يكن :other ضمن :values.',
    'prohibits' => 'حقل :attribute يمنع وجود :other.',
    'regex' => 'صيغة حقل :attribute غير صالحة.',
    'required' => 'حقل :attribute مطلوب.',
    'required_array_keys' => 'يجب أن يحتوي حقل :attribute على مدخلات لـ: :values.',
    'required_if' => 'حقل :attribute مطلوب عندما يكون :other هو :value.',
    'required_if_accepted' => 'حقل :attribute مطلوب عند قبول :other.',
    'required_if_declined' => 'حقل :attribute مطلوب عند رفض :other.',
    'required_unless' => 'حقل :attribute مطلوب ما لم يكن :other ضمن :values.',
    'required_with' => 'حقل :attribute مطلوب عند وجود :values.',
    'required_with_all' => 'حقل :attribute مطلوب عند وجود :values.',
    'required_without' => 'حقل :attribute مطلوب عند عدم وجود :values.',
    'required_without_all' => 'حقل :attribute مطلوب عند عدم وجود أيٍّ من :values.',
    'same' => 'يجب أن يتطابق حقل :attribute مع :other.',

    'size' => [
        'array' => 'يجب أن يحتوي حقل :attribute على :size عنصرًا.',
        'file' => 'يجب أن يكون حجم ملف :attribute :size كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة حقل :attribute :size.',
        'string' => 'يجب أن يحتوي حقل :attribute على :size حرفًا.',
    ],

    'starts_with' => 'يجب أن يبدأ حقل :attribute بأحد القيم التالية: :values.',
    'string' => 'يجب أن يكون حقل :attribute نصًا.',
    'timezone' => 'يجب أن يكون حقل :attribute منطقة زمنية صحيحة.',
    'unique' => 'قيمة حقل :attribute مستخدمة من قبل.',
    'uploaded' => 'فشل رفع حقل :attribute.',
    'uppercase' => 'يجب أن يكون حقل :attribute بأحرف كبيرة.',
    'url' => 'يجب أن يكون حقل :attribute رابطًا صحيحًا.',
    'ulid' => 'يجب أن يكون حقل :attribute معرّف ULID صحيحًا.',
    'uuid' => 'يجب أن يكون حقل :attribute معرّف UUID صحيحًا.',

    /*
    |--------------------------------------------------------------------------
    | رسائل تحقق مخصصة
    |--------------------------------------------------------------------------
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'رسالة مخصصة',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | أسماء الحقول بالعربية
    |--------------------------------------------------------------------------
    |
    | تُستخدم لاستبدال :attribute باسم عربي مفهوم بدلًا من اسم الحقل البرمجي.
    |
    */

    'attributes' => [

        // عام
        'name' => 'الاسم',
        'email' => 'البريد الإلكتروني',
        'phone' => 'الهاتف',
        'password' => 'كلمة المرور',
        'password_confirmation' => 'تأكيد كلمة المرور',
        'current_password' => 'كلمة المرور الحالية',
        'address' => 'العنوان',
        'city' => 'المدينة',
        'country' => 'الدولة',
        'note' => 'الملاحظة',
        'notes' => 'الملاحظات',
        'desc' => 'الوصف',
        'description' => 'الوصف',
        'status' => 'الحالة',
        'type' => 'النوع',
        'kind' => 'النوع',
        'code' => 'الرمز',
        'ref' => 'المرجع',
        'value' => 'القيمة',
        'color' => 'اللون',
        'icon' => 'الأيقونة',
        'locale' => 'اللغة',
        'format' => 'الصيغة',
        'role' => 'الصلاحية',
        'owner' => 'المالك',
        'owner_name' => 'اسم المالك',
        'contact_person' => 'مسؤول التواصل',
        'job_title' => 'المسمى الوظيفي',
        'avatar' => 'الصورة الشخصية',
        'image' => 'الصورة',
        'logo' => 'الشعار',
        'file' => 'الملف',
        'attachment' => 'المرفق',
        'receipt' => 'الإيصال',
        'backup' => 'ملف النسخة الاحتياطية',
        'statement' => 'كشف الحساب',
        'columns' => 'الأعمدة',
        'groups' => 'المجموعات',
        'features' => 'المميزات',
        'items' => 'العناصر',

        // التواريخ
        'date' => 'التاريخ',
        'start' => 'البداية',
        'end' => 'النهاية',
        'starts_at' => 'تاريخ البداية',
        'ends_at' => 'تاريخ النهاية',
        'expires_at' => 'تاريخ الانتهاء',
        'occurred_at' => 'تاريخ الحدوث',
        'spent_at' => 'تاريخ الصرف',
        'opening_date' => 'تاريخ الافتتاح',

        // الفروع والشركات
        'branch' => 'الفرع',
        'branch_id' => 'الفرع',
        'branches' => 'الفروع',
        'business_id' => 'الشركة',
        'customer' => 'العميل',
        'supplier_id' => 'المورّد',
        'plan' => 'الباقة',
        'plan_id' => 'الباقة',
        'category_id' => 'التصنيف',
        'product_id' => 'المنتج',
        'resume_id' => 'السيرة الذاتية',

        // المنتجات والمخزون
        'sku' => 'رمز المنتج',
        'barcode' => 'الباركود',
        'price' => 'السعر',
        'cost' => 'التكلفة',
        'quantity' => 'الكمية',
        'qty' => 'الكمية',
        'alert_qty' => 'حد التنبيه',
        'tax' => 'الضريبة',
        'discount' => 'الخصم',
        'total' => 'الإجمالي',
        'amount' => 'المبلغ',
        'points' => 'النقاط',
        'delivery_fee' => 'رسوم التوصيل',
        'min_order' => 'الحد الأدنى للطلب',
        'max_uses' => 'الحد الأقصى للاستخدام',
        'payment_method' => 'طريقة الدفع',
        'method' => 'الطريقة',

        // الأهداف والعمولات
        'monthly_target' => 'الهدف الشهري',
        'monthly_price' => 'السعر الشهري',
        'yearly_price' => 'السعر السنوي',
        'commission_rate' => 'نسبة العمولة',
        'is_popular' => 'الأكثر شيوعًا',

        // البنوك
        'bank_name' => 'اسم البنك',
        'account_name' => 'اسم الحساب',
        'iban' => 'رقم الآيبان',
        'opening_balance' => 'الرصيد الافتتاحي',

        // عناصر متعددة
        'items.*.name' => 'اسم العنصر',
        'items.*.qty' => 'كمية العنصر',
        'items.*.quantity' => 'كمية العنصر',
        'items.*.price' => 'سعر العنصر',
        'items.*.cost' => 'تكلفة العنصر',
        'items.*.product_id' => 'المنتج',
        'items.*.id' => 'المعرّف',
    ],

];
