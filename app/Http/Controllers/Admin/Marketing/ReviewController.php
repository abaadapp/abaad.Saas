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
    /**
     * ما يُرتَّب في تقييمات العملاء.
     *
     * ═══ و«المُقيِّم» يُرتَّب كما يُقرأ ═══
     *
     * الشاشةُ تعرض `displayName()`: اسمَ العميل المسجَّل إن كان، وإلّا ما
     * كتبه الزائر. وكان الترتيبُ على `author_name` وحدَه — وهو **فارغٌ**
     * لكلّ تقييمٍ لعميلٍ مسجَّل. فيضغط التاجر رأسَ العمود فتتحرّك الصفوف
     * ترتيبًا لا يطابق ما يقرؤه: أسماءٌ ظاهرةٌ مرتَّبةٌ بفراغات.
     *
     * فيُرتَّب على العمود المحسوب `display_author` — وهو التعبيرُ نفسُه
     * الذي تعرضه الشاشة.
     */
    private const SORTS = [
        'author' => 'display_author',
        'rating' => 'rating',
        'status' => 'status',
    ];

    /** كم عميلًا تحمل قائمةُ «تسجيل تقييم» — وما بعدها يُقال لا يُسقَط صامتًا */
    private const CUSTOMER_OPTIONS = 500;

    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    public function index(Request $request): Response
    {
        $bid = $this->bid();

        /*
         * والوصلةُ يسارًا لا داخليّة: تقييمُ زائرٍ بلا عميلٍ مسجَّل يبقى في
         * القائمة. و`deleted_at` في شرط الوصل لا بعده — عميلٌ محذوفٌ لا
         * يُقرأ اسمُه في الترتيب وقد سقط من الشاشة (`belongsTo` يردّ
         * فارغًا للمحذوف)، فيفترق المعروضُ عن المرتَّب من جديد.
         */
        $q = Review::where('reviews.business_id', $bid)->with(['customer', 'product'])
            ->leftJoin('customers', function ($join) {
                $join->on('customers.id', '=', 'reviews.customer_id')
                    ->whereNull('customers.deleted_at');
            })
            ->select('reviews.*')
            ->selectRaw('coalesce(customers.name, reviews.author_name) as display_author');

        if ($s = Search::term($request)) {
            // `like` على PostgreSQL يفرّق بين حالتَي الحرف، وأسماءُ المقيّمين
            // وتعليقاتُهم تُكتب باللاتينية كثيرًا — فالبحث كان أعمى في الإنتاج
            // ويعمل في الاختبار (SQLite متساهل). انظر Support\Search
            $like = Search::like();
            $q->where(fn ($w) => $w->where('reviews.comment', $like, "%{$s}%")
                ->orWhere('reviews.author_name', $like, "%{$s}%")
                /* واسمُ العميل المسجَّل يُبحث فيه أيضًا — هو ما يراه الباحث في العمود */
                ->orWhere('customers.name', $like, "%{$s}%"));
        }
        if ($status = $request->query('status')) {
            $q->where('reviews.status', $status);
        }
        if ($rating = $request->query('rating')) {
            $q->where('reviews.rating', (int) $rating);
        }

        Sort::apply($q, $request, self::SORTS, fn ($w) => $w->orderByDesc('reviews.id'));

        $reviews = $q->paginate(Pagination::perPage($request, 20))->withQueryString();

        /* وواحدٌ زائدٌ على السقف — به وحده يُعرف أنّ ثمّةَ ما بعده */
        $customers = Customer::where('business_id', $bid)->orderBy('name')
            ->limit(self::CUSTOMER_OPTIONS + 1)->get(['id', 'name']);

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
                /*
                 * أكتبه الزبونُ بنفسه، أم سجّله المتجر عنه؟
                 *
                 * صارا يقعان معًا منذ فُتح بابُ الرأي، والشاشةُ لا تفرّق —
                 * فتُقرأ شهادةُ زبونٍ كتبها بيده وشهادةٌ كتبها صاحبُ المحلّ
                 * عن نفسه سواءً. وهذا الفرقُ هو كلُّ قيمة الباب.
                 *
                 * و`order_id` هو العلامة: لا يكتبه إلّا بابُ الدعوة —
                 * `store()` لا يقبله أصلًا.
                 */
                'byCustomer' => $r->order_id !== null,
            ])->all(),
            'pagination' => Pagination::meta($reviews),
            'filters' => $request->only('q', 'status', 'rating')
                + Sort::params($request, self::SORTS),
            'sorts' => Sort::keys(self::SORTS),
            'products' => Product::where('business_id', $bid)->orderBy('name')
                ->get(['id', 'name'])->map(fn ($p) => ['value' => $p->id, 'label' => $p->name])->all(),
            'customers' => $customers->take(self::CUSTOMER_OPTIONS)
                ->map(fn ($c) => ['value' => $c->id, 'label' => $c->name])->all(),
            /*
             * وبُترت القائمة؟ — يُقال، ولا تُبتر صامتة.
             *
             * `limit(500)` كانت تُسقط ما بعدها بلا كلمة: لا رسالة، ولا رقمٌ
             * يقول «٥٠٠ من ٦٢٠». فيبحث التاجر عن عميلٍ يعرف أنّه مسجَّلٌ
             * عنده فلا يجده، ويظنّ القائمةَ كلَّ ما لديه. وهو العطبُ نفسُه
             * الذي دفعنا ثمنَه في `CustomerInvoiceController` مرّةً.
             *
             * وخانةُ «الاسم» تحتها هي المخرج — فتُقال معها لا بعدها.
             */
            'customersCapped' => $customers->count() > self::CUSTOMER_OPTIONS,
            'summary' => $this->summary($bid),
        ]);
    }

    /**
     * البطاقات الأربع — بمسحةٍ واحدة لا بجدولٍ في الذاكرة.
     *
     * كانت تُحسب بـ`Review::…->get()`: كلُّ تقييمٍ في المتجر يُبنى نموذجًا
     * ليُعدّ، والصفحة تحته مرقَّمةٌ بعشرين. فمتجرٌ بألف تقييمٍ يبني ألفَ
     * نموذجٍ ليكتب أربعة أرقام، ويكبر الثمن مع كلّ تقييمٍ يصل.
     *
     * والجمعُ الشرطيّ يفعلها في استعلامٍ واحد، ويعمل على PostgreSQL وSQLite
     * معًا (`case when` قياسيّ، خلافًا لـ`FILTER` التي لا يعرفها الثاني).
     *
     * والمعدّل يُقسَم هنا لا في SQL: `avg` مع `case` تحسب المعلَّقة أصفارًا
     * أو تردّ NULL بحسب المحرّك — والقسمة على عدد المنشور صريحةٌ لا تختلف.
     *
     * @return array<string, mixed>
     */
    private function summary(int $businessId): array
    {
        $when = fn (string $expr) => "sum(case when {$expr} then 1 else 0 end)";

        $row = Review::where('business_id', $businessId)
            ->selectRaw(
                'count(*) as total, '
                .$when('status = ?').' as pending_count, '
                .$when('status = ?').' as published_count, '
                .'sum(case when status = ? then rating else 0 end) as published_stars',
                ['معلّق', 'منشور', 'منشور'],
            )->first();

        $published = (int) ($row->published_count ?? 0);

        return [
            'count' => (int) ($row->total ?? 0),
            'pending' => (int) ($row->pending_count ?? 0),
            'published' => $published,
            // المعدّل على المنشور وحده: المعلّق لم يُقرأ بعد فلا يُحتسب رأيًا
            'average' => $published ? round((float) $row->published_stars / $published, 2) : 0.0,
        ];
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

        $from = $review->status;
        $review->update(['status' => $data['status']]);

        /*
         * والنشرُ يُقيَّد — هو أخطر الأفعال الثلاثة لا أهونها.
         *
         * التسجيلُ يُقيَّد والحذفُ يُقيَّد، وكان النشرُ والرفض لا. والنشرُ
         * يُخرج كلامَ زبونٍ إلى واجهة المتجر يقرؤه كلّ زائر، والرفضُ يحجب
         * شكوى — فيسأل صاحب المحلّ من نشر هذا؟ ومن حجب تلك؟ ولا جواب.
         */
        Activity::log('updated', 'التقييم: من «'.$from.'» إلى «'.$data['status'].'»', [
            'subject_id' => $review->id,
            'subject_type' => 'review',
        ]);

        /*
         * ونشرُ نجومٍ بلا كلامٍ يُقال ما هو.
         *
         * قسمُ الآراء لا يعرض إلا ما فيه تعليق (`scopeShowable`). فتاجرٌ
         * ينشر خمسةَ تقييماتٍ بخمس نجومٍ بلا كلام يرى «منشور ٥» ثمّ يفتح
         * موقعَه فلا يجد شيئًا — ولا يعرف لماذا. والنشرُ صحيحٌ: النجومُ
         * تُحتسب في المعدّل. الناقصُ أن يُقال له أين تذهب.
         */
        $silent = $data['status'] === 'منشور' && trim((string) $review->comment) === '';

        return back()->with('toast', [
            'msg' => $silent
                ? __('نُشر — ونجومُه تُحتسب في المعدّل. ولا يظهر في قسم الآراء: لا تعليق فيه.')
                : __('صار التقييم :status', ['status' => $data['status']]),
            'type' => $data['status'] === 'مرفوض' ? 'warning' : 'success',
        ]);
    }

    /**
     * الردّ على التقييم — وحذفُه.
     *
     * ردٌّ على تقييمٍ معلَّق لا يراه أحد: الردّ يُنشر مع تقييمه، فالنشر يسبقه
     * أو يصحبه — وإلا كتب التاجر ردًّا يظنّه معروضًا وهو محجوب.
     *
     * ═══ وردٌّ يُكتب يجب أن يُمحى ═══
     *
     * كان `reply` مطلوبًا، فلا سبيلَ إلى إزالة ردٍّ إلّا بكتابة ردٍّ آخر
     * مكانه. وهو نصٌّ **موقَّعٌ باسم المحلّ على واجهته** يقرؤه كلّ زائر —
     * يُكتب في لحظة غضبٍ أو يُرسَل قبل تمامه أو يُخطئ في اسم. وصاحبُه لا
     * يملك محوَه. و«بابٌ لا يُعرض» هنا أسوأ من بابٍ يُعرض ولا يُفتح: لا
     * يعرف أنّه معدوم حتى يحتاجه.
     *
     * فصار الحقلُ يقبل الفراغ، والفراغُ محوٌ صريح — ولا يُنشر تقييمٌ بمحو.
     */
    public function reply(Request $request, $id)
    {
        $review = Review::where('business_id', $this->bid())->findOrFail($id);

        $data = $request->validate([
            'reply' => ['nullable', 'string', 'max:2000'],
        ]);

        $text = trim((string) ($data['reply'] ?? ''));

        if ($text === '') {
            /*
             * والمحوُ لا يُغيّر حالَ التقييم.
             *
             * الردُّ إذنٌ بالنشر ضمنًا؛ وسحبُ الردّ ليس سحبًا للإذن. وتقييمٌ
             * نُشر ثمّ يختفي من الموقع لأنّ صاحبَ المحلّ محا تعليقَه عليه
             * يُخفي كلامَ زبونٍ بفعلٍ لا يقصده.
             */
            $review->update(['reply' => null, 'replied_at' => null]);

            Activity::log('updated', 'حذف ردَّه على تقييم', [
                'subject_id' => $review->id,
                'subject_type' => 'review',
            ]);

            return back()->with('toast', ['msg' => __('حُذف الردّ'), 'type' => 'warning']);
        }

        $review->update([
            'reply' => $text,
            'replied_at' => now(),
            /*
             * الردّ إذنٌ بالنشر ضمنًا — للمعلَّق وحده.
             *
             * والمرفوضُ لا يُنشر بردّ: رفضُه قرارٌ اتّخذه صاحبُه بيده، وقلبُه
             * من طرفٍ خفيٍّ يُخرج إلى واجهة المتجر كلامًا حُجب عمدًا.
             */
            'status' => $review->status === 'معلّق' ? 'منشور' : $review->status,
        ]);

        // ردٌّ يُكتب باسم المحلّ ويقرؤه كلّ زائر — يُعرف كاتبُه
        Activity::log('updated', 'ردّ على تقييم', [
            'subject_id' => $review->id,
            'subject_type' => 'review',
        ]);

        /*
         * ═══ و«نُشر الردّ» عمّا لا يقرؤه أحد كذبة ═══
         *
         * ردٌّ محجوبٌ لا يبلغ زبونًا، والشاشةُ كانت تقول «نُشر الردّ» خضراءَ
         * فيطمئنّ صاحبُ المحلّ إلى أنّه أجاب زبونًا غاضبًا وهو لم يرَ حرفًا.
         *
         * وحُجب الردُّ لسببين لا سبب: تقييمٌ **مرفوض**، وتقييمٌ **نجومٌ بلا
         * كلام** — وهذا الثاني بقي على عطبه بعد أن عولج الأوّل، لأنّ الشرطَ
         * كان مكتوبًا هنا بيدٍ بدل أن يُسأل.
         *
         * فيُسأل الشرطُ نفسُه الذي يرسم به الموقعُ صفحتَه — `scopeShowable`.
         * ومن بدّله بدّل الجوابَين معًا.
         */
        $read = Review::whereKey($review->id)->showable()->exists();

        return back()->with('toast', $read
            ? ['msg' => __('نُشر الردّ'), 'type' => 'success']
            : ['msg' => $this->whyUnread($review->fresh()), 'type' => 'warning']);
    }

    /** ولمَ لن يُقرأ هذا الردّ — بحرفه لا بعبارةٍ عامّة */
    private function whyUnread(Review $review): string
    {
        return $review->status !== 'منشور'
            ? __('حُفظ الردّ — ولن يقرأه أحد: التقييم مرفوضٌ ومحجوب. انشره ليظهرا معًا.')
            : __('حُفظ الردّ — ولن يقرأه أحد: التقييم نجومٌ بلا كلام، ولا يُعرض في قسم الآراء إلا ما فيه تعليق.');
    }

    public function destroy($id)
    {
        $review = Review::where('business_id', $this->bid())->findOrFail($id);

        Activity::log('deleted', 'حذف تقييمًا', ['subject_id' => $review->id]);
        $review->delete();

        return back()->with('toast', ['msg' => __('حُذف التقييم'), 'type' => 'warning']);
    }
}
