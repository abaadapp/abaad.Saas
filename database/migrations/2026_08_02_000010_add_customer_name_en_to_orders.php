<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * لقطة الاسم الإنجليزي وقت البيع — بجوار customer_name تمامًا.
     *
     * الفاتورة وثيقة: يجب أن تعرض الاسم كما كان لحظة الشراء، فلا تُقرأ من
     * جدول العملاء لاحقًا (قد يُعدَّل الاسم أو يُحذف العميل). هذا هو سبب
     * وجود customer_name أصلًا، والعمود الجديد يتبع النمط نفسه.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('customer_name_en')->nullable()->after('customer_name');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('customer_name_en');
        });
    }
};
