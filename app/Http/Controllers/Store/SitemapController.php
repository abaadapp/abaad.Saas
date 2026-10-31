<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Support\Storefront;
use App\Support\Website\Domains;
use App\Support\Website\Sitemap;
use Illuminate\Http\Response;

/**
 * خريطةُ موقع التاجر على طرقه الثلاث — `‎/s/متجري/sitemap.xml‎` ونطاقُه
 * الفرعيّ ونطاقُه الخاصّ. وما فيها في `Website\Sitemap`.
 *
 * وحارسُها حارسُ صفحاته نفسُه: متجرٌ لا يُخدَم (باقةٌ لا تبيع الموقع، أو
 * اشتراكٌ انتهى، أو لم يُنشر) لا خريطةَ له — `Storefront::serves`.
 */
class SitemapController extends Controller
{
    public function show(string $slug): Response
    {
        $clean = Storefront::slug($slug);

        return $this->respond($clean ? Storefront::open($clean) : null);
    }

    public function byHost(string $host): Response
    {
        abort_if(! config('storefront.custom_domains'), 404);

        $id = Domains::resolve($host);

        return $this->respond($id === null ? null : Business::find($id), customHost: true);
    }

    private function respond(?Business $business, bool $customHost = false): Response
    {
        $urls = $business === null ? null : Sitemap::urls($business, $customHost);

        abort_if($urls === null, 404);

        return response(Sitemap::xml($urls), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            // ولا تُخزَّن في وسيط: المسارُ `‎/sitemap.xml‎` واحدٌ لمضيفاتٍ كثيرة — انظر `StorefrontController::show`
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
