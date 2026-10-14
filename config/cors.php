<?php

/*
 * CORS — بابٌ واحدٌ عابرٌ للأصل: نموذجُ «تواصل معنا» في الموقع التعريفيّ.
 *
 * الموقعُ على https://abaadapp.om والنظامُ على app.abaadapp.om. ولا مسارَ آخرُ
 * يُفتح لأصلٍ غريب: لا `*`، ولا كعكات (`supports_credentials` مطفأة)، ولا
 * www — nginx يحوّلها إلى abaadapp.om قبل أن تُرسم الصفحة.
 *
 * والقائمةُ نفسُها يقرؤها `WebsiteContactController` فيرفض ما سواها بـ403:
 * CORS يمنع صفحةً غريبة من قراءة الردّ، لا من الإرسال.
 */
return [

    'paths' => ['website/contact'],

    'allowed_methods' => ['POST'],

    'allowed_origins' => ['https://abaadapp.om'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Accept'],

    'exposed_headers' => ['Retry-After'],

    'max_age' => 3600,

    'supports_credentials' => false,

];
