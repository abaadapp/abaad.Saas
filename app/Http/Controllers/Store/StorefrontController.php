<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Support\Storefront;
use Illuminate\Http\Response;

/**
 * الصفحة التي يفتحها زبونُ التاجر.
 *
 * وهي **خارج كلّ حرّاس النظام**: لا جلسة، ولا تسجيل دخول، ولا حارس مستأجر —
 * لأنّ من يفتحها زبونٌ لا حساب له. فالمتجر يُعرف من عنوانه، والحارس الوحيد
 * هنا شرطان: أن يكون المتجر **نشطًا** وأن يكون صاحبُه قد **نشره**. وما لم
 * يُنشر فهو ٤٠٤ لا صفحةٌ فارغة: صفحةٌ فارغة تقول للزائر إنّ المحلّ مغلق،
 * و٤٠٤ تقول إنّه لا عنوان هنا — وهي الحقيقة.
 *
 * والرسم بقالب Blade لا بـInertia عن قصد: الزائر يفتحها من إعلانٍ أو رسالة،
 * وتحميلُ حزمة React كاملةً لعرض شبكة صور تأخيرٌ لا مقابل له — ومحرّكُ البحث
 * يقرأ HTML لا JavaScript.
 */
class StorefrontController extends Controller
{
    public function show(string $slug): Response
    {
        $clean = Storefront::slug($slug);
        $business = $clean ? Storefront::find($clean) : null;

        abort_if($business === null, 404);

        return response()
            ->view('store.show', Storefront::page($business))
            /*
             * ولا تُخزَّن في وسيطٍ مشترك.
             *
             * الصفحة عامّة، لكنّ محتواها يخصّ متجرًا بعينه — وخادمٌ وسيطٌ
             * يخزّنها بمفتاح المسار وحده قد يردّها لمتجرٍ آخر. والخصوصية
             * تُقال صراحةً لا تُترك للافتراض.
             */
            ->header('Cache-Control', 'public, max-age=120');
    }

    /**
     * المتجر كما يراه صاحبُه قبل أن يراه أحد.
     *
     * وكان يضبطه أعمى: يكتب عنوانه ويختار لونه ويُخفي أصنافًا، ولا يرى شيئًا
     * حتى ينشره — فيُنشر ليرى، ثمّ يُطفئ ليُصلح، وبين الاثنين رابطٌ حيٌّ فُتح
     * لمن وصله. أو لا يُنشر أبدًا لأنّه لا يعرف ما سيخرج.
     *
     * وهو القالبُ نفسه بالحمولة نفسها — لا رسمًا يشبهه: رسمٌ يشبهه يفترق عنه
     * عند أوّل حقلٍ يُضاف في أحدهما، فيرى التاجر غير ما يرى زبونُه.
     *
     * والمسار خلف الحارس ولا يقبل معرّفًا: المتجر يُقرأ من جلسة صاحبه وحدها،
     * فلا يُعايَن متجرُ غيره بتبديل رقمٍ في الرابط.
     */
    public function preview(): Response
    {
        $business = \App\Models\Business::findOrFail(
            auth()->user()->business_id ?? \App\Support\Demo::bid()
        );

        return response()
            ->view('store.show', Storefront::page($business) + ['preview' => true])
            // ومعاينةٌ لا تُخزَّن ولا تُفهرَس: هي حالُ لحظتها، ولصاحبها وحده
            ->header('Cache-Control', 'no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
