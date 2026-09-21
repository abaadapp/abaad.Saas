<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Season;
use App\Models\SeasonReminder;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\Seasons;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * المواسم — تبويبٌ في قسم المنتجات.
 *
 * كلُّ استعلامٍ هنا محصورٌ بالمتجر، والصنفُ الذي يُربط يُتحقَّق أنّه لهذا
 * المتجر في الخادم لا في الشاشة. ولا يُمسّ صفُّ منتجٍ من هنا بحال: الربطُ
 * صفٌّ في جدول الصلة، والفكُّ حذفُه، والموسمُ يُحذف ويبقى الصنف.
 */
class SeasonController extends Controller
{
    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    private function mine(int $id): Season
    {
        return Season::where('business_id', $this->bid())->findOrFail($id);
    }

    public function index(Request $request)
    {
        $filter = (string) $request->query('status', 'all');
        $today = today();

        $seasons = Season::where('business_id', $this->bid())
            ->withCount(['products', 'reminders'])
            ->with('reminders')
            ->orderByDesc('starts_at')->orderByDesc('id')
            ->get()
            ->map(fn (Season $s) => $this->row($s, $today))
            ->filter(fn (array $r) => $filter === 'all' || $r['status'] === $filter)
            ->values();

        $all = Season::where('business_id', $this->bid())->get();
        $counts = ['all' => $all->count()];
        foreach ([Season::UPCOMING, Season::ACTIVE, Season::ENDED, Season::INACTIVE] as $st) {
            $counts[$st] = $all->filter(fn (Season $s) => $s->status($today) === $st)->count();
        }

        return Inertia::render('Admin/Seasons/Index', [
            'seasons' => $seasons,
            'filter' => $filter,
            'counts' => $counts,
        ]);
    }

    public function show(int $id)
    {
        $season = $this->mine($id);
        $season->load(['reminders', 'products' => fn ($q) => $q->with('category:id,name,name_en')->orderBy('name')]);
        $today = today();

        return Inertia::render('Admin/Seasons/Show', [
            'season' => $this->row($season, $today) + [
                'products' => $season->products->map(fn (Product $p) => $this->productRow($p))->values()->all(),
                'reminders' => $season->reminders->map(fn (SeasonReminder $r) => $this->reminderRow($r, $season))->values()->all(),
            ],
            'maxReminders' => Seasons::MAX_REMINDERS,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $season = Season::create($data + ['business_id' => $this->bid(), 'created_by' => auth()->id()]);
        Activity::log('created', 'أضاف موسمًا: '.$season->name);

        return redirect()->route('admin.seasons.show', $season->id)
            ->with('toast', ['msg' => __('تم إنشاء الموسم'), 'type' => 'success']);
    }

    public function update(Request $request, int $id)
    {
        $season = $this->mine($id);
        $season->update($this->validated($request));
        Activity::log('updated', 'عدّل الموسم: '.$season->name);

        return back()->with('toast', ['msg' => __('تم تحديث الموسم'), 'type' => 'success']);
    }

    /**
     * حذفُ الموسم يسحب صلاتِه وتذكيراتِه معه — ولا يمسّ صنفًا ولا طلبًا ولا
     * مخزونًا ولا مالًا: مفاتيحُ الجدولين الأجنبيّةُ تُسقط صفوفَهما وحدَها.
     */
    public function destroy(int $id)
    {
        $season = $this->mine($id);
        $name = $season->name;
        $season->delete();
        Activity::log('deleted', 'حذف الموسم: '.$name);

        return redirect()->route('admin.seasons.index')
            ->with('toast', ['msg' => __('تم حذف الموسم'), 'type' => 'success']);
    }

    /* ═══════════ الأصناف ═══════════ */

    /** مُنتقي الأصناف — أصنافُ هذا المتجر وحدَها، وما ليس في الموسم بعد */
    public function products(Request $request, int $id)
    {
        $season = $this->mine($id);
        $already = $season->products()->pluck('products.id')->all();

        return response()->json([
            'products' => Seasons::pickable($this->bid(), (string) $request->query('q', ''), $already)
                ->map(fn (Product $p) => $this->productRow($p))->values()->all(),
        ]);
    }

    public function attach(Request $request, int $id)
    {
        $season = $this->mine($id);

        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1', 'max:200'],
            'product_ids.*' => ['integer'],
        ]);

        /*
         * والمعرّفاتُ لا تُصدَّق: تُرشَّح بأصناف هذا المتجر. صنفٌ من متجرٍ
         * آخر يسقط صامتًا لا يُربط — فلا يرى تاجرٌ صنفَ جاره ولو خمّن رقمه.
         */
        $ids = Product::where('business_id', $this->bid())
            ->whereIn('id', $data['product_ids'])->pluck('id')->all();

        $season->products()->syncWithoutDetaching($ids);

        return back()->with('toast', ['msg' => __('أُضيفت الأصناف إلى الموسم'), 'type' => 'success']);
    }

