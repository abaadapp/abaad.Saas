<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Support\Paper;
use App\Support\PublicDocument;
use Illuminate\Contracts\View\View;

/**
 * الورقةُ أونلاين — ما يفتحه من مسح الرمز أسفل إيصاله.
 *
 * ولا تسجيلَ دخولٍ هنا: الواقف أمام الشاشة زبونٌ لا موظّف، ولا حساب له
 * في النظام ولا يجب أن يكون. وحارسُها أنّ رمزها لا يُخمَّن — اثنان
 * وعشرون حرفًا من ستّةٍ وستّين احتمالًا — لا أنّ أحدًا يُسأل من هو.
 *
 * وهي صفحةٌ قائمةٌ بذاتها لا شاشةٌ من اللوحة: لا Inertia ولا قائمةٌ
 * جانبية ولا أصولُ البناء. تُفتح على هاتفٍ في المحلّ بشبكةٍ ضعيفة، وكلُّ
 * ما فيها في ملفٍّ واحد.
 *
 * والختمُ هنا وحده: الورقة المطبوعة تخرج من طابعة التاجر فلا تُثبت شيئًا
 * عن نفسها، وهذه تُقرأ من خادم أبعاد — فالختمُ يقول إنّ ما تراه هو ما في
 * الدفتر، لا صورةً عُدِّلت.
 */
class PublicDocumentController extends Controller
{
    public function show(string $token): View
    {
        $link = PublicDocument::find($token);

        abort_if($link === null, 404);

        $order = $link->linkable;

        /*
         * ورقةٌ فقدت أصلها لا تُعرض.
         *
         * الطلب يُحذف حذفًا ناعمًا، ونشاطُه قد يُحذف كلُّه — و`morphTo` تردّ
         * null بلا شكوى. فبلا هذا كانت الصفحة تنهار بـ500 في يد زبونٍ لا
         * يعرف ما الذي كسر، بدل «هذه الورقة لم تعد متاحة».
         */
        abort_if(! $order instanceof Order || $link->business === null, 404);

        $order->loadMissing('items');

        /*
         * وقفلُ الاشتراك لا يقفل إيصالًا سُلّم.
         *
         * متجرُ من انتهى اشتراكه يُغلق متجرَه ولوحتَه — وهذا صحيح: كلاهما
         * خدمةٌ تُباع. أمّا هذه فسجلُّ معاملةٍ تمّت ووصلت يدَ الزبون قبل
         * أن ينتهي شيء، ومنعُها يجعل مشكلةَ الفوترة بين التاجر وأبعاد
         * تقع على من لا شأن له بها.
         */
        return view('public.paper', [
            'order' => $order,
            'brand' => Paper::brand($link->business, Paper::vatNumber($link->business_id)),
            'stampedAt' => now()->format('Y-m-d H:i'),
        ]);
    }
}
