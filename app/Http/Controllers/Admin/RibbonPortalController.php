<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerPortalLink;
use App\Models\User;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\InvoiceBranding;
use App\Support\Pagination;
use App\Support\Permissions;
use App\Support\RibbonPortal\Access;
use App\Support\RibbonPortal\Code;
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
 * ولا رابطَ لعميلٍ بعينه: العميلُ يدخل برقمه ورمزٍ من فاتورته (`RibbonPortal\Code`).
 * ومن لا فاتورةَ معه يقرأ له الموظّفُ رمزًا يقف بنهاية الغد. وإلغاءُ الدخول لمن يدير.
 *
 * ولا رسالةَ من هنا ولا قناةَ مراسلة.
 */
class RibbonPortalController extends Controller
{
    /* ─────────── ملفُّ العميل ─────────── */

    /** رمزٌ يُقرأ لعميلٍ لا فاتورةَ معه — ويُكتب في السجلّ من قرأه ولمن، بلا الرمز */
    public function code(int $id): JsonResponse
    {
        $customer = $this->customer($id);

        if (Access::phone($customer) === null) {
            return $this->json(['message' => __('أضف رقم هاتف العميل أولًا — يدخل به إلى بوابته.')], 422);
        }
        Access::log('عرض رمز دخول بوابة العميل', $customer);

        return $this->json(['code' => Code::spaced(Code::daily($customer))]);
    }

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

        $links = CustomerPortalLink::where('business_id', $bid)->whereNull('revoked_at')
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

        return [
            /*
             * والعناوينُ من هنا لا من جدول المسارات في المتصفّح: ذاك يُرسل لكلّ تاجر، وهذه
             * لا تُذكر فيه (`config/ziggy.php`). ومن يشارك ولا يدير لا يصله عنوانُ الإلغاء.
             */
            'urls' => [
                'code' => route('admin.customers.portal.code', $customer->id, false),
                'revoke' => Portal::mayManage($user) ? route('admin.customers.portal.revoke', $customer->id, false) : null,
            ],
            'url' => Portal::url(),
            'hasPhone' => Access::phone($customer) !== null,
            'since' => $link?->created_at?->format('Y-m-d H:i'),
            'used' => $link?->last_used_at?->format('Y-m-d H:i'),
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
