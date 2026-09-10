<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\BranchGooglePlace;
use App\Models\User;

/**
 * الفرعُ وملفُّه على الخرائط — الموضعُ الوحيد الذي يعرف الربط.
 *
 * ═══ ولمَ الفرعُ لا المتجر ═══
 *
 * لكلِّ فرعٍ عند Google ملفٌّ مستقلّ بعنوانه ومعدّله وعدد تقييماته. ومعرّفٌ
 * واحدٌ للمتجر كلِّه يعني أنّ إيصال فرع المعبيلة يحمل رمزًا يفتح ملفَّ فرع
 * الخوض — فيكتب زبونٌ اشترى من هنا تقييمًا يُحسب هناك. وهو عطبٌ لا يراه
 * صاحبُه أبدًا، لأنّه لا يمسح إيصالاته بنفسه.
 *
 * ═══ ولا يُصدَّق ما يأتي من المتصفّح ═══
 *
 * الشاشةُ تعرض نتائج البحث، والتاجر يختار واحدة. والمُرسَل إلى الخادم
 * **معرّفٌ وحده**؛ ثمّ يُنادى Google بذلك المعرّف فيُكتب ما ردّته هي: الاسمُ
 * والمعدّلُ والعددُ والرابط. فلو صُدّق ما جاء مع الطلب لَاستطاع مَن بدّل
 * حقلًا أن يجعل شاشتَنا تشهد باسمٍ ومعدّلٍ لم تقلهما Google.
 */
final class BranchGoogle
{
    /**
     * بعد كم ساعةٍ يُعدّ المعدّل قديمًا.
     *
     * اثنتا عشرةَ ساعة: التقييماتُ لا تتحرّك في الساعة، وكلُّ تحديثٍ نداءٌ
     * مدفوعٌ **لكلّ فرع** — فمتجرٌ بثلاثة فروعٍ يفتح الشاشة عشر مرّاتٍ في
     * اليوم يصير ثلاثين نداءً بلا هذا الحدّ.
     */
    public const STALE_HOURS = 12;

    /* ═══════════════════ القراءة ═══════════════════ */

    /** ربطُ هذا الفرع — أو لا شيء إن لم يُربط أو فُكّ */
    public static function for(Branch $branch): ?BranchGooglePlace
    {
        return BranchGooglePlace::query()->linked()->where('branch_id', $branch->id)->first();
    }

    /**
     * الفرعُ الذي يُمثّل المتجر حين لا يُعرف الفرع.
     *
     * يُقرأ في موقع المتجر وفي شاشة التقييمات: أقدمُ فرعٍ **مربوط**. ولو
     * أُخذ أقدمُ فرعٍ مطلقًا لَقال الموقعُ «غير مربوط» عن متجرٍ ربط فرعَه
     * الثاني وحده.
     */
    public static function primaryFor(int $businessId): ?BranchGooglePlace
    {
        return BranchGooglePlace::query()->linked()
            ->join('branches', 'branches.id', '=', 'branch_google_places.branch_id')
            ->where('branches.business_id', $businessId)
            ->whereNull('branches.deleted_at')
            ->orderBy('branches.id')
            ->select('branch_google_places.*')
            ->first();
    }

    /** رابطُ «اكتب تقييمًا» على ملفّ هذا الفرع بعينه */
    public static function reviewUrl(?BranchGooglePlace $place): ?string
    {
        return $place && $place->isLinked() ? GoogleReviews::reviewUrl($place->place_id) : null;
    }

    /* ═══════════════════ الربط ═══════════════════ */

    /**
     * ربطُ فرعٍ بمكان — بعد أن تشهد Google أنّ المكان موجود.
     *
     * ولا يُكتب صفٌّ قبل الشهادة: معرّفٌ مخترَعٌ يُحفظ بلا فحصٍ يعني إيصالاتٍ
     * تُطبع برمزٍ لا يفتح شيئًا، ولا يشكو أحد.
     *
     * @return array{ok:bool, error:?string, place:?BranchGooglePlace}
     */
    public static function link(Branch $branch, string $placeId, ?User $by = null): array
    {
        $id = GoogleReviews::placeId($placeId);

        if ($id === null) {
            return self::fail(__('معرّف المكان غير مقروء.'));
        }

        $key = GoogleReviews::apiKey($branch->business_id);

        if ($key === null) {
            return self::fail(__('خدمة Google Maps غير مفعلة حاليًا.'));
        }

        $result = GooglePlaces::details($id, $key);

        if (! $result['ok']) {
            return self::fail((string) $result['error']);
        }

        $place = $result['place'];

        /*
         * والاسمُ شرطٌ في القبول.
         *
         * ردٌّ ناجحٌ بلا اسمٍ يعني مكانًا لا تعرفه Google أو حقلًا سقط — وحفظُه
         * يُري التاجر «تمّ الربط» تحته سطرٌ فارغ، فلا يستطيع أن يتحقّق أنّه
         * فرعُه.
         */
        if (trim((string) $place['name']) === '') {
            return self::fail(__('لم تُعِد Google اسمًا لهذا المكان.'));
        }

        $row = BranchGooglePlace::updateOrCreate(
            ['branch_id' => $branch->id],
            [
                'place_id' => $id,
                'place_name' => $place['name'],
                'maps_url' => $place['maps_url'],
                'rating' => $place['rating'],
                'review_count' => $place['count'],
                'synced_at' => now(),
                'linked_by' => $by?->id,
                'linked_at' => now(),
                // ربطٌ جديدٌ على صفٍّ مفكوك: يُرفع الختم
                'unlinked_at' => null,
            ],
        );

        /*
         * والمسحوبُ يسقط من الذاكرة بعد الكتابة.
         *
         * من ربط الآن يقصد أن يرى ما عند Google الآن — لا ما بقي في الذاكرة
         * من قبل. ولولا هذا لَأعاد الربطَ فرأى الرقم القديم فظنّ أنّه لم يصنع
         * شيئًا فأعاده ثالثة.
         */
        GoogleReviews::forget($branch->business_id);

        Activity::log('updated', 'ربط فرع «'.$branch->name.'» بـ'.$place['name'].' على خرائط Google', [
            'subject_id' => $branch->id,
            'subject_type' => 'branch',
        ]);

        return ['ok' => true, 'error' => null, 'place' => $row];
    }

