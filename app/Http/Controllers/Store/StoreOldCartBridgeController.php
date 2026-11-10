<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Support\Website\Domains;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * جسرُ السلّة القديمة — يقرأ سلّةَ الزبون على عنوان النظام ويرسلها إلى نطاق متجره.
 *
 * ═══ لماذا ═══
 *
 * سلّةُ RIBBON في `localStorage`، والمتصفّحُ يحفظها لكلّ عنوانٍ وحده. فمن ملأها
 * على `app.abaadapp.om/s/ribbon` ثمّ نُقل المتجرُ إلى `ribbon.abaadapp.om` يجدها
 * فارغة — والنظامُ لا يراها ليحملها معه: هي في المتصفّح لا في القاعدة.
 *
 * ═══ كيف ═══
 *
 * يفتح النطاقُ هذه الصفحةَ في إطارٍ مخفيّ مرّةً (انظر `store/ribbon/layout`).
 * فتقرأ السلّةَ من عنوانها وترسلها بـ`postMessage` إلى **أصل النطاق وحده** —
 * لا `*`: لا يقرؤها غيرُه ولو أطّرها. والنطاقُ يقبلها من عنوان النظام وحده،
 * ويضعها في سلّته إن كانت فارغة؛ والأسعارُ يحسبها الخادمُ كعادته.
 *
 * ولا يُمسّ شيءٌ في القاعدة، ولا تُمحى السلّةُ القديمة: لو رجعنا عن النقل
 * وجدها صاحبُها حيث تركها.
 *
 * ═══ ومن يؤطّرها ═══
 *
 * `frame-ancestors` بنطاق المتجر وحده — فلا تُؤطَّر في موقعٍ آخر، ولا في نطاق
 * متجرٍ آخر. ولمتجرٍ نُقل إلى نطاقه وحده، وعلى عنوان النظام وحده؛ وما سوى ذلك 404.
 */
class StoreOldCartBridgeController extends Controller
{
    public function __invoke(Request $request, int $business): Response
    {
        $shop = Business::query()->select(['id', 'site_slug'])->find($business);
        $bridge = $shop === null ? null : Domains::oldCartBridge((int) $shop->id);

        abort_if($bridge === null || $request->getSchemeAndHttpHost() !== $bridge['from'], 404);

        return response()
            ->view('store.cart-bridge', ['key' => 'ribbon-cart:'.$shop->id, 'to' => $bridge['to']])
            ->header('Content-Security-Policy', "frame-ancestors {$bridge['to']}")
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
