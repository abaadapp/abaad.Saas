<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerPortalLink;
use App\Models\Order;
use App\Models\User;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\Digits;
use App\Support\InvoiceBranding;
use App\Support\Pagination;
use App\Support\Permissions;
use App\Support\RibbonPortal\Access;
use App\Support\RibbonPortal\Devices;
use App\Support\RibbonPortal\Portal;
use App\Support\RibbonPortal\Qr;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * بوابةُ عملاء RIBBON عند الموظّف — رابطٌ واحدٌ لجميع العملاء، ويديرها صاحبُ النشاط.
 *
 * لمتجر سعود وحده: كلُّ بابٍ هنا يردّ 404 لسواه ولو كُتب عنوانُه بيد (`open`).
 * ولا رابطَ لعميلٍ بعينه ولا رمزَ يُعطى له: يكتب رقمَه، ويعتمد الموظّفُ جهازَه مرّةً
 * (`RibbonPortal\Devices`) من العملاء أو من الصندوق بعد البيع. وإلغاءُ الدخول لمن يدير.
 *
 * ولا رسالةَ من هنا ولا قناةَ مراسلة.
 */
class RibbonPortalController extends Controller
{
    /* ─────────── اعتمادُ الأجهزة ─────────── */

    /** من ينتظر اعتمادَ جهازه — العملاءُ المسجَّلون وحدهم */
    public function waiting(): JsonResponse
    {
        $this->open(Portal::mayShare(auth()->user()));

        return $this->json(['waiting' => array_map(fn (array $w) => $w + [
            'url' => route('admin.customers.portal.device', $w['id'], false),
        ], Devices::waiting((int) Demo::bid()))]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $customer = $this->customer($id);

        return $this->settle($request, $customer) ?? $this->json(['state' => self::state($customer, auth()->user())]);
    }

    /** ومن الصندوق بعد البيع — لعميل هذه البيعة وحده */
    public function approveSale(Request $request, string $number): JsonResponse
    {
        $this->open(Portal::mayShare(auth()->user()));

        $order = Order::where('business_id', Demo::bid())->where('is_held', false)->where('number', $number)->firstOrFail();
        abort_if($order->customer_id === null, 404);

        return $this->settle($request, $this->customer((int) $order->customer_id)) ?? $this->json(['ok' => true]);
    }

    /** لم يكن هو: يُغلق ما ينتظر لهذا العميل */
    public function dismiss(int $id): JsonResponse
    {
        $customer = $this->customer($id);

        if (Devices::drop($customer) > 0) {
            Access::log('رفض جهازًا طلب بوابة العميل', $customer);
        }

        return $this->json(['state' => self::state($customer, auth()->user())]);
    }

    /** يعتمد أو يردّ سببَ الردّ */
    private function settle(Request $request, Customer $customer): ?JsonResponse
    {
        $data = $request->validate(['device' => ['required', 'string', 'max:20']], [], ['device' => __('رقم الجهاز')]);

        if (Access::phone($customer) === null) {
            return $this->json(['message' => __('أضف رقم هاتف العميل أولًا — يدخل به إلى بوابته.')], 422);
        }

        $digits = (string) preg_replace('/\D+/', '', Digits::western($data['device']));
        $device = Devices::approve($customer, $digits, auth()->user());
        if ($device === null) {
            return $this->json(['message' => __('لا جهاز ينتظر لهذا العميل بهذا الرقم. تأكد من رقم الهاتف الذي كتبه ومن الرقم الظاهر على شاشته. وبعد ثلاثة أخطاء يكتب رقمه من جديد.')], 422);
        }
        Access::log('اعتمد جهازًا لبوابة العميل'.($device === '' ? '' : ' ('.$device.')'), $customer);

        return null;
    }

    /* ─────────── ملفُّ العميل ─────────── */

    public function revoke(int $id): JsonResponse
    {
        $customer = $this->customer($id, manage: true);

        Access::revoke($customer, auth()->user());
        Access::log('ألغى دخول العميل إلى بوابته', $customer);

        return $this->json(['state' => self::state($customer, auth()->user())]);
    }

    /* ─────────── الرابطُ العامّ ─────────── */

    /** ورقةُ QR تُطبع وتوضع بجانب الكاشير — الرابطُ العامُّ وحده، لا رابطَ عميل */
    public function poster(): HttpResponse
    {
        $this->open(Portal::mayShare(auth()->user()));
        $bid = (int) Demo::bid();

        return response()->view('portal.ribbon.poster', [
            'shop' => InvoiceBranding::name($bid),
            'logo' => InvoiceBranding::logo($bid) ?? asset('brand/ribbon/logo.svg'),
            'url' => Portal::url(),
            'qr' => Qr::svg(Portal::url(), 320),
        ]);
    }

    /* ─────────── الإعدادات ─────────── */

    public function settings(): Response
    {
        $this->open(Portal::mayManage(auth()->user()));
        $bid = (int) Demo::bid();
        $staff = Portal::staff($bid);

        $links = CustomerPortalLink::where('business_id', $bid)->whereNull('revoked_at')->whereNotNull('last_used_at')
            ->with('customer:id,name')->latest('last_used_at')->latest('id')->paginate(20)
            ->through(fn (CustomerPortalLink $l) => [
                'id' => (int) $l->id,
                'customer' => $l->customer?->name ?? '—',
                'since' => $l->created_at?->format('Y-m-d H:i'),
                'used' => $l->last_used_at?->format('Y-m-d H:i'),
                'devices' => Access::devices($l),
                'revokeUrl' => route('admin.settings.portal.revoke', $l->id, false),
            ]);

        return Inertia::render('Admin/Settings/CustomerPortal', [
            'portal' => Portal::panel(auth()->user()),
            'urls' => [
                'self' => route('admin.settings.portal.index', [], false),
                'staff' => route('admin.settings.portal.staff', [], false),
                'revokeAll' => route('admin.settings.portal.revokeAll', [], false),
            ],
            'links' => $links->items(),
            'pagination' => Pagination::meta($links),
            'employees' => User::where('business_id', $bid)->where('status', 'نشط')->orderBy('name')->get()
                ->reject(fn (User $u) => Permissions::isOwner($u))
                ->map(fn (User $u) => [
                    'id' => (int) $u->id,
                    'name' => $u->name,
                    'role' => $u->roleLabel(),
                    'share' => in_array((int) $u->id, $staff['share'], true),
                    'manage' => in_array((int) $u->id, $staff['manage'], true),
                ])->values()->all(),
            // ومن يديرها يلغي الدخول، ولا يختار من يشاركها إلّا صاحبُ النشاط
            'mayGrant' => Permissions::isOwner(auth()->user()),
        ]);
    }

    public function staff(Request $request): RedirectResponse
    {
        $this->open(Portal::mayManage(auth()->user()));
        abort_unless(Permissions::isOwner(auth()->user()), 403);

        $data = $request->validate([
            'share' => ['present', 'array', 'max:500'], 'share.*' => ['integer'],
            'manage' => ['present', 'array', 'max:500'], 'manage.*' => ['integer'],
        ], [], [
            'share' => __('من يعمل على البوابة'), 'share.*' => __('الموظف'),
            'manage' => __('يدير البوابة'), 'manage.*' => __('الموظف'),
        ]);

        Portal::saveStaff((int) Demo::bid(), $data['share'], $data['manage']);
        Activity::log('updated', 'عدّل صلاحيات بوابة العملاء');

        return back()->with('toast', ['msg' => __('حُفظت صلاحيات بوابة العملاء'), 'type' => 'success']);
    }

    public function revokeLink(int $link): RedirectResponse
    {
        $this->open(Portal::mayManage(auth()->user()));

        $row = CustomerPortalLink::where('business_id', Demo::bid())->whereNull('revoked_at')->findOrFail($link);
        $customer = Customer::withTrashed()->where('business_id', Demo::bid())->findOrFail($row->customer_id);
        Access::revoke($customer, auth()->user());
        Access::log('ألغى دخول العميل إلى بوابته', $customer);

        return back()->with('toast', ['msg' => __('أُلغي الدخول وخرج العميل من أجهزته'), 'type' => 'success']);
    }

    public function revokeAll(): RedirectResponse
    {
        $this->open(Portal::mayManage(auth()->user()));

        $n = Access::revokeAll((int) Demo::bid(), auth()->user());
        Activity::log('updated', 'ألغى دخول عملاء البوابة كلِّهم ('.$n.')');

        return back()->with('toast', ['msg' => __('أُلغي دخول العملاء كلهم: :n', ['n' => $n]), 'type' => 'success']);
    }

    /* ─────────────────────────────────── */

    /** حالُ دخول العميل كما تعرضها شاشتُه — أو `null` فلا قسمَ فيها */
    public static function panelFor(?User $user, int $customerId): ?array
    {
        if (! Portal::mayShare($user)) {
            return null;
        }

        $customer = Customer::where('business_id', $user->business_id)->find($customerId);

        return $customer ? self::state($customer, $user) : null;
    }

    private static function state(Customer $customer, ?User $user): array
    {
        $link = Access::live($customer);
        $in = $link?->last_used_at !== null;

        return [
            /*
             * والعناوينُ من هنا لا من جدول المسارات في المتصفّح: ذاك يُرسل لكلّ تاجر، وهذه
             * لا تُذكر فيه (`config/ziggy.php`). ومن يشارك ولا يدير لا يصله عنوانُ الإلغاء.
             */
            'urls' => [
                'device' => route('admin.customers.portal.device', $customer->id, false),
                'revoke' => Portal::mayManage($user) ? route('admin.customers.portal.revoke', $customer->id, false) : null,
            ],
            'url' => Portal::url(),
            'hasPhone' => Access::phone($customer) !== null,
            'waiting' => Devices::of($customer),
            'since' => $in ? $link->created_at?->format('Y-m-d H:i') : null,
            'used' => $in ? $link->last_used_at->format('Y-m-d H:i') : null,
            'devices' => $link ? Access::devices($link) : 0,
        ];
    }

    /** متجرُه وصلاحيّتُه: سواه لا بابَ هنا أصلًا، ومن لا يملكها يُردّ */
    private function open(bool $may): void
    {
        $user = auth()->user();
        abort_unless(Portal::on($user?->business_id !== null ? (int) $user->business_id : null), 404);
        abort_unless($may, 403);
    }

    private function customer(int $id, bool $manage = false): Customer
    {
        $this->open($manage ? Portal::mayManage(auth()->user()) : Portal::mayShare(auth()->user()));

        return Customer::where('business_id', Demo::bid())->findOrFail($id);
    }

    /** وما يُردّ لا يُخزَّن في متصفّحٍ ولا وسيط */
    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store, private');
    }
}
