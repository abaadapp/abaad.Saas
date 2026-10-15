<?php

namespace App\Support\Lists;

use App\Models\Business;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Support\Search;
use App\Support\Signup;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * من فتح متجرَه بنفسه — قسمٌ في صفحة الشركات، وصفحتُه الكاملة.
 *
 * ═══ صفوفُ الشركات نفسُها لا نسخةٌ منها ═══
 *
 * المصدرُ `businesses.registration_source = self_signup` (`Business::selfSignup`)
 * — الختمُ الذي يكتبه `Signup::register` وحده. ولا جدولَ ثانٍ ولا عميلَ CRM:
 * المسجِّلُ شركةٌ حقيقيّة، والقسمُ نافذةٌ على صفوفها.
 *
 * ═══ والحالاتُ من تعاريف `Business` لا من تعريفٍ جديد ═══
 *
 *   - في التجربة: `Business::trialing` — ساريةٌ ولا فاتورةَ مدفوعة.
 *   - مشترك: `Business::subscribed` — ساريةٌ ولها فاتورةٌ مدفوعة.
 *   - انتهت تجربتُه بلا دفع: انقضى `ends_at` قبل اليوم (حدُّ `scopeLive`
 *     نفسُه) ولا فاتورةَ مدفوعة — أيًّا كانت حالتُه.
 *
 * وما لا يقع في أيٍّ منها لا يُرمى في أقربها: شركةٌ عُطّلت في تجربتها، أو
 * دفعت ثمّ انقضى اشتراكُها، تُعرض بحالتها هي. فالمجموعُ قد يزيد على الثلاثة.
 *
 * ═══ وكلُّ عمودٍ استعلامٌ فرعيّ — لا استعلامٌ لكلّ صفّ ═══
 *
 * كقائمة الشركات (`BusinessesList`): آخرُ بيعةٍ وبريدُ المالك وما مثلهما
 * أعمدةٌ في الاستعلام نفسِه، فالصفحةُ بعشرين صفًّا كالصفحة بصفّ.
 */
final class SelfSignupsList
{
    public const TRIALING = 'trialing';

    public const EXPIRED = 'expired';

    public const SUBSCRIBED = 'subscribed';

    /** ما يُرشَّح به — وما سواه يُتجاهل لا يُردّ */
    public const STATES = [self::TRIALING, self::EXPIRED, self::SUBSCRIBED];

    /** صفوفُ القسم في صفحة الشركات — وما بعدها في «عرض الكل» */
    public const RECENT = 5;

    /** صفوفُ الصفحة الكاملة */
    public const PER_PAGE = 20;

    /**
     * كم يومًا يبقى التسجيلُ خبرًا في جرس المنصّة.
     *
     * أسبوعٌ: خبرُ «سجّل اليوم» لا شأنُ متابعةٍ طوال التجربة — المتابعةُ في
     * القسم. وهو دون عمر الإخفاء (`Demo::DISMISSAL_DAYS`) فلا يعود ما أُخفي.
     */
    public const BELL_DAYS = 7;

    /**
     * الأرقامُ الأربعة — كلُّها من التسجيلات الذاتيّة وحدها.
     *
     * والتجريبيّةُ خارجها بـ`real()` داخل `selfSignup`، واليدويّةُ والقديمةُ
     * خارجها بالختم: لا يدخلها إلّا `self_signup`.
     *
     * @return array{total: int, trialing: int, expired: int, subscribed: int}
     */
    public static function stats(): array
    {
        return [
            'total' => Business::selfSignup()->count(),
            'trialing' => Business::selfSignup()->trialing()->count(),
            'expired' => self::expiredUnpaid(Business::selfSignup())->count(),
            'subscribed' => Business::selfSignup()->subscribed()->count(),
        ];
    }

    /** انقضت مدّتُه قبل اليوم ولم يدفع قطّ */
    public static function expiredUnpaid(Builder $q): Builder
    {
        return $q->whereNotNull('ends_at')
            ->where('ends_at', '<', now()->startOfDay())
            ->whereNotExists(fn ($w) => $w->selectRaw('1')->from('invoices')
                ->whereColumn('invoices.business_id', 'businesses.id')
                ->where('invoices.status', 'مدفوعة'));
    }

