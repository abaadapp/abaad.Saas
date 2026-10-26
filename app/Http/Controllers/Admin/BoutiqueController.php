<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\SettlementRefused;
use App\Http\Controllers\Controller;
use App\Models\Boutique;
use App\Models\BoutiqueReturn;
use App\Models\BoutiqueSettlement;
use App\Models\Business;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Support\BoutiqueDues;
use App\Support\BoutiqueReturns;
use App\Support\Boutiques;
use App\Support\Demo;
use App\Support\Search;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * البوتيكات — تبويبٌ تحت «المنتجات» لا قسمٌ في الشريط.
 *
 * فهي طبقةٌ فوق الأصناف كما «المواسم»: من يفتح المنتجات يفتحها، ومن لا
 * يُؤوي بوتيكاتٍ لا يراها أصلًا (`Boutiques::enforce`).
 */
class BoutiqueController extends Controller
{
    private function bid(): int
    {
        return (int) (auth()->user()->business_id ?? Demo::bid());
    }

    private function shop(): Business
    {
        $business = Business::findOrFail($this->bid());

        Boutiques::enforce($business);

        return $business;
    }

    private function find(int $id): Boutique
    {
        return Boutique::where('business_id', $this->bid())->findOrFail($id);
    }

    /* ═══════════ القائمة ═══════════ */

    public function index()
    {
        $business = $this->shop();
        $period = Boutiques::period(request()->query('period'));

        /*
         * وعدّادُ الأصناف ومبيعاتُ الشهر في الصفّ نفسه.
         *
         * «كم صنفًا له» سؤالُ إدارة، و«كم باع هذا الشهر» سؤالُ المال — ومن
         * فتح الشاشة آخرَ الشهر يسأل الثاني. فيُقرآن معًا بلا فتح كلّ صفّ.
         */
        $sold = $this->monthlyGross($business->id, $period);

        /*
         * وما ينتظر ورقةً غيرُ ما بِيع.
         *
         * صار الشهرُ يُسوَّى على دفعات، فوجودُ ورقةٍ لا يعني أنّ الشهرَ
         * أُغلق: تُصدَر في العاشر ثمّ يُباع في الحادي عشر. و«سُوّي هذا
         * الشهر» على صفٍّ ينتظر مالًا تجعل صاحبَه يمرّ عليه ولا يفتحه.
         */
        $pending = $this->monthlyGross($business->id, $period, true);

        $rows = Boutique::where('business_id', $business->id)
            ->withCount('products')
            ->orderByDesc('active')->orderBy('name')
            ->get()
            ->map(fn (Boutique $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'name_en' => $b->name_en,
                'phone' => $b->phone,
                'contact_person' => $b->contact_person,
                'rate' => (float) $b->commission_rate,
                'active' => (bool) $b->active,
                // وما تحمله نافذةُ التعديل كلُّه — حقلٌ لا يصلها يُرسَل فارغًا فيُمحى
                'notes' => $b->notes,
                'returns_terms' => $b->returns_terms,
                'returns_terms_ref' => $b->returns_terms_ref,
                'products_count' => (int) $b->products_count,
                'gross' => round((float) ($sold[$b->id] ?? 0), 3),
                // وكم ينتظر ورقةً منه — به يُعرف الصفُّ الذي يُفتح
                'pending' => round((float) ($pending[$b->id] ?? 0), 3),
                // وأصدرتْ ورقةً في هذا الشهر؟ — خبرٌ عن المضى لا عن الانتظار
                'settled' => BoutiqueSettlement::where('business_id', $business->id)
                    ->where('boutique_id', $b->id)->where('period', $period)->exists(),
            ])->all();

        return Inertia::render('Admin/Boutiques/Index', [
            'boutiques' => $rows,
            'period' => $period,
            'periods' => $this->periodOptions(),
        ]);
    }

    /* ═══════════ كشفُ الحساب ═══════════ */

