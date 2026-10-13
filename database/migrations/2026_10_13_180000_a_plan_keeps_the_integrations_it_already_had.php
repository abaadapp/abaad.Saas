<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * الباقةُ القائمة لا تفقد تطبيقاتِها التكامليّة يومَ صارت قدرة.
 *
 * ═══ العطب الذي تمنعه ═══
 *
 * `PlanFeatures::allowedBy` تقرأ القائمةَ الصريحة كما هي: ما ليس فيها مغلق.
 * والباقاتُ القائمة على الإنتاج قوائمُها صريحة منذ 2026_09_01 — ولا تعرف
 * `integrations` لأنّها لم تكن. فلو نزل حارسُها والقوائمُ لا تعرفها لَأُغلقت
 * التطبيقاتُ التكامليّة — Google وPaymob وربطُ واتساب — على **كلّ** متجرٍ قائم.
 *
 * فيُضاف المفتاحُ إلى كلّ قائمةٍ صريحة: ما كان مفتوحًا يبقى مفتوحًا. وقرارُ
 * إغلاقه عن باقةٍ بعينها يُتّخذ بعدها من شاشة الباقات بيد مدير المنصّة — لا هنا.
 *
 * وتنزل في طلبٍ يسبق الحارس، لا معه — كما نزل `website` (#107) قبل حارسه
 * (#106): سكربتُ النشر يسحب الكودَ ثم يبني الواجهة ثم يرحّل، ولو وصل الحارسُ
 * مع الترحيل لَأغلق التكاملاتِ في الدقائق بينهما.
 *
 * ═══ وما لا تمسّه ═══
 *
 *  - `null`: تعني «كلّ شيء مفتوح» عند الحارس، وتبقى كذلك.
 *  - أسعارُ الباقات وأسقفُها وأسماؤها ونصوصُها: لا تُقرأ ولا تُكتب هنا. فالباقاتُ
 *    لا تُعرف بأسمائها، ولا يُفترض أنّها ثلاث.
 *  - ما سوى المفتاح في القائمة: يبقى بترتيبه.
 *
 * وتُعاد بلا أثر: قائمةٌ فيها المفتاحُ لا يُكتب فيها مرّتين.
 */
return new class extends Migration
{
    /** القدرةُ الجديدة — كانت مفتوحةً لكلّ باقةٍ قبل أن تكون قدرة */
    private const ADDED = ['integrations'];

    public function up(): void
    {
        DB::table('plans')->whereNotNull('capabilities')->orderBy('id')->get(['id', 'capabilities'])
            ->each(function ($plan) {
                $granted = json_decode((string) $plan->capabilities, true);

                // ونصٌّ لا يُقرأ قائمةً لا يُخمَّن: الحارسُ يقرؤه «مفتوحًا» كما هو
                if (! is_array($granted)) {
                    return;
                }

                $missing = array_values(array_diff(self::ADDED, $granted));

                if ($missing === []) {
                    return;
                }

                DB::table('plans')->where('id', $plan->id)
                    ->update(['capabilities' => json_encode([...array_values($granted), ...$missing])]);
            });
    }

    /**
     * والتراجعُ يُسقط المفتاح — فالكودُ قبلها لا يعرفه.
     *
     * ولو بقي لَردّ محرّرُ الباقات كلَّ حفظٍ: `capabilities.*` تُقاس على
     * `PlanFeatures::keys()`، ومفتاحٌ لا تعرفه يُرفض.
     */
    public function down(): void
    {
        DB::table('plans')->whereNotNull('capabilities')->orderBy('id')->get(['id', 'capabilities'])
            ->each(function ($plan) {
                $granted = json_decode((string) $plan->capabilities, true);

                if (! is_array($granted) || array_intersect(self::ADDED, $granted) === []) {
                    return;
                }

                DB::table('plans')->where('id', $plan->id)
                    ->update(['capabilities' => json_encode(array_values(array_diff($granted, self::ADDED)))]);
            });
    }
};
