<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Support\InvoiceCorrectionsSwitch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ‏‏مفتاحُ إيقاف تعديل فواتير العملاء وتصحيحها — من شاشة إعدادات المنصّة (`InvoiceCorrectionsSwitch`).
 *
 * المسارُ تحت `role:super_admin` ومجموعةِ `web` (رمزُ CSRF): لا يبلغه تاجرٌ ولا موظّف. وله
 * بابٌ واحد يقيّد من بدّله وكان ماذا وصار ماذا — لا حقلٌ في نموذج الإعدادات العامّ.
 */
class InvoiceCorrectionsSwitchController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $r = InvoiceCorrectionsSwitch::set((bool) $data['enabled']);

        $msg = ! $r['changed']
            ? __('تعديل فواتير العملاء كما هو — لم يتغيّر شيء.')
            : ($r['now']
                ? __('شُغّل تعديل فواتير العملاء وتصحيحها. يسري من الطلب التالي.')
                : __('أُوقف تعديل فواتير العملاء وتصحيحها. لا يصدر مستند تصحيح جديد، وما صدر يُقرأ كما هو.'));

        return back()->with('toast', ['msg' => $msg, 'type' => $r['changed'] ? 'success' : 'info']);
    }
}
