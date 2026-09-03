<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ترويسات الحماية — لم تكن الردود تحمل واحدةً منها.
 *
 * والفحص أظهرها فارغة: لا HSTS، ولا منعَ تأطير، ولا منعَ تخمين نوع
 * المحتوى، ولا سياسةَ مُحيل. وهي أسطرٌ يقولها الخادم مرّةً فتُغلق أبوابًا
 * لا يُغلقها الكود:
 *
 *   - **HSTS**: من كتب `app.abaadapp.om` بلا https يذهب طلبُه الأوّل عاريًا
 *     عبر الشبكة، وفيه كعكةُ الجلسة إن كانت. ومع الترويسة لا يخرج الطلب
 *     أصلًا — المتصفّح يُحوّله بنفسه قبل أن يُرسل.
 *   - **X-Frame-Options**: يمنع وضع الشاشة في إطارٍ في موقعٍ آخر، وهو
 *     أصل «سرقة النقرة»: صفحةٌ شفّافة فوق شاشة التاجر تجعله يضغط «حذف»
 *     وهو يظنّ أنّه يضغط زرًّا آخر.
 *   - **nosniff**: يمنع المتصفّح من تخمين نوع الملفّ — فملفٌّ رفعه أحدهم
 *     صورةً لا يُنفَّذ نصًّا برمجيًّا لأنّ محتواه يشبهه.
 *   - **Referrer-Policy**: لا يُسرَّب مسارُ صفحةٍ داخليّة (وفيها أرقام
 *     فواتير ومعرّفات) إلى موقعٍ خارجيّ يُنقَر إليه.
 *
 * ولا `Content-Security-Policy` هنا: الواجهة تحمّل حزمها من Vite، وسياسةٌ
 * تُكتب على عجل تكسر الشاشة كلَّها بلا رسالة. تُبنى وحدها حين تُبنى.
 *
 * و`HSTS` على HTTPS وحده: قولُها على اتصالٍ غير آمن يتجاهله المتصفّح،
 * وفي التطوير المحلّي تحبس المطوّر على https لا خادم له.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
