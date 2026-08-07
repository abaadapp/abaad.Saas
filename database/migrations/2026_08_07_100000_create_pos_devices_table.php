<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جهاز نقطة البيع: سجلٌّ يعرف متجرَه وفرعَه.
 *
 * كان الجهاز يُعرَف بكوكي تحمل رقم المتجر وحده، فجهاز الخوير وجهاز السيب
 * متطابقان في نظر النظام — والفرع يأتي من جلسة المتصفّح، أي من مبدّل الفروع
 * في لوحة الإدارة. فتبديلٌ في تبويبٍ آخر كان يحوّل مبيعات فرعٍ إلى فرعٍ آخر
 * بلا إنذار، ولا يُكتشف إلا عند جرد آخر الشهر.
 *
 * والرمز يُخزَّن مجزَّأً بـSHA-256 لا bcrypt: ٢٥٦ بت عشوائية لا تُخمَّن، وهي
 * تحتاج بحثًا مفهرسًا في كل طلب — وهو ما تفعله Sanctum نفسها. أما رمز الموظف
 * (أربعة أرقام) فيبقى على bcrypt: فضاؤه ١٠٠٠٠ احتمال، فيلزمه هاشٌ بطيء.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // الفرع إلزامي: جهازٌ بلا فرع هو العطب الذي جاء هذا الجدول لإغلاقه
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // فريد على المنصة كلها: التصادم يعني جهازًا يقرأ بيانات جهاز آخر
            $table->string('token_hash', 64)->unique();
            $table->string('status')->default('نشط');
            $table->timestamp('last_seen_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            // الاستعلام المتكرّر: أجهزة متجرٍ مرتّبةً بفرعها
            $table->index(['business_id', 'branch_id']);
            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_devices');
    }
};