    public function show(int $id)
    {
        $business = $this->shop();
        $boutique = $this->find($id);
        $period = Boutiques::period(request()->query('period'));

        $statement = Boutiques::statement($business->id, $boutique->id, $period);

        /*
         * وآخرُ ورقةٍ لا أوّلُها.
         *
         * صار الشهرُ يحمل ورقاتٍ، و`first()` بلا ترتيبٍ تُعطي أقدمَها —
         * فيقرأ صاحبُ المحلّ ورقةً سُدِّدت من زمنٍ ويظنّها آخرَ ما صدر.
         */
        $settlement = BoutiqueSettlement::where('business_id', $business->id)
            ->where('boutique_id', $boutique->id)->where('period', $period)
            ->with('expense:id,status,amount,reference')->latest('id')->first();

        return Inertia::render('Admin/Boutiques/Show', [
            'boutique' => [
                'id' => $boutique->id,
                'name' => $boutique->name,
                'name_en' => $boutique->name_en,
                'phone' => $boutique->phone,
                'contact_person' => $boutique->contact_person,
                'rate' => (float) $boutique->commission_rate,
                'active' => (bool) $boutique->active,
                'notes' => $boutique->notes,
                'returns_terms' => $boutique->returns_terms,
                'returns_terms_ref' => $boutique->returns_terms_ref,
            ],
            'statement' => $statement,
            // مرتجعاتٌ بعد الورقة وما ينتظر منها — انظر `BoutiqueReturns`
            'returns' => $this->returnsOf((int) $business->id, $boutique),
            'period' => $period,
            'periods' => $this->periodOptions(),
            /*
             * أصنافُ هذا البوتيك — وهي غيرُ «المنتجات المباعة» أسفلَ الكشف.
             *
             * ذاك جدولُ شهرٍ مضى، وهذا ما يحمله البوتيكُ اليوم: يُقرأ ليُعرف
             * ما عنده، ويُنزع منه صنفٌ رُدّ إلى صاحبه. ولولاه لبقي الإسنادُ
             * في بطاقة كلّ صنفٍ على حدة — وصاحبُ البوتيك يأتي بعشرين قطعة.
             */
            'products' => $this->attached($business->id, $boutique->id),
            /*
             * وما يُختار منه — مُرشَّحًا في الخادم لا مُرسَلًا كلُّه.
             *
             * كتالوجُ محلٍّ يكبر، وإرسالُه كلَّه في كلّ فتحةِ شاشةٍ حِملٌ
             * يثقل مع كلّ صنفٍ يُضاف. فيُبحث فيه من هنا (`only: ['catalog']`)
             * ويُقطع عند حدٍّ يُقال للقارئ أنّه قُطع.
             */
            'catalog' => $this->catalog($business->id),
            'q' => Search::term(request()),
            /*
             * وأُغلق الشهر؟ — في الكشف نفسِه (`partial`) لا في خاصيّةٍ ثانية.
             *
             * كانت `closed` هنا و`partial` هناك يقولان الشيء نفسَه مقلوبًا،
             * وخاصيّتان لسؤالٍ واحد تفترقان يومًا. والجوابُ من الخادم في
             * الحالين: «الشهرُ الجاري» عند متصفّحٍ على توقيتٍ آخر غيرُه عند
             * الخادم الذي يردّ الطلب.
             */
            'settlement' => $settlement ? [
                'id' => $settlement->id,
                'number' => $settlement->number,
                'net' => (float) $settlement->net,
                'gross' => (float) $settlement->gross,
                'commission' => (float) $settlement->commission,
                'issued_at' => optional($settlement->issued_at)->format('Y-m-d H:i'),
                'paid' => $settlement->paid(),
                'expense_reference' => $settlement->expense?->reference,
                ...self::componentsOf($settlement),
            ] : null,
            // الرصيدُ بين المتجر والبوتيك وحركاتُه — لمن فُتح له سجلُّ المكوّنات وحده
            'ledger' => BoutiqueDues::active((int) $business->id) ? BoutiqueDues::ledgerFor((int) $business->id, (int) $boutique->id) : null,
            /*
             * وتاريخُ ما صدر — فيُقرأ ما مضى بلا تنقّلٍ بين الشهور.
             */
            'history' => BoutiqueSettlement::where('business_id', $business->id)
                ->where('boutique_id', $boutique->id)->with('expense:id,status')
                ->orderByDesc('period')->limit(24)->get()
                ->map(fn (BoutiqueSettlement $s) => [
                    'id' => $s->id, 'number' => $s->number, 'period' => $s->period,
                    'gross' => (float) $s->gross, 'commission' => (float) $s->commission,
                    'net' => (float) $s->net, 'paid' => $s->paid(),
                    ...self::componentsOf($s),
                ])->all(),
        ]);
    }

