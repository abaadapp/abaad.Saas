<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Order;
use App\Models\User;

/**
 * ما تبلغه لوحةُ التجهيز لمستخدم — نطاقُ صلاحيّةٍ واحدٌ لكلّ أبوابها.
 *
 * ═══ العطب ═══
 *
 * كانت اللوحةُ تُقصّ بالفرع المحفوظ في الجلسة (`Demo::currentBranchId`) —
 * اختيارٌ قديمٌ في مبدّل الفروع، أو بقيّةُ يومٍ كان الصندوقُ فيه يكتب فرعَه
 * هناك (#165). فموظّفان بالصلاحيّة نفسِها يريان لوحتين، ولا كلمةَ تقول إنّ
 * فيها ترشيحًا: طلبٌ دُفع ثمنُه لا يُجهَّز لأنّ من يقف عند الطاولة لا يراه.
 * وفي المقابل لا تُسأل فروعُه المُسنَد إليها: من قُيِّد بفرعٍ وجلستُه «كل
 * الفروع» يرى الجميعَ وينقل طلباتِ غيره.
 *
 * ═══ والقاعدة ═══
 *
 * - **النطاقُ صلاحيّة:** متجرُه، وفروعُه المُسنَد إليها. وصاحبُ النشاط بلا
 *   قيد (كما في `BranchTransfers::covers`)، ومن لم يُقيَّد يعمل في الكلّ
 *   (`User::worksAt`)، ومن قُيِّد بفرعٍ زال لا يصير بلا قيد.
 * - **والترشيحُ اختيارٌ ظاهر:** فرعٌ يُختار في اللوحة نفسِها (`?branch=`)
 *   يُكتب فوقها ويُلغى منها — ولا يوسّع النطاق. وما ليس من فروعه يُهمل.
 * - **ولا منشئَ ولا صندوقَ ولا ورديّةَ ولا جلسة:** تبقى على الطلب للتقارير
 *   والمحاسبة والسجلّ، ولا تُسأل هنا.
 *
 * ═══ وما لا فرعَ له مغلقٌ على من قُيِّد ═══
 *
 * فراغُ الفرع لا يعني «للجميع»: طلبٌ سُجّل قبل أن يكون للمتجر فرع، أو صفٌّ
 * قديم، أو مهمّةُ فرعٍ مُحي صفُّه (`nullOnDelete`) — ولا يُعرف من الفراغ أيُّها.
 * ولا بابَ في النظام يمحو فرعًا وحدَه اليوم، لكنّ القاعدةَ تُكتب لما لا يُعرف:
 * لا يُعطى من قُيِّد ما لا يُعرف فرعُه (كـ`CostsAndLosses`)، ويراه صاحبُ
 * النشاط ومن لم يُقيَّد ويُقال لهما عددُه (`unassigned`) — فلا يضيع.
 *
 * ومن شمل إسنادُه فروعَ المتجر كلَّها ليس مقيَّدًا: لا فرعَ ممنوعٌ عليه. فمتجرٌ
 * بفرعٍ واحد يرى كلُّ من أُسند إليه ما فيه كلَّه.
 */
final class PreparationScope
{
    /**
     * @param  list<int>|null  $allowed  فروعُه — أو `null` بلا قيد
     * @param  int|null  $chosen  الفرعُ المختار ترشيحًا — أو `null` للكلّ
     */
    private function __construct(
        public readonly int $businessId,
        public readonly ?array $allowed,
        public readonly ?int $chosen = null,
    ) {}

    public static function of(?User $user, int $businessId): self
    {
        $allowed = Permissions::isOwner($user) ? null : CostsAndLosses::allowedBranches($businessId, $user);

        // وبالمحذوف منها: فرعٌ في السلّة ما زال فرعًا لم يُسنَد إليه
        if ($allowed !== null && $allowed !== []
            && Branch::withTrashed()->where('business_id', $businessId)->whereNotIn('id', $allowed)->doesntExist()) {
            $allowed = null;
        }

        return new self($businessId, $allowed);
    }

    /** مع ترشيحِ فرعٍ — من فروعه الحيّة وحدها؛ والمجهولُ «الكلّ» لا لوحةٌ فارغة */
    public function filtered(mixed $branch): self
    {
        $id = is_scalar($branch) && ctype_digit((string) $branch) ? (int) $branch : 0;

        return in_array($id, array_column($this->branches(), 'id'), true)
            ? new self($this->businessId, $this->allowed, $id)
            : $this;
    }

    /**
     * فروعُه الحيّة — ما يُعرض في المُرشِّح.
     *
     * @return list<array{id: int, name: string}>
     */
    public function branches(): array
    {
        return Branch::where('business_id', $this->businessId)
            ->when($this->allowed !== null, fn ($q) => $q->whereIn('id', $this->allowed))
            ->orderBy('id')->get(['id', 'name'])
            ->map(fn (Branch $b) => ['id' => (int) $b->id, 'name' => (string) $b->name])
            ->values()->all();
    }

    /**
     * فروعُه إن كانت لوحتُه بعضَ فروع المتجر — لتُقال له؛ و`null` لمن يراها كلَّها.
     *
     * وفارغةٌ لمن قُيِّد بفرعٍ زال: يُقال له أنّه بلا فرع، لا تُعرض له لوحةٌ فارغة بلا سبب.
     *
     * @param  list<array{id: int, name: string}>  $mine
     * @return list<string>|null
     */
    public function limits(array $mine): ?array
    {
        return $this->allowed !== null && Branch::where('business_id', $this->businessId)->count() > count($mine)
            ? array_column($mine, 'name')
            : null;
    }

    /** يحصر استعلامًا فيه `branch_id` — طلباتٍ كان أو مهامَّ فواتير */
    public function apply($query)
    {
        return $query
            ->when($this->allowed !== null, fn ($q) => $q->whereIn('branch_id', $this->allowed))
            ->when($this->chosen !== null, fn ($q) => $q->where('branch_id', $this->chosen));
    }

    /** طلباتُ النطاق — ويضيف القارئُ ما ينتظر (`awaitingPreparation`) أو ما يُجهَّز ولو أُغلق (`toPrepare`) */
    public function orders()
    {
        return $this->apply(Order::where('business_id', $this->businessId));
    }
}
