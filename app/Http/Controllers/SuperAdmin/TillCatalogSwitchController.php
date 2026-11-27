<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Support\TillCatalogSwitch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ‏‏مفتاحُ كتالوج الصندوق على الجهاز — من شاشة إعدادات المنصّة (`TillCatalogSwitch`).
 *
 * المسارُ تحت `role:super_admin` ومجموعةِ `web` (رمزُ CSRF)، فلا يبلغه تاجرٌ ولا موظّف
 * ولا طلبٌ من صفحةٍ أخرى. ولا يُكتب المفتاحُ من نموذج الإعدادات العامّ (`SettingController::KEYS`):
 * له بابٌ واحد يقيّد من بدّله وكان ماذا وصار ماذا.
 */
class TillCatalogSwitchController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $r = TillCatalogSwitch::set((bool) $data['enabled']);

        $msg = ! $r['changed']
            ? __('كتالوج الصندوق على الجهاز كما هو — لم يتغيّر شيء.')
            : ($r['now']
                ? __('شُغّل كتالوج الصندوق على الجهاز. يسري من سؤال الصندوق التالي.')
                : __('أُطفئ كتالوج الصندوق على الجهاز. الصناديق تحمّل الأصناف من الخادم من سؤالها التالي.'));

        return back()->with('toast', ['msg' => $msg, 'type' => $r['changed'] ? 'success' : 'info']);
    }
}
