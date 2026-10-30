<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * قطاعٌ في منصّة أبعاد — يجمع باقاته وشركاته تحت تصنيفٍ إداريّ واحد.
 *
 * ليس نوعَ النشاط (`BusinessTypes`): «محل ورود» و«بقالة» نشاطان في قطاعٍ
 * واحد. والقطاعُ لا يفتح شيئًا ولا يُغلقه — الباقةُ وقدراتُها تفعل ذلك.
 *
 * والرمزُ (`code`) يُكتب مرّةً عند الإنشاء ولا يُعدَّل: يقرؤه الكود، والاسمُ
 * يقرؤه الناس.
 */
class Sector extends Model
{
    /** التجارة والتجزئة — قطاعُ كلِّ ما بُني في أبعاد حتى اليوم */
    public const CODE_RETAIL = 'retail';

    /**
     * أيقوناتُ القطاع — قائمةٌ مغلقةٌ تعرفها الواجهة (`SectorIcon`).
     *
     * ونصٌّ حرٌّ هنا يعني أيقونةً لا تُرسم.
     */
    public const ICONS = [
        'store', 'shopping-bag', 'shopping-cart', 'package', 'flower', 'gift',
        'utensils', 'coffee', 'scissors', 'sparkles', 'stethoscope', 'briefcase',
        'building', 'graduation-cap', 'car', 'wrench', 'truck', 'heart',
    ];

    /**
     * الأنظمةُ التي يُشغَّل عليها القطاع — نظامُ أبعاد الحاليّ وحده اليوم.
     *
     * معلوماتيٌّ: لا لوحةَ ثانيةً ولا مسارَ آخر. يُضاف نظامٌ هنا يومَ يوجد.
     */
    public const SYSTEMS = ['abaad' => ['name' => 'نظام أبعاد', 'name_en' => 'Abaad']];

    protected $guarded = [];

    protected $casts = ['active' => 'boolean', 'sort' => 'integer'];

    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
    }

    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class);
    }
}
