<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * إشعار استلام بضاعة — توأمُ إشعار التسليم بالاتجاه المعاكس.
 *
 * مستند حركةٍ لا مستند مال: لا يُنشئ ذمّةً للمورّد ولا قيدًا — الذمّة تنشأ
 * بسند المورّد. ولا يمسّ المخزون: `PurchaseOrderController::receive` أدخلت
 * الكمية، وهذا ورقتُها. ولو أدخلها ثانيةً لدخلت الشحنة مرّتين.
 */
class GoodsReceiptNote extends Model
{
    protected $guarded = [];

    protected $casts = ['received_at' => 'date'];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }

    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }

    public function purchaseOrder(): BelongsTo { return $this->belongsTo(PurchaseOrder::class); }

    public function items(): HasMany { return $this->hasMany(GoodsReceiptNoteItem::class); }

    /**
     * الرقم التالي — على قالب `DeliveryNote::nextNumber` نفسه.
     *
     * والقراءة في PHP لا في SQL عمدًا: `CAST` على نصٍّ غير رقميّ يتساهل معه
     * SQLite ويرفضه PostgreSQL، فرقمٌ واحد شاذّ — من نسخةٍ مستعادة أو إدخالٍ
     * يدويّ — يُعطّل الاستلام كلّه بعد النقل. وهذه الأوراق لا تُحذف، فأعلى
     * معرّفٍ هو أعلى رقم.
     */
    public static function nextNumber(int $businessId): string
    {
        $last = static::where('business_id', $businessId)->orderByDesc('id')->value('number');
        $n = $last ? ((int) substr($last, 4)) + 1 : 1;

        return 'GRN-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }
}
