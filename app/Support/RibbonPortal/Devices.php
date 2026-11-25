<?php

namespace App\Support\RibbonPortal;

use App\Models\Customer;
use App\Models\CustomerPortalRequest as Waiting;
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
 * موظّفٌ يرى العميلَ أو يعرفه. وقبل شهادته لا يُقرأ من الحساب حرف — ولا حتّى أنّ الرقم مسجَّل.
 *
 * ═══ والرقمُ الذي على الشاشة ═══
 *
 * ثلاثُ خاناتٍ تظهر على جهاز العميل وحده، ولا تظهر للموظّف: يكتبها هو. فلا يُعتمد جهازُ من كتب
 * رقمَ غيره من بعيد — لا يعرف الموظّفُ خاناتِه — وإن انتظر جهازان لرقمٍ واحد اعتُمد الذي أمامه.
 * والعميلُ لا يكتب منها شيئًا.
 *
 * ═══ ولكلّ رقمٍ صفّ ═══
 *
 * مسجَّلٍ أو غيره: الردُّ واحدٌ وزمنُه واحد. ولا يُعتمد إلّا لعميلٍ في السجلّ، فلا حسابَ يُنشأ هنا.
 */
final class Devices
{
    /** كم ساعةً ينتظر الجهاز */
    public const HOURS = 24;

    public const DIGITS = 3;

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

    /** أكان له طلبٌ ثمّ انقضى أو أُغلق؟ — ليُقال له، لا ليُفتح له شيء */
    public static function was(int $businessId, Request $request): bool
    {
        $raw = Access::raw($request);

        return $raw !== null && Waiting::where('business_id', $businessId)->where('token_hash', Access::hash($raw))->whereNull('approved_at')->exists();
    }

    /** أهو ينتظر بهذا الرقم نفسِه؟ — فلا يُكتب طلبٌ ثانٍ ولا تُحسب محاولة */
    public static function already(int $businessId, string $phone, Request $request): bool
    {
        $mine = self::mine($businessId, $request);

        return $mine !== null && hash_equals($mine->phone_hash, self::key($phone));
    }

    /** يكتب طلبَه ويردّ رمزَ الجهاز */
    public static function ask(int $businessId, string $phone, Request $request): string
    {
        return DB::transaction(function () use ($businessId, $phone, $request) {
            self::forget($businessId, $request);

            $key = self::key($phone);
            $others = self::live($businessId)->where('phone_hash', $key)->orderByDesc('id')->get();
            $others->slice(self::PER_PHONE - 1)->each(fn (Waiting $w) => self::close($w));
            $taken = $others->map(fn (Waiting $w) => self::number($w))->all();

            do {
                $raw = bin2hex(random_bytes(32));
                $row = new Waiting([
                    'business_id' => $businessId,
                    'phone_hash' => $key,
                    'token_hash' => Access::hash($raw),
                    'device' => self::label($request),
                    'expires_at' => now()->addHours(self::HOURS),
                ]);
                // ولا جهازان ينتظران لرقمٍ واحدٍ بخاناتٍ واحدة
            } while (in_array(self::number($row), $taken, true));

            $row->save();
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

    /** خاناتُه الثلاث — من رمزه ومفتاح الخادم، لا تُحفظ */
    public static function number(Waiting $row): string
    {
        return (string) (100 + hexdec(substr(hash_hmac('sha256', 'device|'.$row->token_hash, Access::secret()), 0, 8)) % 900);
    }

    /* ─────────────────────────── الموظّف ─────────────────────────── */

    /**
     * من ينتظر — العملاءُ المسجَّلون وحدهم، ولا خاناتِ جهازٍ هنا: تُقرأ من شاشة صاحبه.
     *
     * @return list<array{id: int, name: string, phone: string, twin: bool, devices: list<array{device: string, at: string}>}>
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
            'phone' => (string) (filled($x['customer']->phone) ? $x['customer']->phone : $x['customer']->contact_phone),
            // رقمٌ لسجلّين: يُقال للموظّف ليختار صاحبَه
            'twin' => $shared[$x['phone']] > 1,
            'devices' => self::shown($rows[self::key($x['phone'])]),
        ])->values()->all();
    }

    /** @return list<array{device: string, at: string}> أجهزةُ هذا العميل التي تنتظر */
    public static function of(Customer $customer): array
    {
        $phone = Access::phone($customer);

        return $phone === null ? [] : self::shown(self::live((int) $customer->business_id)->where('phone_hash', self::key($phone))->orderByDesc('id')->get());
    }

    /**
     * يعتمد الجهازَ الذي خاناتُه هذه — ويردّ اسمَه، أو `null` ولا شيءَ تغيّر للعميل.
     *
     * وخطأٌ ثالثٌ يُغلق ما ينتظر لهذا الرقم: فلا تُجرَّب الخانات.
     */
    public static function approve(Customer $customer, string $digits, User $by): ?string
    {
        $phone = Access::phone($customer);
        if ($phone === null) {
            return null;
        }

        return DB::transaction(function () use ($customer, $phone, $digits, $by) {
            $rows = self::live((int) $customer->business_id)->where('phone_hash', self::key($phone))->lockForUpdate()->get();
            $row = $rows->first(fn (Waiting $w) => hash_equals(self::number($w), $digits));

            if ($row === null) {
                $rows->each(fn (Waiting $w) => $w->forceFill(['tries' => $w->tries + 1, 'closed_at' => $w->tries + 1 >= self::TRIES ? now() : null])->save());

                return null;
            }

            $link = Access::grant($customer);
            if ($link === null) {
                return null;
            }
            Access::open($link, $row->token_hash);
            $row->forceFill(['customer_id' => $customer->id, 'approved_by' => $by->id, 'approved_at' => now()])->save();

            return (string) $row->device;
        });
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

    private static function key(string $phone): string
    {
        return Access::phoneHash((string) Access::normalize($phone));
    }

    /** @return list<array{device: string, at: string}> */
    private static function shown(Collection $rows): array
    {
        return $rows->map(fn (Waiting $w) => ['device' => (string) $w->device, 'at' => (string) $w->created_at?->format($w->created_at->isToday() ? 'H:i' : 'm-d H:i')])->values()->all();
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
