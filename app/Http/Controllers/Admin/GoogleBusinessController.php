<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchGooglePlace;
use App\Models\GoogleBusinessAccount;
use App\Models\GoogleBusinessReview;
use App\Support\CostsAndLosses;
use App\Support\Demo;
use App\Support\GoogleBusiness;
use App\Support\MarketingSettings;
use App\Support\Pagination;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * إدارةُ تقييمات ملفّ الأعمال — قراءةً وردًّا.
 *
 * ═══ وما لا يفعله هذا الملفّ ═══
 *
 * لا يكتب ردًّا في قاعدتنا قبل أن تقبله Google. الزرُّ اسمُه «نشر الردّ على
 * Google»، وإمّا أن يُنشر أو يُقال إنّه لم يُنشر — ولا ثالثَ بينهما.
 *
 * ═══ والبابُ لا يُعرض قبل أن يُفتح ═══
 *
 * ثلاثةٌ يحتاجها التكامل: عميلُ OAuth وسرُّه ووصولٌ **معتمَدٌ** من Google.
 * وغيابُ أيٍّ منها يجعل الشاشة تقول «غيرُ مهيّأ» ولا تعرض زرَّ ربطٍ يقود
 * إلى صفحة خطأٍ عند Google.
 *
 * ═══ وهي للتاجر وحده ═══
 *
 * مديرُ المنصّة يدخل المتجرَ باسم صاحبه ليساعده، فيرث صلاحياته. وهذه الشاشة
 * تقرأ زبائنَ التاجر وتنشر باسمه على ملفّه العامّ وتربط حسابه: لا يد لأبعاد فيها.
 * فتُغلق كلُّها أثناء الدخول، ويبقى له الحالُ التشخيصيّ في لوحته بلا رمزٍ ولا نصّ.
 *
 * والمقيَّدُ بفرعٍ يرى فرعَه ويردّ عليه وحده، وما يمسّ المتجرَ كلَّه (الإذنُ
 * والتنبيهات) لمن لم يُقيَّد.
 */
class GoogleBusinessController extends Controller implements HasMiddleware
{
    private const STATE_KEY = 'google_business_state';

    /** كم تقييمًا في الصفحة */
    private const PER_PAGE = 25;

    /** عمرُ طلب الإذن — من ضغط الزرّ إلى العودة من Google */
    private const STATE_MINUTES = 10;

    public static function middleware(): array
    {
        return [static function (Request $request, Closure $next) {
            abort_if(
                $request->session()->has('impersonator_id'),
                403,
                __('تقييمات Google يديرها صاحب المتجر — لا تُفتح أثناء الدخول باسمه.'),
            );

            return $next($request);
        }];
    }

    private function bid(): int
    {
        return Demo::bid();
    }

    /**
     * فروعُ المتجر التي يراها هذا المستخدم.
     *
     * @return Collection<int, int>
     */
    private function branchIds(): Collection
    {
        $all = Branch::where('business_id', $this->bid())->orderBy('id')->pluck('id');
        $only = CostsAndLosses::allowedBranches($this->bid(), auth()->user());

        return $only === null ? $all : $all->intersect($only)->values();
    }

    /** ما يمسّ المتجر كلَّه لا يفعله من قُيِّد بفرع */
    private function wholeShopOnly(): void
    {
        abort_unless(CostsAndLosses::allowedBranches($this->bid(), auth()->user()) === null, 403);
    }

