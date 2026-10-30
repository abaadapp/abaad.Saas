<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Plan;
use App\Models\Sector;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\Lists\BusinessesList;
use App\Support\Pagination;
use App\Support\PlanFeatures;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * القطاعات في لوحة المنصّة — مدخلٌ منظَّمٌ لما هو قائم، لا نظامٌ موازٍ له.
 *
 * - الباقاتُ تُحفظ بباباها (`PlanController`) وتُرسم ببطاقاتها (`PageController::planCards`).
 * - والشركاتُ استعلامُ قائمتها نفسُه (`BusinessesList`) مقيَّدًا بالقطاع، وتُفتح بصفحاتها.
 * - وإضافةُ شركةٍ منه نموذجُ الإنشاء نفسُه بقطاعه محدَّدًا.
 */
class SectorController extends Controller
{
    /** حالاتُ الاشتراك في تبويب الشركات — تُحسب كما تحسبها لوحةُ الاشتراكات (`Business::live`) */
    public const STATES = ['subscribed', 'trial', 'awaiting', 'expired', 'disabled'];

    public function index(): Response
    {
        $sectors = Sector::withCount([
            'plans',
            'businesses' => fn ($q) => $q->real(),
        ])->orderBy('sort')->orderBy('id')->get();

        return $this->page('Platform/Sectors/Index', [
            'sectors' => $sectors->map(fn (Sector $s) => self::card($s))->all(),
            'icons' => Sector::ICONS,
        ]);
    }

