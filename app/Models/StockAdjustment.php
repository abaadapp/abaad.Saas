<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تعديل مخزون — فرقٌ موجبٌ زيادة وسالبٌ نقص.
 *
 * لا حقلَ اتجاهٍ منفصلًا عن المقدار: حقلان يقولان الشيء نفسه يفترقان يومًا،
 * فيُقرأ نقصٌ زيادةً ويُصحَّح الجرد إلى ضعفه.
 */
class StockAdjustment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity_delta' => 'decimal:3',
        'cost_at_time' => 'decimal:3',
        'adjusted_at' => 'datetime',
    ];

    /** أسباب التعديل — قائمةٌ مغلقة كي تُجمَّع التقارير عليها */
    public const REASONS = ['تلف', 'فقد', 'جرد', 'إهداء', 'تصحيح'];

    /**
     * سببُ النقص الذي يكشفه العدّ الفعليّ — يكتبه الجرد ولا يُختار بيد.
     *
     * خارج `REASONS` عمدًا: تلك ما يعرضه نموذج التعديل اليدويّ، وهذا ما
     * يكتبه الجرد وحده. وخلطُهما كان سيجعل الموظّف يسجّل «فاقد جرد» بيده
     * على صنفٍ لم يُجرد — فيصير السبب اسمًا لا مصدرًا.
     *
     * ويُقرأ هالكًا: انظر Waste::LOSS_REASONS.
     */
    public const STOCKTAKE_LOSS = 'فاقد جرد';

    /** والزيادة التي يكشفها العدّ ليست ربحًا ولا هالكًا — تصحيحُ دفتر */
    public const STOCKTAKE_GAIN = 'جرد';

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }

    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }

    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    /** أثره بالمال — نقصٌ يعني خسارة بتكلفة اللحظة لا بتكلفة اليوم */
    public function valueImpact(): float
    {
        return round((float) $this->quantity_delta * (float) $this->cost_at_time, 3);
    }

    public static function nextNumber(int $businessId): string
    {
        $last = static::where('business_id', $businessId)->orderByDesc('id')->value('number');
        $n = $last ? ((int) substr($last, 3)) + 1 : 1;

        return 'SA-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }
}