    public function detach(int $id, int $productId)
    {
        $season = $this->mine($id);
        // فكُّ صلةٍ لا حذفُ صنف: الصفُّ في `products` لا يُمسّ
        $season->products()->detach($productId);

        return back()->with('toast', ['msg' => __('أُزيل الصنف من الموسم'), 'type' => 'success']);
    }

    /* ═══════════ التذكيرات ═══════════ */

    public function storeReminder(Request $request, int $id)
    {
        $season = $this->mine($id);

        if ($season->reminders()->count() >= Seasons::MAX_REMINDERS) {
            return back()->withErrors(['message' => __('بلغ الموسم أقصى عدد من التذكيرات (:n).', ['n' => Seasons::MAX_REMINDERS])]);
        }

        $data = $request->validate([
            'type' => ['required', Rule::in(SeasonReminder::TYPES)],
            'days_before' => ['required_if:type,relative', 'nullable', 'integer', 'min:0', 'max:'.SeasonReminder::MAX_DAYS_BEFORE],
            'remind_at' => ['required_if:type,fixed', 'nullable', 'date'],
            'message' => ['required', 'string', 'max:500'],
        ], [], ['days_before' => __('عدد الأيام'), 'remind_at' => __('التاريخ'), 'message' => __('نص التذكير')]);

        $season->reminders()->create([
            'business_id' => $this->bid(),
            'type' => $data['type'],
            'days_before' => $data['type'] === SeasonReminder::RELATIVE ? (int) $data['days_before'] : null,
            'remind_at' => $data['type'] === SeasonReminder::FIXED ? $data['remind_at'] : null,
            'message' => $data['message'],
            'active' => true,
        ]);

        return back()->with('toast', ['msg' => __('أُضيف التذكير'), 'type' => 'success']);
    }

    public function updateReminder(Request $request, int $id, int $reminderId)
    {
        $season = $this->mine($id);
        $reminder = $season->reminders()->findOrFail($reminderId);
        $reminder->update(['active' => $request->boolean('active')]);

        return back();
    }

    public function destroyReminder(int $id, int $reminderId)
    {
        $season = $this->mine($id);
        $season->reminders()->findOrFail($reminderId)->delete();

        return back()->with('toast', ['msg' => __('حُذف التذكير'), 'type' => 'success']);
    }

    /* ═══════════ أدوات ═══════════ */

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
            'starts_at' => ['required', 'date'],
            /* والنهايةُ لا تسبق البداية — موسمٌ ينتهي قبل أن يبدأ ليس موسمًا */
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'active' => ['nullable', 'boolean'],
            'show_in_pos' => ['nullable', 'boolean'],
            'show_on_website' => ['nullable', 'boolean'],
        ], [], [
            'name' => __('اسم الموسم'), 'starts_at' => __('بداية الموسم'), 'ends_at' => __('نهاية الموسم'),
        ]) + [
            'active' => $request->boolean('active', true),
            'show_in_pos' => $request->boolean('show_in_pos', true),
            'show_on_website' => $request->boolean('show_on_website', true),
        ];
    }

    private function row(Season $s, $today): array
    {
        $status = $s->status($today);
        $next = $s->reminders
            ->filter(fn (SeasonReminder $r) => $r->active)
            ->map(fn (SeasonReminder $r) => ['at' => $r->dueAt($s), 'message' => $r->message])
            ->filter(fn ($r) => $r['at'] !== null && $r['at']->gte(now()))
            ->sortBy(fn ($r) => $r['at']->timestamp)
            ->first();

        return [
            'id' => $s->id,
            'name' => $s->name,
            'name_en' => $s->name_en,
            'label' => Demo::ln($s->name, $s->name_en),
            'starts_at' => $s->starts_at->toDateString(),
            'ends_at' => $s->ends_at->toDateString(),
            'active' => $s->active,
            'show_in_pos' => $s->show_in_pos,
            'show_on_website' => $s->show_on_website,
            'status' => $status,
            'statusLabel' => Seasons::statusLabel($status),
            'daysUntil' => $status === Season::UPCOMING ? (int) $today->diffInDays($s->starts_at, false) : null,
            'productsCount' => (int) ($s->products_count ?? $s->products()->count()),
            'remindersCount' => (int) ($s->reminders_count ?? $s->reminders->count()),
            'nextReminder' => $next ? ['at' => $next['at']->toIso8601String(), 'message' => $next['message']] : null,
        ];
    }

    private function productRow(Product $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'label' => Demo::ln($p->name, $p->name_en),
            'sku' => $p->sku,
            'image' => $p->image,
            'category' => $p->category ? Demo::ln($p->category->name, $p->category->name_en) : null,
            'active' => (bool) $p->active,
            /* حالةُ المخزون كما يقولها الصنفُ نفسُه — وغيرُ المرتبط لا حالةَ له */
            'stock_status' => $p->stock_status,
        ];
    }

    private function reminderRow(SeasonReminder $r, Season $season): array
    {
        return [
            'id' => $r->id,
            'type' => $r->type,
            'days_before' => $r->days_before,
            'remind_at' => $r->remind_at?->toIso8601String(),
            'due_at' => $r->dueAt($season)?->toIso8601String(),
            'message' => $r->message,
            'active' => $r->active,
        ];
    }
}
