<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * بوتيكٌ يبيع تحت سقف المحلّ — ويأخذ المحلُّ نسبةً ممّا باع.
 *
 * ليس متجرًا في أبعاد ولا فرعًا: هو صاحبُ أصنافٍ قائمة. وأصنافُه تُباع
 * وتُخصم وتدخل التقارير كأصناف المحلّ — ويُنسب بيعُها إليه.
 */
class Boutique extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'commission_rate' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** أصنافُه على الرفّ — والمحذوفُ حذفًا ناعمًا يسقط بنطاقه */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(BoutiqueSettlement::class);
    }

    /** اسمُه بلغة القارئ — كما يُقرأ كلُّ اسمٍ في النظام */
    public function label(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'en'
            ? ($this->name_en ?: $this->name)
            : $this->name;
    }
}
