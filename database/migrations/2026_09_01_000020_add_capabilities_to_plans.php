<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الباقة تقول ما تفتحه — لا نصًّا تسويقيًّا وحده.
 *
 * كان في `plans` عمودان من نوعين: أسقفٌ عدديّة تُفرَض فعلًا (`max_branches`
 * وأخواتها)، و`features` قائمةُ نصوصٍ حرّة تُعرض في صفحة التسعير ولا يقرؤها
 * حارسٌ واحد. فمن اشترى «الأساسية» يفتح «التقارير المتقدمة» و«الصلاحيات
 * المخصّصة» كما يفتحها من اشترى «المؤسسات» — والفرق بين الباقتين وعدٌ في
 * صفحةٍ لا في كود.
 *
 * وهذا ليس عطبًا في شاشة: هو تسريبُ إيراد. من اكتشفه لا يرقّي أبدًا ولا
 * يخبر أحدًا.
 *
 * فصار للباقة قائمةُ قدراتٍ مغلقة يقرؤها الحارس. و`null` تعني «كلّ شيء
 * مفتوح»: باقةٌ أُنشئت قبل هذا العمود لا تُقفل على أصحابها لأنّ حقلًا فيها
 * فارغ — الحدُّ يُفرَض حين يُكتب، لا حين يُنسى.
 */
return new class extends Migration
{
    /** ما تفتحه كلّ باقةٍ من الباقات الثلاث المزروعة — والباقي يبقى null */
    private const SEEDED = [
        // «تقارير أساسية» و«دعم بالبريد» — ولا صلاحيات مخصّصة فيها
        'الباقة الأساسية' => ['loyalty', 'whatsapp'],
        'الباقة الاحترافية' => ['loyalty', 'whatsapp', 'reports_advanced', 'custom_permissions'],
        'باقة المؤسسات' => ['loyalty', 'whatsapp', 'reports_advanced', 'custom_permissions'],
    ];

    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->json('capabilities')->nullable()->after('features');
        });

        /*
         * والباقات الثلاث المعروفة تُملأ باسمها.
         *
         * ما عداها يبقى `null` — أي مفتوحًا: باقةٌ سمّاها صاحبُ المنصّة بيده
         * لا أعرف ما وعد بها، وتخمينُها يسحب من تاجرٍ ميزةً اشتراها.
         */
        foreach (self::SEEDED as $name => $capabilities) {
            DB::table('plans')->where('name', $name)->whereNull('capabilities')
                ->update(['capabilities' => json_encode($capabilities)]);
        }
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('capabilities');
        });
    }
};
