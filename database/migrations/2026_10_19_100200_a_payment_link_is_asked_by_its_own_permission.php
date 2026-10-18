<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * طلبُ الدفع برابطٍ صار فعلًا باسمه — `paymentLink.create`.
 *
 * ═══ ما كان ═══
 *
 * الرابطُ يُطلب من «المالية» وبفعل التحصيل: من كانت قائمتُه اليدويّة تحمل
 * القسمَ `finance` والفعلَ `customerPayment.create` معًا كان يطلبه.
 *
 * ═══ وما يفعله هذا ═══
 *
 * يمنح الفعلَ الجديدَ لهؤلاء وحدهم — فلا يُقطع عن أحدٍ ما كان يفعله أمس، ولا
 * يُمنح أحدٌ ما لم يكن يفعله: من له «المالية» بلا التحصيل، أو التحصيلُ بلا
 * «المالية»، لم يكن يبلغ زرَّ الإنشاء — فلا يُمنحه هنا.
 *
 * ومن يتبع دورَه لا قائمةَ له تُعدَّل: `ACTION_ROLES` تقرّر له، وتمنح الفعلَ
 * للأدوار الثلاثة التي كانت تملك القسمَ والتحصيلَ معًا.
 *
 * ═══ ولماذا يحمل أسماءه بنفسه ═══
 *
 * كسلفه (`selling_opens_the_screen_issuing_makes_the_debt`): ثوابتُ
 * `Permissions` تتغيّر بعد اليوم، وهجرةٌ رُحّلت لا تُعاد — فتبقى مقروءةً
 * كما كُتبت.
 */
return new class extends Migration
{
    private const NEEDS = ['finance', 'customerPayment.create'];

    private const GRANT = 'paymentLink.create';

    public function up(): void
    {
        $this->rewrite(function (array $list): array {
            if (array_diff(self::NEEDS, $list) === []) {
                $list[] = self::GRANT;
            }

            return $list;
        });
    }

    public function down(): void
    {
        $this->rewrite(fn (array $list): array => array_values(array_diff($list, [self::GRANT])));
    }

    /**
     * قراءةُ القائمة اليدوية وكتابتُها — ومن لا قائمةَ له لا يُمسّ.
     *
     * `NULL` تعني «اتبع الدور»، وكتابةُ قائمةٍ فوقها تقطع الوراثة.
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
