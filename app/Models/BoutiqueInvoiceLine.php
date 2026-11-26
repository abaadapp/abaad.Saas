<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ما لبوتيكٍ في بندٍ من فاتورة عميلٍ بلا طلب — لقطةٌ ساعةَ الإصدار.
 *
 * اللقطةُ (الكمّيّة والسعر والنسبة والعمولة والصافي والضريبة) تُكتب مرّةً ولا
 * تُعدَّل. وما يتغيّر بعدها أعمدتُه غيرُها: ما رُدّ (`returned_*`)، ومتى اكتمل
 * التحصيل (`collected_at`)، وما حملته الورقة (`settled_*`). انظر `BoutiqueInvoiceDues`.
 */
class BoutiqueInvoiceLine extends Model
{
    /** صنفُ بوتيكٍ بِيع منفردًا في بندٍ من الفاتورة */
    public const LINE = 'line';

    /** قطعةُ بوتيكٍ داخل باقةٍ أو إضافةٍ في بندٍ من الفاتورة (`BoutiqueInvoicePieces`) */
    public const PIECE = 'piece';

    /** قاعدةُ القطعة: ثمنُها في بطاقتها كاملًا، وعمولتُها داخل الباقة باتّفاقٍ إن كُتب */
    public const RULE_PIECE = 'piece';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tax_inclusive' => 'boolean',
            'issued_at' => 'datetime',
            'accrued_on' => 'date',
            'collected_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /** أعمدةُ اللقطة — لا يكتبها شيءٌ بعد الإنشاء */
    public const SNAPSHOT = [
        'business_id', 'boutique_id', 'boutique_name', 'customer_invoice_id', 'customer_invoice_item_id', 'product_id', 'name',
        'quantity', 'unit_price', 'list_price', 'gross', 'discount', 'tax_rate', 'tax_inclusive', 'tax_amount',
        'rule', 'base', 'rate', 'commission', 'net', 'issued_at',
        'agreed_price', 'kind', 'piece_key', 'source', 'recipe_item_id', 'addon_id', 'parent_name', 'unit_quantity',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $line) {
            // ويومُ الاعتراف يُكتب مرّةً مع قيده
            if (array_intersect(array_keys($line->getDirty()), self::SNAPSHOT) !== []
                || ($line->isDirty('accrued_on') && $line->getRawOriginal('accrued_on') !== null)) {
                throw new \LogicException('لقطةُ مستحقّ البوتيك لا تُعدَّل بعد الإصدار.');
            }
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(BoutiqueSettlement::class, 'boutique_settlement_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(BoutiqueInvoiceReturn::class);
    }
}
