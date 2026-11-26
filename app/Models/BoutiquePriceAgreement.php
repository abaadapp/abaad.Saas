<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * سعرٌ متّفقٌ عليه بين المتجر وبوتيكٍ لصنف — بتاريخ سريانه ومرجع اتّفاقه (`BoutiquePolicy`).
 *
 * سطرٌ يُضاف ولا يُعدَّل ولا يُحذف: تغييرُ المتّفق عليه سطرٌ جديد، وعرضٌ مؤقّتٌ سطرٌ له نهاية.
 */
class BoutiquePriceAgreement extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['price' => 'decimal:3', 'starts_on' => 'date', 'ends_on' => 'date'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('السعر المتفق عليه لا يُعدَّل — يُكتب سطرٌ جديد بتاريخه.'));
        static::deleting(fn () => throw new \LogicException('السعر المتفق عليه لا يُحذف — يُكتب سطرٌ جديد بتاريخه.'));
    }
}
