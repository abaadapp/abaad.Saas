<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * للباقة رمزٌ ثابت لا يغيّره اسمُها — والتسجيلُ الذاتيّ يقرأ الرمز.
 *
 * ═══ ما كان ═══
 *
 * لا شيء في `plans` يقول «هذه الأساسيّة» إلّا اسمُها، والاسمُ يُعدَّل من
 * شاشة الباقات. فما يربط التسجيلَ الذاتيّ بالأساسيّة عن طريق الاسم ينقطع
 * يومَ تُسمّى «الباقة الأساسية ٢٠٢٧» — أو يقع على باقةٍ أخرى سُمّيت به.
 *
 * ═══ وما تفعله هذه ═══
 *
 * عمودٌ فريدٌ يقبل الفراغ. لا يكتبه `PlanController` (حقولُه بأسمائها)،
 * فتعديلُ الاسم والسعر والقدرات لا يمسّه. والتسجيلُ الذاتيّ يطلب
 * `code = basic` وحده — انظر `Signup::plan`.
 *
 * ═══ والتثبيتُ الأوّل من الاسم الحاليّ — بشرط ═══
 *
 * يُكتب `basic` على الباقة المسمّاة «الباقة الأساسية» (اسمُها في
 * `SeedData::plans`) **إن كانت واحدةً بالضبط**. فإن لم توجد، أو وُجدت
 * اثنتان، بقي العمودُ فارغًا ورُفض التسجيلُ الذاتيّ رفضًا صريحًا — لا
 * يُختار سعرٌ أدنى ولا أوّلُ رقم: باقةٌ خاطئةٌ تفتح للمسجِّل ما لم يُبَع له.
 */
return new class extends Migration
{
    private const BASIC_NAME = 'الباقة الأساسية';

    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('code', 32)->nullable()->unique();
        });

        $basic = DB::table('plans')->get(['id', 'name'])
            ->filter(fn ($plan) => trim((string) $plan->name) === self::BASIC_NAME);

        if ($basic->count() === 1) {
            DB::table('plans')->where('id', $basic->first()->id)->update(['code' => 'basic']);
        }
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
