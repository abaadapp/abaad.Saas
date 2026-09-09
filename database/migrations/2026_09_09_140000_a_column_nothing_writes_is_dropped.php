<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ثلاثةُ أعمدةٍ في `users` تقاعدت — وتُحذف الآن بطلب صاحب النظام.
 *
 * ═══ ولماذا بقيت حتى اليوم ═══
 *
 * رفعُ المقبض شيءٌ وحذفُ العمود شيءٌ آخر: الأوّل يمنع الكذب، والثاني يمحو
 * البيانات. فبقيت الأعمدة بما فيها بعد أن رُفعت مقابضُها، ولم تُحذف إلّا
 * بكلمةٍ صريحة.
 *
 * ١) `sales_total` — لا بيعةٌ تزيده ولا ورديةٌ تُغلقه. كان يُقرأ في أربع
 *    شاشاتٍ فتعرض صفرًا لكلّ موظّفٍ منذ فتح المتجر. ومبيعاتُ الموظّف تُحسب
 *    اليوم من الطلبات — انظر `Demo::employees`.
 *
 * ٢) `commission_rate` — نسبةٌ يُدخلها التاجر ولا يُصرف منها شيء: لا مسيرةَ
 *    رواتبَ تقرؤها ولا كشفَ عمولةٍ في النظام. رُفع حقلُها من شاشة الموظّف.
 *
 * ٣) `pin` — رُفع الدخولُ بالرمز من النظام كلِّه.
 *
 * ═══ وما لا يعود ═══
 *
 * `down()` تُعيد الأعمدة **فارغة** لا بما كان فيها: العمودُ يُستعاد والبيانات
 * لا. وهو صادقٌ في وصفه — لا يُوهم بتراجعٍ كامل. وقد أُخذت نسخةٌ احتياطية
 * قبل التشغيل على الإنتاج.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            /*
             * وكلُّ عمودٍ يُفحص وجودُه: هذه المهاجرة تجري على قواعدَ بُنيت
             * في أوقاتٍ مختلفة — وقاعدةٌ لا `pin` فيها أصلًا كانت ستُسقط
             * المهاجرة كلَّها ومعها ما بعدها.
             */
            foreach (['sales_total', 'commission_rate', 'pin'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'sales_total')) {
                $table->decimal('sales_total', 12, 3)->default(0);
            }
            if (! Schema::hasColumn('users', 'commission_rate')) {
                $table->decimal('commission_rate', 5, 2)->default(0);
            }
            if (! Schema::hasColumn('users', 'pin')) {
                $table->string('pin')->nullable();
            }
        });
    }
};
