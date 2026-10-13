<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حدُّ الباقة الفارغ يعني «بلا حدّ» — والعمودُ يقبله.
 *
 * ═══ ما كان ═══
 *
 * نافذةُ «إضافة/تعديل الباقة» تقول تحت السقوف «الفراغ يعني بلا حدّ»،
 * و`PlanController` يكتب الفراغ `null`، و`PlanLimits::cap` يقرأ `null` بلا
 * سقف. لكنّ الأعمدة الثلاثة وُلدت `NOT NULL` منذ أوّل جداول الصندوق — فحقلٌ
 * فارغٌ واحد يُسقط الحفظ بخطأ 500، إنشاءً كان أو تعديلًا، ولا يُحفظ شيء.
 *
 * ═══ وما تفعله هذه ═══
 *
 * تقبل الأعمدةُ الفراغ. والافتراضيّ يبقى كما كان (1 / 3 / 100) لمن ينشئ باقةً
 * لا يذكر سقوفها أصلًا. لا صفَّ يتغيّر: الباقاتُ القائمة تحتفظ بأرقامها.
 *
 * ═══ ولا رجوع ═══
 *
 * `down` لا يُعيد `NOT NULL`: إعادتُه تُسقط الهجرة على باقةٍ قيل إنها بلا حدّ،
 * أو تفرض عليها رقمًا لم يكتبه أحد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('max_branches')->nullable()->default(1)->change();
            $table->unsignedInteger('max_employees')->nullable()->default(3)->change();
            $table->unsignedInteger('max_products')->nullable()->default(100)->change();
        });
    }

    public function down(): void
    {
        // لا يُعاد `NOT NULL` على باقةٍ بلا حدّ — انظر أعلاه
    }
};
