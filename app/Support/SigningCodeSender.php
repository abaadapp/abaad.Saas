<?php

namespace App\Support;

use App\Models\PlatformDocument;

/**
 * من يوصل رمزَ التحقّق إلى هاتف العميل.
 *
 * لا مزوّدَ مربوطًا في التطبيق اليوم: لا SMS في المشروع، وواتساب المنصّة بلا
 * قالبِ تحقّقٍ معتمَد. فما لم يُربط مزوّدٌ في الحاوية (`SigningCodes::available`)
 * بقي التوقيعُ مغلقًا — لا يُرسل رمز، ولا يُقبل توقيع.
 *
 * والمزوّدُ لا يكتب الرمزَ في سجلٍّ ولا رسالةِ خطأ.
 */
interface SigningCodeSender
{
    /**
     * يرسل الرمز إلى الرقم الدوليّ (أرقامٌ بلا +) — ويرمي إن لم يصل.
     */
    public function send(string $phone, string $code, PlatformDocument $document): void;
}
