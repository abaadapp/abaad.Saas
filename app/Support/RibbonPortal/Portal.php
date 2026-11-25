<?php

namespace App\Support\RibbonPortal;

use App\Models\Setting;
use App\Models\User;
use App\Support\InvoiceBranding;
use App\Support\Permissions;
use App\Support\Storefront;
use App\Support\Website\Domains;

/**
 * بوابةُ عملاء RIBBON — لمن هي، وأين، ومن يشاركها.
 *
 * ═══ لمتجرٍ واحد ═══
 *
 * تخصيصٌ لسعود لا منتج: معرّفُ متجره في `storefront.ribbon_customer_portal`
 * **ومعه** نطاقُه المثبَّت (`Domains::pinnedLabel`). وكلُّ بابٍ لها — الصفحةُ
 * وبياناتُها وفواتيرُها، وأزرارُ الموظّفين في العملاء والصندوق والإعدادات —
 * يسأل `on()` هنا. فمتجرٌ آخر لا يُعرض له شيءٌ ويُردّ بـ404 إن طرق البابَ بيده.
 *
 * ═══ ومن يعمل عليها ═══
 *
 * صاحبُ النشاط، ومن اختارهم بأسمائهم في «بوابة العملاء» (`STAFF`). وليست فعلًا
 * في قائمة الصلاحيات العامّة: تلك يراها كلُّ تاجر. ولا تفتح قسمًا ولا فاتورة ولا
 * نقاطًا — وتُطلب معها صلاحيّةُ الشاشة التي فيها الزرّ.
 */
final class Portal
{
    /** إعدادُ المتجر: من يعمل على البوابة ومن يديرها — معرّفاتُ موظّفين */
    public const STAFF = 'ribbon_portal_staff';

    public const PATH = 'my';

    /** المتجرُ الذي كُتبت له — أو `null` والقفلُ مُغلق */
    public static function businessId(): ?int
    {
        $c = (array) config('storefront.ribbon_customer_portal', []);
        $id = $c['business'] ?? null;

        return ($c['enabled'] ?? false) === true && is_int($id) && $id > 0 ? $id : null;
    }

    /** أهذا المتجرُ صاحبُها، ونطاقُه مثبَّتٌ له؟ */
    public static function on(?int $businessId): bool
    {
        return $businessId !== null && $businessId > 0
            && $businessId === self::businessId()
            && Domains::pinnedLabel($businessId) !== null;
    }

    /**
     * مضيفُها — من القائمة وحدها بلا قاعدة، لتُسجَّل به المسارات.
     *
     * ولا يُقرأ القفلُ هنا: المسارُ مسجَّلٌ دائمًا ويُردّ بـ404 وهو مُغلق.
     */
    public static function host(): ?string
    {
        $id = config('storefront.ribbon_customer_portal.business');
        $label = is_int($id) ? (Domains::movedShops()[$id] ?? null) : null;

        return $label === null ? null : strtolower($label.'.'.Storefront::domain());
    }

    /** الرابطُ العامّ — لا يكشف شيئًا وحده */
    public static function url(): string
    {
        return 'https://'.self::host().'/'.self::PATH;
    }

    /** @return array{share: list<int>, manage: list<int>} */
    public static function staff(int $businessId): array
    {
        $saved = json_decode((string) Setting::where('business_id', $businessId)->where('key', self::STAFF)->value('value'), true);
        $ids = fn (string $key) => array_values(array_unique(array_filter(array_map('intval', (array) ($saved[$key] ?? [])))));

        return ['share' => $ids('share'), 'manage' => $ids('manage')];
    }

    /** يحفظ من يشارك ومن يدير — من موظّفي المتجر الأحياء وحدهم، وصاحبُه ليس منهم: يملكها بدوره */
    public static function saveStaff(int $businessId, array $share, array $manage): void
    {
        $mine = User::where('business_id', $businessId)->get()
            ->reject(fn (User $u) => Permissions::isOwner($u))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $keep = fn (array $ids) => array_values(array_intersect($mine, array_map('intval', $ids)));

        Setting::updateOrCreate(
            ['business_id' => $businessId, 'key' => self::STAFF],
            ['value' => json_encode(['share' => $keep($share), 'manage' => $keep($manage)])],
        );
    }

    /** يرى الرابطَ وقسمَ البوابة ويعتمد أجهزةَ العملاء: صاحبُ النشاط، أو من اختاره — ومن يدير كذلك */
    public static function mayShare(?User $user): bool
    {
        return self::granted($user, ['share', 'manage']);
    }

    /** يرى «بوابة العملاء» في الإعدادات ويلغي الدخول */
    public static function mayManage(?User $user): bool
    {
        return self::granted($user, ['manage']);
    }

    private static function granted(?User $user, array $keys): bool
    {
        if ($user === null || ! self::on($user->business_id !== null ? (int) $user->business_id : null)) {
            return false;
        }
        if (Permissions::isOwner($user)) {
            return true;
        }

        $staff = self::staff((int) $user->business_id);

        return array_filter($keys, fn ($k) => in_array((int) $user->id, $staff[$k], true)) !== [];
    }

    /**
     * ما تعرضه شاشةُ العملاء — أو `null` فلا زرَّ ولا مفتاحَ في الصفحة.
     *
     * @return array{url: string, qr: string, poster: string, name: string, waiting: int, waitingUrl: string}|null
     */
    public static function panel(?User $user): ?array
    {
        return self::mayShare($user) ? [
            'url' => self::url(),
            'qr' => Qr::dataUri(self::url()),
            'poster' => route('admin.customers.portal.poster'),
            'name' => InvoiceBranding::name((int) $user->business_id),
            'waiting' => count(Devices::waiting((int) $user->business_id)),
            'waitingUrl' => route('admin.customers.portal.waiting', [], false),
        ] : null;
    }
}
