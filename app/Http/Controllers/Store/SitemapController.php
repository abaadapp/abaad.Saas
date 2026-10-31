<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Support\Storefront;
use App\Support\Website\Domains;
use App\Support\Website\Sitemap;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * خريطةُ موقع التاجر على طرقه الثلاث — `‎/s/متجري/sitemap.xml‎` ونطاقُه
 * الفرعيّ ونطاقُه الخاصّ. وما فيها في `Website\Sitemap`.
 *
 * وحارسُها حارسُ صفحاته نفسُه: متجرٌ لا يُخدَم (باقةٌ لا تبيع الموقع، أو
 * اشتراكٌ انتهى، أو لم يُنشر) لا خريطةَ له — `Storefront::serves`.
 *
 * وخريطةٌ تزيد على ملفٍّ تُقسم (`Sitemap::PER_FILE`): `sitemap.xml` فهرسُها،
 * و`sitemap-1.xml` وما بعده أجزاؤها — على الطرق الثلاث.
 */
class SitemapController extends Controller
{
    public function show(string $slug): Response
    {
        return $this->respond($this->open($slug), null);
    }

    /** ملفٌّ من خريطةٍ قُسمت — `sitemap-2.xml` */
    public function part(string $slug, string $part): Response
    {
        return $this->respond($this->open($slug), (int) $part);
    }

    public function byHost(string $host): Response
    {
        return $this->respond($this->resolve($host), null, customHost: true);
    }

    public function byHostPart(string $host, string $part): Response
    {
        return $this->respond($this->resolve($host), (int) $part, customHost: true);
    }

    /**
     * خريطةُ المنصّة — فهرسٌ بملفّات كلِّ متجرٍ مؤهَّلٍ أصلُه على هذا المضيف.
     *
     * يدلّ عليها `public/robots.txt`، فمتجرٌ يُنشر غدًا يعرفه غوغل بلا إرسال.
     * وما فيها في `Sitemap::platform`.
     */
    public function platform(Request $request): Response
    {
        return $this->xml(Sitemap::index(Sitemap::platform($request->getHost())));
    }

    private function open(string $slug): ?Business
    {
        $clean = Storefront::slug($slug);

        return $clean ? Storefront::open($clean) : null;
    }

    private function resolve(string $host): ?Business
    {
        abort_if(! config('storefront.custom_domains'), 404);

        $id = Domains::resolve($host);

        return $id === null ? null : Business::find($id);
    }

    /**
     * `sitemap.xml` بلا رقم: الخريطةُ نفسُها إن كانت ملفًّا، وإلّا فهرسُ أجزائها.
     */
    private function respond(?Business $business, ?int $part, bool $customHost = false): Response
    {
        $parts = $business === null ? null : Sitemap::parts($business, $customHost);

        abort_if($parts === null, 404);

        if ($part === null && $parts > 1) {
            return $this->xml(Sitemap::index(Sitemap::files($business) ?? []));
        }

        // ورقمٌ بعد آخر جزءٍ لا ملفَّ له — يقوله `Sitemap::urls`
        $urls = Sitemap::urls($business, $customHost, $part ?? 1);

        abort_if($urls === null, 404);

        return $this->xml(Sitemap::xml($urls));
    }

    private function xml(string $body): Response
    {
        return response($body, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            // ولا تُخزَّن في وسيط: المسارُ `‎/sitemap.xml‎` واحدٌ لمضيفاتٍ كثيرة — انظر `StorefrontController::show`
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
