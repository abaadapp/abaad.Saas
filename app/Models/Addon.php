<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Addon extends Model
{
    protected $guarded = [];

    protected $casts = [
        'price' => 'decimal:3',
        'active' => 'boolean',
        'inventory_quantity' => 'decimal:3',
    ];

    /** تُعرض مع كلّ منتجات المتجر — وهو مدى كلّ إضافةٍ قبل وجود هذا العمود */
    public const SCOPE_ALL = 'all';

    /** تُعرض مع ما اختِير من المنتجات وحده — صفوف product_addons */
    public const SCOPE_SELECTED = 'selected';

    /**
     * مدى الإضافة كما يُقرأ لا كما يُكتب.
     *
     * المملوكة لمنتج مداها ملكيّتُها، والفراغ يُقرأ «مع الجميع»: هو مدى كلّ
     * إضافةٍ قائمة، فلا تختفي واحدةٌ عن كاشيرٍ لحظة الترقية.
     */
    public function scopeName(): string
    {
        if ($this->product_id !== null) {
            return 'product';
        }

        return $this->scope === self::SCOPE_SELECTED ? self::SCOPE_SELECTED : self::SCOPE_ALL;
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * البضاعة التي تنقص حين تُباع هذه الإضافة — إن كانت بضاعةً أصلًا.
     *
     * «دبّ» قطعةٌ في الرفّ، و«تغليف فاخر» خدمةٌ لا رصيد لها. فالربط اختياريّ،
     * وإجبارُ كلّ إضافةٍ على صنفٍ مخزنيّ كان يخلق أصنافًا وهميّة لتمرير حقل.
     */
    public function inventoryProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'inventory_product_id');
    }
}
