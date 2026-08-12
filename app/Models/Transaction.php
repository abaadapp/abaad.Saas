<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    protected $guarded = [];
    protected $casts = ['amount' => 'decimal:3', 'occurred_at' => 'datetime'];

    /**
     * مرجعٌ متسلسل لكل نشاط.
     *
     * كان `'TRX-' . random_int(60000, 99999)`: أربعون ألف قيمة بلا قيد فريد،
     * فاحتمال أن تحمل معاملتان المرجع نفسه يبلغ النصف بعد ٢٣٥ معاملة. وهو
     * عطبُ ترقيم الفواتير نفسه الذي أُصلح هناك وبقي هنا — وفي دفترٍ ماليّ
     * مرجعان متطابقان يعنيان أن التاجر لا يعرف أيّ صفٍّ يُصحّح.
     */
    public static function nextReference(int $businessId): string
    {
        $last = static::where('business_id', $businessId)
            ->where('reference', 'like', 'TRX-%')
            ->orderByDesc('id')
            ->value('reference');

        $n = $last ? (int) substr($last, 4) : 0;

        return 'TRX-'.str_pad((string) ($n + 1), 6, '0', STR_PAD_LEFT);
    }
    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
}
