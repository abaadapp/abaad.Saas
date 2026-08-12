<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الوردية التي تُقفَل بلا عدّ.
 *
 * كان الإقفال طريقًا واحدًا: يعدّ الكاشير الدرج فيُحسب الفرق. ومن نسي
 * الإقفال وذهب إلى بيته تبقى ورديتُه مفتوحة، فمبيعات الغد كلّها تُنسب إلى
 * درج الأمس ولا يُطابَق شيء — والرقابة تموت بصمت والشاشة تبدو سليمة.
 *
 * فصار للإقفال ثلاثة وجوه، ولا بدّ من تمييزها: من عدّ الدرج فرقُه معلوم،
 * ومن أُقفلت ورديتُه تلقائيًّا أو إداريًّا **فرقُه مجهول لا صفر**. والصفر
 * يعني «طابق الدرج» — وهي كذبةٌ تُبرّئ نقصًا لم يعدّ أحدٌ ليعرفه.
 *
 * ولذلك يقبل العمودان NULL بدل أن يُنشأ لهما توأمان: عمودان لمعنًى واحد
 * يعنيان مصدرين للحقيقة، وسؤالَ «أيّهما الصحيح؟» بعد سنة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            // counted | auto | admin — كيف انتهت الوردية
            $table->string('closed_kind', 20)->nullable()->after('status');

            // «لم يُعدّ» حالةٌ لا رقم — ولا يُنتحل لها صفر
            $table->decimal('actual_balance', 12, 3)->nullable()->default(null)->change();
            $table->decimal('difference', 12, 3)->nullable()->default(null)->change();
        });

        // ما أُقفل قبل اليوم أُقفل بعدٍّ — لا وجه آخر كان موجودًا
        DB::table('shifts')->where('status', 'مغلقة')->update(['closed_kind' => 'counted']);

        // والمفتوحة لم تُعدّ بعد: صفرُها الافتراضي القديم يعني «طابقت» وهي لم تُقفل
        DB::table('shifts')->where('status', 'مفتوحة')
            ->update(['actual_balance' => null, 'difference' => null]);
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('closed_kind');
            $table->decimal('actual_balance', 12, 3)->default(0)->change();
            $table->decimal('difference', 12, 3)->default(0)->change();
        });
    }
};
