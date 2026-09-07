<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * تفصيلُ أفعال الدورة المالية — ومن مُنح قسمًا يُمنح ما كان يفعله به.
 *
 * ═══ لماذا هجرةٌ أصلًا وليست جدولًا جديدًا ═══
 *
 * لا عمودَ يُضاف هنا ولا جدول: الصلاحياتُ تعيش في `users.permissions` منذ
 * البداية. وهذه تُصلح بيانات: من خُصِّصت صلاحياتُه يدويًّا يقرأها `may()`
 * بالمطابقة الحرفية — فما لم يُكتب في قائمته لا يملكه.
 *
 * ولولا هذه لكان صباحُ النشر هكذا: محاسبٌ مُنح «الرواتب والموظفين» يفتح
 * مسيرة الرواتب فيُردّ بـ٤٠٣، وأمينُ مخزنٍ مُنح «المشتريات» لا يُدخل سندًا
 * ولا يفتح شاشته. ميزةُ فصلِ الصلاحيات كانت ستُقرأ عطبًا في النظام.
 *
 * ═══ ما يُمنح لمن ═══
 *
 * كلٌّ يُمنح ما كان قسمُه يبيحه له أمسِ — لا أكثر:
 *
 *   «المخزون»    → قراءةُ إشعارات الاستلام
 *   «المشتريات»  → كتابةُ الاستلام، وقراءةُ السندات وكتابتُها، وسدادُها
 *   «الموظفين»   → قراءةُ مسيرة الرواتب واعتمادُها وصرفُها
 *   ومرفقاتُ المستندات لمن ملك أيًّا من الثلاثة أو «المصروفات»
 *
 * والاعتمادُ والرفضُ والتجاوزُ لا تُمنح هنا: لم تكن تُملَك أمسِ بقسم، بل
 * أُفردت أفعالًا في الإصدارات السابقة — ومنحُها بهجرةٍ يفتح على النائم بابًا
 * لم يفتحه له صاحبُ المتجر.
 *
 * وقوائمُ الأدوار (`ACTION_ROLES`) لا تُلمَس هنا: من يتبع دورَه لا قائمةَ
 * له تُعدَّل.
 */
return new class extends Migration
{
    /**
     * القسمُ → ما كان يبيحه من الأفعال الجديدة.
     *
     * ولا تُكتب هنا: `Permissions::LEGACY_SECTION_ACTIONS` مصدرُها، ويقرؤها
     * الاختبارُ أيضًا — فلا تفترق نسختان يومًا.
     */
    private const GRANTS = Permissions::LEGACY_SECTION_ACTIONS;

    public function up(): void
    {
        $this->rewrite(fn (array $list): array => Permissions::withLegacyActions($list));
    }

    /** والتراجعُ يرفع ما وضعته هذه الهجرة وحدَه */
    public function down(): void
    {
        $added = array_unique(array_merge(...array_values(self::GRANTS)));

        $this->rewrite(fn (array $list): array => array_values(array_diff($list, $added)));
    }

    /**
     * قراءةُ القائمة اليدوية وكتابتُها — ومن لا قائمةَ له لا يُمسّ.
     *
     * `NULL` تعني «اتبع الدور»، وكتابةُ قائمةٍ فوقها تقطع الوراثة: موظّفٌ
     * كان يرث دورَه يصير محبوسًا في ما كُتب له يوم الهجرة، فلا يبلغه فعلٌ
     * يُضاف غدًا.
     */
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
