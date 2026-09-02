<?php

namespace App\Http\Controllers\Admin\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Review;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\Pagination;
use App\Support\Search;
use App\Support\Sort;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * تقييمات العملاء — لا يُنشر منها إلا ما أُذن بنشره.
 *
 * التقييم يصل معلَّقًا ولا يظهر حتى يُقرأ: الموقع واجهةُ المتجر، وتقييمٌ مسيء
 * أو مكتوبٌ بغلط يظهر فيها فور وصوله يُقرأ على أنه رأي المتجر في نفسه.
 *
 * والحذف ليس بديلًا عن الرفض: المرفوض يبقى محفوظًا فيُعرف كم رُفض ولماذا،
 * والممحوّ لا يقول شيئًا.
 */
class ReviewController extends Controller
{
    /** ما يُرتَّب في تقييمات العملاء */
    private const SORTS = [
        'author' => 'author_name',
        'rating' => 'rating',
        'status' => 'status',
    ];

    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    public function index(Request $request): Response
    {
        $bid = $this->bid();

        $q = Review::where('business_id', $bid)->with(['customer', 'product']);

        if ($s = Search::term($request)) {
            // `like` على PostgreSQL يفرّق بين حالتَي الحرف، وأسماءُ المقيّمين
            // وتعليقاتُهم تُكتب باللاتينية كثيرًا — فالبحث كان أعمى في الإنتاج
            // ويعمل في الاختبار (SQLite متساهل). انظر Support\Search
            $like = Search::like();
            $q->where(fn ($w) => $w->where('comment', $like, "%{$s}%")
                ->orWhere('author_name', $like, "%{$s}%"));
        }
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        if ($rating = $request->query('rating')) {
            $q->where('rating', (int) $rating);
        }

        Sort::apply($q, $request, self::SORTS, fn ($w) => $w->orderByDesc('id'));

        $reviews = $q->paginate((int) $request->query('per_page', 20))->withQueryString();

        $all = Review::where('business_id', $bid)->get();

        return Inertia::render('Admin/Marketing/Reviews', [
            'reviews' => collect($reviews->items())->map(fn ($r) => [
                'id' => $r->id,
                'author' => $r->displayName(),
                'product' => $r->product?->name,
                'rating' => (int) $r->rating,
                'comment' => $r->comment,
                'status' => $r->status,
                'reply' => $r->reply,
                'replied_at' => optional($r->replied_at)->format('Y-m-d'),
                'at' => optional($r->created_at)->format('Y-m-d'),
            ])->all(),
            'pagination' => Pagination::meta($reviews),
            'filters' => $request->only('q', 'status', 'rating')
                + Sort::params($request, self::SORTS),
            'sorts' => Sort::keys(self::SORTS),
            'products' => Product::where('business_id', $bid)->orderBy('name')
                ->get(['id', 'name'])->map(fn ($p) => ['value' => $p->id, 'label' => $p->name])->all(),
            'customers' => Customer::where('business_id', $bid)->orderBy('name')->limit(500)
                ->get(['id', 'name'])->map(fn ($c) => ['value' => $c->id, 'label' => $c->name])->all(),
            'summary' => [
                'count' => $all->count(),
                'pending' => $all->where('status', 'معلّق')->count(),
                'published' => $all->where('status', 'منشور')->count(),
                // المعدّل على المنشور وحده: المعلّق لم يُقرأ بعد فلا يُحتسب رأيًا
                'average' => $all->where('status', 'منشور')->count()
                    ? round($all->where('status', 'منشور')->avg('rating'), 2)
                    : 0.0,
            ],
        ]);
    }

    /** تسجيل تقييمٍ يدويًّا — ما يصل بالهاتف أو في المحل */
    public function store(Request $request)
    {
        $bid = $this->bid();

        $data = $request->validate([
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('business_id', $bid)],
            'product_id' => ['nullable', Rule::exists('products', 'id')->where('business_id', $bid)],
            'author_name' => ['nullable', 'string', 'max:255'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        Review::create($data + ['business_id' => $bid]);
        Activity::log('created', 'سجّل تقييمًا بـ'.$data['rating'].' نجوم');

        return back()->with('toast', ['msg' => __('سُجّل التقييم معلَّقًا'), 'type' => 'success']);
    }

    /** النشر أو الرفض — والمرفوض يبقى محفوظًا */
    public function status(Request $request, $id)
    {
        $review = Review::where('business_id', $this->bid())->findOrFail($id);

        $data = $request->validate([
            'status' => ['required', Rule::in(['معلّق', 'منشور', 'مرفوض'])],
        ]);

        $review->update(['status' => $data['status']]);

        return back()->with('toast', [
            'msg' => __('صار التقييم :status', ['status' => $data['status']]),
            'type' => $data['status'] === 'مرفوض' ? 'warning' : 'success',
        ]);
    }

    /**
     * الردّ على التقييم.
     *
     * ردٌّ على تقييمٍ معلَّق لا يراه أحد: الردّ يُنشر مع تقييمه، فالنشر يسبقه
     * أو يصحبه — وإلا كتب التاجر ردًّا يظنّه معروضًا وهو محجوب.
     */
    public function reply(Request $request, $id)
    {
        $review = Review::where('business_id', $this->bid())->findOrFail($id);

        $data = $request->validate([
            'reply' => ['required', 'string', 'max:2000'],
        ]);

        $review->update([
            'reply' => $data['reply'],
            'replied_at' => now(),
            // الردّ إذنٌ بالنشر ضمنًا: لا يُردّ إلا على ما يُعرض
            'status' => $review->status === 'معلّق' ? 'منشور' : $review->status,
        ]);

        return back()->with('toast', ['msg' => __('نُشر الردّ'), 'type' => 'success']);
    }

    public function destroy($id)
    {
        $review = Review::where('business_id', $this->bid())->findOrFail($id);

        Activity::log('deleted', 'حذف تقييمًا', ['subject_id' => $review->id]);
        $review->delete();

        return back()->with('toast', ['msg' => __('حُذف التقييم'), 'type' => 'warning']);
    }
}
