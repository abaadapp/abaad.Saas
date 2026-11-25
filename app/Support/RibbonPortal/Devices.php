<?php

namespace App\Support\RibbonPortal;

use App\Models\Customer;
use App\Models\CustomerPortalRequest as Waiting;
use App\Models\CustomerPortalSession as Session;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * جهازُ العميل — يكتب فيه رقمَ هاتفه، ويعتمده موظّفٌ مرّةً واحدة.
 *
 * ═══ لمَ لا يكفي الرقم ═══
 *
 * يعرفه كلُّ من يعرف صاحبَه، والجهازُ الجديد لا يقول لمن هو. فما بين الرقم والجهاز شاهدٌ واحد:
 * موظّفٌ يرى العميلَ أو يتّصل هو برقمه المسجَّل. وقبل شهادته لا يُقرأ من الحساب حرف — ولا حتّى
 * أنّ الرقم مسجَّل. وكيف تحقّق يُكتب مع اسمه (`HOW`).
 *
 * ═══ والرقمُ الذي على الشاشة ═══
 *
 * ستُّ خاناتٍ تظهر على جهاز العميل وحده، ولا تظهر للموظّف: يكتبها هو. فلا يُعتمد جهازُ من كتب
 * رقمَ غيره من بعيد — لا يعرف الموظّفُ خاناتِه — وإن انتظر جهازان لرقمٍ واحد اعتُمد الذي أمامه.
 * والعميلُ لا يكتب منها شيئًا. وتعيش عشرَ دقائق، ويطلب الجهازُ نفسُه غيرَها بلا كتابة هاتفه ثانية.
 *
 * ═══ ولكلّ رقمٍ صفّ ═══
 *
 * مسجَّلٍ أو غيره: الردُّ واحدٌ وزمنُه واحد. ولا يُعتمد إلّا لعميلٍ في السجلّ، فلا حسابَ يُنشأ هنا.
 */
final class Devices
{
    /** كم دقيقةً يصلح رقمُ الجهاز */
    public const MINUTES = 10;

    public const DIGITS = 6;

    /** كيف تحقّق الموظّف: العميلُ أمامه، أو اتّصل هو برقمه المسجَّل */
    public const HOW = ['shop', 'call'];

    /** أخطاءُ الموظّف في الخانات قبل أن تُغلق الطلبات */
    private const TRIES = 3;

    /** كم جهازًا ينتظر لرقمٍ واحد — والأقدمُ يسقط */
    private const PER_PHONE = 3;

    /* ─────────────────────────── العميل ─────────────────────────── */

    /** طلبُ هذا الجهاز وهو ينتظر — أو `null` */
    public static function mine(int $businessId, Request $request): ?Waiting
    {
        $raw = Access::raw($request);

        return $raw === null ? null : self::live($businessId)->where('token_hash', Access::hash($raw))->first();
    }

    /** آخرُ طلبٍ كتبه هذا الجهازُ اليوم ولم يُعتمد — ولو انقضى رقمُه: ليطلب غيرَه، لا ليُفتح له شيء */
    public static function last(int $businessId, Request $request): ?Waiting
    {
        $raw = Access::raw($request);

        return $raw === null ? null : Waiting::where('business_id', $businessId)->where('token_hash', Access::hash($raw))
            ->whereNull('approved_at')->where('created_at', '>', now()->subDay())->first();
    }

    /** كم ثانيةً بقيت لرقمه — أو صفر: انقضى أو أُغلق */
    public static function left(Waiting $row): int
    {
        return $row->approved_at !== null || $row->closed_at !== null ? 0 : max(0, (int) now()->diffInSeconds($row->expires_at, false));
    }

    /** أهو ينتظر بهذا الرقم نفسِه؟ — فلا يُكتب طلبٌ ثانٍ ولا تُحسب محاولة */
    public static function already(int $businessId, string $key, Request $request): bool
    {
        $mine = self::mine($businessId, $request);

        return $mine !== null && hash_equals($mine->phone_hash, $key);
    }

