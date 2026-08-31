<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * مكوّنٌ واحد في وصفة — «١٢ × ورد أحمر».
 *
 * لا جدول رأسٍ فوق هذه الصفوف: الوصفة هي مجموعُ صفوف (منتج، مقاس)، فلا
 * يمكن أن توجد وصفتان لمقاسٍ واحد فيُسأل أيّهما يُخصم.
 */
class RecipeItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:3',
        'wastage_percent' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }

    public function variant(): BelongsTo { return $this->belongsTo(ProductVariant::class, 'variant_id'); }

    public function component(): BelongsTo { return $this->belongsTo(Product::class, 'component_product_id'); }

    /**
     * ما يُستهلك فعلًا من هذا المكوّن لصنع قطعةٍ واحدة.
     *
     * الفاقد يُضاف إلى المستهلك لا إلى التكلفة وحدها: وردةٌ تُكسر أثناء
     * التجهيز نقصت من الرفّ فعلًا، فحسابُها في المال دون المخزون يجعل
     * النظام يعدّ ورودًا لم تعد موجودة.
     */
    public function effectiveQuantity(): float
    {
        return round((float) $this->quantity * (1 + (float) $this->wastage_percent / 100), 4);
    }
}
