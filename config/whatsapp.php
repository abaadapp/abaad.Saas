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
    'api_version' => env('META_WHATSAPP_API_VERSION', 'v26.0'),

    'graph_url' => env('META_GRAPH_URL', 'https://graph.facebook.com'),

    'app_id' => env('META_WHATSAPP_APP_ID'),

    'app_secret' => env('META_WHATSAPP_APP_SECRET'),

    /** معرّف إعداد التسجيل المدمج (Embedded Signup) لربط أرقام المحلّات */
    'config_id' => env('META_WHATSAPP_CONFIG_ID'),

    /** الكلمة التي تردّها ميتا عند تسجيل الإشعارات أوّل مرّة */
    'verify_token' => env('META_WHATSAPP_VERIFY_TOKEN'),

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
