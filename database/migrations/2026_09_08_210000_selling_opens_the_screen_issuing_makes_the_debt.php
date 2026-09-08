<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * فتحُ «المبيعات» يفتح الشاشة — والإصدارُ يُنشئ الذمّة.
 *
 * ═══ ما كان ═══
 *
 * أربعةُ أفعالٍ ماليّة كانت تُملَك بفتح القسم: إصدارُ الفاتورة — وهو ما
 * يولد الذمّةَ ويكتب القيد — وإلغاؤها بعكس قيدها، والإشعارُ الدائن الذي
 * يُنقص الدَّين، وتسجيلُ التحصيل. فمن يُؤتمن على قراءة الطلبات كان يُلغي
 * فاتورةً صادرةً على وزارة.
 *
 * ═══ وما يفعله هذا ═══
 *
 * يمنح الأربعةَ لمن كانت له قائمةٌ يدويّة فيها «المبيعات» — فلا يُقطع عن
 * أحدٍ ما كان يفعله أمس. ومن يتبع دورَه لا قائمةَ له تُعدَّل: `ACTION_ROLES`
 * تقرّر له، وقد ضاقت هناك عمدًا.
 *
 * ═══ ولماذا لا يقرأ `LEGACY_SECTION_ACTIONS` ═══
 *
 * سلفُه يقرؤها — وقد رُحّل على الإنتاج ومضى. فإضافةُ مفتاحٍ إليها اليوم
 * لا تُعيد تشغيله، لكنّها تغيّر معنى `down()` فيه بأثرٍ رجعيّ: تراجعٌ عن
 * هجرةٍ قديمة كان سينزع أفعالًا لم تكن موجودةً يومَ كُتبت. فهذه تحمل
 * قائمتَها بنفسها وتبقى مقروءةً كما هي بعد سنة.
 */
return new class extends Migration
{
    /**
     * القسمُ → ما كان يبيحه.
     *
     * والتحصيلُ تحت «المالية» لا «المبيعات»: بابُه `customerPayments` وهو
     * محسوبٌ عليها في `ALIASES`. فمن مُنح «المبيعات» وحدها لم يكن يبلغه —
     * ومنحُه إيّاه هنا يفتح على النائم بابًا لم يفتحه له صاحبُ المتجر.
     */
    private const GRANTS = [
        'orders' => [
            Permissions::CUSTOMER_INVOICE_ISSUE,
            Permissions::CUSTOMER_INVOICE_CANCEL,
            Permissions::CUSTOMER_CREDIT_NOTE,
        ],
        'finance' => [Permissions::CUSTOMER_PAYMENT_CREATE],
    ];

    public function up(): void
    {
        $this->rewrite(function (array $list): array {
            foreach (self::GRANTS as $section => $actions) {
                if (in_array($section, $list, true)) {
                    $list = array_merge($list, $actions);
                }
            }

            return $list;
        });
    }

    public function down(): void
    {
        $added = array_merge(...array_values(self::GRANTS));

        $this->rewrite(fn (array $list): array => array_values(array_diff($list, $added)));
    }

    /**
     * قراءةُ القائمة اليدوية وكتابتُها — ومن لا قائمةَ له لا يُمسّ.
     *
     * `NULL` تعني «اتبع الدور»، وكتابةُ قائمةٍ فوقها تقطع الوراثة: موظّفٌ
     * كان يرث دورَه يصير محبوسًا في ما كُتب له يوم الهجرة.
     */
    private function rewrite(callable $map): void
    {
        DB::table('users')->whereNotNull('permissions')->orderBy('id')
            ->chunkById(200, function ($rows) use ($map) {
                foreach ($rows as $row) {
                    $list = json_decode($row->permissions ?? 'null', true);

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