    /**
     * فكُّ الربط — ختمٌ لا محو.
     *
     * الصفُّ يبقى ليُقرأ أنّ هذا الفرع كان مربوطًا بهذا المكان وفُكّ في هذا
     * اليوم. ومحوُ الصفّ يمحو السؤالَ وجوابَه معًا.
     */
    public static function unlink(Branch $branch): bool
    {
        $row = self::for($branch);

        if (! $row) {
            return false;
        }

        /* والإسقاطُ قبل الختم: بعده لا يُعرف أيُّ مكانٍ كان ليُسقَط موضعُه */
        GoogleReviews::forget($branch->business_id);

        $row->forceFill(['unlinked_at' => now()])->save();

        Activity::log('updated', 'فكّ ربط فرع «'.$branch->name.'» عن خرائط Google', [
            'subject_id' => $branch->id,
            'subject_type' => 'branch',
        ]);

        return true;
    }

    /* ═══════════════════ المزامنة ═══════════════════ */

    public static function isStale(BranchGooglePlace $place): bool
    {
        return $place->synced_at === null
            || $place->synced_at->lt(now()->subHours(self::STALE_HOURS));
    }

    /**
     * تحديثُ المعدّل والعدد من Google.
     *
     * ولا يُمحى المحفوظُ عند الفشل: عطلٌ عابرٌ عند Google لا يجعل معدّلَ
     * الفرع مجهولًا في الشاشة — يبقى آخرُ ما عُرف، ويقول تاريخُ المزامنة متى
     * عُرف.
     */
    public static function sync(BranchGooglePlace $place, bool $force = false): bool
    {
        if (! $force && ! self::isStale($place)) {
            return false;
        }

        $branch = $place->branch;
        $key = $branch ? GoogleReviews::apiKey($branch->business_id) : null;

        if ($key === null) {
            return false;
        }

        $result = GooglePlaces::details($place->place_id, $key);

        if (! $result['ok']) {
            return false;
        }

        $place->forceFill([
            'place_name' => $result['place']['name'] ?: $place->place_name,
            'maps_url' => $result['place']['maps_url'] ?? $place->maps_url,
            'rating' => $result['place']['rating'],
            'review_count' => $result['place']['count'],
            'synced_at' => now(),
        ])->save();

        return true;
    }

    /* ═══════════════════ العرض ═══════════════════ */

    /**
     * قائمةُ فروع المتجر وحالُ كلٍّ منها — وهي ما ترسمه الشاشة.
     *
     * والمزامنةُ هنا **للقديم وحده**: الشاشةُ تُفتح كثيرًا، ونداءُ Google لكلّ
     * فرعٍ في كلّ فتحةٍ فاتورةٌ تكبر بلا أن يقرأ أحدٌ رقمًا جديدًا.
     *
     * @return list<array<string, mixed>>
     */
    public static function branches(int $businessId, bool $sync = true): array
    {
        $branches = Branch::where('business_id', $businessId)
            ->with('googlePlace')->orderBy('id')->get();

        $out = [];

        foreach ($branches as $branch) {
            $place = $branch->googlePlace;
            $place = $place && $place->isLinked() ? $place : null;

            if ($place && $sync) {
                self::sync($place);
            }

            $out[] = [
                'id' => $branch->id,
                'name' => $branch->name,
                'linked' => $place !== null,
                'placeName' => $place?->place_name,
                'rating' => $place?->rating,
                'reviewCount' => $place?->review_count,
                'mapsUrl' => $place?->maps_url ?: GoogleReviews::placeUrl($place?->place_id),
                'reviewUrl' => self::reviewUrl($place),
                'syncedAt' => optional($place?->synced_at)->toIso8601String(),
                /*
                 * ومعرّفُ المكان لا يُرسل إلى شاشة التاجر.
                 *
                 * لا يفعل به شيئًا: هو يبحث ويختار ويقرأ الاسم. وإظهارُه يدعو
                 * إلى لصقه بيدٍ — وهو الطريق الذي جاءت هذه الشاشة لتُغنيَ عنه.
                 */
            ];
        }

        return $out;
    }

    /** @return array{ok:false, error:string, place:null} */
    private static function fail(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'place' => null];
    }
}
