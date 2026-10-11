<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $guarded = [];
    protected $casts = ['card_hidden' => 'boolean'];
    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function products(): HasMany { return $this->hasMany(Product::class); }

    /**
     * ترتيبُ الفئات في الموقع — ما رتّبه صاحبُها، ثمّ ما لم يُرتَّب بالاسم.
     *
     * فارغُ `sort_order` آخرًا: قبل أن يرتّب أحدٌ تبقى الأبجديّةُ كما كانت،
     * وبعده تقع الفئةُ الجديدة في ذيل ترتيبه لا وسطَه. و`CASE` لا
     * `NULLS LAST`: الأولى تفهمها SQLite وPostgreSQL كلتاهما.
     */
    public function scopeInStoreOrder(Builder $query): Builder
    {
        return $query->orderByRaw('CASE WHEN sort_order IS NULL THEN 1 ELSE 0 END')
            ->orderBy('sort_order')
            ->orderBy('name');
    }
}