    /* ═══════════ الإنشاء والتعديل ═══════════ */

    public function store(Request $request)
    {
        $business = $this->shop();

        Boutique::create($this->validated($request) + ['business_id' => $business->id]);

        return back()->with('toast', ['msg' => __('أُضيف البوتيك'), 'type' => 'success']);
    }

    public function update(Request $request, int $id)
    {
        $this->shop();
        $boutique = $this->find($id);

        /*
         * ═══ والنسبةُ تتغيّر لِما يأتي لا لِما مضى ═══
         *
         * بنودُ ما بِيع تحمل نسبتَها ساعتَها (`order_items.boutique_rate`)،
         * فرفعُها اليوم لا يُعيد حسبةَ كشفِ الشهر الماضي. وهذا مقصودٌ
         * ومحروس — انظر ترويسة الهجرة.
         */
        $before = [$boutique->returns_terms, $boutique->returns_terms_ref];
        $boutique->update($this->validated($request));

        // وشرطُ المرتجع يحسم مَن يتحمّل — فمن غيّره ومتى يُقرأ في السجلّ
        if ($before !== [$boutique->returns_terms, $boutique->returns_terms_ref]) {
            \App\Support\Activity::log('updated', 'شرط مرتجع البوتيك '.$boutique->name.': '.($boutique->returns_terms ?? 'غير موثّق')
                .($boutique->returns_terms_ref ? ' — '.$boutique->returns_terms_ref : ''), ['subject_id' => $boutique->id, 'subject_type' => 'boutique']);
        }

        return back()->with('toast', ['msg' => __('حُفظ البوتيك'), 'type' => 'success']);
    }

    /**
     * يُحذف البوتيك — وتبقى أصنافُه على الرفّ وبيعاتُه في الدفتر.
     *
     * فالبضاعةُ الموجودةُ فعلًا لا تُمحى بحذف صفٍّ إداريّ، والبيعةُ التي
     * وقعت تحمل اسمَه لقطةً فلا تفقد معناها.
     */
    public function destroy(int $id)
    {
        $this->shop();
        $boutique = $this->find($id);

        if (BoutiqueSettlement::where('boutique_id', $boutique->id)->whereHas('expense', fn ($q) => $q
            ->where('status', '!=', Expense::PAID))->exists()) {
            return back()->withErrors(['boutique' => __('عليه تسويةٌ لم تُسدَّد — سدّدها قبل حذفه.')]);
        }

        if (BoutiqueReturn::where('boutique_id', $boutique->id)->whereIn('status', BoutiqueReturn::OPEN)->exists()) {
            return back()->withErrors(['boutique' => __('له مرتجعاتٌ لم تُحسم أو لم تُستردّ — أتمّها قبل حذفه.')]);
        }

        $boutique->delete();

        return back()->with('toast', ['msg' => __('حُذف البوتيك — وبقيت أصنافه على الرفّ'), 'type' => 'success']);
    }

    /** جزءُ المكوّنات في ورقة — ونوعُها: تسويةٌ أم سندُ استرداد */
    private static function componentsOf(BoutiqueSettlement $s): array
    {
        return [
            'kind' => $s->kind ?? BoutiqueSettlement::SETTLEMENT,
            'components_due' => (float) $s->components_due,
            'components_credit' => (float) $s->components_credit,
            'components_net' => (float) $s->components_net,
            'components_paid' => $s->components_paid_at !== null || (float) $s->components_due <= 0,
            'standalone_paid' => (int) $s->lines_count === 0 || ($s->expense !== null && $s->expense->status === \App\Models\Expense::PAID),
        ];
    }

    /* ═══════════ التسوية ═══════════ */

    /** طرقُ السداد والاسترداد — ما يعرفه `Books::payingAccount` */
    private const METHODS = ['نقدي', 'تحويل', 'بطاقة', 'شيك'];

