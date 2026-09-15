<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Review;
use App\Support\Activity;
use App\Support\Contention;
use App\Support\Paper;
use App\Support\ReviewInvite;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * الصفحةُ التي يكتب فيها الزبونُ رأيَه — وما كان له بابٌ قبلها.
 *
 * ولا تسجيلَ دخولٍ هنا: الواقف أمام الشاشة زبونٌ لا حساب له في النظام ولا
 * يجب أن يكون. وحارسُها رمزُ طلبه: اثنان وعشرون حرفًا لا تُخمَّن، تقول ما
 * لا يقوله نموذجٌ مفتوح — **هذا اشترى**.
 *
 * وهي صفحةٌ قائمةٌ بذاتها لا شاشةٌ من اللوحة: لا Inertia ولا حزمةَ بناء.
 * تُفتح من رسالةِ واتساب على هاتفٍ بشبكةٍ ضعيفة، ومن ينتظر تحميلَ حزمةٍ
 * ليكتب جملةً يُغلقها قبل أن تصل. ونجومُها CSS خالص — لا جافاسكربت، فمن
 * أطفأه يبقى قادرًا على الإرسال.
 *
 * ═══ وما يصل يصل معلَّقًا ═══
 *
 * لا يظهر حرفٌ على موقع التاجر حتى يقرأه ويأذن. وهذا شرطُ النموذج منذ
 * كُتب (انظر `Review`) — والبابُ الجديد لا يفتح فيه ثغرة: يكتب الزبون،
 * ويقرأ التاجر، ثمّ يُنشر أو يُرفض.
 *
 * ═══ وقفلُ الاشتراك لا يقفل رأيًا عن بيعةٍ تمّت ═══
 *
 * متجرٌ انتهى اشتراكه تُغلق لوحتُه ومتجره — وهذا صحيح، كلاهما خدمةٌ تُباع.
 * أمّا هذه فرأيُ زبونٍ عن بيعةٍ وقعت قبل أن ينتهي شيء، ومنعُه يجعل مشكلةَ
 * الفوترة بين التاجر وأبعاد تقع على من لا شأن له بها. فيُكتب الرأي وينتظره
 * التاجرُ حين يعود.
 */
class ReviewInviteController extends Controller
{
    public function show(string $token): View
    {
        $order = ReviewInvite::find($token);

        /*
         * ورمزٌ لا يقود إلى طلبٍ يُدعى صاحبُه ٤٠٤.
         *
         * لا صفحةٌ تقول «انتهت الصلاحية»: لا صلاحيةَ هنا تنتهي. وطلبٌ رُدَّ
         * إلى «قيد التجهيز» بعد تسليمه يسقط شرطُه — والجوابُ الصادق أنّه
         * لا شيء هنا الآن.
         */
        abort_if(! ReviewInvite::eligible($order) || $order->business === null, 404);

        return view('public.review', $this->canvas($order));
    }

    /**
     * ما يصل من يده.
     *
     * والشروطُ تُقاس هنا ثانيةً لا في الصفحة وحدها: من ينادي المسار مباشرةً
     * لا يمرّ بنموذجٍ مرسوم.
     */
    public function store(Request $request, string $token): RedirectResponse
    {
        $order = ReviewInvite::find($token);

        abort_if(! ReviewInvite::eligible($order) || $order->business === null, 404);

        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        /*
         * ═══ ورأيٌ واحدٌ لكلّ طلب — بالقيد وحدَه ═══
         *
         * من ضغط «إرسال» ضغطتين على شبكةٍ بطيئة لم يُخطئ. فيُقال له شكرًا،
         * ويبقى في القاعدة صفٌّ واحد.
         *
         * وكان هنا فحصٌ يسبق الكتابة: «هل كُتب؟» فإن كُتب فشكرًا. وأسقطتُه
         * لأنّه **لا يحرس شيئًا**: نقرتان متزامنتان تمرّان عليه معًا فتجيبان
         * «لا»، والذي يردّ الثانية هو الفهرسُ الفريد على `order_id` — وهو
         * يردّها بالفحص وبدونه. وفحصان لسؤالٍ واحد يفترقان يوم يُبدَّل
         * أحدُهما.
         *
         * فيبقى الحارسُ واحدًا، ويُلتقط اصطدامُه ويُقال شكرًا. وفي نقطة حفظ:
         * الالتقاطُ وحده لا يُنقذ على PostgreSQL — انظر `Contention`.
         */
        $review = Contention::attempt(fn () => Review::create([
            'business_id' => (int) $order->business_id,
            'customer_id' => $order->customer_id,
            'order_id' => $order->getKey(),
            'author_name' => ReviewInvite::author($order),
            'rating' => (int) $data['rating'],
            'comment' => $data['comment'] ?? null,
            /* معلَّقًا — لا يظهر على الموقع حتى يقرأه صاحبُ المحلّ ويأذن */
            'status' => 'معلّق',
        ]));

        if ($review !== null) {
            /*
             * والأثرُ يُنسب إلى متجر الطلب لا إلى جلسةِ من كتب.
             *
             * كاتبُه زبونٌ لا حساب له ولا متجرَ في جلسته، و`business_id`
             * المقروءُ من المستخدم يكون فارغًا — فيسقط السطرُ خارج سجلّ
             * المتجر ولا يراه صاحبُه. و`user_name` تقول «زائر»، وهي الحقّ.
             */
            Activity::log(
                'created',
                'كتب زبونٌ تقييمًا بـ'.$review->rating.' نجوم عن الطلب '.$order->number,
                [
                    'business_id' => (int) $order->business_id,
                    'subject_id' => $review->id,
                    'subject_type' => 'review',
                ],
            );
        }

        return redirect()->route('review.write', $token)->with('done', true);
    }

    /**
     * ما تُرسم به الصفحة — والبيانُ واحدٌ للعرض وللشكر.
     *
     * @return array<string, mixed>
     */
    private function canvas(Order $order): array
    {
        return [
            'brand' => Paper::brand($order->business),
            'number' => (string) $order->number,
            'token' => (string) $order->review_token,
            /* وقد كتب من قبل: تُعرض الشكرُ لا النموذج — لا رأيان عن طلبٍ واحد */
            'done' => session('done') === true || ReviewInvite::written($order),
        ];
    }
}
