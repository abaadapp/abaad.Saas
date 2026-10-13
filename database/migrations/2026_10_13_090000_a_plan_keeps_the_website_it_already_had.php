<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * الباقةُ القائمة لا تفقد موقعَها ولا أدواتِ تسويقها يومَ صارتا قدرتين.
 *
 * ═══ العطب الذي تمنعه ═══
 *
 * `PlanFeatures::allowedBy` تقرأ القائمةَ الصريحة كما هي: ما ليس فيها مغلق.
 * والباقاتُ القائمة على الإنتاج قوائمُها صريحة منذ 2026_09_01 — ولا تعرف
 * `website` ولا `marketing_tools` لأنّهما لم يكونا. فلو نُشرت القدرتان وحدهما
 * لأُغلق الموقعُ العامّ ولوحتُه والتقييماتُ والكوبونات على **كلّ** متجرٍ قائم
 * لحظةَ النشر — ومنها متاجرُ تبيع من موقعها الآن.
 *
 * فيُضاف المفتاحان إلى كلّ قائمةٍ صريحة: ما كان مفتوحًا يبقى مفتوحًا. وقرارُ
 * التقسيم التجاريّ الجديد يُتّخذ بعدها من شاشة الباقات، باقةً باقة، بيد مدير
 * المنصّة — لا هنا.
 *
 * وتنزل في طلبٍ يسبق حارسيهما، لا معهما: سكربتُ النشر يسحب الكودَ ثم يبني
 * الواجهة ثم يرحّل، ولو وصل الحارسان مع الترحيل لَأغلقا الموقعَ في الدقائق
 * بينهما. فإذا نزلا بعدُ وجدا كلَّ قائمةٍ تعرفهما.
 *
 * ═══ وما لا تمسّه ═══
 *
 *  - `null`: تعني «كلّ شيء مفتوح» عند الحارس، وتبقى كذلك.
 *  - أسعارُ الباقات وأسقفُها وأسماؤها: لا تُقرأ ولا تُكتب هنا. فالباقاتُ لا
 *    تُعرف بأسمائها، ولا يُفترض أنّها ثلاث.
 *  - ما سوى المفتاحين في القائمة: يبقى بترتيبه.
 *
 * وتُعاد بلا أثر: قائمةٌ فيها المفتاحان لا تُكتب مرّتين.
 */
return new class extends Migration
{
    /** القدرتان الجديدتان — كانتا مفتوحتين لكلّ باقةٍ قبل أن تكونا قدرتين */
    private const ADDED = ['website', 'marketing_tools'];

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
     * والتراجعُ يُسقط المفتاحين — فالكودُ قبلها لا يعرفهما.
     *
     * ولو بقيا لَردّ محرّرُ الباقات كلَّ حفظٍ: `capabilities.*` تُقاس على
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
