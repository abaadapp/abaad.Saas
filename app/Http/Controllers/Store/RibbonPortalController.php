<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerPortalSession;
use App\Support\InvoiceBranding;
use App\Support\RibbonPortal\Access;
use App\Support\RibbonPortal\Account;
use App\Support\RibbonPortal\Devices;
use App\Support\RibbonPortal\Paper;
use App\Support\RibbonPortal\Texts;
use App\Support\Search;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cookie as CookieJar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * بوابةُ عملاء RIBBON كما يراها العميل — `ribbon.abaadapp.om/my`.
 *
 * خارج حرّاس النظام: من يفتحها لا حسابَ له في أبعاد، ولا تُقرأ جلسةُ موظّف.
 * متجرُها من نطاقها (`RibbonPortalGate`)، وصاحبُها من جلسةٍ لها وحدها
 * (`RibbonPortal\Access`) تُسأل في كلّ طلب. وكلُّ ما فيها قراءة.
 */
class RibbonPortalController extends Controller
{
    private const LANG = 'rb_my_lang';

    public function index(Request $request): BaseResponse
    {
        if (in_array($pick = $request->query('lang'), Texts::LANGS, true)) {
            return redirect()->route('my.index', [], 303)->withCookie($this->langCookie($request, $pick));
        }

        $session = $this->session($request);
        $customer = $session?->link?->customer;
        if ($customer === null) {
            return $this->door($request);
        }

        $frame = $this->frame($request, $customer);
        $account = new Account($customer, $frame['lang']);

        // والكعكةُ تُجدَّد بالزيارة كما تُجدَّد جلستُها
        return response()->view('portal.ribbon.account', $frame + [
            'summary' => $account->summary(),
            'purchases' => $account->purchases(),
            'points' => $account->points(),
            'rules' => $account->rules(),
            'updated' => now()->format('H:i:s'),
        ])->withCookie(Access::cookie($request, Access::held($request, $session)));
    }

    /** من لم يُعتمد جهازُه: خانةُ الهاتف، أو رقمُ جهازه وهو ينتظر — ولا اسمَ ولا حرفَ من حساب */
    private function door(Request $request): BaseResponse
    {
        $waiting = Devices::mine($this->business($request), $request);
        if ($waiting !== null) {
            return response()->view('portal.ribbon.wait', $this->frame($request, null) + ['number' => Devices::number($waiting)]);
        }

        $response = response()->view('portal.ribbon.door', $this->frame($request, null) + ['over' => Devices::was($this->business($request), $request)]);

        // جلسةٌ أُلغيت أو طلبٌ انقضى: كعكتُه لا تبقى
        return Access::raw($request) === null ? $response : $response->withCookie(Access::cookie($request, null));
    }

    /**
     * رقمُ الهاتف وحده — ولا يُفتح به شيء: يُكتب أنّ هذا الجهاز ينتظر الاعتماد.
     *
     * وردٌّ واحدٌ لرقمٍ مسجَّلٍ وغيره: من يجرّب الأرقام لا يعرف عميلًا من سواه.
     */
    public function login(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $phone = mb_substr((string) $request->string('phone'), 0, 60);
        $say = fn (string $key, array $with = []) => Texts::say($this->lang($request, null), $key, $with);

        if (Access::normalize($phone) === null) {
            return response()->json(['message' => $say('phoneShort')], 422);
        }
        if ($this->session($request) !== null || Devices::already($business, $phone, $request)) {
            return response()->json(['ok' => true]);
        }
        if (($wait = Access::blockedFor($phone, $request)) > 0) {
            // و`wait` يميّزه عن عدّاد المسار: ذاك ردُّه بالإنجليزيّة ولا مدّةَ فيه
            return response()->json(['message' => $say('tooMany', ['n' => (int) ceil($wait / 60)]), 'wait' => $wait], 429);
        }

        Access::count($phone, $request);

        return response()->json(['ok' => true])->withCookie(Access::cookie($request, Devices::ask($business, $phone, $request)));
    }

    /** أاعتُمد؟ — يسألها الجهازُ وهو ينتظر */
    public function wait(Request $request): JsonResponse
    {
        return response()->json(['state' => match (true) {
            $this->session($request) !== null => 'in',
            Devices::mine($this->business($request), $request) !== null => 'wait',
            default => 'over',
        }]);
    }

    /** يُنسى الجهاز: جلستُه إن دخل، وطلبُه إن كان ينتظر */
    public function logout(Request $request): BaseResponse
    {
        Access::logout($this->session($request));
        Devices::forget($this->business($request), $request);

        return redirect()->route('my.index', [], 303)->withCookie(Access::cookie($request, null));
    }

