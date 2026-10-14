<?php

return [

    /*
     * مستلمُ إشعار «تواصل معنا» من الموقع التعريفيّ abaadapp.om.
     *
     * هذا المستلمُ وحده — لا كلمةَ مرور: البريدُ يخرج عبر مُرسِل النظام
     * (MAIL_*) ومن `MAIL_FROM_ADDRESS`. والطلبُ نفسُه في CRM المنصّة قبل البريد؛
     * فراغُ هذه القيمة يُسكت الإشعار ولا يُضيع طلبًا. انظر CrmWebsiteInquiries.
     */
    'leads_to' => env('CONTACT_LEADS_TO', 'abaadappom@gmail.com'),

];