    /**
     * سدادُ جزء المكوّنات من ورقة — مدين 2160، ودائن 1370 بما قاصّته، والنقدُ بالباقي.
     *
     * وجزءُ المنفرد لا يُسدَّد من هنا: مصروفُه في «المبالغ المستحقة» كما كان.
     */
    public function payComponents(Request $request, int $id, int $settlement)
    {
        $business = $this->shop();
        $boutique = $this->find($id);
        abort_unless(BoutiqueDues::active((int) $business->id), 404);
        $data = $request->validate(['method' => ['required', 'string', \Illuminate\Validation\Rule::in(self::METHODS)]]);

        $doc = BoutiqueSettlement::where('business_id', $business->id)->where('boutique_id', $boutique->id)
            ->where('kind', BoutiqueSettlement::SETTLEMENT)->findOrFail($settlement);
        BoutiqueDues::pay($doc, $data['method']);

        return back()->with('toast', ['msg' => __('سُجّل سدادُ مستحقّات المكوّنات في :number', ['number' => $doc->number]), 'type' => 'success']);
    }

    /** سندُ استرداد: البوتيكُ ردّ نقدًا ما للمتجر عليه — كلَّه أو بعضَه */
    public function refund(Request $request, int $id)
    {
        $business = $this->shop();
        $boutique = $this->find($id);
        abort_unless(BoutiqueDues::active((int) $business->id), 404);
        $data = $request->validate([
            'method' => ['required', 'string', \Illuminate\Validation\Rule::in(self::METHODS)],
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
        ]);

        try {
            $doc = BoutiqueDues::refund($business, $boutique, $data['method'], null, auth()->user()?->name,
                isset($data['amount']) ? (float) $data['amount'] : null);
        } catch (SettlementRefused $e) {
            return back()->withErrors(['refund' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('سُجّل الاسترداد :number', ['number' => $doc->number]), 'type' => 'success']);
    }

    /* ═══════════ مرتجعٌ بعد الورقة ═══════════ */

    /**
     * قرارُ المحاسب في مرتجعٍ لا يحسمه شرطٌ موثَّق — على البوتيك أو على المتجر، بسببه.
     *
     * بقسم المصروفات لا بقسم المنتجات وحده: هو من يسدّد الأوراق، والقرارُ
     * يُنقص مستحقًّا أو يُنشئ مطالبة.
     */
    public function reviewReturn(Request $request, int $id)
    {
        $business = $this->shop();
        $boutique = $this->find($id);
        abort_unless(auth()->user()?->allows('expenses'), 403);

        $data = $request->validate([
            'note' => ['required', 'integer'],
            'bearer' => ['required', \Illuminate\Validation\Rule::in(['boutique', 'shop'])],
            'why' => ['required', 'string', 'max:500'],
        ]);

        $note = BoutiqueReturn::where('business_id', $business->id)->where('boutique_id', $boutique->id)->findOrFail($data['note']);

        try {
            BoutiqueReturns::review($note, $data['bearer'], $data['why']);
        } catch (SettlementRefused $e) {
            return back()->withErrors(['returns' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('حُسم المرتجع'), 'type' => 'success']);
    }

    /** استلامٌ من البوتيك بمستندٍ ومرجع — يُطفئ مطالباتِه الأقدمَ أوّلًا */
    public function returnReceipt(Request $request, int $id)
    {
        $business = $this->shop();
        $boutique = $this->find($id);
        abort_unless(auth()->user()?->allows('expenses'), 403);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'method' => ['required', 'string', \Illuminate\Validation\Rule::in(self::METHODS)],
            'reference' => ['required', 'string', 'max:120'],
        ]);

        try {
            BoutiqueReturns::receipt($business, $boutique, (float) $data['amount'], $data['method'], $data['reference']);
        } catch (SettlementRefused $e) {
            return back()->withErrors(['returns' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('سُجّل الاستلام من البوتيك'), 'type' => 'success']);
    }

    /** خصمُ المطالبات المفتوحة من ورقةٍ لاحقة لهذا البوتيك لم تُسدَّد */
    public function returnOffset(Request $request, int $id)
    {
        $business = $this->shop();
        $boutique = $this->find($id);
        abort_unless(auth()->user()?->allows('expenses'), 403);

        $data = $request->validate(['settlement' => ['required', 'integer']]);
        $target = BoutiqueSettlement::where('business_id', $business->id)->where('boutique_id', $boutique->id)->findOrFail($data['settlement']);

        try {
            $total = BoutiqueReturns::offset($business, $boutique, $target);
        } catch (SettlementRefused $e) {
            return back()->withErrors(['returns' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('خُصم :amount من الورقة :number', ['amount' => number_format($total, 3), 'number' => $target->number]), 'type' => 'success']);
    }

    /**
     * مرتجعاتُ البوتيك بعد أوراقها — وما يُخصم منه وما يُفعل.
     *
     * @return array{notes: list<array<string, mixed>>, open: float, review: int, targets: list<array<string, mixed>>, can_decide: bool}
     */
    private function returnsOf(int $businessId, Boutique $boutique): array
    {
        $notes = BoutiqueReturn::where('business_id', $businessId)->where('boutique_id', $boutique->id)
            ->with(['settlement:id,number', 'order:id,number'])->latest('id')->limit(100)->get();

        // أوراقٌ لم تُسدَّد قُيّدت يومَ صدورها — منها وحدها يُخصم
        $targets = BoutiqueSettlement::where('business_id', $businessId)->where('boutique_id', $boutique->id)
            ->where('kind', BoutiqueSettlement::SETTLEMENT)->whereNotNull('expense_id')->with('expense')->latest('id')->limit(24)->get()
            ->filter(fn (BoutiqueSettlement $s) => $s->expense && ! $s->expense->isPaid() && Boutiques::accrual($s->expense) !== null)
            ->map(fn (BoutiqueSettlement $s) => ['id' => $s->id, 'number' => $s->number, 'payable' => BoutiqueReturns::payable($s->expense)])
            ->values()->all();

        return [
            'notes' => $notes->map(fn (BoutiqueReturn $n) => [
                'id' => $n->id,
                'order' => $n->order?->number,
                'sheet' => $n->settlement?->number,
                'name' => $n->name,
                'quantity' => (int) $n->quantity,
                'amount' => (float) $n->amount,
                'condition' => $n->condition,
                'status' => $n->status,
                'basis' => $n->basis,
                'terms_ref' => $n->terms_ref,
                'decision_note' => $n->decision_note,
                'sold_at' => optional($n->sold_at)->format('Y-m-d'),
                'returned_at' => optional($n->returned_at)->format('Y-m-d'),
                'booked_at' => optional($n->booked_at)->format('Y-m-d'),
                'remaining' => $n->status === BoutiqueReturn::CLAIM ? BoutiqueReturns::remaining($n) : 0.0,
            ])->all(),
            'open' => round(BoutiqueReturns::claims($businessId, (int) $boutique->id)->sum(fn ($c) => BoutiqueReturns::remaining($c)), 3),
            'review' => $notes->where('status', BoutiqueReturn::REVIEW)->count(),
            'targets' => $targets,
            'can_decide' => (bool) auth()->user()?->allows('expenses'),
        ];
    }

    public function settle(Request $request, int $id)
    {
        $business = $this->shop();
        $boutique = $this->find($id);
        $period = Boutiques::period($request->input('period'));

        try {
            $settlement = Boutiques::settle($business, $boutique, $period, auth()->user()?->name);
        } catch (SettlementRefused $e) {
            // وما ليس رفضًا يصعد: عطبُ القاعدة يُسجَّل ولا يُعرض جوابًا
            return back()->withErrors(['settlement' => $e->getMessage()]);
        }

        return back()->with('toast', [
            'msg' => __('صدرت التسوية :number — ودخلت المبالغ المستحقة', ['number' => $settlement->number]),
            'type' => 'success',
        ]);
    }

    /* ═══════════ ربطُ الأصناف ═══════════ */

    /**
     * يُسند صنفٌ إلى بوتيك أو يُنزع منه — من شاشة البوتيك نفسها.
     *
     * وشاشةُ الصنف تفعلها أيضًا (حقلٌ في بطاقته). وهما بابان لبابٍ واحد:
     * من يُدخل بضاعةَ بوتيكٍ دفعةً واحدة يريد شاشتَه، ومن يُصحّح صنفًا
     * واحدًا يريد بطاقته.
     */
    public function attach(Request $request, int $id)
    {
        $business = $this->shop();
        $boutique = $this->find($id);

        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer'],
            'detach' => ['nullable', 'boolean'],
        ]);

        $detach = (bool) ($data['detach'] ?? false);

        /*
         * ولا يُنقل صنفٌ من بوتيكٍ إلى بوتيكٍ في صمت.
         *
         * `update` لا تسأل عمّا كان: صنفُ «دانة» يصير صنفَ «ريم» بضغطةٍ من
         * شاشة ريم، ولا أحد يُخبر دانة. وكشفُ حسابها بعدها ينقص صنفًا كانت
         * تبيعه — لا لأنّه رُدّ إليها بل لأنّه نُسب لغيرها.
         *
         * فمن أراد النقلَ ينزعه من الأوّل ثمّ يُسنده إلى الثاني: خطوتان
         * يراهما صاحبُهما، لا خطوةٌ واحدةٌ لا تُرى. والشاشةُ تمنع اختيارَه
         * أصلًا وتقول أين هو — وهذا الحاجزُ لمن لم يمرّ بالشاشة.
         */
        if (! $detach) {
            $held = Product::where('business_id', $business->id)
                ->whereIn('id', $data['product_ids'])
                ->whereNotNull('boutique_id')
                ->where('boutique_id', '!=', $boutique->id)
                ->count();

            if ($held > 0) {
                throw ValidationException::withMessages([
                    'product_ids' => __(':n صنفًا مرتبطًا ببوتيكٍ آخر — انزعه من بوتيكه أوّلًا.', ['n' => $held]),
                ]);
            }
        }

        $n = Product::where('business_id', $business->id)
            ->whereIn('id', $data['product_ids'])
            ->when($detach, fn ($q) => $q->where('boutique_id', $boutique->id))
            ->update(['boutique_id' => $detach ? null : $boutique->id]);

        return back()->with('toast', [
            'msg' => $detach
                ? __(':n صنفًا نُزعت من البوتيك', ['n' => $n])
                : __(':n صنفًا أُسندت إلى البوتيك', ['n' => $n]),
            'type' => 'success',
        ]);
    }

