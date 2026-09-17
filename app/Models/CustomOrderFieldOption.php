<?php

namespace App\Models;

use App\Support\Demo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * خيارٌ في حقل اختيار — كلمةٌ من قائمةٍ كتبها التاجر.
 *
 * ويُحذف أو يُوقَف متى شاء، ولا يتغيّر به طلبٌ مضى: البندُ يحمل **لقطةَ**
 * تسميته يوم البيع، لا مرجعًا إلى هذا الصفّ.
 */
class CustomOrderFieldOption extends Model
{
    protected $guarded = [];

    protected $casts = ['active' => 'boolean'];

    /** أكثرُ ما يُقبل من خياراتٍ في حقلٍ واحد — حدٌّ يمنع حمولةً مصنوعة */
    public const MAX_PER_FIELD = 100;

    public function field(): BelongsTo
    {
        return $this->belongsTo(CustomOrderField::class, 'field_id');
    }

    /** التسميةُ كما تُعرض — انظر `CustomOrderField::display` في تسمية الدالّة */
    public function display(): string
    {
        return Demo::ln($this->label, $this->label_en);
    }
}
