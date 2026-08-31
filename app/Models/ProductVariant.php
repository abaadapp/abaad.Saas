<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * مقاسُ منتج — «وسط» من «بوكيه الحب».
 *
 * المنتج بلا مقاسات يبقى منتجًا بسيطًا يُباع بسعره. ومن له مقاسات لا يُباع
 * بنفسه: السعر يأتي من المقاس المختار، والخادم هو من يقرؤه — لا الشاشة.
 */
class ProductVariant extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'price' => 'decimal:3',
        'active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }

    /** مكوّنات هذا المقاس وحده — انظر Recipe::forLine لقاعدة الرجوع إلى وصفة المنتج */
    public function recipeItems(): HasMany
    {
        return $this->hasMany(RecipeItem::class, 'variant_id');
    }

    /** الاسم كما يُعرض للزبون بلغته */
    public function label(): string
    {
        return app()->getLocale() === 'en' && filled($this->name_en)
            ? $this->name_en
            : $this->name;
    }
}
