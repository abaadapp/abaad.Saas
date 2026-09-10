<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchGooglePlace;
use App\Models\GoogleBusinessReview;
use App\Support\Demo;
use App\Support\GoogleBusiness;
use App\Support\MarketingSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
 */
class GoogleBusinessController extends Controller
{
    private const STATE_KEY = 'google_business_state';

    private function bid(): int
    {
        return Demo::bid();
    }

    public function index(Request $request): Response
    {
        $bid = $this->bid();
        $account = GoogleBusiness::for($bid);
        $settings = MarketingSettings::group($bid, 'google');

        return Inertia::render('Admin/Integrations/GoogleBusiness', [
            /* حالُ التهيئة أوّلُ ما يُقرأ — ولا يُعرض زرٌّ يقود إلى خطأ */
            'configured' => GoogleBusiness::configured(),
            'connected' => $account !== null,
            'account' => $account ? [
                'email' => $account->account_email,
                'accountName' => $account->account_name,
                'linkedAt' => optional($account->linked_at)->toIso8601String(),
                'syncedAt' => optional($account->synced_at)->toIso8601String(),
                'error' => $account->last_error,
            ] : null,
            'branches' => $this->branches($bid),
            'reviews' => $this->reviews($bid),
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
        if (! GoogleBusiness::configured()) {
            return back()->with('toast', [
                'msg' => __('تكامل Google Business غير مهيّأ في أبعاد بعد.'), 'type' => 'danger',
            ]);
        }

        $state = GoogleBusiness::newState();
        $request->session()->put(self::STATE_KEY, $state);

        return redirect()->away(GoogleBusiness::authUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected = (string) $request->session()->pull(self::STATE_KEY, '');
        $back = redirect()->route('admin.integrations.googleBusiness');

        /*
         * الحالةُ تُقارَن بـ`hash_equals` وتُشترط غيرَ فارغة.
         *
         * وفارغةٌ تساوي فارغةً لو قُورنت بـ`===` — فيمرّ من يصل إلى هذا
         * الباب بلا أن يمرّ بصفحة الإذن أصلًا.
         */
        if ($expected === '' || ! hash_equals($expected, (string) $request->query('state'))) {
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

        GoogleBusiness::store($this->bid(), (array) $result['data'], $request->user());

        return $back->with('toast', ['msg' => __('تم ربط حساب Google بنجاح'), 'type' => 'success']);
    }

    public function disconnect(): RedirectResponse
    {
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

        if (! GoogleBusiness::for($this->bid())) {
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

        GoogleBusiness::linkLocation($this->branch($branch), $data['location'], $data['account']);

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

    /** فرعٌ من فروع هذا المتجر — والحصرُ في الاستعلام لا في فحصٍ بعده */
    private function branch(int $id): Branch
    {
        return Branch::where('business_id', $this->bid())->findOrFail($id);
    }

    /** تقييمٌ على فرعٍ من فروع هذا المتجر — ولا يُردّ على تقييم الجار */
    private function review(int $id): GoogleBusinessReview
    {
        return GoogleBusinessReview::whereIn(
            'branch_id',
            Branch::where('business_id', $this->bid())->pluck('id'),
        )->findOrFail($id);
    }

    /** @return list<array<string, mixed>> */
    private function branches(int $bid): array
    {
        return Branch::where('business_id', $bid)->with('googlePlace')->orderBy('id')->get()
            ->map(fn (Branch $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'location' => $b->googlePlace?->gbp_location,
                'linked' => filled($b->googlePlace?->gbp_location),
                'reviews' => GoogleBusinessReview::where('branch_id', $b->id)->live()->count(),
            ])->all();
    }

    /**
     * تقييماتُ المتجر — الأحدثُ أوّلًا، والمحذوفُ عند Google لا يُعرض.
     *
     * @return list<array<string, mixed>>
     */
    private function reviews(int $bid): array
    {
        return GoogleBusinessReview::live()
            ->whereIn('branch_id', Branch::where('business_id', $bid)->pluck('id'))
            ->with('branch:id,name')
            ->orderByDesc('reviewed_at')
            ->limit(100)->get()
            ->map(fn (GoogleBusinessReview $r) => [
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
            ])->all();
    }
}
