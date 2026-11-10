<?php

namespace App\Http\Middleware;

use App\Models\Business;
use App\Support\Storefront;
use App\Support\Website\Domains;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * متجرٌ له نطاقُه الفرعيّ يُزار عليه — والبابُ البديل `/s/اسمه` يحوّل إليه.
 *
 * ═══ لمن ═══
 *
 * لمتجرٍ في `storefront.subdomain_businesses` بمعرّفه (`Domains::movedToSubdomain`).
 * والعلَمُ العامّ `storefront.subdomains` لا يحوّل أحدًا: يكتب `canonical` على
 * النطاق ويُبقي البابين يخدمان. ومن سواه يمرّ كما كان حرفًا.
 *
 * ═══ ما يُحوَّل ═══
 *
 * زيارةٌ بـGET أو HEAD لصفحةٍ من صفحات المتجر على `/s/اسمه` — على أيّ مضيف،
 * فـ`app.abaadapp.om/s/ribbon/shop` و`ribbon.abaadapp.om/s/ribbon/shop` كلاهما
 * إلى `https://ribbon.abaadapp.om/shop`. بمسارها ومعلماتها كما وصلت حرفًا:
 * رابطُ منتجٍ قديم يصل إلى المنتج نفسه، وعودةٌ من بوابة الدفع تحمل مرجعها
 * ومعلماتِ Paymob كما كتبتها.
 *
 * والتحويلُ مؤقّتٌ (302) حتى يثبت النقل، ثمّ دائمٌ (301) — يُقرأ من
 * `storefront.subdomain_redirect_status`، ولا يُقبل منه غيرُ هذين.
 *
 * والوجهةُ جذرُ النطاق لا بابٌ تحته، فلا يعود التحويلُ إلى نفسه.
 *
 * ═══ وما لا يُحوَّل ═══
 *
 * POST: إتمامُ طلبٍ أو سؤالُ سعرٍ أو بطاقةُ هدية أُرسلت من صفحةٍ فُتحت قبل
 * التحويل تُخدم حيث أُرسلت — والمتصفّح لا يعيد إرسال جسم الطلب بعد 301.
 * وما ليس من صفحات المتجر (اللوحة، والمعاينة، وإشعارات Paymob) لا يمرّ هنا أصلًا.
 */
class StoreLivesOnItsSubdomain
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return $next($request);
        }

        $slug = Storefront::slug((string) $request->route('slug'));
        $id = $slug === null ? null : Business::query()->where('site_slug', $slug)->value('id');

        if ($id === null || ! Domains::movedToSubdomain((int) $id)) {
            return $next($request);
        }

        $root = Domains::canonical((int) $id, $slug);

        // والوجهةُ عنوانٌ آمنٌ كامل — لا البابُ البديل نفسُه
        if ($root === null || ! str_starts_with($root, 'https://')) {
            return $next($request);
        }

        $target = rtrim($root, '/').$this->rest($request);
        $query = (string) $request->server->get('QUERY_STRING', '');

        return redirect()->away($query === '' ? $target : $target.'?'.$query, self::status());
    }

    /** 302 ما لم يُكتب 301 صراحةً — فقيمةٌ خاطئةٌ لا تصير تحويلًا دائمًا يحفظه المتصفّح */
    public static function status(): int
    {
        return (int) config('storefront.subdomain_redirect_status') === 301 ? 301 : 302;
    }

    /** المسارُ بعد `/s/اسمه` — والعربيُّ فيه مُرمَّزٌ كما يُرسَل في `Location` */
    private function rest(Request $request): string
    {
        return match ($request->route()?->getName()) {
            'store.show.sitemap' => '/sitemap.xml',
            'store.show.sitemap.part' => '/sitemap-'.$request->route('part').'.xml',
            'store.show.page' => '/'.implode('/', array_map('rawurlencode', explode('/', (string) $request->route('path')))),
            default => '/',
        };
    }
}
