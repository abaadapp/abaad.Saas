<?php

/*
 * إعداد تكامل واتساب — لا سرَّ في هذا الملفّ.
 *
 * القيم كلّها من البيئة: المفتاح السرّي في ملفّ الخادم لا في المستودع ولا
 * في قاعدة البيانات. وقاعدة البيانات مقصودةٌ بالذكر — ما يُخزَّن فيها يخرج
 * مع كلّ نسخةٍ احتياطية، ونسخُ قاعدة البيانات تُنقل وتُشارك.
 *
 * ونسخة الواجهة إعدادٌ لا ثابتٌ في الكود: ميتا تُصدر نسخةً كلّ بضعة أشهر
 * وتُوقف القديمة، فترقيتُها يجب أن تكون سطرًا في البيئة لا نشرةَ كودٍ كاملة.
 */
return [
    /*
     * نسخة الواجهة.
     *
     * و`META_GRAPH_API_VERSION` اسمٌ ثانٍ يُقبل ولا يُفرض: ملفُّ الإنتاج يحمل
     * الاسم الأوّل ويعمل به، وتبديلُ اسمٍ عاملٍ يقطع واتساب في لحظة النشر.
     * فالأوّل أوّلًا، والثاني احتياطًا لمن كتبه.
     */
    'api_version' => env('META_WHATSAPP_API_VERSION') ?: env('META_GRAPH_API_VERSION', 'v26.0'),

    'graph_url' => env('META_GRAPH_URL', 'https://graph.facebook.com'),

    'app_id' => env('META_WHATSAPP_APP_ID') ?: env('META_APP_ID'),

    'app_secret' => env('META_WHATSAPP_APP_SECRET') ?: env('META_APP_SECRET'),

    /** معرّف إعداد التسجيل المدمج (Embedded Signup) لربط أرقام المحلّات */
    'config_id' => env('META_WHATSAPP_CONFIG_ID') ?: env('META_WHATSAPP_EMBEDDED_CONFIG_ID'),

    /** الكلمة التي تردّها ميتا عند تسجيل الإشعارات أوّل مرّة */
    'verify_token' => env('META_WHATSAPP_VERIFY_TOKEN') ?: env('META_WHATSAPP_WEBHOOK_VERIFY_TOKEN'),

    /*
     * ═══ التسجيل المدمج: اسمُ الميزة ونسخةُ الجلسة ═══
     *
     * `whatsapp_business_app_onboarding` هو الاسم الرسميّ اليوم لمسارِ من
     * يملك رقمه في **تطبيق** واتساب للأعمال ويريد أن يبقى فيه وأن يصل
     * النظامَ معًا (ما كان يُسمّى Coexistence في قنوات الدعم).
     *
     * ومكتوبٌ هنا لا في ملفّ الشاشة: الشاشةُ تعرضه والخادمُ يقرأ ما يعود
     * منه — ولو كُتب في الاثنين لَافترقا يوم تُبدّله ميتا.
     *
     * ونسخةُ الجلسة `3` شرطُ أن تصل أحداثُ هذا المسار أصلًا.
     */
    'feature_type' => 'whatsapp_business_app_onboarding',

    'session_info_version' => '3',

    /*
     * ═══ نسخةُ التسجيل المدمج — لا تُفترض ═══
     *
     * وثيقةُ النسخ عند ميتا (2026-10-28): v2 وv3 أُوقفتا في 2026-10-15،
     * و«طلبٌ بلا `version` أو بنسخةٍ غير صالحة يُخدَم بـv4». فما يجري اليوم
     * v4 وإن لم يُكتب شيء. ويُكتب هنا (`v5` مثلًا) بعد فحص الإعداد في
     * «Embedded Signup Builder › Check a launch config» — لا قبله.
     */
    'es_version' => env('META_WHATSAPP_ES_VERSION') ?: null,

    /*
     * ═══ التعايش مع تطبيق واتساب للأعمال — مطفأٌ ما لم يُشعَل ═══
     *
     * لا يُختار إلّا صراحةً من التاجر، ولا يُعرض اختيارُه إلّا إن أُشعل هنا.
     * وشرطُ إشعاله عند ميتا: اشتراكُ التطبيق في حقول `smb_message_echoes` و
     * `history` و`smb_app_state_sync` و`account_update`، ومزامنةُ البيانات
     * خلال ٢٤ ساعة (`/<PHONE_NUMBER_ID>/smb_app_data`) — ولا شيءَ منها مبنيٌّ
     * بعد. انظر `WhatsAppOnboarding::coexistenceOpen`.
     */
    'coexistence_enabled' => (bool) env('META_WHATSAPP_COEXISTENCE', false),

    /*
     * مهلة النداء.
     *
     * البيع لا ينتظرها — الإرسال في طابور. لكنّ عاملًا معلّقًا دقيقتين على
     * نداءٍ لا يردّ يعني طابورًا يتكدّس خلفه.
     */
    'timeout' => (int) env('META_WHATSAPP_TIMEOUT', 15),

    /** اسم الطابور — يُفصل عن غيره ليُراقَب ويُوقَف وحده عند الحاجة */
    'queue' => env('META_WHATSAPP_QUEUE', 'whatsapp'),

    /** لغة القوالب الافتراضية */
    'language' => env('META_WHATSAPP_LANGUAGE', 'ar'),

    /** مفتاح الدولة الافتراضي لأرقامٍ كُتبت محليًّا — عُمان */
    'default_country_code' => env('META_WHATSAPP_COUNTRY_CODE', '968'),

    /*
     * من تُفتح لهم «محادثات واتساب» عند الترحيل — مرّةً واحدة.
     *
     * قرارُ المالك: لمتجر سعود (RIBBON، `businesses.id = 5`) وحده في المرحلة
     * الأولى. والقائمةُ تُقرأ في ترحيل `a_shop_may_read_its_whatsapp_as_conversations`
     * وحده لتكتب `businesses.whatsapp_conversations_enabled`. ولم يعد العمودُ
     * يُقرأ: تلك الشاشةُ صارت صندوقَ المحادثات لكلّ متجرٍ يملك واتساب
     * (`WhatsAppInbox::available`). والقائمةُ باقيةٌ لأنّ الترحيلَ القديمَ يقرؤها.
     */
    /*
     * RIBBON (متجر سعود) له دورة إشعاراته المتفق عليها وحدها:
     * استلام الطلب، تأكيده، جاهزيته، وخروجه للتوصيل.
     *
     * المعرّف في الإعداد لا في منطق الإرسال حتى لا يتسرّب تخصيص متجرٍ
     * بعينه إلى بقيّة المتاجر إذا تغيّر صفّه يومًا.
     */
    'ribbon_businesses' => [5],

    'conversations_businesses' => [5],

    /*
     * من يُفتح له «صندوق محادثات واتساب» عند الترحيل — مرّةً واحدة.
     *
     * قرارُ المالك: متجر سعود (RIBBON، `businesses.id = 5`) أوّلُ من يُجرَّب
     * عليه. والقائمةُ تُقرأ في ترحيل `a_shop_reads_and_answers_its_customers_on_whatsapp`
     * وحده لتكتب `businesses.whatsapp_inbox_enabled`. ولم يعد العمودُ يُقرأ:
     * الصندوقُ لكلّ متجرٍ تشمل باقتُه واتساب ولم يُطفأ لحسابه
     * (`WhatsAppInbox::available`) — لا قائمةَ ولا معرّف. والقائمةُ باقيةٌ لأنّ
     * الترحيلَ القديمَ يقرؤها.
     */
    'inbox_businesses' => [5],
];