    /* ═══════════ مساعدات ═══════════ */

    /** حدُّ ما يُرسل من الكتالوج في الفتحة الواحدة — ويُقال للقارئ إن قُطع */
    private const PICK = 50;

    /**
     * أصنافُ البوتيك كما تُقرأ في شاشته — استعلامٌ واحد بلا استعلامٍ لكلّ صفّ.
     *
     * @return list<array<string, mixed>>
     */
    private function attached(int $businessId, int $boutiqueId): array
    {
        return Product::where('business_id', $businessId)
            ->where('boutique_id', $boutiqueId)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'active', 'quantity', 'alert_qty', 'tracks_stock'])
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'active' => (bool) $p->active,
                // ومن لا يمسك جردًا لا رقمَ له: صفرٌ يُقرأ «نفد» وهو لم ينفد
                'quantity' => $p->tracksStock() ? (int) $p->quantity : null,
                'stock' => $p->tracksStock()
                    ? Product::statusFor((int) $p->quantity, (int) $p->alert_qty)
                    : null,
            ])->all();
    }

    /**
     * ما يُعرض في نافذة الاختيار — أصنافُ هذا المحلّ وحدَه.
     *
     * والمحذوفُ ناعمًا خارجٌ من نفسه: النموذجُ عليه `SoftDeletes`. ويُقال عن
     * كلّ صنفٍ أين هو الآن، فلا يُختار ما هو عند بوتيكٍ آخر بلا علمِ مختاره.
     *
     * وأسماءُ البوتيكات تُقرأ دفعةً واحدة: جدولٌ صغيرٌ وصفٌّ لكلّ منها، لا
     * استعلامٌ لكلّ صنفٍ في القائمة.
     *
     * @return array{rows: list<array<string, mixed>>, more: bool}
     */
    private function catalog(int $businessId): array
    {
        $term = Search::term(request());

        $rows = Product::where('business_id', $businessId)
            ->when($term !== '', fn ($q) => $q->where(function ($w) use ($term) {
                $like = Search::like();
                $w->where('name', $like, '%'.$term.'%')
                    ->orWhere('name_en', $like, '%'.$term.'%')
                    ->orWhere('sku', $like, '%'.$term.'%');
            }))
            ->orderBy('name')
            ->limit(self::PICK + 1)
            ->get(['id', 'name', 'sku', 'boutique_id']);

        $more = $rows->count() > self::PICK;
        $names = Boutique::where('business_id', $businessId)->pluck('name', 'id');

        return [
            'rows' => $rows->take(self::PICK)->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'boutique_id' => $p->boutique_id ? (int) $p->boutique_id : null,
                'boutique_name' => $p->boutique_id ? ($names[$p->boutique_id] ?? null) : null,
            ])->values()->all(),
            'more' => $more,
        ];
    }

    /**
     * إجماليُّ ما بِيع لكلّ بوتيك في الشهر — استعلامٌ واحد لا استعلامٌ لكلّ صفّ.
     *
     * @return array<int, float>
     */
    private function monthlyGross(int $businessId, string $period, bool $pendingOnly = false): array
    {
        $from = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfMonth();
        $to = (clone $from)->endOfMonth();

        return OrderItem::query()
            ->whereNotNull('order_items.boutique_id')
            // وما ينتظر ورقةً هو ما لا ختمَ عليه — انظر `Boutiques::unsettled`
            ->when($pendingOnly, fn ($q) => $q->whereNull('order_items.boutique_settlement_id'))
            ->whereIn('order_items.order_id', Order::where('business_id', $businessId)->sold()
                ->whereBetween('ordered_at', [$from, $to])->select('id'))
            ->selectRaw('order_items.boutique_id, COALESCE(SUM(order_items.total), 0) as gross')
            ->groupBy('order_items.boutique_id')
            ->pluck('gross', 'boutique_id')
            ->map(fn ($v) => (float) $v)->all();
    }

    /**
     * الشهورُ التي تُقرأ: اثنا عشر مضت والشهرُ الجاري.
     *
     * ولا تُبنى في الشاشة: قائمةٌ تُحسب في المتصفّح تختلف عن شهر الخادم
     * لمن جهازُه على توقيتٍ آخر، فيفتح كشفًا لشهرٍ غير الذي اختار.
     *
     * @return list<array{value: string, label: string}>
     */
    private function periodOptions(): array
    {
        $out = [];

        for ($i = 0; $i < 13; $i++) {
            $m = now()->startOfMonth()->subMonths($i);
            $out[] = ['value' => $m->format('Y-m'), 'label' => $m->translatedFormat('F Y')];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            /*
             * والنسبةُ بين صفرٍ ومئة.
             *
             * فوق المئة يعني أنّ المحلّ يأخذ أكثرَ ممّا بِيع فيصير الصافي
             * سالبًا — دَينًا على البوتيك لا له. وهو رقمٌ يُكتب سهوًا
             * (١٢٠ بدل ١٢) ولا يُكتشف إلّا في كشف آخر الشهر.
             */
            'commission_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            /*
             * وشرطُ المرتجع في الاتّفاقية بمرجعه — شرطٌ بلا مرجعٍ لا يُوثَّق.
             *
             * وبلا شرطٍ لا يُفترض أنّ البوتيك يتحمّل: يُعرض كلُّ مرتجعٍ بعد
             * ورقته للمراجعة المحاسبيّة (`BoutiqueReturns::settle`).
             */
            'returns_terms' => ['nullable', \Illuminate\Validation\Rule::in(BoutiqueReturns::TERMS)],
            'returns_terms_ref' => ['nullable', 'required_with:returns_terms', 'string', 'max:255'],
        ], [
            'name.required' => __('اسم البوتيك مطلوب'),
            'commission_rate.max' => __('النسبة لا تتجاوز ١٠٠٪ — وإلا صار الصافي دَينًا على البوتيك.'),
        ]) + ['active' => (bool) $request->boolean('active', true)];
    }
}
