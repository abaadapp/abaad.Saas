<?php

namespace App\Support\Store;

use App\Models\Branch;
use App\Support\FlowerOrder;
use App\Support\MarketingSettings;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * من أيّ فرعٍ يُنفَّذ طلبُ الموقع — ويُخصم رفُّه، ويُرى في «المبيعات» والتجهيز.
 *
 * ═══ ما كان ═══
 *
 * أوّلُ فروع المتجر بمعرّفه، في أربعة مواضع من `WebCheckout`. فمتجرٌ بفرعين
 * يُنسب كلُّ طلبٍ من موقعه إلى أقدمهما — ولو كان الزبونُ سيستلم من الآخر —
 * ويُخصم رفُّه من فرعٍ لن يخرج منه شيء.
 *
 * ═══ ما صار ═══
 *
 * - **فرعُ طلبات الموقع** (`store_branch_id`): يختاره صاحبُ المتجر. وفارغًا —
 *   افتراضُ كلّ متجرٍ قائم — أو فرعًا حُذف بعد ضبطه: أوّلُ فروعه كما كان.
 * - **فرعُ الاستلام يختاره الزبون** (`store_pickup_branch_choice`): مطفأٌ حتّى
 *   يفتحه صاحبُ المتجر، ولا يُعرض إلّا لمتجرٍ له أكثرُ من فرع. والقائمةُ فروعُ
 *   المتجر الحيّة وحدها: لا محذوفٌ ولا فرعُ متجرٍ آخر ولو أُرسل رقمُه بيده.
 *
 * والتوصيلُ يخرج من فرع طلبات الموقع. ومتجرٌ بفرعٍ واحد لا يتبدّل عليه شيء.
 *
 * ═══ ولا يُمسّ طلبٌ سابق ═══
 *
 * يُقرأ هنا عند الإتمام وحده؛ فرعُ الطلب المكتوب لا يُعاد حسابُه.
 */
final class StoreBranches
{
    /** فرعُ طلبات الموقع — معرّفُه، وفارغًا يعني أوّلَ الفروع */
    public const DEFAULT = 'store_branch_id';

    /** أيختار الزبونُ فرعَ الاستلام؟ */
    public const CHOICE = 'store_pickup_branch_choice';

    /** حقلُ صفحة الإتمام */
    public const FIELD = 'pickup_branch';

    /** فروعُ المتجر الحيّة بترتيبها — `SoftDeletes` يُسقط المحذوف */
    public static function live(int $bid): Collection
    {
        return Branch::where('business_id', $bid)->orderBy('id')->get(['id', 'business_id', 'name', 'address']);
    }

    /** فرعُ طلبات الموقع: ما اختاره صاحبُ المتجر إن بقي حيًّا، وإلّا أوّلُ فروعه — كما كان */
    public static function default(int $bid): ?Branch
    {
        $live = self::live($bid);
        $chosen = (int) (MarketingSettings::group($bid, 'website')[self::DEFAULT] ?? 0);

        return ($chosen > 0 ? $live->firstWhere('id', $chosen) : null) ?? $live->first();
    }

    /** فروعُ الاستلام التي يختار منها الزبون — لا شيء ما لم يفتحها صاحبُ المتجر وله أكثرُ من فرع */
    public static function pickupChoices(int $bid): Collection
    {
        if ((MarketingSettings::group($bid, 'website')[self::CHOICE] ?? '0') !== '1') {
            return collect();
        }

        $live = self::live($bid);

        return $live->count() > 1 ? $live : collect();
    }

    /**
     * الفرعُ الذي يُنفَّذ منه هذا الطلب.
     *
     * لطلبٍ جديد (`$strict`): استلامٌ من متجرٍ فتح الاختيار يُسأل عن فرعه، وفرعٌ
     * ليس في القائمة يُردّ بسببه قبل أن يُكتب شيءٌ أو يُطرق بنك.
     *
     * ولتسعيرةٍ تُعرض أو دفعةٍ قُبضت لا يُردّ: الأولى معاينة، والثانية مالٌ
     * خرج — فرعٌ حُذف بين الدفع والإشعار يُسلَّم طلبُه من فرع طلبات الموقع،
     * لا يُترك مالًا بلا طلب.
     *
     * @throws ValidationException
     */
    public static function resolve(int $bid, ?string $fulfil, mixed $requested, bool $strict = true): ?Branch
    {
        $choices = $fulfil === FlowerOrder::PICKUP ? self::pickupChoices($bid) : collect();

        if ($choices->isEmpty()) {
            return self::default($bid);
        }

        $id = is_scalar($requested) && ctype_digit((string) $requested) ? (int) $requested : 0;

        if ($id > 0 && ($hit = $choices->firstWhere('id', $id)) !== null) {
            return $hit;
        }

        if ($strict) {
            throw ValidationException::withMessages([self::FIELD => __('اختر فرع الاستلام من القائمة.')]);
        }

        return self::default($bid);
    }

    /**
     * ما تقرؤه صفحةُ الإتمام — فروعُ الاستلام بأسمائها وعناوينها، أو لا شيء.
     *
     * @return list<array{id: int, name: string, address: ?string}>
     */
    public static function forPage(int $bid): array
    {
        return self::pickupChoices($bid)
            ->map(fn (Branch $b) => ['id' => (int) $b->id, 'name' => (string) $b->name, 'address' => $b->address ?: null])
            ->values()->all();
    }
}
