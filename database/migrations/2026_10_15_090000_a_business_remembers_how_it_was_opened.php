<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الشركةُ تعرف كيف فُتحت — بختمٍ يكتبه الخادمُ ساعةَ الإنشاء.
 *
 * ═══ ما كان ═══
 *
 * بابان يُنشئان شركة: التسجيلُ الذاتيّ من الموقع (`Support\Signup`) وزرُّ
 * «إضافة شركة» في لوحة المنصّة (`SuperAdmin\BusinessController::store`).
 * ولا شيء في الصفّ يقول أيَّهما فتحها. فلا يُعرف من سجّل بنفسه إلّا تخمينًا
 * — ببريدٍ أو بسطرٍ في السجلّ أو بتاريخ — وكلُّها تخطئ يومًا.
 *
 * ═══ وما تفعله هذه ═══
 *
 * عمودٌ يقبل الفراغ: `self_signup` أو `platform_manual` (`Business::SOURCES`).
 * والفهرسُ لأنّ قسمَ «التسجيلات من الموقع» وجرسَ المنصّة يرشّحان به.
 *
 * ═══ ولا تعبئةَ لما قبل اليوم ═══
 *
 * الشركاتُ القائمة تبقى فارغةً: لا شيء يقول بيقينٍ كيف فُتحت، والتخمينُ
 * يُدخل في القسم من لم يسجّل بنفسه. فالقسمُ يبدأ بما يُسجَّل بعد النشر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('registration_source', 32)->nullable();
            $table->index('registration_source');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropIndex(['registration_source']);
            $table->dropColumn('registration_source');
        });
    }
};
