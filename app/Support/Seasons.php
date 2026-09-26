<?php

namespace App\Support;

use App\Models\Product;
use App\Models\Season;
use App\Models\SeasonReminder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * قواعدُ المواسم في موضعٍ واحد — الحالةُ، وما يحين من تذكيرات، وما يُعرض
 * في الصندوق والموقع. فلا تُكتب «اليومُ داخل المدّة» بثلاث صيغٍ في ثلاث
 * شاشات وتفترق ليلًا عند حدود التوقيت.
 */
final class Seasons
{
    /** أقصى عددٍ من التذكيرات على موسم — حمايةٌ من الإغراق لا حدٌّ يُلمس */
    public const MAX_REMINDERS = 20;

    /**
     * حالةُ الموسم اليوم.
     *
     * والمُطفأُ «غير فعّال» أيًّا كان تاريخُه — فاللوحةُ تقول ذلك ولا تقول
     * «نشط» عن موسمٍ أطفأه صاحبُه. والتواريخُ أيّامٌ لا لحظات: اليومُ
     * الأخير من الموسم موسمٌ حتى منتصف ليله بتوقيت التطبيق.
     */
    public static function status(Season $season, ?Carbon $today = null): string
    {
        if (! $season->active) {
            return Season::INACTIVE;
        }

        $day = ($today ?? today())->startOfDay();

        if ($day->lt($season->starts_at->copy()->startOfDay())) {
            return Season::UPCOMING;
        }

        if ($day->gt($season->ends_at->copy()->startOfDay())) {
            return Season::ENDED;
        }

        return Season::ACTIVE;
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            Season::UPCOMING => __('قادم'),
            Season::ACTIVE => __('نشط'),
            Season::ENDED => __('منتهي'),
            default => __('غير فعّال'),
        };
    }

    /**
     * المواسمُ الجاريةُ في الصندوق لهذا المتجر — بمعرّفات أصنافها.
     *
     * قائمةٌ فارغةٌ تعني «لا شريطَ مواسم» في الصندوق. والأصنافُ معرّفاتٌ لا
     * صفوف: الصندوقُ حمّل أصنافَه بقواعده، والموسمُ يرشّح ما حُمّل ولا يضيف.
     *
     * @return array<int, array{id:int, name:string, product_ids:int[]}>
     */
    public static function forPos(int $businessId, ?Carbon $today = null): array
    {
        return Season::where('business_id', $businessId)->live($today)
            ->where('show_in_pos', true)
            ->orderBy('starts_at')->orderBy('id')
            ->with(['products' => fn ($q) => $q->select('products.id')])
            ->get()
            ->map(fn (Season $s) => [
                'id' => $s->id,
                'name' => Demo::ln($s->name, $s->name_en),
                'product_ids' => $s->products->pluck('id')->map(fn ($id) => (int) $id)->all(),
            ])
            ->filter(fn ($s) => $s['product_ids'] !== [])
            ->values()->all();
    }

    /**
     * المواسمُ الجاريةُ على الموقع — بمعرّفات أصنافها.
     *
     * المعرّفاتُ فقط: أهليّةُ الصنف للعرض العامّ تُقرأ من قواعد الموقع
     * نفسِها (`active` و`published` و`Shelf`)، لا من هنا. فالموسمُ لا يُخرج
     * صنفًا غيرَ منشورٍ إلى الناس بأيّ حال.
     *
     * @return Collection<int, Season>
     */
    public static function forWebsite(int $businessId, ?Carbon $today = null): Collection
    {
        return Season::where('business_id', $businessId)->live($today)
            ->where('show_on_website', true)
            ->orderBy('starts_at')->orderBy('id')
            ->with(['products' => fn ($q) => $q->select('products.id')])
            ->get();
    }

    /**
     * ما حان من تذكيرات هذا المتجر ولم يُقرأ — لقسم المواسم وحدَه.
     *
     * بابٌ واحد: يقرؤه `SeasonController::index` ولا يقرؤه الجرسُ العامّ.
     * فالإخفاءُ من القسم يُخفي التنبيهَ حقًّا، ولا يبقى له ظلٌّ في قائمةٍ
     * أخرى يُقرأ منها مرّةً ثانية.
     *
     * والمحسوبُ عند كلّ قراءة لا مجدولٌ في مهمّة: من غاب حتى مرّ الموعدُ
     * يجد تنبيهَه واقفًا حين يفتح، ولا صفَّ إشعارٍ يُكتب في كلّ فتحة.
     *
     * @return Collection<int, SeasonReminder>
     */
    public static function due(int $businessId, ?Carbon $now = null): Collection
    {
        $now ??= now();

        return SeasonReminder::where('business_id', $businessId)
            ->where('active', true)
            /*
             * والموسمُ المنتهي لا يُذكَّر به: تنبيهٌ لموسمٍ مضى لا يُستعدّ له.
             * والمُطفأُ كذلك — أطفأه صاحبُه فلا يُلحّ عليه.
             */
            ->whereHas('season', fn ($q) => $q->where('active', true)
                ->whereDate('ends_at', '>=', $now->toDateString()))
            ->with('season')
            ->get()
            ->filter(fn (SeasonReminder $r) => $r->isDue($r->season, $now))
            ->sortBy(fn (SeasonReminder $r) => $r->dueAt()?->timestamp ?? 0)
            ->values();
    }

    /** الأصنافُ المقترَحة في مُنتقي الموسم — أصنافُ هذا المتجر وحدَها، ببحثٍ وسقف */
    public static function pickable(int $businessId, string $q, array $exclude = [], int $limit = 20): Collection
    {
        /* و`%` من صندوق البحث تُمحى كما في `Search::term` — بحثٌ لا يبحث خيرٌ من كلِّ شيء */
        $needle = trim(str_replace('%', '', $q));
        $like = Search::like();

        return Product::where('business_id', $businessId)
            ->when($exclude, fn ($w) => $w->whereNotIn('id', $exclude))
            ->when($needle !== '', fn ($w) => $w->where(fn ($x) => $x
                ->where('name', $like, "%{$needle}%")
                ->orWhere('name_en', $like, "%{$needle}%")
                ->orWhere('sku', $like, "%{$needle}%")
                ->orWhere('barcode', $like, "%{$needle}%")))
            ->with('category:id,name,name_en')
            ->orderBy('name')->limit($limit)->get();
    }
}