    public static function query(Request $request): Builder
    {
        $owner = fn (string $column) => User::select($column)
            ->whereColumn('users.business_id', 'businesses.id')
            ->where('role', 'admin')
            ->orderBy('id')
            ->limit(1);

        $sales = fn (string $aggregate) => Order::selectRaw($aggregate.'(ordered_at)')
            ->whereColumn('orders.business_id', 'businesses.id')
            ->sold();

        $q = Business::selfSignup()
            ->with('plan:id,name')
            ->addSelect([
                'owner_email' => $owner('email'),
                'owner_last_login' => $owner('last_login_at'),
                // حجمُ الفريق إعدادٌ لا عمود — انظر `Signup::register`
                'team_size' => Setting::select('value')
                    ->whereColumn('settings.business_id', 'businesses.id')
                    ->where('key', Signup::TEAM_SIZE)
                    ->limit(1),
                'first_sale' => $sales('MIN'),
                'last_sale' => $sales('MAX'),
            ])
            ->withCount('products')
            ->withExists(['invoices as paid' => fn ($w) => $w->where('status', 'مدفوعة')]);

        if ($s = Search::term($request)) {
            $op = Search::like();
            $q->where(fn ($w) => $w->where('name', $op, "%{$s}%")
                ->orWhere('owner_name', $op, "%{$s}%")
                ->orWhere('email', $op, "%{$s}%")
                ->orWhere('phone', $op, "%{$s}%")
                ->orWhereHas('users', fn ($u) => $u->where('role', 'admin')->where('email', $op, "%{$s}%")));
        }

        match (self::state($request)) {
            self::TRIALING => $q->trialing(),
            self::SUBSCRIBED => $q->subscribed(),
            self::EXPIRED => self::expiredUnpaid($q),
            default => null,
        };

        return $q->orderByDesc('created_at')->orderByDesc('id');
    }

    /** الحالةُ المطلوبة إن كانت ممّا يُرشَّح به — وإلّا لا ترشيح */
    public static function state(Request $request): ?string
    {
        $state = $request->query('state');

        return in_array($state, self::STATES, true) ? $state : null;
    }

    /** أحدثُ التسجيلات — صفوفُ القسم في صفحة الشركات */
    public static function recent(): array
    {
        return self::rows(self::query(new Request)->limit(self::RECENT)->get());
    }

    /**
     * صفوفٌ للواجهة — وحالةُ كلٍّ منها من `scopeLive` نفسِه.
     *
     * والسريانُ يُسأل مرّةً للصفحة كلّها لا مرّةً لكلّ صفّ، وبالنطاق نفسِه
     * الذي تعدّ به الأرقامُ — فلا يقول الصفُّ «في التجربة» والرقمُ لا يعدّه.
     *
     * @param  Collection<int, Business>  $page
     */
    public static function rows(Collection $page): array
    {
        $live = $page->isEmpty()
            ? collect()
            : Business::live()->whereKey($page->modelKeys())->pluck('id')->flip();

        $today = now()->startOfDay();

        return $page->map(function (Business $b) use ($live, $today) {
            $paid = (bool) $b->paid;
            $isLive = $live->has($b->id);

            $state = match (true) {
                $isLive && $paid => self::SUBSCRIBED,
                $isLive => self::TRIALING,
                ! $paid && $b->ends_at !== null && $b->ends_at->lt($today) => self::EXPIRED,
                default => null,
            };

            return [
                'id' => $b->id,
                'name' => $b->name,
                'owner' => $b->owner_name,
                'type' => $b->type,
                'phone' => $b->phone,
                // بريدُ التواصل المكتوب عند التسجيل — وبريدُ الدخول في عمودٍ آخر
                'contactEmail' => $b->email,
                'loginEmail' => $b->owner_email,
                'teamSize' => $b->team_size,
                'address' => $b->address,
                'city' => $b->city,
                'registeredAt' => $b->created_at?->format('Y-m-d H:i'),
                'plan' => $b->plan?->name,
                'trialStarts' => $b->starts_at?->format('Y-m-d'),
                'trialEnds' => $b->ends_at?->format('Y-m-d'),
                'daysLeft' => Tenancy::daysLeft($b),
                'state' => $state,
                'status' => $b->status,
                // ختمُ شاشة التهيئة (`SetupController::confirm`) — لا تخمينٌ من الاسم
                'setupConfirmedAt' => $b->identity_confirmed_at?->format('Y-m-d H:i'),
                'lastLogin' => $b->owner_last_login ? Carbon::parse($b->owner_last_login)->format('Y-m-d H:i') : null,
                'products' => (int) $b->products_count,
                'firstSale' => $b->first_sale ? Carbon::parse($b->first_sale)->format('Y-m-d') : null,
                'lastSale' => $b->last_sale ? Carbon::parse($b->last_sale)->format('Y-m-d') : null,
            ];
        })->values()->all();
    }
}
