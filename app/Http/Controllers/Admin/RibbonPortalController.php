<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerPortalLink;
use App\Models\Order;
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
 * بوابةُ عملاء RIBBON عند الموظّف — يُنشئ رابطَ العميل وينسخه ويعرض رمزَه، ويديرها صاحبُ النشاط.
 *
 * لمتجر سعود وحده: كلُّ بابٍ هنا يردّ 404 لسواه ولو كُتب عنوانُه بيد (`open`).
 * وصلاحيّةُ المشاركة لا تفتح فاتورةً ولا نقاطًا: لا يُردّ من هنا إلّا الرابط. وإلغاؤه وإعادةُ
 * إصداره لمن يدير البوابة: كلاهما يُخرج العميلَ من أجهزته.
 *
 * ولا رسالةَ من هنا ولا قناةَ مراسلة: الرابطُ يُنسخ أو يُمسح رمزُه، ولا يخرج من النظام بغير يد الموظّف.
 */
class RibbonPortalController extends Controller
{
    /* ─────────── ملفُّ العميل ─────────── */

    public function share(int $id): JsonResponse
    {
        return $this->reply($this->customer($id), fn (Customer $c) => Access::share($c, auth()->user()));
    }

    public function reissue(int $id): JsonResponse
    {
        return $this->reply($this->customer($id, manage: true), fn (Customer $c) => Access::reissue($c, auth()->user()));
    }

    public function revoke(int $id): JsonResponse
    {
        $customer = $this->customer($id, manage: true);

        if (Access::revoke($customer, auth()->user())) {
            Access::log('ألغى رابط بوابة العميل', $customer);
        }

        return $this->json(['state' => self::state($customer, auth()->user())]);
    }

    /* ─────────── الصندوق ─────────── */

    /** رابطُ عميلِ هذه البيعة ليُنسخ — بعد أن تمّت، ولا يمسّها فشلُه */
    public function shareSale(string $number): JsonResponse
    {
        $this->open(Portal::mayShare(auth()->user()));

        $order = Order::where('business_id', Demo::bid())->where('is_held', false)->where('number', $number)->firstOrFail();
        abort_if($order->customer_id === null, 404);

        return $this->reply($this->customer((int) $order->customer_id), fn (Customer $c) => Access::share($c, auth()->user()), sale: true);
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
            ->with(['customer:id,name', 'creator:id,name'])->latest('id')->paginate(20)
            ->through(fn (CustomerPortalLink $l) => [
                'id' => (int) $l->id,
                'customer' => $l->customer?->name ?? '—',
                'by' => $l->creator?->name,
                'issued' => $l->created_at?->format('Y-m-d H:i'),
                'used' => $l->last_used_at?->format('Y-m-d H:i'),
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
            // ومن يديرها يلغي الروابط، ولا يختار من يشاركها إلّا صاحبُ النشاط
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
            'share' => __('من يشارك روابط العملاء'), 'share.*' => __('الموظف'),
            'manage' => __('يدير البوابة'), 'manage.*' => __('الموظف'),
        ]);

        Portal::saveStaff((int) Demo::bid(), $data['share'], $data['manage']);
        Activity::log('updated', 'عدّل من يشارك روابط بوابة العملاء');

        return back()->with('toast', ['msg' => __('حُفظت صلاحيات مشاركة الرابط'), 'type' => 'success']);
    }

    public function revokeLink(int $link): RedirectResponse
    {
        $this->open(Portal::mayManage(auth()->user()));

        $row = CustomerPortalLink::where('business_id', Demo::bid())->whereNull('revoked_at')->findOrFail($link);
        $customer = Customer::withTrashed()->where('business_id', Demo::bid())->findOrFail($row->customer_id);
        Access::revoke($customer, auth()->user());
        Access::log('ألغى رابط بوابة العميل', $customer);

        return back()->with('toast', ['msg' => __('أُلغي الرابط وخرج صاحبه من أجهزته'), 'type' => 'success']);
    }

    public function revokeAll(): RedirectResponse
    {
        $this->open(Portal::mayManage(auth()->user()));

        $n = Access::revokeAll((int) Demo::bid(), auth()->user());
        Activity::log('updated', 'ألغى روابط بوابة العملاء كلَّها ('.$n.')');

        return back()->with('toast', ['msg' => __('أُلغيت روابط الوصول كلها: :n', ['n' => $n]), 'type' => 'success']);
    }

    /* ─────────────────────────────────── */

    /** حالُ رابط العميل كما تعرضها شاشتُه — أو `null` فلا زرَّ فيها */
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
        $manage = Portal::mayManage($user);

        return [
            /*
             * والعناوينُ من هنا لا من جدول المسارات في المتصفّح: ذاك يُرسل لكلّ تاجر، وهذه
             * لا تُذكر فيه (`config/ziggy.php`).
             */
            'urls' => [
                'share' => route('admin.customers.portal.share', $customer->id, false),
                // ومن يشارك ولا يدير لا يصله عنوانُهما، ولو كتبه بيده رُدّ
                'reissue' => $manage ? route('admin.customers.portal.reissue', $customer->id, false) : null,
                'revoke' => $manage ? route('admin.customers.portal.revoke', $customer->id, false) : null,
            ],
            'hasPhone' => Access::phone($customer) !== null,
            'issued' => $link?->created_at?->format('Y-m-d H:i'),
            'used' => $link?->last_used_at?->format('Y-m-d H:i'),
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

    /** الرابطُ — ومعه في ملفّ العميل رمزُه وحالُه. ولا يُردّ رمزُه للصندوق: زرُّه ينسخ وحسب */
    private function reply(Customer $customer, callable $make, bool $sale = false): JsonResponse
    {
        $made = $make($customer);

        if ($made === null) {
            return $this->json(['message' => __('أضف رقم هاتف العميل أولًا — يدخل به إلى بوابته.')], 422);
        }
        if ($made['created']) {
            Access::log('أصدر رابط بوابة العميل', $customer);
        }

        return $this->json(['url' => $made['url']] + ($sale ? [] : [
            'qr' => Qr::dataUri($made['url'], 320),
            'state' => self::state($customer, auth()->user()),
        ]));
    }

    /** والرابطُ لا يُخزَّن في متصفّحٍ ولا وسيط */
    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store, private');
    }
}
