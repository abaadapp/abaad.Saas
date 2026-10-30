<?php

namespace App\Support;

use App\Models\Plan;
use App\Models\Sector;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * القطاعات — وقاعدتُها الوحيدة: باقةُ الشركة من قطاع الشركة.
 *
 * والقاعدةُ تُسأل في الخادم عند كلّ بابٍ يختار باقة (`assertPlanFits`)، ثمّ
 * مرّةً أخيرةً عند حفظ الصفّ نفسه (`Business::booted`): إخفاءُ خيارٍ في
 * الشاشة ليس حراسة، وطلبٌ يُعدَّل باليد يصل كما كُتب.
 */
final class Sectors
{
    /**
     * القطاعُ التجاريّ — قطاعُ كلِّ ما بُني في أبعاد حتى اليوم.
     *
     * يزرعه الترحيل، ويُنشأ هنا إن غاب: قاعدةٌ أُفرغت بعد الترحيل (اختباراتُ
     * التفريغ) لا تُترك بلا قطاعٍ يُختم على صفٍّ جديد.
     */
    public static function retail(): Sector
    {
        return Sector::firstOrCreate(['code' => Sector::CODE_RETAIL], [
            'name' => 'التجارة والتجزئة',
            'name_en' => 'Retail & Commerce',
            'icon' => 'store',
            'sort' => 1,
            'active' => true,
            'system' => 'abaad',
        ]);
    }

    /** @return Collection<int, Sector> القطاعاتُ النشطة بترتيب عرضها */
    public static function active(): Collection
    {
        return Sector::where('active', true)->orderBy('sort')->orderBy('id')->get();
    }

    /** القطاعُ الذي يُختار وحده — حين لا نشطَ غيرُه */
    public static function only(): ?Sector
    {
        $active = self::active();

        return $active->count() === 1 ? $active->first() : null;
    }

    /**
     * قطاعُ صفٍّ جديدٍ لم يُذكر قطاعُه — من باقته إن كانت، وإلّا التجاريّ.
     *
     * فالتسجيلُ الذاتيّ وكلُّ بابٍ قائمٍ لا يعرف القطاعات يبقى كما هو، ويُختم
     * قطاعُ ما يُنشئه من باقته.
     */
    public static function forNew(mixed $planId): int
    {
        $fromPlan = filled($planId) ? Plan::whereKey($planId)->value('sector_id') : null;

        return (int) ($fromPlan ?: self::retail()->id);
    }

    /** أهذه الباقةُ من هذا القطاع؟ — والفراغُ في أيٍّ منهما لا يُسأل عنه */
    public static function fits(mixed $sectorId, mixed $planId): bool
    {
        if (blank($sectorId) || blank($planId)) {
            return true;
        }

        $planSector = Plan::whereKey($planId)->value('sector_id');

        return $planSector === null || (int) $planSector === (int) $sectorId;
    }

    /** الباقةُ من قطاع الشركة — أو يُردّ الطلبُ تحت حقله */
    public static function assertPlanFits(mixed $sectorId, mixed $planId, string $field = 'plan_id'): void
    {
        if (! self::fits($sectorId, $planId)) {
            throw ValidationException::withMessages([$field => self::refusal()]);
        }
    }

    public static function refusal(): string
    {
        return __('هذه الباقة ليست من قطاع الشركة — اختر من باقات قطاعها.');
    }
}