    public function show(Request $request, int $id): Response
    {
        $sector = Sector::withCount(['plans', 'businesses' => fn ($q) => $q->real()])->findOrFail($id);
        $plans = Plan::where('sector_id', $sector->id)->orderBy('monthly_price')->orderBy('id')->get();

        $q = BusinessesList::query($request, $sector->id)->withExists([
            'invoices as has_paid' => fn ($w) => $w->where('status', 'مدفوعة'),
        ]);

        if (in_array($state = (string) $request->query('state'), self::STATES, true)) {
            self::whereState($q, $state);
        }

        $page = $q->paginate(15)->withQueryString()->through(fn (Business $b) => [
            'id' => $b->id,
            'name' => $b->name,
            'type' => $b->type,
            'plan' => $b->plan?->name,
            'state' => self::state($b),
            'expires' => optional($b->ends_at)->format('Y-m-d'),
            'daysLeft' => Tenancy::daysLeft($b),
        ]);

        return $this->page('Platform/Sectors/Show', [
            'sector' => self::card($sector),
            'icons' => Sector::ICONS,
            'tab' => in_array($request->query('tab'), ['plans', 'businesses', 'system'], true) ? $request->query('tab') : 'plans',
            'plans' => PageController::planCards($plans),
            'capabilityOptions' => PlanFeatures::options(),
            'businesses' => $page->items(),
            'pagination' => Pagination::meta($page),
            'filters' => $request->only('q', 'state', 'plan'),
            'planNames' => $plans->pluck('name')->all(),
            'system' => [
                'code' => $sector->system,
                'name' => Sector::SYSTEMS[$sector->system]['name'] ?? $sector->system,
                'name_en' => Sector::SYSTEMS[$sector->system]['name_en'] ?? $sector->system,
                // نشطٌ يعمل عليه متاجرُه اليوم — أو موقوفٌ عن الإضافة الجديدة
                'running' => (bool) $sector->active,
                'live' => Business::live()->where('sector_id', $sector->id)->count(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(self::rules() + [
            /*
             * والرمزُ يُكتب مرّةً: حروفٌ لاتينيّةٌ صغيرة يقرؤها الكود، ولا يُعدَّل بعدها —
             * الاسمُ يتغيّر، والرمزُ هو ما يربط القطاع بما يُبنى عليه.
             */
            'code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,31}$/', 'unique:sectors,code'],
            'name' => ['required', 'string', 'max:100', 'unique:sectors,name'],
        ], self::messages(), self::attributes());

        $sector = Sector::create([
            'code' => $data['code'],
            'name' => trim($data['name']),
            'name_en' => filled($data['name_en'] ?? null) ? trim($data['name_en']) : null,
            'icon' => $data['icon'],
            'sort' => (int) ($data['sort'] ?? 0),
            'active' => $request->boolean('active', true),
            'system' => 'abaad',
        ]);

        Activity::log('created', 'أضاف قطاعًا: '.$sector->name, ['business_id' => null, 'subject_id' => $sector->id]);

        return redirect()->route('super-admin.sectors.show', $sector->id)
            ->with('toast', ['msg' => __('أُضيف القطاع'), 'type' => 'success']);
    }

    public function update(Request $request, int $id)
    {
        $sector = Sector::findOrFail($id);

        $data = $request->validate(self::rules() + [
            // الرمزُ لا يتغيّر — ومن أرسله يرسله كما هو أو لا يرسله
            'code' => ['nullable', 'string', Rule::in([$sector->code])],
            'name' => ['required', 'string', 'max:100', Rule::unique('sectors', 'name')->ignore($sector->id)],
        ], self::messages() + ['code.in' => __('رمز القطاع ثابتٌ لا يتغيّر.')], self::attributes());

        $active = $request->has('active') ? $request->boolean('active') : $sector->active;

        /*
         * ولا يُطفأ آخرُ قطاعٍ نشط: إضافةُ الشركات تحتاج قطاعًا تُضاف إليه.
         *
         * والإطفاءُ لا يمسّ شركةً ولا باقة: ما فيه يعمل كما كان، ولا تُضاف إليه
         * شركةٌ جديدة من اللوحة حتى يُفتح.
         */
        if ($sector->active && ! $active && ! Sector::where('active', true)->whereKeyNot($sector->id)->exists()) {
            throw ValidationException::withMessages(['active' => __('لا يُطفأ آخرُ قطاعٍ نشط — إضافةُ الشركات تحتاجه.')]);
        }

        $sector->update([
            'name' => trim($data['name']),
            'name_en' => filled($data['name_en'] ?? null) ? trim($data['name_en']) : null,
            'icon' => $data['icon'],
            'sort' => (int) ($data['sort'] ?? $sector->sort),
            'active' => $active,
        ]);

        Activity::log('updated', 'عدّل القطاع: '.$sector->name, ['business_id' => null, 'subject_id' => $sector->id]);

        return back()->with('toast', ['msg' => __('حُفظ القطاع'), 'type' => 'success']);
    }

    /** بطاقةُ القطاع كما تقرؤها الصفحتان */
    private static function card(Sector $s): array
    {
        return [
            'id' => $s->id,
            'code' => $s->code,
            'name' => $s->name,
            'name_en' => $s->name_en,
            'icon' => in_array($s->icon, Sector::ICONS, true) ? $s->icon : 'store',
            'sort' => (int) $s->sort,
            'active' => (bool) $s->active,
            'system' => Sector::SYSTEMS[$s->system]['name'] ?? $s->system,
            'plans_count' => (int) ($s->plans_count ?? 0),
            'businesses_count' => (int) ($s->businesses_count ?? 0),
        ];
    }

    /**
     * حالُ اشتراك الشركة — من صفّها كما تحسبه لوحةُ الاشتراكات.
     *
     * الساري (`Business::live`) بفاتورةٍ مدفوعة مشترك، وبلاها في التجربة.
     */
    private static function state(Business $b): string
    {
        return match (true) {
            in_array((string) $b->status, ['معطل', 'معطّل'], true) => 'disabled',
            $b->status === Business::AWAITING_PAYMENT => 'awaiting',
            $b->ends_at !== null && $b->ends_at->lt(now()->startOfDay()) => 'expired',
            (bool) $b->has_paid => 'subscribed',
            default => 'trial',
        };
    }

    /** ترشيحُ الحالة في القاعدة — كي يصدق الترقيمُ لا الصفحةُ الواحدة */
    private static function whereState($q, string $state): void
    {
        $paid = fn ($w) => $w->selectRaw('1')->from('invoices')
            ->whereColumn('invoices.business_id', 'businesses.id')
            ->where('invoices.status', 'مدفوعة');
        $notDisabled = fn ($w) => $w->whereNotIn('status', ['معطل', 'معطّل', Business::AWAITING_PAYMENT]);
        $running = fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>=', now()->startOfDay());

        match ($state) {
            'disabled' => $q->whereIn('status', ['معطل', 'معطّل']),
            'awaiting' => $q->where('status', Business::AWAITING_PAYMENT),
            'expired' => $q->where($notDisabled)->where('ends_at', '<', now()->startOfDay()),
            'subscribed' => $q->where($notDisabled)->where($running)->whereExists($paid),
            'trial' => $q->where($notDisabled)->where($running)->whereNotExists($paid),
        };
    }

    private static function rules(): array
    {
        return [
            'name_en' => ['nullable', 'string', 'max:100'],
            'icon' => ['required', Rule::in(Sector::ICONS)],
            'sort' => ['nullable', 'integer', 'min:0', 'max:999'],
            'active' => ['nullable', 'boolean'],
        ];
    }

    private static function messages(): array
    {
        return ['code.regex' => __('الرمز حروفٌ لاتينيّة صغيرة وأرقامٌ و_ — يبدأ بحرف (مثال: retail).')];
    }

    private static function attributes(): array
    {
        return [
            'code' => __('رمز القطاع'),
            'name' => __('اسم القطاع'),
            'name_en' => __('الاسم بالإنجليزية'),
            'icon' => __('الأيقونة'),
            'sort' => __('ترتيب العرض'),
            'active' => __('الحالة'),
        ];
    }

    private function page(string $component, array $props): Response
    {
        return Inertia::render($component, [...$props, 'currency' => Demo::displayCurrency()]);
    }
}