    public function index(Request $request): Response
    {
        $bid = $this->bid();
        $account = GoogleBusiness::for($bid);
        $settings = MarketingSettings::group($bid, 'google');
        $ids = $this->branchIds();
        $page = $this->reviews($ids, $request);

        return Inertia::render('Admin/Integrations/GoogleBusiness', [
            /* حالُ التهيئة أوّلُ ما يُقرأ — ولا يُعرض زرٌّ يقود إلى خطأ */
            'configured' => GoogleBusiness::configured(),
            'state' => GoogleBusiness::state($bid)['state'],
            'connected' => $account !== null,
            /* انقطع الإذنُ من جهة Google — فيُعرض «أعِد الربط» لا «لم يُربط بعد» */
            'reconnect' => $account === null && GoogleBusiness::needsReconnect($bid),
            'lastError' => $account === null
                ? GoogleBusinessAccount::where('business_id', $bid)->value('last_error')
                : null,
            'account' => $account ? [
                'email' => $account->account_email,
                'accountName' => $account->account_name,
                'linkedAt' => optional($account->linked_at)->toIso8601String(),
                'syncedAt' => optional($account->synced_at)->toIso8601String(),
                'error' => $account->last_error,
            ] : null,
            'branches' => $this->branches($ids),
            'reviews' => collect($page->items())->map($this->reviewRow(...))->values()->all(),
            'pagination' => Pagination::meta($page),
            'filters' => $this->filters($request),
            'alerts' => [
                'enabled' => ($settings['gbp_alerts_enabled'] ?? '1') === '1',
                'threshold' => (int) ($settings['gbp_low_rating'] ?? 2),
            ],
        ]);
    }

    /* ═══════════════════ الإذن ═══════════════════ */

