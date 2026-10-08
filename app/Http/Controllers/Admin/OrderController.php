<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\Lists\OrdersList;
use App\Support\OrderCorrection;
use App\Support\SalesChannel;
use Illuminate\Http\Request;
use RuntimeException;

class OrderController extends Controller
{
    /** ما يُرتَّب في قائمة المبيعات — مصدرُه `OrdersList` الذي يقرؤه التصدير */
    private const SORTS = OrdersList::SORTS;

    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    public function index(Request $request)
    {
        // الاستعلامُ نفسُه الذي يقرؤه الملفّ — الشاشةُ ترقّمه والتصديرُ يقرؤه كلَّه
        $q = OrdersList::filtered($request);

        /*
         * مجموع ما رُشّح لا مجموع الصفحة.
         *
         * الجدول يعرض عشرة صفوف من مئة، فجمعُ المعروض يقول رقمًا لا معنى له.
         * ويُحسب قبل الترقيم على النسخة نفسها من الاستعلام.
         */
        $filtered = (clone $q);
        $totalAmount = (float) $filtered->clone()->sold()->sum('total');
        $totalCount = $filtered->clone()->count();
        $cancelledCount = $filtered->clone()->where('status', Order::CANCELLED)->count();

        /*
         * كم منها جاء من الموقع — ومبلغُه.
         *
         * والسؤالُ «أين أكثرُ طلباتي؟» لا يُجاب بعمودٍ يُقرأ صفًّا صفًّا:
         * الصفحةُ عشرةٌ من مئة. فيُحسب على ما رُشّح كلِّه كما يُحسب الإجمالي،
         * وبجواره — فيُقرأ الاثنان نسبةً بلا حساب.
         */
        $websiteCount = $filtered->clone()
            ->where('channel', SalesChannel::WEBSITE)->count();
        $websiteAmount = (float) $filtered->clone()->sold()
            ->where('channel', SalesChannel::WEBSITE)->sum('total');

        $orders = OrdersList::query($request)->paginate(10)->withQueryString()
            ->through(fn ($o) => OrdersList::row($o));

        return \Inertia\Inertia::render('Admin/Orders/Index', [
            'orders' => $orders->items(),
            'pagination' => \App\Support\Pagination::meta($orders),
            'filters' => $request->only('q', 'payment', 'status', 'from', 'to', 'when', 'channel')
                + \App\Support\Sort::params($request, self::SORTS),
            'sorts' => \App\Support\Sort::keys(self::SORTS),
            // المبلغ من المُباع وحده، والعدد من الكلّ — والملغى يُذكر صراحةً
            // كي لا يُقرأ الفرقُ بينهما خطأً في الجمع
            'totalAmount' => $totalAmount,
            'totalCount' => $totalCount,
            'cancelledCount' => $cancelledCount,
            // وما جاء من الموقع يُقرأ بجوار الإجمالي — عددًا ومبلغًا
            'websiteCount' => $websiteCount,
            'websiteAmount' => $websiteAmount,
            // قائمة الحالات من مصدرها الواحد — لا تُكتب في الشاشة مرّةً ثانية
            'statusOptions' => \App\Support\OrderStatus::options(),
            // والقنوات من مصدرها الواحد كذلك — انظر App\Support\SalesChannel
            'channelOptions' => SalesChannel::options(),
            // زرُّ «حذف» لصاحب النشاط وحده — الحكمُ نفسُه الذي يردّ به `destroy`
            'mayDelete' => self::isOwner(),
        ]);
    }

    /**
     * «حذف» البيعة — وهو إلغاؤها ماليًّا، لا محوُ صفّها.
     *
     * ═══ لصاحب النشاط وحده ═══
     *
     * `role === 'admin'` لا `User::isAdmin()`: تلك تشمل المدير. ولا صلاحيةَ
     * تُمنح لموظّف: من وصل «المبيعات» بصلاحيّته يصل هذا المسار أيضًا (اسمُه
     * تحت `admin.orders.*`)، فيُردّ هنا بـ403 ولو استُدعي باليد.
     *
     * ═══ ولمَ إلغاءٌ لا `delete()` ═══
     *
     * البيعةُ أخذت من الرفّ، وقيّدت دخلًا، وأعطت نقاطًا، وربما أحرقت كوبونًا
     * أو دخلت فاتورةَ عميل. ومحوُ الصفّ يُبقي ذلك كلَّه بلا أصل. و
     * `OrderCorrection::cancel` يعيد كلّ شيءٍ بطريقه — والدفترُ يُعكس ولا
     * يُمحى — ويرفض فاتورةً دخلت إقرارًا ضريبيًّا قُدِّم (`assertNotFiled`).
     */
    public function destroy(Request $request, string $number)
    {
        abort_unless(self::isOwner(), 403);

        $order = Order::where('business_id', $this->bid())
            ->where('is_held', false)
            ->where('number', $number)
            ->firstOrFail();

        try {
            OrderCorrection::cancel($order, __('حذف البيعة'));
        } catch (RuntimeException $e) {
            // والرفضُ يُرى — اللوحة لا تعرض إلّا `flash.toast`
            return back()
                ->with('toast', ['msg' => $e->getMessage(), 'type' => 'danger'])
                ->withErrors(['order' => $e->getMessage()]);
        }

        Activity::log('deleted', 'حذف البيعة '.$order->number.' — أُلغي أثرها المالي', [
            'subject_id' => $order->id,
            'subject_type' => 'order',
        ]);

        return back()->with('toast', ['msg' => __('حُذفت البيعة — أُلغي أثرها المالي وعاد المخزون'), 'type' => 'success']);
    }

    private static function isOwner(): bool
    {
        return auth()->user()?->role === 'admin';
    }
}
