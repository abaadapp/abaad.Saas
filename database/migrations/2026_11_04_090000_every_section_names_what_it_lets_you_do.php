<?php

use App\Support\SectionActions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * كلُّ قسمٍ يسمّي ما يُفعل فيه — ومن مُنح قسمًا يُمنح ما كان يفعله به.
 *
 * ═══ ما الذي ينفصل ═══
 *
 * كان القسمُ يفتح الشاشة ويبيح كلَّ ما فيها: الإضافةَ والتعديلَ والحذفَ
 * والتصدير. وصار كلٌّ منها فعلًا باسمه (`SectionActions::SPLIT`)، يحرسه
 * الخادمُ في `CheckAbility`.
 *
 * ═══ ولماذا هجرةٌ ═══
 *
 * من خُصّصت صلاحياتُه يدويًّا يقرأها `may()` بالمطابقة الحرفيّة — فلولا
 * هذه لصار صباحُ النشر هكذا: موظّفٌ مُنح «العملاء» يضغط «إضافة عميل»
 * فيُردّ بـ٤٠٣. ميزةٌ تُقرأ عطبًا.
 *
 * فيُضاف إلى كلّ قائمةٍ أفعالُ كلِّ قسمٍ فيها — ما كان يفعله أمسِ، لا أكثر.
 * وفعلٌ جديدٌ لم يكن يُملك بقسم (اعتماد التصحيح الماليّ) لا يُمنح هنا.
 *
 * ومن يتبع دورَه لا قائمةَ له تُكتب: أدوارُ كلّ فعلٍ هي أدوارُ قسمه
 * (`SectionActions::ROLES`)، فيبقى على ما كان. وكتابةُ قائمةٍ فوق `NULL`
 * تقطع الوراثة — فلا تُكتب.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rewrite(fn (array $list): array => SectionActions::withActionsOf($list));
    }

    /** والتراجعُ يرفع ما وضعته هذه الهجرة وحدَه — والقسمُ يعود يبيحه كما كان */
    public function down(): void
    {
        $added = array_keys(SectionActions::SPLIT);

        $this->rewrite(fn (array $list): array => array_values(array_diff($list, $added)));
    }

    /** قراءةُ القائمة اليدوية وكتابتُها — ومن لا قائمةَ له لا يُمسّ */
    private function rewrite(callable $map): void
    {
        DB::table('users')->whereNotNull('permissions')->orderBy('id')
            ->chunkById(200, function ($rows) use ($map) {
                foreach ($rows as $row) {
                    $list = json_decode($row->permissions ?? 'null', true);

                    // وما ليس قائمةً لا يُخمَّن: يُترك كما هو ويُقرأ كما كان
                    if (! is_array($list)) {
                        continue;
                    }

                    $next = array_values(array_unique($map(array_values($list))));

                    if ($next !== array_values(array_unique($list))) {
                        DB::table('users')->where('id', $row->id)
                            ->update(['permissions' => json_encode($next, JSON_UNESCAPED_UNICODE)]);
                    }
                }
            });
    }
};
