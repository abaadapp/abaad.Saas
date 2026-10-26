<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\Lists\AllSalesList;
use App\Support\Lists\OrdersList;
use App\Support\Lists\PaidLinksList;
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
        // «رابط دفع» تحصيلاتٌ من جدولها لا طلبات — انظر `PaidLinksList`
        if (PaidLinksList::chosen($request)) {
            return $this->collections($request);
        }

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

        /*
         * و«كل المصادر» لمتجرٍ فُتحت فيه روابطُ الدفع: الطلباتُ وتحصيلاتُها في
         * جدولٍ واحد بيوم كلٍّ منها (`AllSalesList`). والمجاميعُ فوقُ من الطلبات
         * وحدها كما كانت — والتحصيلُ مجموعُه وحده (`linkCollections`).
         */
        $merged = AllSalesList::merges($request);
        $orders = $merged
            ? AllSalesList::page($request)
            : OrdersList::query($request)->paginate(10)->withQueryString()->through(fn ($o) => OrdersList::row($o));

        return \Inertia\Inertia::render('Admin/Orders/Index', [
            'orders' => $orders->items(),
            'pagination' => \App\Support\Pagination::meta($orders),
            // ومفاتيحُ الفترة من مصدرها — من/إلى القديمة أو شهرٌ أو سنة (`ListFilters::orderPeriod`)
            'filters' => $request->only('q', 'payment', 'status', 'when', 'channel')
                + \App\Support\ListFilters::orderPeriod($request)->params()
                + \App\Support\Sort::params($request, self::SORTS),
            'period' => \App\Support\ListFilters::orderPeriod($request)->screen($this->bid()),
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
            'channelOptions' => self::channelOptions(),
            /*
             * وتحصيلاتُ روابط الدفع تُذكر سطرًا تحت الإجمالي — لا فيه.
             *
             * من دفع عبر رابطٍ يبحث عن ماله في «المبيعات». والمالُ تحصيلٌ لبيعٍ
             * محسوبٍ في فاتورته، فيُقال عددُه ومبلغُه بجوار الإجمالي، ويُفتح
             * بمُرشِّحه. وحين يُختار مصدرٌ آخر لا يُذكر: سُئل عن غيره.
             */
            'linkCollections' => $request->filled('channel') ? null : self::linkSummary($request),
            // أفي الجدول تحصيلات؟ — فيُسمّى الإجماليُّ «إجمالي المبيعات» والتحصيلُ مجموعَه
            'withLinks' => $merged,
            // سندُ القبض صفحةُ «المالية» — والزرُّ لمن يفتحها
            'mayOpenReceipts' => (bool) auth()->user()?->allows('finance'),
            // زرُّ «حذف» لصاحب النشاط وحده — الحكمُ نفسُه الذي يردّ به `destroy`
            'mayDelete' => self::isOwner(),
        ]);
    }

    /**
     * «عرض» تحصيلٍ عبر رابط دفع.
     *
     * المرتبطُ بفاتورةٍ يُفتح على فاتورته الأصليّة نفسِها — بنودِها وضريبتِها
     * وطباعتِها، وطلباتِها إن صدرت عن بيع — ومعه تحصيلُه (`?link=`). والرابطُ
     * المستقلُّ يُفتح على تحصيله: لا فاتورةَ له، ولا تُنشأ له ولا تُعاد.
     *
     * ولا يُفتح ما لا تعرضه القائمة: معلَّقٌ أو تجربةٌ أو ملغًى أو لمتجرٍ آخر
     * — 404 (`PaidLinksList::detail`). والقراءةُ لا تكتب.
     */
    public function collection(int $id)
    {
        $collection = PaidLinksList::detail($id);
        abort_if($collection === null, 404);

        if ($collection['invoice'] !== null) {
            return redirect()->route('admin.customerInvoices.show', ['id' => $collection['invoice']['id'], 'link' => $id]);
        }

        return \Inertia\Inertia::render('Admin/Orders/Collection', [
            'collection' => $collection,
            'mayOpenReceipts' => (bool) auth()->user()?->allows('finance'),
        ]);
    }

    /**
     * «رابط دفع» — الشاشةُ نفسُها، وصفوفُها تحصيلاتٌ لا طلبات.
     *
     * ومجاميعُ الطلبات أصفارٌ هنا عن قصد: لا طلبَ في هذا المُرشِّح، والمحصَّلُ
     * يُقال في سطره (`collectionTotals`) ولا يُقرأ «إجماليَّ مبيعات».
     */
    private function collections(Request $request)
    {
        $page = PaidLinksList::page($request);

        return \Inertia\Inertia::render('Admin/Orders/Index', [
            'orders' => [],
            'collections' => $page->items(),
            'collectionTotals' => PaidLinksList::totals($request),
            // سندُ القبض صفحةُ «المالية» — والزرُّ لمن يفتحها
            'mayOpenReceipts' => (bool) auth()->user()?->allows('finance'),
            'pagination' => \App\Support\Pagination::meta($page),
            'filters' => $request->only('q', 'payment', 'status', 'when', 'channel')
                + \App\Support\ListFilters::orderPeriod($request)->params()
                + \App\Support\Sort::params($request, PaidLinksList::SORTS),
            'period' => \App\Support\ListFilters::orderPeriod($request)->screen($this->bid()),
            'sorts' => \App\Support\Sort::keys(PaidLinksList::SORTS),
            'totalAmount' => 0.0,
            'totalCount' => 0,
            'cancelledCount' => 0,
            'websiteCount' => 0,
            'websiteAmount' => 0.0,
            'statusOptions' => \App\Support\OrderStatus::options(),
            'channelOptions' => self::channelOptions(),
            'linkCollections' => null,
            'mayDelete' => false,
        ]);
    }

    /**
     * قنواتُ الطلبات من مصدرها الواحد (`SalesChannel`) — ومعها «رابط دفع».
     *
     * يُلحق هنا لا في `SalesChannel::options`: تلك قنواتُ أبواب البيع تقرؤها
     * شاشاتٌ أخرى، والرابطُ ليس منها.
     */
    private static function channelOptions(): array
    {
        return [...SalesChannel::options(), PaidLinksList::option()];
    }

    /** عددُ التحصيلات ومبلغُها بما سيعرضه مُرشِّحُها — أو `null` حين لا شيء */
    private static function linkSummary(Request $request): ?array
    {
        $totals = PaidLinksList::totals($request);

        return $totals['count'] > 0 ? ['count' => $totals['count'], 'amount' => $totals['amount']] : null;
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
        // وصاحبُ النشاط من دورُه سلطتُه — لا صفٌّ دورُه `admin` ضُيّقت صلاحياتُه (`Permissions::isOwner`)
        return \App\Support\Permissions::isOwner(auth()->user());
    }
}
