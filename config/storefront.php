<?php

return [

    /*
     * النطاق الذي تُبنى عليه عناوين المتاجر: «متجري.abaadapp.om».
     *
     * ويلزمه على الخادم شيئان يُضبطان مرّةً: سجلّ DNS بالحرف البدل
     * (‏`*.abaadapp.om`‎) وشهادة SSL بالحرف البدل. وقبل ضبطهما يعمل العنوان
     * البديل `/s/{slug}` — انظر `Storefront::fallbackUrl`.
     */
    'domain' => env('STOREFRONT_DOMAIN', 'abaadapp.om'),

];
