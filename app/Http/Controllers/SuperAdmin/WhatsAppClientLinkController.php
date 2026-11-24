<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Support\WhatsAppClientLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** ‏‏«أرسل رابطًا لعميلك» من صفحة المتجر عند فريق أبعاد — انظر `WhatsAppClientLinks` */
class WhatsAppClientLinkController extends Controller
{
    public function store(Request $request, int $id): RedirectResponse
    {
        $business = Business::findOrFail($id);
        $result = WhatsAppClientLinks::create($business, $request->user());

        if (! $result['ok']) {
            return back()->withErrors(['client_link' => $result['message']]);
        }

        return back()->with('whatsappClientLink', [
            'url' => $result['url'],
            'message' => WhatsAppClientLinks::message($result['link'], $result['url']),
        ])->with('toast', ['msg' => __('أُنشئ الرابط — انسخه الآن، فلن يظهر مرّة أخرى.'), 'type' => 'success']);
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $revoked = WhatsAppClientLinks::revoke(Business::findOrFail($id), $request->user());

        return back()->with('toast', [
            'msg' => $revoked ? __('أُلغي الرابط — لم يعد يعمل') : __('لا رابط مفتوح لهذا المتجر'),
            'type' => $revoked ? 'success' : 'info',
        ]);
    }
}
