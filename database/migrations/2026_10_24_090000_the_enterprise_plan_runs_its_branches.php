<?php

use App\Models\Plan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * باقةُ المؤسسات تملك إدارةَ الفروع — برمزٍ ثابت وقدراتٍ صريحة.
 *
 * ═══ ما يُكتب ═══
 *
 *   ١ — رمزُ `enterprise` على الباقة المسمّاة «باقة المؤسسات» **إن كانت واحدةً
 *       بالضبط** وعلى سلّم أسقفها (10 فروع، 100 مستخدم، منتجات بلا حدّ —
 *       `2026_10_17_120000_the_plans_climb_one_ladder_of_limits`). فإن لم توجد،
 *       أو وُجدت اثنتان، أو عُدّلت أسقفُها، لم يُكتب شيء: الرمزُ لا يُسنَد إلى
 *       باقةٍ ملتبسة، وقدراتُ المؤسسات تبقى مغلقةً حتى يؤشّرها صاحبُ المنصّة.
 *   ٢ — قدراتُ المؤسسات الخمس (`PlanFeatures::ENTERPRISE`) على ذات الرمز وحدها.
 *       وقائمةٌ فارغةُ العمود (`null` = كلُّ ما قبلها مفتوح) تُكتب صريحةً بكلّ
 *       القدرات، فلا يُغلق عليها شيءٌ كان مفتوحًا.
 *   ٣ — نصُّ العرض — إن كان ما كتبه سلّمُ الأسقف بعينه. وما عدّله المشغّل بعده
 *       من شاشة الباقات لا يُكتب فوقه.
 *
 * ولا تُمسّ الاحترافيّةُ ولا الأساسيّةُ ولا أيُّ باقةٍ مخصّصة: قدراتُ المؤسسات
 * لا تُفتح بالافتراض (`PlanFeatures::allowedBy`)، فلا يُحتاج إلى إغلاقها فيها.
 *
 * ═══ والرجوع ═══
 *
 * `down` ينزع القدرات الخمس عن ذات الرمز، ويُعيد النصَّ إن بقي كما كتبه `up`،
 * ويمحو الرمز.
 */
return new class extends Migration
{
    private const NAME = 'باقة المؤسسات';

    /** [فروع، مستخدمون، منتجات] — سلّمُ الأسقف الذي تُعرف به */
    private const LIMITS = [10, 100, null];

    /** ما كتبه سلّمُ الأسقف — يُستبدل هو وحده */
    public const BEFORE = ['تشغيل عدة فروع', '10 فروع', '100 مستخدم مع المالك', 'منتجات غير محدودة', 'جميع قدرات الباقة الاحترافية'];

    /** والنصُّ الجديد — هو نفسُه في `SeedData::plans` */
    public const AFTER = [
        'إدارة متكاملة للشركات متعددة الفروع',
        'جميع مميزات الباقة الاحترافية',
        'حتى 10 فروع',
        'حتى 100 مستخدم نشط مع المالك',
        'منتجات غير محدودة',
        'نقل المخزون بين الفروع',
        'طلبات تزويد الفروع',
        'الموافقة على النقل ومتابعة الإرسال والاستلام',
        'تأكيد الاستلام ومعالجة الفروقات',
        'صلاحيات متقدمة لعمليات الفروع',
        'لوحة مركزية لمقارنة أداء الفروع',
        'سجل وتقارير حركة المخزون',
        'تنبيهات مركزية للفروع والمخزون',
        'تقرير أسبوعي مجمع لجميع الفروع',
    ];

    /** القدراتُ الخمس — منسوخةٌ هنا كي لا يتغيّر ما يكتبه الترحيلُ بتغيّر الكود بعده */
    public const CAPABILITIES = [
        'enterprise_transfers',
        'enterprise_replenishment',
        'enterprise_branch_analytics',
        'enterprise_weekly_report',
        'enterprise_alerts',
    ];

    /** ما كان مفتوحًا قبلها لباقةٍ عمودُها فارغ */
    private const BASE = ['reports_advanced', 'custom_permissions', 'loyalty', 'whatsapp', 'website', 'marketing_tools', 'integrations'];

    public function up(): void
    {
        $id = $this->enterpriseId();
        if ($id === null) {
            return;
        }

        $row = DB::table('plans')->where('id', $id)->first(['capabilities', 'features']);
        $granted = $row->capabilities === null ? null : json_decode((string) $row->capabilities, true);

        $capabilities = is_array($granted)
            ? [...array_values($granted), ...array_values(array_diff(self::CAPABILITIES, $granted))]
            : [...self::BASE, ...self::CAPABILITIES];

        $update = [
            'code' => Plan::CODE_ENTERPRISE,
            'capabilities' => json_encode(array_values(array_unique($capabilities))),
            'updated_at' => now(),
        ];

        if (json_decode((string) $row->features, true) === self::BEFORE) {
            $update['features'] = json_encode(self::AFTER);
        }

        DB::table('plans')->where('id', $id)->update($update);
    }

    public function down(): void
    {
        $row = DB::table('plans')->where('code', Plan::CODE_ENTERPRISE)->first(['id', 'capabilities', 'features']);
        if (! $row) {
            return;
        }

        $granted = json_decode((string) $row->capabilities, true);
        $update = ['code' => null, 'updated_at' => now()];

        if (is_array($granted)) {
            $update['capabilities'] = json_encode(array_values(array_diff($granted, self::CAPABILITIES)));
        }

        if (json_decode((string) $row->features, true) === self::AFTER) {
            $update['features'] = json_encode(self::BEFORE);
        }

        DB::table('plans')->where('id', $row->id)->update($update);
    }

    /** الباقةُ المقصودة — برمزها إن كُتب، وإلّا باسمها وأسقفها، وواحدةٌ بالضبط */
    private function enterpriseId(): ?int
    {
        $coded = DB::table('plans')->where('code', Plan::CODE_ENTERPRISE)->pluck('id');
        if ($coded->count() === 1) {
            return (int) $coded->first();
        }

        $found = DB::table('plans')->whereNull('code')
            ->get(['id', 'name', 'max_branches', 'max_employees', 'max_products'])
            ->filter(fn ($plan) => trim((string) $plan->name) === self::NAME)
            ->filter(fn ($plan) => array_map(
                fn ($v) => $v === null ? null : (int) $v,
                [$plan->max_branches, $plan->max_employees, $plan->max_products],
            ) === self::LIMITS)
            ->pluck('id');

        return $found->count() === 1 ? (int) $found->first() : null;
    }
};
