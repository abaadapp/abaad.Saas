<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
     * ملفُّ Google Business Profile — إدارةُ تقييمات المتجر والردُّ عليها.
     *
     * ═══ ولمَ في البيئة لا في القاعدة ═══
     *
     * سرُّ العميل يفتح بابَ الإذن لكلّ متاجر المنصّة. وما يُخزَّن في القاعدة
     * يخرج مع كلّ نسخةٍ احتياطية، ونسخُ القاعدة تُنقل وتُشارك.
     *
     * ═══ وهذا **غيرُ** مفتاح Places ═══
     *
     * ذاك يقرأ الملفَّ العامّ لأيّ مكان. وهذا يفتح ملفَّ المتجر نفسِه: يقرأ
     * تقييماته كلَّها ويردّ عليها باسمه. فيحتاج إذنَ صاحبه (OAuth) ووصولًا
     * مُعتمَدًا من Google إلى «Business Profile APIs» — وهو طلبٌ يُقدَّم
     * ويُراجَع، لا واجهةٌ تُفعَّل بضغطة.
     *
     * وغيابُ أيٍّ من الثلاثة يعني أنّ الميزة **غيرُ موصولة** ولا تُعرض
     * كأنّها تعمل — انظر `GoogleBusiness::configured`.
     */
    'google_business' => [
        'client_id' => env('GOOGLE_BUSINESS_CLIENT_ID'),
        'client_secret' => env('GOOGLE_BUSINESS_CLIENT_SECRET'),

        /*
         * عنوانُ العودة — يجب أن يُطابق المسجَّل في Google Cloud حرفًا بحرف.
         *
         * ويُقرأ من البيئة لا يُبنى بـ`route()`: خادمُ التطوير وخادمُ الإنتاج
         * عنوانان، و`APP_URL` قد تختلف عمّا سُجّل عندهم فيُردّ الإذن بلا خبر.
         */
        'redirect' => env('GOOGLE_BUSINESS_REDIRECT'),
    ],

    /*
     * عنوانُ خادمنا كما تراه الخدماتُ الخارجيّة.
     *
     * يُعرض للتاجر في دليل المفتاح: Google تسمح بتقييد المفتاح بعناوينَ
     * بعينها، فلا يعمل عند غيرها ولو سُرِّب. ونحن نطلب منه ذلك — فيجب أن
     * نُمكّنه منه.
     *
     * ويُقرأ من البيئة لا يُكتب في الشاشة: الخادمُ يُبدَّل يومًا، ورقمٌ
     * محفورٌ في واجهةٍ يبقى يُعرض بعد أن يصير خطأً — فيقيّد التاجر مفتاحه
     * بعنوانٍ لا نناديه منه، ويرى «رفضت Google المفتاح» بلا سببٍ مفهوم.
     *
     * وغيابُه لا يُخمَّن: تقول الشاشة «اطلبه من الدعم» ولا تعرض عنوانًا.
     */
    'outbound_ip' => env('OUTBOUND_IP'),

    /*
     * تسوياتُ Paymob — إيقافٌ مؤقّتٌ لتسجيلها في كلّ المتاجر (`PaymobSettlements::paused`).
     * للنشر المتدرّج والطوارئ: لا يُسجَّل تحويلٌ ولا يُعاد فحصُه ولا يُحدَّد ما حمله
     * تحويلٌ قديم حتّى يُفتح. ولا يمسّ الدفعَ نفسَه ولا إشعاراتِ Paymob.
     */
    'paymob' => [
        'settlements_paused' => (bool) env('PAYMOB_SETTLEMENTS_PAUSED', false),
    ],

    /*
     * ‏‏إنستقرام بتسجيل الدخول عبر Instagram — عناوينُ Meta وإصدارُها.
     *
     * ومعرّفُ تطبيق إنستقرام وسرُّه يُكتبان من إعدادات المنصّة (`InstagramPlatform`)، وهذان للخادم
     * احتياطًا لا غير: صفُّ القاعدة يُقرأ في كلّ طلب، و`.env` لا يُقرأ بعد النشر بلا إعادة تشغيل.
     */
    'instagram' => [
        'app_id' => env('INSTAGRAM_APP_ID'),
        'app_secret' => env('INSTAGRAM_APP_SECRET'),
        'api_version' => env('INSTAGRAM_API_VERSION', 'v27.0'),
        'authorize_url' => 'https://www.instagram.com/oauth/authorize',
        'token_url' => 'https://api.instagram.com/oauth/access_token',
        'graph_url' => env('INSTAGRAM_GRAPH_URL', 'https://graph.instagram.com'),
        'timeout' => 15,
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
