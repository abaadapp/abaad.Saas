<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * مادّةٌ دخلت في طلبٍ مخصَّص — لقطةٌ لا علاقة.
 *
 * الوصفةُ العاديّة معرَّفةٌ قبل البيع في `recipe_items`، وهذه تُختار لكلّ
 * طلبٍ على حدة. فإن تغيّر اسمُ الورد أو سعرُه أو حُذف من الكتالوج، بقي طلبُ
 * الشهر الماضي مفهومًا: الاسمُ والرمزُ والتكلفةُ منسوخةٌ هنا.
 *
 * والمعرّفُ يبقى للتجميع في التقارير — وهو `nullOnDelete`.
 */
class OrderItemComponent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_cost' => 'decimal:3',
        'total_cost' => 'decimal:3',
        'restockable' => 'boolean',
    ];

    /** وردٌ وأوراقٌ وما يدخل في الباقة نفسِها */
    public const FLOWER = 'flower';

    /** كيسٌ أو بوكسٌ أو ورقُ لفّ — ما يُغلَّف به */
    public const PACKAGING = 'packaging';

    /** أنواعُ المواد كما تُعرض في لوحة التجهيز — مصدرٌ واحد تقرأ منه الشاشة والحارس */
    public const KINDS = [self::FLOWER, self::PACKAGING];

    public function item(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
