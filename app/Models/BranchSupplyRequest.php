<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * طلبُ تزويد فرعٍ بالبضاعة — لا يحرّك رفًّا.
 *
 * يطلبه الفرعُ ويعتمده المخوَّل، فيصير سندَ نقلٍ أو أكثر — سندًا لكلّ فرعٍ
 * يزوّده (`App\Support\BranchSupply`). والمخزونُ يتحرّك عند إرسال السند لا عند
 * الاعتماد: موافقةٌ على ورقٍ لا تُنشئ بضاعةً على رفّ.
 */
class BranchSupplyRequest extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    protected $guarded = [];

    protected $casts = ['decided_at' => 'datetime'];

    public function lines(): HasMany
    {
        return $this->hasMany(BranchSupplyRequestLine::class)->orderBy('id');
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(BranchTransfer::class, 'branch_supply_request_id')->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(BranchOperationEvent::class, 'subject_id')
            ->where('subject_type', BranchOperationEvent::SUPPLY)->orderBy('id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * أين وصل الطلب — يُشتقّ من سنداته لا يُخزَّن.
     *
     * معتمدٌ سنداتُه كلُّها استُلمت أو أُلغيت ← «مكتمل». وما دام سندٌ منها
     * مفتوحًا فالطلبُ «قيد التنفيذ».
     */
    public function progress(): string
    {
        if ($this->status !== self::APPROVED) {
            return $this->status;
        }

        $transfers = $this->relationLoaded('transfers') ? $this->transfers : $this->transfers()->get(['id', 'status']);

        return $transfers->isNotEmpty() && $transfers->every(fn ($t) => $t->isFinal())
            ? 'fulfilled'
            : 'in_progress';
    }
}
