<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * صورةٌ إضافية لمنتج — والرئيسية في `products.image`.
 *
 * لا عَلَمَ «رئيسية» هنا: عَلَمٌ في الجدول وعمودٌ في المنتج يقولان الشيء
 * نفسه، ويفترقان يومًا — فيُعرض للزبون غيرُ ما يُطبع له.
 */
class ProductImage extends Model
{
    protected $guarded = [];

    protected $casts = ['sort_order' => 'integer'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * الرابط كما يُعرض — بالقاعدة نفسها التي يقرأ بها المنتج صورته.
     *
     * والمنطق واحدٌ في الموضعين عمدًا: صورةٌ تُعرض من الجدول ورابطٌ يُبنى
     * بقاعدةٍ أخرى يعني أنّ الرئيسية والإضافية تظهران بمسارين — فتعمل
     * إحداهما وتنكسر الأخرى على خادمٍ بإعدادٍ مختلف.
     */
    public function getUrlAttribute(): string
    {
        return str_starts_with($this->path, 'http')
            ? $this->path
            : Storage::url($this->path);
    }
}
