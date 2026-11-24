<?php

namespace App\Support;

use Tighten\Ziggy\Ziggy;

/**
 * أيُّ جدولِ مساراتٍ تحمله الصفحة — بمجلّدها، ولمن يجلس.
 *
 * المجموعاتُ في `config/ziggy.php`. والمجلّدُ هو أوّلُ مقطعٍ من اسم مكوّن Inertia
 * (`Pos/Index` ← `Pos`)، والمرآةُ في المتصفّح `lib/route-groups.ts` — والحارسُ
 * يقابل بينهما (`EveryRouteAScreenCallsIsInItsGroupTest`).
 *
 * ومديرُ المنصّة — ومن دخل منه باسم تاجر — يأخذ الجدولَ كلَّه: ينتقل بين المنصّة
 * واللوحة والصندوق بلا تحميل، وشريطُ الدعم ينادي ما ليس في مجموعات التاجر.
 */
final class RouteGroups
{
    public const GUEST = 'guest';

    public const POS = 'pos';

    public const MERCHANT = 'merchant';

    public const ALL = 'all';

    /** مجلّدُ الصفحات ← مجموعتُه */
    public const FOLDERS = [
        'Auth' => self::GUEST,
        'Pos' => self::POS,
        'Admin' => self::MERCHANT,
        'Profile' => self::MERCHANT,
        'Platform' => self::ALL,
    ];

    /** ما تغطّيه كلُّ مجموعة — اللوحةُ تحمل مجموعةَ الصندوق كلَّها */
    public const COVERS = [
        self::ALL => [self::ALL, self::MERCHANT, self::POS, self::GUEST],
        self::MERCHANT => [self::MERCHANT, self::POS],
        self::POS => [self::POS],
        self::GUEST => [self::GUEST],
    ];

    /** مجموعةُ صفحةٍ بمكوّنها — وما لا يُعرف مجلّدُه يأخذ الكلّ */
    public static function forComponent(?string $component): string
    {
        return self::FOLDERS[strtok((string) $component, '/')] ?? self::ALL;
    }

    /** مجموعةُ هذا الطلب — والمنصّةُ والدعمُ يأخذان الكلّ */
    public static function forRequest(?string $component): string
    {
        return self::seesEverything() ? self::ALL : self::forComponent($component);
    }

    /** مديرُ المنصّة، أو من دخل منه باسم تاجر — ينتقل بين الواجهات كلِّها بلا تحميل */
    public static function seesEverything(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin()
            || (request()->hasSession() && request()->session()->has('impersonator_id'));
    }

    /** اسمُ المجموعة كما يأخذه Ziggy — و`null` للكلّ */
    public static function ziggy(string $group): ?string
    {
        return $group === self::ALL ? null : $group;
    }

    /**
     * مساراتُ المجموعة — لجدول الصفحة، ولما يُضمّ في المتصفّح حين ينتقل إلى صفحةٍ من غيرها.
     *
     * ═══ ولمَ لا `new Ziggy($group)` ═══
     *
     * Ziggy يطابق كلَّ مسارٍ بكلّ نمطٍ واحدًا واحدًا (`Str::is`): ثمانمئة مسارٍ في
     * خمسةٍ وثلاثين نمطًا — ملّيثانيتان في كلّ صفحةٍ تُحمَّل، أكثرُ ممّا يأخذه
     * الجدولُ كلُّه. فتُجمع أنماطُ المجموعة (`config/ziggy.php`، المصدرُ نفسُه)
     * في تعبيرٍ واحد يُطابَق مرّةً لكلّ مسار — والنتيجةُ ما يختاره Ziggy حرفًا
     * بحرف (`EveryRouteAScreenCallsIsInItsGroupTest`).
     *
     * @return array<string, mixed>
     */
    public static function routes(string $group): array
    {
        return self::table($group)['routes'];
    }

    /**
     * جدولُ الصفحة كما يكتبه Ziggy — الرابطُ والمنفذُ والقيمُ الافتراضيّة، ومساراتُ المجموعة.
     *
     * @return array<string, mixed>
     */
    public static function table(string $group): array
    {
        $table = (new Ziggy)->toArray();

        if ($group === self::ALL) {
            return $table;
        }

        $patterns = (array) config("ziggy.groups.{$group}", []);
        $regex = fn (array $list) => '#^(?:'.implode('|', array_map(
            fn (string $p) => str_replace('\\*', '.*', preg_quote($p, '#')),
            $list,
        )).')\z#su';

        // وما سُبق بـ`!` لا يدخل ولو شمله نمطٌ آخر — كما يقرؤه Ziggy
        $never = array_values(array_map(fn (string $p) => substr($p, 1), array_filter($patterns, fn (string $p) => str_starts_with($p, '!'))));
        $take = $regex($patterns);
        $skip = $never === [] ? null : $regex($never);

        $table['routes'] = $patterns === []
            ? []
            : array_filter($table['routes'], fn ($name) => preg_match($take, (string) $name) === 1
                && ($skip === null || preg_match($skip, (string) $name) !== 1), ARRAY_FILTER_USE_KEY);

        return $table;
    }

    /**
     * ما يكتبه `@routes` — `const Ziggy=…` ثمّ دالّةُ `route()` من Ziggy نفسه.
     *
     * بلا `@routes`: ذاك يبني الجدولَ بـ`new Ziggy($group)` (انظر `routes`).
     */
    public static function script(string $group): string
    {
        $table = json_encode(self::table($group), JSON_UNESCAPED_SLASHES);
        $function = (string) file_get_contents(base_path('vendor/tightenco/ziggy/dist/route.umd.js'));

        return '<script type="text/javascript">const Ziggy='.$table.';'.$function.'</script>';
    }
}
