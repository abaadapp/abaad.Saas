<?php

namespace App\Http\Middleware;

use App\Support\RibbonPortal\Portal;
use App\Support\Website\Domains;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * بابُ بوابة عملاء RIBBON — النطاقُ يقول لمن هي، والردُّ لا يُخزَّن ولا يُفهرَس.
 *
 * المتجرُ من النطاق المثبَّت له (`Domains::pinnedBusiness`) لا من رقمٍ في الطلب،
 * ويجب أن يكون هو المتجرَ الذي كُتبت له. وما سواه 404 كأن لا شيء هنا.
 *
 * والترويساتُ لكلّ ردّ: لا تخزينَ في متصفّحٍ ولا وسيط، ولا فهرسة، ولا نصَّ من غير
 * الصفحة — فلا تحليلاتِ متجرٍ ولا أداةَ غيرِه تقرأ ما فيها.
 */
class RibbonPortalGate
{
    public function handle(Request $request, Closure $next): Response
    {
        $business = Portal::businessId();
        $host = strtolower($request->getHost());

        abort_unless(
            $business !== null && $host === Portal::host()
                && Domains::pinnedBusiness(explode('.', $host)[0]) === $business
                && Portal::on($business),
            404,
        );

        $request->attributes->set('portal_business', $business);
        $request->attributes->set('portal_nonce', $nonce = Str::random(24));

        $response = $next($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
        if (! $response->headers->has('X-Portal-Public')) {
            $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
        }
        $response->headers->remove('X-Portal-Public');

        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'none'", "script-src 'nonce-{$nonce}'", "style-src 'self' 'unsafe-inline'", "font-src 'self'",
                "img-src 'self' data:", "connect-src 'self'", "frame-src 'self'", "form-action 'self'", "base-uri 'none'", "frame-ancestors 'self'",
            ]));
        }

        return $response;
    }
}