    /** ما يتحدّث: الملخّصُ والمشترياتُ والنقاط — بالقالب الذي رسم الصفحةَ أوّلَ مرّة */
    public function data(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        if ($customer === null) {
            return response()->json(['over' => true], 401);
        }

        $frame = $this->frame($request, $customer);
        $account = new Account($customer, $frame['lang']);
        $purchases = $account->purchases(
            ['q' => Search::term($request), 'from' => $request->query('from'), 'to' => $request->query('to')],
            (int) $request->query('offset'), (int) ($request->query('limit') ?: Account::PAGE),
        );

        $out = [
            'rows' => array_map(fn (array $row) => ['key' => $row['key'], 'html' => view('portal.ribbon._row', $frame + ['row' => $row])->render()], $purchases['rows']),
            'more' => $purchases['more'],
            'updated' => now()->format('H:i:s'),
        ];

        if ($request->boolean('all')) {
            $out['summary'] = view('portal.ribbon._summary', $frame + ['summary' => $account->summary()])->render();
            $out['points'] = view('portal.ribbon._points', $frame + ['points' => $account->points(), 'rules' => $account->rules()])->render();
        }

        return response()->json($out);
    }

    /** وصفحةٌ أقدمُ من سجلّ النقاط */
    public function points(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        if ($customer === null) {
            return response()->json(['over' => true], 401);
        }

        $frame = $this->frame($request, $customer);
        $points = (new Account($customer, $frame['lang']))->points((int) $request->query('offset'));

        return response()->json([
            'rows' => array_map(fn (array $p) => ['key' => $p['key'], 'html' => view('portal.ribbon._point', $frame + ['p' => $p])->render()], $points['rows']),
            'more' => $points['more'],
        ]);
    }

    /** صفحةُ الفاتورة: الورقةُ وأزرارُها */
    public function paper(Request $request, string $kind, int $id): BaseResponse
    {
        $customer = $this->customer($request);
        if ($customer === null) {
            return redirect()->route('my.index', [], 303);
        }

        $document = Paper::find($customer, $kind, $id);
        abort_if($document === null, 404);

        return response()->view('portal.ribbon.paper', $this->frame($request, $customer) + [
            'kind' => $kind, 'id' => $id, 'number' => Paper::number($document),
        ]);
    }

    /** الورقةُ نفسُها داخل إطار الصفحة — معزولةً: لا نصَّ فيها يُنفَّذ ولا شبكة */
    public function sheet(Request $request, string $kind, int $id): Response
    {
        return response(Paper::html($this->mine($request, $kind, $id)), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => "sandbox allow-same-origin allow-modals; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline' 'self'; font-src 'self' data:; frame-ancestors 'self'",
        ]);
    }

    public function pdf(Request $request, string $kind, int $id): BaseResponse
    {
        return Paper::pdf($this->mine($request, $kind, $id), $request->boolean('download'));
    }

    /** شعارُ المتجر من بياناته — وهو وحدَه ما يُحفظ، وفي متصفّح صاحبه: الردُّ يحمل كعكةَ الجلسة فلا يخزّنه وسيط */
    public function logo(Request $request): BaseResponse
    {
        $raw = DB::table('businesses')->where('id', $this->business($request))->value('logo');
        $disk = Storage::disk('public');
        $mine = is_string($raw) && $raw !== '' && ! preg_match('#^(https?:|data:)#', $raw) && $disk->exists($raw);

        return response($mine ? $disk->get($raw) : (string) file_get_contents(public_path('brand/ribbon/logo.svg')), 200, [
            'Content-Type' => $mine ? ($disk->mimeType($raw) ?: 'image/png') : 'image/svg+xml',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, max-age=600',
            'X-Portal-Public' => '1',
        ]);
    }

    /* ─────────────────────────────────────────────────────────── */

    private function business(Request $request): int
    {
        return (int) $request->attributes->get('portal_business');
    }

    private function session(Request $request): ?CustomerPortalSession
    {
        return Access::session($this->business($request), $request);
    }

    private function customer(Request $request): ?Customer
    {
        return $this->session($request)?->link?->customer;
    }

    private function mine(Request $request, string $kind, int $id): mixed
    {
        $customer = $this->customer($request);
        abort_if($customer === null, 403);

        $document = Paper::find($customer, $kind, $id);
        abort_if($document === null, 404);

        return $document;
    }

    /** لغتُه: ما اختاره في الجهاز، وإلّا لغتُه في سجلّه، وإلّا العربيّة */
    private function lang(Request $request, ?Customer $customer): string
    {
        foreach ([$request->cookie(self::LANG), $customer?->language] as $lang) {
            if (in_array($lang, Texts::LANGS, true)) {
                return $lang;
            }
        }

        return 'ar';
    }

    private function langCookie(Request $request, string $lang): Cookie
    {
        return CookieJar::make(self::LANG, $lang, 60 * 24 * 365, '/my', null, $request->isSecure() || app()->isProduction(), true, false, 'lax');
    }

    /** @return array<string, mixed> ما في كلّ صفحة */
    private function frame(Request $request, ?Customer $customer): array
    {
        $lang = $this->lang($request, $customer);
        // ورمزُ العملة وأرقامُها بلغة الصفحة
        app()->setLocale($lang);

        return [
            'lang' => $lang,
            'dir' => $lang === 'en' ? 'ltr' : 'rtl',
            't' => fn (string $key, array $with = []) => Texts::say($lang, $key, $with),
            'shop' => InvoiceBranding::name($this->business($request)),
            'nonce' => (string) $request->attributes->get('portal_nonce'),
        ];
    }
}
