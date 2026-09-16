<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الأرشيفُ يقول أيَّ مدًى من الزمن يغطّي — لا أيَّ شهرٍ وحده.
 *
 * ═══ ولمَ لا يُضاف عمودُ نوعٍ وحده ═══
 *
 * كان الصفُّ يحمل `year` و`month`، وفهرسٌ فريدٌ عليهما مع المتجر. وأرشيفٌ
 * أسبوعيٌّ لا موضعَ له فيهما: أسبوعُ ٧–١٣ سبتمبر يقع في شهرٍ واحد، وأسبوعُ
 * ٣١ أغسطس–٦ سبتمبر يقع في شهرين. وحشوُ رقم الأسبوع في عمود `month` يجعل
 * الصفَّ `2026/9` يعني «سبتمبر» مرّةً و«الأسبوع التاسع» أخرى — عمودٌ بمعنيين
 * لا يُقرأ إلّا بمعرفة عمودٍ آخر، وهو أوّلُ ما يُنسى.
 *
 * فالمدى يُكتب مدًى: بدايةٌ ونهاية. ومنهما تُشتقّ السنةُ والشهرُ متى لزما —
 * فلا يبقى حقلان يقولان الشيء نفسه ليفترقا يومًا.
 *
 * ═══ والنوعُ في الفهرس الفريد لا خارجه ═══
 *
 * الحارسُ ضدّ التكرار فهرسٌ في القاعدة لا فحصٌ في PHP: المجدولُ وزرُّ
 * التاجر يقعان معًا، وبين السؤال والكتابة يمرّ طلبٌ آخر. وبدايةُ المدى
 * وحدَها لا تكفي مفتاحًا: أرشيفُ شهرِ سبتمبر وأرشيفُ أسبوعِ ١–٧ سبتمبر
 * يبدآن في اليوم نفسه ويختلفان تمامًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_archives', function (Blueprint $table) {
            // weekly | monthly — انظر Archive\Period
            $table->string('archive_type', 10)->default('monthly')->after('business_id');
            $table->date('period_start')->nullable()->after('archive_type');
            $table->date('period_end')->nullable()->after('period_start');
        });

        /*
         * والصفوفُ القائمة تُملأ من عموديها القديمين لا تُحذف.
         *
         * ثلاثةُ أرشيفاتٍ على الإنتاج يوم كُتبت هذه الهجرة، ولكلٍّ ملفٌّ على
         * القرص. وصفٌّ بلا مدًى يجعل زرَّ تنزيله يردّ خطأً على ملفٍّ سليم.
         */
        foreach (DB::table('business_archives')->select('id', 'year', 'month')->get() as $row) {
            $start = Carbon::create((int) $row->year, (int) $row->month, 1, 0, 0, 0);

            DB::table('business_archives')->where('id', $row->id)->update([
                'archive_type' => 'monthly',
                'period_start' => $start->toDateString(),
                'period_end' => $start->copy()->endOfMonth()->toDateString(),
            ]);
        }

        Schema::table('business_archives', function (Blueprint $table) {
            $table->date('period_start')->nullable(false)->change();
            $table->date('period_end')->nullable(false)->change();
        });

        /*
         * والفهرسُ القديم يسقط قبل عموديه.
         *
         * عمودٌ داخلٌ في فهرسٍ لا يُحذف وهو فيه — تردّ القاعدةُ خطأً غامضًا
         * في منتصف الهجرة، فيبقى الجدولُ نصفَ مُحوَّل.
         */
        Schema::table('business_archives', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'year', 'month']);
            $table->dropIndex(['business_id', 'year', 'month']);
        });

        Schema::table('business_archives', function (Blueprint $table) {
            $table->dropColumn(['year', 'month']);
        });

        Schema::table('business_archives', function (Blueprint $table) {
            $table->unique(['business_id', 'archive_type', 'period_start']);
            // «أرِني أرشيفات متجري من الأحدث» — وهو استعلامُ الشاشة
            $table->index(['business_id', 'archive_type', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::table('business_archives', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->nullable();
            $table->unsignedTinyInteger('month')->nullable();
        });

        foreach (DB::table('business_archives')->select('id', 'period_start')->get() as $row) {
            $at = Carbon::parse($row->period_start);

            DB::table('business_archives')->where('id', $row->id)
                ->update(['year' => $at->year, 'month' => $at->month]);
        }

        Schema::table('business_archives', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'archive_type', 'period_start']);
            $table->dropIndex(['business_id', 'archive_type', 'period_start']);
            $table->dropColumn(['archive_type', 'period_start', 'period_end']);
        });

        /*
         * والتراجعُ يُعيد الحارسَ لا الأعمدةَ وحدَها.
         *
         * أوّلُ بروفةٍ على بوستجرس سقطت هنا: تراجعٌ ثمّ هجرةٌ ثانية ردّت
         * «القيدُ غير موجود» — لأنّ `down` كانت تُعيد العمودين بلا فهرسهما
         * الفريد. وأخطرُ من سقوط الهجرة أنّها كانت **تنجح** لو لم يُعَد
         * تشغيلُها: جدولٌ عاد إلى شكله القديم بلا الحارس الذي يمنع أرشيفين
         * لشهرٍ واحد — والمجدولُ وزرُّ التاجر يقعان معًا.
         */
        Schema::table('business_archives', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->nullable(false)->change();
            $table->unsignedTinyInteger('month')->nullable(false)->change();
            $table->unique(['business_id', 'year', 'month']);
            $table->index(['business_id', 'year', 'month']);
        });
    }
};