    /** يكتب طلبَه ويردّ رمزَ الجهاز — `$key` بصمةُ رقم الهاتف (`key`) */
    public static function ask(int $businessId, string $key, Request $request): string
    {
        return DB::transaction(function () use ($businessId, $key, $request) {
            self::forget($businessId, $request);

            $others = self::live($businessId)->where('phone_hash', $key)->orderByDesc('id')->get();
            Waiting::whereKey($others->slice(self::PER_PHONE - 1)->modelKeys())->update(['closed_at' => now(), 'updated_at' => now()]);

            $raw = bin2hex(random_bytes(32));
            Waiting::create([
                'business_id' => $businessId,
                'phone_hash' => $key,
                'token_hash' => Access::hash($raw),
                'device' => self::label($request),
                'expires_at' => now()->addMinutes(self::MINUTES),
            ]);
            Waiting::where('business_id', $businessId)->where('expires_at', '<', now()->subDays(30))->whereNull('approved_at')->delete();

            return $raw;
        });
    }

    /** هذا الجهازُ عدل عن طلبه */
    public static function forget(int $businessId, Request $request): void
    {
        $mine = self::mine($businessId, $request);
        if ($mine !== null) {
            self::close($mine);
        }
    }

    /** خاناتُه الستّ — من رمزه ومفتاح الخادم، لا تُحفظ */
    public static function number(Waiting $row): string
    {
        return (string) (100000 + hexdec(substr(hash_hmac('sha256', 'device|'.$row->token_hash, Access::secret()), 0, 12)) % 900000);
    }

    /* ─────────────────────────── الموظّف ─────────────────────────── */

    /**
     * من ينتظر — العملاءُ المسجَّلون وحدهم، ولا خاناتِ جهازٍ هنا: تُقرأ من شاشة صاحبه.
     *
     * @return list<array{id: int, name: string, phone: string, twin: bool, devices: list<array{device: string, until: string}>}>
     */
    public static function waiting(int $businessId): array
    {
        $rows = self::live($businessId)->orderByDesc('id')->get()->groupBy('phone_hash');
        if ($rows->isEmpty()) {
            return [];
        }

        $found = Customer::where('business_id', $businessId)
            ->where(fn ($q) => $q->where('phone', '!=', '')->orWhere('contact_phone', '!=', ''))
            ->get(['id', 'business_id', 'name', 'phone', 'contact_phone'])
            ->map(fn (Customer $c) => ['customer' => $c, 'phone' => (string) Access::phone($c)])
            ->filter(fn (array $x) => $x['phone'] !== '' && $rows->has(self::key($x['phone'])));
        $shared = $found->countBy('phone');

        return $found->map(fn (array $x) => [
            'id' => (int) $x['customer']->id,
            'name' => (string) $x['customer']->name,
            'phone' => Access::shown($x['customer']),
            // رقمٌ لسجلّين: يُقال للموظّف ليختار صاحبَه
            'twin' => $shared[$x['phone']] > 1,
            'devices' => self::shown($rows[self::key($x['phone'])]),
        ])->values()->all();
    }

    /** @return list<array{device: string, until: string}> أجهزةُ هذا العميل التي تنتظر */
    public static function of(Customer $customer): array
    {
        $phone = Access::phone($customer);

        return $phone === null ? [] : self::shown(self::live((int) $customer->business_id)->where('phone_hash', self::key($phone))->orderByDesc('id')->get());
    }

    /**
     * يعتمد الجهازَ الذي خاناتُه هذه — ويردّ اسمَه، أو `null` ولا شيءَ تغيّر للعميل.
     *
     * وخطأٌ ثالثٌ يُغلق ما ينتظر لهذا الرقم: فلا تُجرَّب الخانات.
     *
     * @param  string  $how  كيف تحقّق الموظّف (`HOW`)
     */
    public static function approve(Customer $customer, string $digits, User $by, string $how): ?string
    {
        $phone = Access::phone($customer);
        if ($phone === null) {
            return null;
        }

        return DB::transaction(function () use ($customer, $phone, $digits, $by, $how) {
            $rows = self::live((int) $customer->business_id)->where('phone_hash', self::key($phone))->lockForUpdate()->get();
            $match = $rows->filter(fn (Waiting $w) => hash_equals(self::number($w), $digits));
            // وجهازان لرقمٍ واحدٍ بخاناتٍ واحدة: لا يُعرف أيُّهما أمامه، فلا يُعتمد أحدُهما
            $row = $match->count() === 1 ? $match->first() : null;

            if ($row === null) {
                $rows->each(fn (Waiting $w) => $w->forceFill(['tries' => $w->tries + 1, 'closed_at' => $w->tries + 1 >= self::TRIES ? now() : null])->save());

                return null;
            }

            $link = Access::grant($customer);
            if ($link === null) {
                return null;
            }
            Access::open($link, $row->token_hash);
            $row->forceFill(['customer_id' => $customer->id, 'approved_by' => $by->id, 'approved_how' => $how, 'approved_at' => now()])->save();

            return (string) $row->device;
        });
    }

