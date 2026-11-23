<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Support\WhatsAppMerchantLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ‏‏كيف يربط التجّارُ واتسابهم، ورقمُ إعداد الربط المدمج — من شاشة إعدادات المنصّة (`WhatsAppMerchantLink`).
 *
 * المسارُ تحت `role:super_admin` ومجموعةِ `web` (رمزُ CSRF)، ولا يُكتب المفتاحان من نموذج
 * الإعدادات العامّ (`SettingController::KEYS`): لهما بابٌ يقيّد من بدّل وكان ماذا وصار ماذا.
 */
class WhatsAppMerchantLinkController extends Controller
{
    public function mode(Request $request): RedirectResponse
    {
        $data = $request->validate(['mode' => ['required', 'string', Rule::in(WhatsAppMerchantLink::MODES)]], [], ['mode' => __('طريقة الربط')]);
        $r = WhatsAppMerchantLink::set($data['mode']);

        if (! $r['ok']) {
            return back()->withErrors(['mode' => $r['message']]);
        }

        $msg = ! $r['changed']
            ? __('طريقة ربط واتساب كما هي — لم يتغيّر شيء.')
            : __('صار التجّار الجدد يربطون واتساب عبر: :way. الوصلات القائمة لا تتغيّر.', ['way' => __(WhatsAppMerchantLink::label($r['now']))]);

        return back()->with('toast', ['msg' => $msg, 'type' => $r['changed'] ? 'success' : 'info']);
    }

    public function coexistence(Request $request): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $r = WhatsAppMerchantLink::setCoexistence((bool) $data['enabled']);

        if (! $r['ok']) {
            return back()->withErrors(['enabled' => $r['message']]);
        }

        $msg = ! $r['changed']
            ? __('خيار البقاء في تطبيق واتساب للأعمال كما هو — لم يتغيّر شيء.')
            : ((bool) $data['enabled']
                ? __('فُتح للتجّار خيار البقاء في تطبيق واتساب للأعمال.')
                : __('أُقفل على التجّار خيار البقاء في تطبيق واتساب للأعمال. الأرقام المربوطة به لا تتغيّر.'));

        return back()->with('toast', ['msg' => $msg, 'type' => $r['changed'] ? 'success' : 'info']);
    }

    public function configId(Request $request): RedirectResponse
    {
        $data = $request->validate(['config_id' => ['nullable', 'string', 'regex:'.WhatsAppMerchantLink::CONFIG_ID_PATTERN]]);
        $r = WhatsAppMerchantLink::setConfigId($data['config_id'] ?? null);

        if (! $r['ok']) {
            return back()->withErrors(['config_id' => $r['message']]);
        }

        return back()->with('toast', [
            'msg' => $r['changed'] ? __('حُفظ رقم إعداد الربط المدمج.') : __('رقم إعداد الربط المدمج كما هو — لم يتغيّر شيء.'),
            'type' => $r['changed'] ? 'success' : 'info',
        ]);
    }
}
