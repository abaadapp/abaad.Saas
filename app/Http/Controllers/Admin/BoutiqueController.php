<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\SettlementRefused;
use App\Http\Controllers\Controller;
use App\Models\Boutique;
use App\Models\BoutiqueSettlement;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Support\Boutiques;
use App\Support\Demo;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
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
                'products_count' => (int) $b->products_count,
                'gross' => round((float) ($sold[$b->id] ?? 0), 3),
                // وأصدرتْ تسويةَ هذا الشهر؟ — فلا يُصدرها مرّتين من لا يذكر
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

        $settlement = BoutiqueSettlement::where('business_id', $business->id)
            ->where('boutique_id', $boutique->id)->where('period', $period)
            ->with('expense:id,status,amount,reference')->first();

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
            ],
            'statement' => $statement,
            'period' => $period,
            'periods' => $this->periodOptions(),
            'settlement' => $settlement ? [
                'id' => $settlement->id,
                'number' => $settlement->number,
                'net' => (float) $settlement->net,
                'gross' => (float) $settlement->gross,
                'commission' => (float) $settlement->commission,
                'issued_at' => optional($settlement->issued_at)->format('Y-m-d H:i'),
                'paid' => $settlement->paid(),
                'expense_reference' => $settlement->expense?->reference,
            ] : null,
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
        $boutique->update($this->validated($request));

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
            ->where('status', '!=', \App\Models\Expense::PAID))->exists()) {
            return back()->withErrors(['boutique' => __('عليه تسويةٌ لم تُسدَّد — سدّدها قبل حذفه.')]);
        }

        $boutique->delete();

        return back()->with('toast', ['msg' => __('حُذف البوتيك — وبقيت أصنافه على الرفّ'), 'type' => 'success']);
    }

    /* ═══════════ التسوية ═══════════ */

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

    /**
     * إجماليُّ ما بِيع لكلّ بوتيك في الشهر — استعلامٌ واحد لا استعلامٌ لكلّ صفّ.
     *
     * @return array<int, float>
     */
    private function monthlyGross(int $businessId, string $period): array
    {
        $from = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfMonth();
        $to = (clone $from)->endOfMonth();

        return OrderItem::query()
            ->whereNotNull('order_items.boutique_id')
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
        ], [
            'name.required' => __('اسم البوتيك مطلوب'),
            'commission_rate.max' => __('النسبة لا تتجاوز ١٠٠٪ — وإلا صار الصافي دَينًا على البوتيك.'),
        ]) + ['active' => (bool) $request->boolean('active', true)];
    }
}