    /**
     * أجهزةُ كلّ إذنٍ القائمة: من اعتمدها وكيف ومتى. ومن دخل برابطٍ شخصيٍّ قبل الاعتماد بلا اسم.
     *
     * @param  list<int>  $links
     * @return array<int, list<array{device: string, by: string|null, how: string|null, at: string}>>
     */
    public static function approvals(array $links): array
    {
        $sessions = Session::whereIn('link_id', $links)->whereNull('revoked_at')->where('expires_at', '>', now())->orderByDesc('id')->get(['link_id', 'token_hash', 'created_at']);
        $rows = Waiting::with('approver:id,name')->whereIn('token_hash', $sessions->pluck('token_hash'))->get()->keyBy('token_hash');

        return $sessions->groupBy('link_id')->map(fn (Collection $mine) => $mine->map(fn (Session $s) => [
            'device' => (string) $rows->get($s->token_hash)?->device,
            'by' => $rows->get($s->token_hash)?->approver?->name,
            'how' => $rows->get($s->token_hash)?->approved_how,
            'at' => (string) ($rows->get($s->token_hash)?->approved_at ?? $s->created_at)?->format('Y-m-d H:i'),
        ])->values()->all())->all();
    }

    /** يُغلق ما ينتظر لهذا العميل — لم يكن هو، أو أُلغي دخولُه */
    public static function drop(Customer $customer): int
    {
        $phone = Access::phone($customer);

        return $phone === null ? 0 : self::live((int) $customer->business_id)->where('phone_hash', self::key($phone))->update(['closed_at' => now(), 'updated_at' => now()]);
    }

    public static function dropAll(int $businessId): void
    {
        self::live($businessId)->update(['closed_at' => now(), 'updated_at' => now()]);
    }

    /* ─────────────────────────────────── */

    /** ما ينتظر: لم يُعتمد، ولم يُغلق، ولم تنقضِ مدّتُه */
    private static function live(int $businessId): Builder
    {
        return Waiting::query()->where('business_id', $businessId)->whereNull('approved_at')->whereNull('closed_at')->where('expires_at', '>', now());
    }

    private static function close(Waiting $row): void
    {
        $row->forceFill(['closed_at' => now()])->save();
    }

    /** بصمةُ رقم الهاتف بمفتاح الخادم — بأيّ صيغةٍ كُتب */
    public static function key(string $phone): string
    {
        return Access::phoneHash((string) Access::normalize($phone));
    }

    /** @return list<array{device: string, until: string}> نوعُه وحتّى متى يصلح رقمُه — ولا رقمَه */
    private static function shown(Collection $rows): array
    {
        return $rows->map(fn (Waiting $w) => ['device' => (string) $w->device, 'until' => $w->expires_at->format('H:i')])->values()->all();
    }

    /** «iPhone · Safari» — ما يكفي الموظّفَ ليعرفه، لا بصمةَ متصفّحٍ تُحفظ */
    private static function label(Request $request): string
    {
        $ua = (string) $request->userAgent();
        $first = fn (array $names) => collect($names)->first(fn ($name, $pattern) => preg_match($pattern, $ua) === 1);

        return implode(' · ', array_filter([
            $first(['/iPhone/' => 'iPhone', '/iPad/' => 'iPad', '/Android/' => 'Android', '/Windows/' => 'Windows', '/Macintosh/' => 'Mac']),
            $first(['/Edg/' => 'Edge', '/SamsungBrowser/' => 'Samsung Internet', '/OPR|Opera/' => 'Opera', '/Firefox|FxiOS/' => 'Firefox', '/Chrome|CriOS/' => 'Chrome', '/Safari/' => 'Safari']),
        ]));
    }
}
