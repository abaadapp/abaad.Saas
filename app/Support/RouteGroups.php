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
     * مساراتُ المجموعة — لما يُضمّ في المتصفّح حين ينتقل إلى صفحةٍ من غيرها.
     *
     * @return array<string, mixed>
     */
    public static function routes(string $group): array
    {
        return (new Ziggy(self::ziggy($group)))->toArray()['routes'];
    }
}
