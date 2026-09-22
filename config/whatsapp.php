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
];
