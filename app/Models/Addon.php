<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Addon extends Model
{
    protected $guarded = [];

    protected $casts = ['price' => 'decimal:3', 'active' => 'boolean'];

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
