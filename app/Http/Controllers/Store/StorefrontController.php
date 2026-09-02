<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Product;
use App\Support\Storefront;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * المتجر كما يراه الزبون — الصفحة العامّة الوحيدة في النظام.
 *
 * كلُّ ما سواها خلف تسجيل دخول. وهذه تُفتح بلا حساب، فكلُّ ما تقرؤه يجب أن
 * يُقيَّد بالمتجر صراحةً: لا `Demo::bid()` ولا جلسة ولا مستخدم حاليّ — تلك
 * كلُّها تجيب عن سؤال «أيّ متجرٍ يعمل عليه المستخدم؟» وليس ثمّ مستخدم.
 *
 * والمعاينةُ ليست مسارًا ثانيًا: صاحب المتجر يفتح صفحته نفسها فيراها ولو لم
 * تُنشر بعد. فما يُعاين هو ما يُنشر حرفًا بحرف، لا نسخةٌ منه قد تفترق عنه.
 */
class StorefrontController extends Controller
{
    public function home(Request $request, Business $business)
    {
        $this->gate($business);

        $categoryId = (int) $request->query('category') ?: null;
        $search = (string) $request->query('q', '');

        return view('store.home', Storefront::view($business, $categoryId, $search) + [
            'activeCategory' => $categoryId,
            'search' => $search,
            'preview' => ! Storefront::isOpen($business),
        ]);
    }

    public function product(Business $business, Product $product)
    {
        $this->gate($business);

        /*
         * الصنف من هذا المتجر ومنشور — وإلّا فلا وجود له.
         *
         * ربطُ النموذج بالمسار يجلب أيّ صنفٍ بمعرّفه، من أيّ متجر. ورقمٌ
         * يُبدَّل في الرابط يعرض صنف تاجرٍ آخر بسعره وكلفته على صفحةِ هذا.
         */
        if ((int) $product->business_id !== (int) $business->id) {
            throw new NotFoundHttpException;
        }

        if (! $product->published || ! $product->active) {
            $this->ownerOnly($business);
        }

        return view('store.product', Storefront::view($business) + [
            'product' => $product->load('category', 'images', 'variants'),
            'preview' => ! Storefront::isOpen($business),
        ]);
    }

    /**
     * متجرٌ غير منشور لا يُفتح لأحدٍ إلا صاحبه.
     *
     * و«لا يُفتح» هنا ٤٠٤ لا ٤٠٣: الرسالة الثانية تقول لمن جرّب معرّفًا
     * عشوائيًّا «هنا متجرٌ لكنّه مغلق»، فتصير طريقةً لعدّ متاجر المنصّة
     * وأيّها نشط. والأولى لا تقول شيئًا.
     */
    private function gate(Business $business): void
    {
        if (! Storefront::isOpen($business)) {
            $this->ownerOnly($business);
        }
    }

    private function ownerOnly(Business $business): void
    {
        $user = auth()->user();

        if (! $user || (int) $user->business_id !== (int) $business->id) {
            throw new NotFoundHttpException;
        }
    }
}
