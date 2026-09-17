<?php

namespace App\Models;

use App\Support\Demo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * حقلٌ في قالب طلبٍ مخصَّص — سؤالٌ يسأله المتجر، لا يعرف النظامُ معناه.
 *
 * ═══ والحقلُ ليس مادّة ═══
 *
 * الحقلُ معلومةٌ أو اختيار: «المناسبة»، «التركيز»، «اسم المُهدى إليه». لا
 * يَنقص به رفٌّ ولا تُحسب منه تكلفة.
 *
 * والمادّةُ (`OrderItemComponent`) صنفٌ من المخزون خرج فعلًا: يُخصم ويُكلّف
 * ويعود أو لا يعود. وخلطُهما يجعل سؤالًا يُنقص بضاعة، أو بضاعةً تُكتب سؤالًا.
 */
class CustomOrderField extends Model
{
    protected $guarded = [];

    protected $casts = [
        'required' => 'boolean',
        'internal' => 'boolean',
        'active' => 'boolean',
    ];

    /** سطرٌ قصير — اسمٌ أو رقمُ هاتف */
    public const SHORT_TEXT = 'short_text';

    /** فقرة — وصفٌ أو ملاحظة */
    public const LONG_TEXT = 'long_text';

    /** رقم — مقاسٌ أو وزنٌ أو عدد. ولا يُخصم به مخزون */
    public const NUMBER = 'number';

    /** اختيارٌ واحد من قائمة */
    public const SELECT = 'select';

    /** اختياراتٌ عدّة من قائمة */
    public const MULTI_SELECT = 'multi_select';

    /** نعم/لا */
    public const CHECKBOX = 'checkbox';

    /** الأنواعُ المعروفة — مصدرٌ واحد تقرأ منه الشاشةُ والحارسُ والمُحقِّق */
    public const TYPES = [
        self::SHORT_TEXT,
        self::LONG_TEXT,
        self::NUMBER,
        self::SELECT,
        self::MULTI_SELECT,
        self::CHECKBOX,
    ];

    /**
     * تسمياتُ الأنواع كما تُعرض في محرّر القالب.
     *
     * وهي مفاتيحُ ترجمةٍ لا بياناتُ تاجر: النوعُ من النظام، والتسميةُ التي
     * يكتبها التاجر هي `label` الحقل نفسِه.
     */
    public const TYPE_LABELS = [
        self::SHORT_TEXT => 'نص قصير',
        self::LONG_TEXT => 'نص طويل',
        self::NUMBER => 'رقم',
        self::SELECT => 'اختيار واحد',
        self::MULTI_SELECT => 'اختيار متعدد',
        self::CHECKBOX => 'مربع اختيار',
    ];

    /** أنواعٌ لها قائمةُ خيارات — وما سواها لا يُقبل منه خيار */
    public const OPTION_TYPES = [self::SELECT, self::MULTI_SELECT];

    /** أطولُ ما يُقبل في سطرٍ قصير */
    public const SHORT_MAX = 200;

    /** وأطولُ ما يُقبل في فقرة */
    public const LONG_MAX = 2000;

    public function template(): BelongsTo
    {
        return $this->belongsTo(CustomOrderTemplate::class, 'template_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(CustomOrderFieldOption::class, 'field_id')
            ->orderBy('sort_order')->orderBy('id');
    }

    /**
     * التسميةُ كما تُعرض — و`display()` لا `label()`.
     *
     * العمودُ اسمُه `label`، ودالّةٌ بالاسم نفسه تجعل `$f->label` و`$f->label()`
     * شيئين مختلفين لا يفرّق بينهما إلّا قوسان — وأحدُهما يتخطّى الترجمة.
     */
    public function display(): string
    {
        return Demo::ln($this->label, $this->label_en);
    }

    /** أيقبل هذا الحقلُ خياراتٍ أصلًا؟ */
    public function takesOptions(): bool
    {
        return in_array($this->type, self::OPTION_TYPES, true);
    }
}
