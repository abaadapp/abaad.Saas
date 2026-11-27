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
 * ولا رابطَ لعميلٍ بعينه ولا رمزَ يُعطى له: يكتب رقمَه فتفتح صفحتُه (`RibbonPortal\Access`).
 * وإيقافُ صفحةِ عميلٍ والسماحُ بها لمن يدير.
 *
 * ولا رسالةَ من هنا ولا قناةَ مراسلة.
 */
class RibbonPortalController extends Controller
{
    /* ─────────── ملفُّ العميل ─────────── */

    /** يخرج من أجهزته، ولا تُفتح صفحتُه برقمه حتّى يُسمح بها */
    public function stop(int $id): JsonResponse
    {
        $customer = $this->customer($id);

        Access::stop($customer, auth()->user());
        Access::log('أوقف دخول العميل إلى بوابته', $customer);

        return $this->json(['state' => self::state($customer, auth()->user())]);
    }

    public function allow(int $id): JsonResponse
    {
        $customer = $this->customer($id);

        Access::allow($customer);
        Access::log('سمح بدخول العميل إلى بوابته', $customer);

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

        // من دخل، ومن أوقف المتجرُ صفحتَه ولو لم يدخل
        $links = CustomerPortalLink::where('business_id', $bid)
            ->where(fn ($q) => $q->where('revoked_why', Access::STOPPED)->orWhere(fn ($in) => $in->whereNull('revoked_at')->whereNotNull('last_used_at')))
            ->with('customer:id,name')->orderByRaw('last_used_at is null')->latest('last_used_at')->latest('id')->paginate(20);
        $devices = Access::devices($links->pluck('id')->all());
        $links = $links->through(fn (CustomerPortalLink $l) => [
            'id' => (int) $l->id,
            'customer' => $l->customer?->name ?? '—',
            'since' => $l->last_used_at === null ? null : $l->created_at?->format('Y-m-d H:i'),
            'used' => $l->last_used_at?->format('Y-m-d H:i'),
            'devices' => $devices[$l->id] ?? 0,
            'stopped' => $l->revoked_at !== null,
            'url' => route('admin.settings.portal.link', $l->id, false),
        ]);

        return Inertia::render('Admin/Settings/CustomerPortal', [
            'portal' => Portal::panel(auth()->user()),
            'urls' => [
                'self' => route('admin.settings.portal.index', [], false),
                'staff' => route('admin.settings.portal.staff', [], false),
                'signOut' => route('admin.settings.portal.signOut', [], false),
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
            // ومن يديرها يوقف الدخول، ولا يختار من يشاركها إلّا صاحبُ النشاط
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

    public function stopLink(int $link): RedirectResponse
    {
        $customer = $this->linked($link, null);
        Access::stop($customer, auth()->user());
        Access::log('أوقف دخول العميل إلى بوابته', $customer);

        return back()->with('toast', ['msg' => __('أُوقف الدخول وخرج العميل من أجهزته'), 'type' => 'success']);
    }

    public function allowLink(int $link): RedirectResponse
    {
        $customer = $this->linked($link, Access::STOPPED);
        Access::allow($customer);
        Access::log('سمح بدخول العميل إلى بوابته', $customer);

        return back()->with('toast', ['msg' => __('سُمح بالدخول، وتفتح صفحة العميل برقم هاتفه'), 'type' => 'success']);
    }

    public function signOut(): RedirectResponse
    {
        $this->open(Portal::mayManage(auth()->user()));

        $n = Access::signOutAll((int) Demo::bid());
        Activity::log('updated', 'أخرج عملاء البوابة كلَّهم من أجهزتهم ('.$n.')');

        return back()->with('toast', ['msg' => __('خرج العملاء من أجهزتهم: :n', ['n' => $n]), 'type' => 'success']);
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
             * والعنوانُ من هنا لا من جدول المسارات في المتصفّح: ذاك يُرسل لكلّ تاجر، وهذا لا
             * يُذكر فيه (`config/ziggy.php`). ومن يشارك ولا يدير لا يصله.
             */
            'manage' => Portal::mayManage($user) ? route('admin.customers.portal.stop', $customer->id, false) : null,
            'url' => Portal::url(),
            'phone' => Access::phone($customer) === null ? null : Access::shown($customer),
            'stopped' => Access::stopped($customer),
            'since' => $in ? $link->created_at?->format('Y-m-d H:i') : null,
            'used' => $in ? $link->last_used_at->format('Y-m-d H:i') : null,
            'devices' => $link ? Access::devices([$link->id])[$link->id] ?? 0 : 0,
        ];
    }

    /** متجرُه وصلاحيّتُه: سواه لا بابَ هنا أصلًا، ومن لا يملكها يُردّ */
    private function open(bool $may): void
    {
        $user = auth()->user();
        abort_unless(Portal::on($user?->business_id !== null ? (int) $user->business_id : null), 404);
        abort_unless($may, 403);
    }

    private function customer(int $id): Customer
    {
        $this->open(Portal::mayManage(auth()->user()));

        return Customer::where('business_id', Demo::bid())->findOrFail($id);
    }

    /** صاحبُ هذا الإذن — ولو حُذف إلى السلّة. `$why` سببُ إغلاقه: موقوفٌ أو قائم */
    private function linked(int $link, ?string $why): Customer
    {
        $this->open(Portal::mayManage(auth()->user()));

        $row = CustomerPortalLink::where('business_id', Demo::bid())->where('revoked_why', $why)->findOrFail($link);

        return Customer::withTrashed()->where('business_id', Demo::bid())->findOrFail($row->customer_id);
    }

    /** وما يُردّ لا يُخزَّن في متصفّحٍ ولا وسيط */
    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store, private');
    }
}
