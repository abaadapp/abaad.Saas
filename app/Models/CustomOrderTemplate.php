<?php

namespace App\Models;

use App\Support\CustomArrangement;
use App\Support\Demo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * قالبُ طلبٍ مخصَّص — شكلُ الطلب كما كتبه صاحبُ النشاط.
 *
 * ═══ ولمَ قالبٌ لا صناعة ═══
 *
 * أبعاد لا يعرف ما يبيع التاجر ولا يحتاج أن يعرف. فبدل أن يحمل النظامُ
 * قائمةَ الصناعات وفرعًا لكلّ واحدة، يحمل **محرّكًا واحدًا** يقرأ قالبًا:
 * اسمٌ، وأوضاعُ تسعير، وحقولٌ سمّاها التاجر، وموادُّ من مخزونه.
 *
 * ومحلُّ الورد يصنع قالبًا فيه «ألوان الورد»، ومحلُّ العطر قالبًا فيه
 * «التركيز» — والمحرّكُ واحدٌ لا يعرف أيَّهما يُشغّل.
 */
class CustomOrderTemplate extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'modes' => 'array',
        'allow_components' => 'boolean',
        'allow_addons' => 'boolean',
        'components_restockable_default' => 'boolean',
        'active' => 'boolean',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** حقولُ القالب مرتَّبةً كما يراها الكاشير */
    public function fields(): HasMany
    {
        return $this->hasMany(CustomOrderField::class, 'template_id')
            ->orderBy('sort_order')->orderBy('id');
    }

    /** الاسمُ كما يُعرض — بلغة الواجهة، وبالعربية حين لا إنجليزيّة */
    public function display(): string
    {
        return Demo::ln($this->name, $this->name_en);
    }

    /**
     * تسميةُ القيمة الأساسية — ما يكتبه التاجر، أو الافتراض العامّ.
     *
     * ولا تُبدّل شيئًا في المالية: هي كلمةٌ فوق حقل، والحقلُ نفسُه سعرُ بيعٍ
     * يدخل الفاتورة والدفتر كما يدخل سعرُ أيّ بند.
     */
    public function baseLabel(): string
    {
        $written = Demo::ln($this->base_label, $this->base_label_en);

        return $written !== '' ? $written : __('القيمة الأساسية');
    }

    /** أهذا الوضع مسموحٌ في هذا القالب؟ */
    public function allowsMode(?string $mode): bool
    {
        return $mode !== null && in_array($mode, (array) $this->modes, true);
    }

    /**
     * قوالبُ المتجر التي يجوز البيعُ بها الآن.
     *
     * والمحذوفُ ليّنًا خارجٌ بحكم `SoftDeletes`، والموقوفُ يخرج بالشرط —
     * وكلاهما يبقى مقروءًا لطلبٍ مضى، لأنّ الطلبَ يحمل لقطتَه لا مرجعَه.
     */
    public static function sellable(int $businessId)
    {
        return self::where('business_id', $businessId)
            ->where('active', true)
            ->orderBy('sort_order')->orderBy('id');
    }

    /**
     * القالبُ الذي يُباع به حين لا يُسمّى واحد.
     *
     * ═══ ولمَ الاستنتاجُ لا الرفض ═══
     *
     * سلّةٌ عُلّقت قبل الترقية لا تحمل معرّفَ قالب — لم يكن للقوالب وجود.
     * ورفضُها يعني أنّ ترقيةً تُسقط سلّاتٍ في وجه الكاشير بـ٤٢٢ لا يفهمه.
     *
     * فتُباع بقالب المتجر الأوّل — وهو الافتراضيّ الذي يصف السلوكَ الذي
     * عُلّقت به. ومتجرٌ لم يُنشئ قالبًا قطّ يُنشأ له واحدٌ هنا: الميزةُ تعمل
     * عنده اليوم، وترقيةٌ تُطفئ ما كان يعمل عطبٌ لا إعداد.
     *
     * ومن أوقف قوالبَه كلَّها لا يُقحَم عليه شيء: `seedDefault` تنصرف متى
     * وُجد قالبٌ — ولو موقوفًا أو محذوفًا — فيُردّ `null` ويُرفض الطلب.
     */
    public static function ensureDefault(int $businessId): ?self
    {
        self::seedDefault($businessId);

        return self::sellable($businessId)->first();
    }

    /**
     * قالبٌ افتراضيّ لمتجرٍ لا قالبَ له — يصف السلوك القائم بالحرف.
     *
     * الوضعان كلاهما، والموادُّ والإضافات مفتوحة، ولا حقولَ فيه. فمتجرٌ
     * يُرقّى اليوم يجد طلبَه المخصَّص كما تركه أمس، ومن أراد حقولًا أضافها.
     *
     * ويُنادى مرّةً لكلّ متجر: وجودُ قالبٍ واحدٍ — أيًّا كان — يعني أنّ
     * التاجر قد بدأ، فلا يُقحَم عليه قالبٌ لم يطلبه.
     */
    public static function seedDefault(int $businessId): ?self
    {
        if (self::withTrashed()->where('business_id', $businessId)->exists()) {
            return null;
        }

        return self::create([
            'business_id' => $businessId,
            'name' => 'طلب مخصص',
            'name_en' => 'Custom Order',
            'modes' => CustomArrangement::MODES,
            'default_mode' => CustomArrangement::MODE_VALUE,
            'allow_components' => true,
            'allow_addons' => true,
            'components_restockable_default' => false,
            'active' => true,
            'sort_order' => 0,
        ]);
    }
}
