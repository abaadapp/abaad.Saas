<?php

namespace App\Support;

use App\Models\Boutique;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * حسابُ صاحب البوتيك — مربوطٌ ببوتيكه، ولا يرى من المتجر إلا رفَّه.
 *
 * ═══ لماذا ربطٌ لا صلاحيّة ═══
 *
 * «المنتجات» مفتاحُ قسم: من يحمله يفتح أصنافَ المتجر كلَّها، ويُسند أيَّ صنفٍ
 * إلى أيّ بوتيك فتُستحقّ له مبيعاتُه القادمة، ويقرأ أسماءَ البوتيكات الأخرى
 * وقطعَها. وصاحبُ البوتيك ليس موظّفًا عند المتجر — هو طرفٌ في حسابٍ معه. فلا
 * يكفيه مفتاحٌ أضيق؛ يحتاج أن يُعرف **لمن** هو.
 *
 * فيُربط الحسابُ ببوتيكٍ واحد من شاشة الموظف بيد التاجر (`users.boutique_id`)،
 * وعندئذٍ:
 *
 *   - لا يبلغ إلا أبواب أصناف بوتيكه (`ROUTES` و`PRODUCT_ROUTES`)، وكلُّ بابٍ
 *     سواها يُردّ — ما لم يُسمَّ هنا لا يُفتح، فبابٌ يُضاف غدًا مغلقٌ عليه.
 *   - وكلُّ صنفٍ يُطلب برقمه يُسأل: أهو من بوتيكه؟ وإلّا ٤٠٤ — لا يُقال له
 *     إن كان الرقمُ موجودًا.
 *   - وما يُنشئه يُنسب إلى بوتيكه، ولا ينقل صنفًا إلى بوتيكٍ آخر.
 *   - ولا يقرأ مالًا: لا مبيعاتٍ ولا فواتير ولا عمولةً ولا مستحقًّا ولا ورقة،
 *     مهما حمل حسابُه من صلاحيّات (`User::allows` و`BoutiqueAccess`).
 *
 * والربطُ يُضيّق ولا يُوسّع: ما لم يمنحه التاجرُ من «المنتجات» لا يأخذه به.
 * ولا يُخمَّن لحسابٍ قديم بوتيكُه — يبقى كما كان حتى يربطه التاجر.
 */
final class BoutiqueAccount
{
    /** أبوابُ الحساب نفسِه: الخروجُ والملفُّ الشخصيّ واللغة — تُفتح ولو غاب بوتيكُه */
    public const ACCOUNT_ROUTES = [
        'logout', 'profile.edit', 'profile.update', 'impersonate.stop', 'subscription.expired',
        'admin.language.update', 'admin.currency.switch',
    ];

    /** أبوابُ رفِّه بلا صنفٍ بعينه: قائمةُ أصنافه وإنشاؤها — وبوتيكُه قائمٌ في متجره */
    public const ROUTES = [
        'admin.products.index', 'admin.products.create', 'admin.products.store',
    ];

    /** أبوابُ صنفٍ بعينه — `{id}` فيها صنفٌ يُسأل عنه: أهو من بوتيكه؟ */
    public const PRODUCT_ROUTES = [
        'admin.products.show', 'admin.products.edit', 'admin.products.update',
        'admin.products.quick', 'admin.products.toggle', 'admin.products.destroy',
        'admin.products.duplicate', 'admin.products.websiteStatus',
        'admin.products.images.store', 'admin.products.images.order', 'admin.products.images.destroy',
        'admin.products.images.destroyMain', 'admin.products.images.promote',
        'admin.products.variants.store', 'admin.products.variants.update', 'admin.products.variants.destroy',
    ];

    public static function bound(?User $user): bool
    {
        return $user !== null && $user->boutique_id !== null;
    }

    /**
     * بوتيكُه — من متجره وحده.
     *
     * وربطٌ إلى بوتيكٍ ليس من متجره لا يُقرأ ربطًا إلى شيء: يبقى الحسابُ مربوطًا
     * فلا يُفتح له بابٌ مالٌ ولا صنف، ولا يصير حسابًا غيرَ مربوطٍ يفتح المتجرَ كلَّه.
     */
    public static function boutique(User $user): ?Boutique
    {
        return Boutique::where('business_id', $user->business_id)->find($user->boutique_id);
    }

    /** هل يفتح هذا الحسابُ المربوطُ هذا الباب؟ — و`$product` رقمُ الصنف في المسار إن كان */
    public static function opens(User $user, ?string $route, mixed $product): bool
    {
        if (in_array($route, self::ACCOUNT_ROUTES, true)) {
            return true;
        }

        // وبوتيكٌ غاب — حُذف من غير الشاشة، أو نسخةٌ استُعيدت قبله — لا يفتح رفًّا
        if (in_array($route, self::ROUTES, true)) {
            return self::boutique($user) !== null;
        }

        return in_array($route, self::PRODUCT_ROUTES, true) && self::owns($user, $product);
    }

    /** أهذا الصنفُ من بوتيكه في متجره؟ */
    public static function owns(User $user, mixed $product): bool
    {
        return is_numeric($product) && self::boutique($user) !== null
            && Product::where('business_id', $user->business_id)
                ->where('boutique_id', $user->boutique_id)
                ->whereKey((int) $product)
                ->exists();
    }

    /** قائمةُ أصناف المتجر كما يراها: رفُّ بوتيكه وحده — ولغيره كما هي */
    public static function scope(Builder $query, ?User $user): Builder
    {
        return self::bound($user) ? $query->where('boutique_id', $user->boutique_id) : $query;
    }
}