    /**
     * إلى صفحة الإذن — بكلمةِ حالةٍ تُحفظ في الجلسة.
     *
     * وبلا مقارنتها عند العودة يستطيع موقعٌ آخر أن يقود التاجر إلى ربط
     * حسابٍ ليس حسابه بمتجره.
     */
    public function connect(Request $request): RedirectResponse
    {
        $this->wholeShopOnly();

        if (! GoogleBusiness::configured()) {
            return back()->with('toast', [
                'msg' => __('إدارة تقييمات Google غير متاحة على المنصّة بعد.'), 'type' => 'danger',
            ]);
        }

        $state = GoogleBusiness::newState();

        /*
         * والحالةُ تحمل المتجرَ والمستخدمَ ووقتَ الطلب — لا الكلمةَ وحدها.
         *
         * الكلمةُ وحدها تحمي من موقعٍ آخر، ولا تحمي من الجلسة نفسِها: من
         * بدأ الربط في متجرٍ ثمّ بدّل المتجرَ في تبويبٍ آخر قبل أن يعود من
         * Google، كان إذنُه يُحفظ على المتجر الذي صار فيه — فيقرأ متجرٌ
         * تقييماتِ حسابِ متجرٍ آخر ويردّ باسمه.
         */
        $request->session()->put(self::STATE_KEY, [
            'state' => $state,
            'business' => $this->bid(),
            'user' => $request->user()?->id,
            'at' => now()->getTimestamp(),
        ]);

        return redirect()->away(GoogleBusiness::authUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        // `pull` لا `get`: الحالةُ تُستعمل مرّةً واحدة، فلا يُعاد العنوانُ نفسُه
        $pending = $request->session()->pull(self::STATE_KEY);
        $back = redirect()->route('admin.integrations.googleBusiness');

        if (! $this->stateMatches($pending, (string) $request->query('state'), $request)) {
            return $back->with('toast', ['msg' => __('طلبُ الربط غير صالح — أعد المحاولة.'), 'type' => 'danger']);
        }

        if (filled($request->query('error'))) {
            // ألغى التاجر الإذن عند Google — وليس عطلًا يُقال بلغة عطل
            return $back->with('toast', ['msg' => __('لم يكتمل الربط — لم يُمنح الإذن.'), 'type' => 'warning']);
        }

        $code = (string) $request->query('code');

        if ($code === '') {
            return $back->with('toast', ['msg' => __('لم يكتمل الربط — لم يُمنح الإذن.'), 'type' => 'warning']);
        }

        $result = GoogleBusiness::exchange($code);

        if (! $result['ok']) {
            return $back->with('toast', ['msg' => $result['error'], 'type' => 'danger']);
        }

        /*
         * وإذنٌ بلا إدارة الملفّ لا يُحفظ.
         *
         * من ألغى خانتها في صفحة الموافقة يعود ناجحًا، ثمّ يُردّ كلُّ نداءٍ بـ٤٠٣
         * وشاشتُه تقول «مربوط». فيُقال له الآن ما ينقص، ولا يُكتب رمزٌ لا يفتح شيئًا.
         */
        if (! GoogleBusiness::granted((array) $result['data'])) {
            return $back->with('toast', [
                'msg' => __('لم تمنح إذن إدارة ملفّ الأعمال — أعد الربط واترك خانته مفعّلة.'),
                'type' => 'warning',
            ]);
        }

        GoogleBusiness::store($this->bid(), (array) $result['data'], $request->user());

        return $back->with('toast', ['msg' => __('تم ربط حساب Google بنجاح'), 'type' => 'success']);
    }

    /**
     * أتعود هذه العودةُ إلى الطلب الذي بدأه هذا المستخدم في هذا المتجر؟
     *
     * ═══ وأربعةٌ معًا ═══
     *
     * الكلمةُ تُقارَن بـ`hash_equals` وتُشترط غيرَ فارغة — وفارغةٌ تساوي
     * فارغةً لو قُورنت بـ`===`، فيمرّ من يصل إلى هذا الباب بلا أن يمرّ بصفحة
     * الإذن أصلًا. والمتجرُ والمستخدمُ هما من بدأ الطلب. والوقتُ لم يتجاوز
     * عمرَ الطلب: رابطُ عودةٍ قديمٌ لا يُستعمل بعد ساعات.
     *
     * @param  mixed  $pending  ما حُفظ في الجلسة عند `connect`
     */
    private function stateMatches(mixed $pending, string $given, Request $request): bool
    {
        if (! is_array($pending)) {
            return false;
        }

        $expected = (string) ($pending['state'] ?? '');

        return $expected !== ''
            && hash_equals($expected, $given)
            && (int) ($pending['business'] ?? 0) === $this->bid()
            && ($pending['user'] ?? null) === $request->user()?->id
            && now()->getTimestamp() - (int) ($pending['at'] ?? 0) <= self::STATE_MINUTES * 60;
    }

    public function disconnect(): RedirectResponse
    {
        $this->wholeShopOnly();

        $account = GoogleBusiness::for($this->bid());

        if ($account) {
            GoogleBusiness::revoke($account);
        }

        return back()->with('toast', ['msg' => __('أُلغي الربط'), 'type' => 'warning']);
    }

    /* ═══════════════════ المواقع ═══════════════════ */

    /** مواقعُ حساب الأعمال — تُقرأ عند الطلب لا في كلّ فتحةِ شاشة */
    public function locations(Request $request)
    {
        $account = GoogleBusiness::for($this->bid());

        if (! $account) {
            return response()->json(['ok' => false, 'error' => __('اربط حساب Google أوّلًا.'), 'locations' => []]);
        }

        $accounts = GoogleBusiness::accounts($account);

        if (! $accounts['ok']) {
            return response()->json(['ok' => false, 'error' => $accounts['error'], 'locations' => []]);
        }

        $out = [];

        foreach ($accounts['accounts'] as $one) {
            $found = GoogleBusiness::locations($account, $one['name']);

            if (! $found['ok']) {
                return response()->json(['ok' => false, 'error' => $found['error'], 'locations' => []]);
            }

            foreach ($found['locations'] as $location) {
                $out[] = $location + ['account' => $one['name'], 'accountTitle' => $one['title']];
            }
        }

        return response()->json(['ok' => true, 'error' => null, 'locations' => $out]);
    }

    public function linkLocation(Request $request, int $branch): RedirectResponse
    {
        $data = $request->validate([
            'location' => ['required', 'string', 'max:255'],
            'account' => ['required', 'string', 'max:255'],
        ]);

        // الفرعُ أوّلًا: فرعُ متجرٍ آخر يُردّ ٤٠٤ قبل أن يُسأل Google عن شيء
        $target = $this->branch($branch);
        $account = GoogleBusiness::for($this->bid());

        if (! $account) {
            return back()->withErrors(['location' => __('اربط حساب Google أوّلًا.')]);
        }

        /*
         * وموقعٌ مربوطٌ بفرعٍ آخر يُردّ ويُقال لماذا.
         *
         * والفهرسُ الفريد هو الحارس، لكنّ استثناءَ قاعدةٍ في وجه التاجر لا
         * يقول شيئًا. ولو رُبط بفرعين لَسُحبت تقييماتُه مرّتين ونُبّه بها
         * مرّتين وعُدّت مرّتين.
         */
        $taken = BranchGooglePlace::where('gbp_location', $data['location'])
            ->where('branch_id', '!=', $branch)->exists();

        if ($taken) {
            return back()->withErrors(['location' => __('هذا الموقع مربوطٌ بفرعٍ آخر.')]);
        }

        /* والموقعُ من مواقع هذا الإذن كما تردّها Google — لا كما وصل في الطلب */
        $owned = GoogleBusiness::ownedLocation($account, $data['account'], $data['location']);

        if (! $owned['ok']) {
            return back()->withErrors(['location' => $owned['error']]);
        }

        /*
         * ومكانُ الموقع يُقارَن بمكان الفرع على الخرائط.
         *
         * موقعان متشابها الاسم يُخلطان، فتُسحب تقييماتُ فرعٍ إلى آخر ويُردّ باسمه.
         * ويُسأل التاجر ولا يُمنع: معرّفُ المكان قد يتبدّل عند Google.
         */
        $placeOfBranch = (string) BranchGooglePlace::query()->linked()->where('branch_id', $target->id)->value('place_id');
        $placeOfLocation = (string) ($owned['location']['placeId'] ?? '');

        if ($placeOfBranch !== '' && $placeOfLocation !== '' && $placeOfBranch !== $placeOfLocation && ! $request->boolean('confirmed')) {
            return back()->withErrors(['location' => __('هذا الموقع ليس مكان هذا الفرع على الخرائط. تأكّد منه قبل ربطه.')]);
        }

        GoogleBusiness::linkLocation($target, $data['location'], $data['account']);

        return back()->with('toast', ['msg' => __('تم ربط الفرع بموقعه'), 'type' => 'success']);
    }

    /* ═══════════════════ التقييمات ═══════════════════ */

    public function sync(int $branch): RedirectResponse
    {
        $result = GoogleBusiness::syncReviews($this->branch($branch));

        if (! $result['ok']) {
            return back()->with('toast', ['msg' => $result['error'], 'type' => 'danger']);
        }

        return back()->with('toast', [
            'msg' => __('حُدِّثت التقييمات — :n جديد من :total', ['n' => $result['new'], 'total' => $result['total']]),
            'type' => 'success',
        ]);
    }

    /**
     * نشرُ ردٍّ — ولا يُقال «نُشر» إلا إن نُشر.
     *
     * وحدُّ الردّ عند Google أربعةُ آلاف حرف. ويُفحص هنا قبل النداء: نصٌّ
     * أطول يُردّ بعد ثوانٍ ويُقيَّد عطلًا، وقد كان يُقاس في الحقل.
     */
    public function reply(Request $request, int $review): RedirectResponse
    {
        $data = $request->validate([
            'comment' => ['required', 'string', 'max:4000'],
        ]);

        $row = $this->review($review);
        $result = GoogleBusiness::reply($row, $data['comment']);

        if (! $result['ok']) {
            return back()->withErrors(['comment' => $result['error']]);
        }

        return back()->with('toast', ['msg' => __('نُشر الردّ على Google'), 'type' => 'success']);
    }

    /** مقابضُ التنبيه — مُشغَّلةٌ وحدُّها */
    public function alerts(Request $request): RedirectResponse
    {
        $this->wholeShopOnly();

        $data = $request->validate([
            'gbp_alerts_enabled' => ['nullable', 'boolean'],
            'gbp_low_rating' => ['required', 'integer', 'min:1', 'max:3'],
        ]);

        MarketingSettings::save($this->bid(), 'google', [
            'gbp_alerts_enabled' => $request->boolean('gbp_alerts_enabled'),
            'gbp_low_rating' => (string) $data['gbp_low_rating'],
        ]);

        return back()->with('toast', ['msg' => __('حُفظت الإعدادات'), 'type' => 'success']);
    }

    /* ═══════════════════ البناء ═══════════════════ */

    /** فرعٌ يراه هذا المستخدم من فروع متجره — والحصرُ في الاستعلام لا في فحصٍ بعده */
    private function branch(int $id): Branch
    {
        return Branch::whereIn('id', $this->branchIds())->findOrFail($id);
    }

    /**
     * تقييمٌ قائمٌ على فرعٍ يراه هذا المستخدم — ولا يُردّ على تقييم الجار، ولا
     * على كلامٍ اختفى من Google.
     */
    private function review(int $id): GoogleBusinessReview
    {
        return GoogleBusinessReview::live()->whereIn('branch_id', $this->branchIds())->findOrFail($id);
    }

    /**
     * @param  Collection<int, int>  $ids
     * @return list<array<string, mixed>>
     */
    private function branches(Collection $ids): array
    {
        return Branch::whereIn('id', $ids)->with('googlePlace')->orderBy('id')->get()
            ->map(function (Branch $b) {
                $place = $b->googlePlace;

                return [
                    'id' => $b->id,
                    'name' => $b->name,
                    'location' => $place?->gbp_location,
                    'linked' => filled($place?->gbp_location),
                    'reviews' => GoogleBusinessReview::where('branch_id', $b->id)->live()->count(),
                    /* ومعرّفُ الفرع على الخرائط — به يُعلَّم موقعُه في قائمة الاختيار */
                    'placeId' => $place?->isLinked() && filled($place->place_id) ? $place->place_id : null,
                    'syncedAt' => optional($place?->gbp_synced_at)->toIso8601String(),
                    'syncError' => $place?->gbp_sync_error,
                ];
            })->all();
    }

    /** مرشِّحاتُ القائمة — تُقاس بما يُقبل ولا تُصدَّق كما وصلت */
    private function filters(Request $request): array
    {
        $stars = (int) $request->query('stars', 0);

        return [
            'branch' => (int) $request->query('branch', 0) ?: null,
            'stars' => $stars >= 1 && $stars <= 5 ? $stars : null,
            'unanswered' => $request->boolean('unanswered'),
        ];
    }

    /**
     * تقييماتُ ما يراه المستخدم — الأحدثُ أوّلًا، والمحذوفُ عند Google لا يُعرض.
     *
     * وصفحاتٌ لا سقف: كانت تُقرأ أوّلُ مئةٍ وحدها، فتقييمٌ قديمٌ بلا ردٍّ لا يصله
     * صاحبُه أبدًا. والمزامنةُ تجلب الكلّ، فالشاشةُ تعرض الكلّ.
     *
     * @param  Collection<int, int>  $ids
     */
    private function reviews(Collection $ids, Request $request): LengthAwarePaginator
    {
        $filters = $this->filters($request);

        return GoogleBusinessReview::live()
            ->whereIn('branch_id', $filters['branch'] ? $ids->intersect([$filters['branch']]) : $ids)
            ->when($filters['stars'], fn ($q, $n) => $q->where('rating', $n))
            ->when($filters['unanswered'], fn ($q) => $q->whereNull('reply'))
            ->with('branch:id,name')
            ->orderByDesc('reviewed_at')->orderByDesc('id')
            ->paginate(self::PER_PAGE)->withQueryString();
    }

    /** @return array<string, mixed> */
    private function reviewRow(GoogleBusinessReview $r): array
    {
        return [
            'id' => $r->id,
            'branch' => $r->branch?->name,
            'rating' => $r->rating,
            'comment' => $r->comment,
            'author' => $r->author,
            'photo' => $r->author_photo,
            'at' => optional($r->reviewed_at)->toIso8601String(),
            /* والردُّ المعروضُ هو ما عند Google — لا ما كُتب في الحقل */
            'reply' => $r->reply,
            'repliedAt' => optional($r->replied_at)->toIso8601String(),
        ];
    }
}
