<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
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
            'order_item_id' => ['nullable', 'integer'],
        ]);

        /*
         * ═══ ورأيٌ في بندٍ — والبندُ من هذا الطلب وحده ═══
         *
         * المعرّفُ من المتصفّح، فيُطلب بين بنود الطلب الذي فتحه الرمز
         * (`ReviewInvite::item`): بندُ طلبٍ آخر أو متجرٍ آخر لا يوجد هنا، ويُردّ
         * ٤٠٤. والصنفُ يُقرأ من البند — لا يُقبل `product_id` من أحد.
         */
        if (filled($data['order_item_id'] ?? null)) {
            $item = ReviewInvite::item($order, (int) $data['order_item_id']);

            abort_if($item === null, 404);

            return $this->storeForItem($order, $item, $data, $token);
        }

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
            'type' => Review::TYPE_ORDER,
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
     * رأيٌ في بندٍ اشتُري — واحدٌ لكلّ بند، بالقيد.
     *
     * والحارسُ كحارس رأي الطلب: الفهرسُ الفريد على `order_item_id`، ونقرتان
     * متزامنتان تُلتقط ثانيتُهما ويُقال شكرًا (`Contention`). وبندٌ آخر من
     * الطلب نفسِه يبقى مفتوحًا.
     *
     * @param  array<string, mixed>  $data
     */
    private function storeForItem(Order $order, OrderItem $item, array $data, string $token): RedirectResponse
    {
        $review = Contention::attempt(fn () => Review::create([
            'business_id' => (int) $order->business_id,
            'type' => Review::TYPE_PRODUCT,
            'customer_id' => $order->customer_id,
            'order_id' => $order->getKey(),
            'order_item_id' => $item->getKey(),
            // من البند لا من الطلب: البندُ هو ما اشتُري
            'product_id' => (int) $item->product_id,
            'author_name' => ReviewInvite::author($order),
            'rating' => (int) $data['rating'],
            'comment' => $data['comment'] ?? null,
            /* معلَّقًا — كرأي الطلب: لا يظهر حتى يقرأه صاحبُ المحلّ ويأذن */
            'status' => 'معلّق',
        ]));

        if ($review !== null) {
            Activity::log(
                'created',
                'كتب زبونٌ تقييمًا بـ'.$review->rating.' نجوم عن «'.$item->displayName().'» في الطلب '.$order->number,
                [
                    'business_id' => (int) $order->business_id,
                    'subject_id' => $review->id,
                    'subject_type' => 'review',
                ],
            );
        }

        return redirect()->route('review.write', $token)->with('reviewed', (int) $item->getKey());
    }

    /**
     * ما تُرسم به الصفحة — والبيانُ واحدٌ للعرض وللشكر.
     *
     * @return array<string, mixed>
     */
    private function canvas(Order $order): array
    {
        $items = ReviewInvite::items($order);

        return [
            'brand' => Paper::brand($order->business),
            'number' => (string) $order->number,
            'token' => (string) $order->review_token,
            /*
             * وبنودُ الطلب — لكلٍّ نموذجُه أو «وصل رأيك». وطلبٌ بلا صنفٍ يُقيَّم
             * يبقى على نموذجه الواحد كما كان.
             */
            'items' => $items->map(fn (OrderItem $i) => [
                'id' => (int) $i->getKey(),
                'name' => $i->displayName(),
                'image' => $i->product?->image,
                'reviewed' => $i->review_id !== null,
            ])->all(),
            'justReviewed' => (int) session('reviewed', 0),
            /*
             * وقد أتمّ: تُعرض الشكرُ لا النموذج — لا رأيان عن بندٍ واحد. وطلبُ
             * الأصناف يتمّ بآخر بنوده لا بأوّل رأي (`ReviewInvite::written`).
             */
            'done' => $items->isEmpty()
                ? session('done') === true || ReviewInvite::written($order)
                : $items->every(fn (OrderItem $i) => $i->review_id !== null),
        ];
    }
}
