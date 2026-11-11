<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * فاتورةُ العميل تحفظ ما استُبدل عليها من نقاط — عددَها وقيمتَها.
 *
 * ═══ ولمَ لقطةٌ في الصفّ ═══
 *
 * الخصمُ يُحسب مرّةً عند كتابة الفاتورة، وتُخصم النقاطُ معه في المعاملة
 * نفسِها. وما بعد ذلك — الإصدارُ، والرابط، والسداد، والوصولُ إلى لوحة
 * التجهيز — يقرأ هذين العمودين ولا يعيد الحساب: إعدادٌ تغيّر غدًا لا يغيّر
 * فاتورةً كُتبت اليوم، ولا تُخصم النقاطُ مرّتين.
 *
 * ولا بيانات تُمسّ: فاتورةٌ قائمة تبدأ بصفرين، وهو ما وقع لها فعلًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->unsignedInteger('loyalty_points')->default(0);
            $table->decimal('loyalty_discount', 12, 3)->default(0);
        });
    }

    public function down(): void
    {
        /*
         * ولا يُسقط عمودٌ فيه نقاطٌ صُرفت: محوُه يجعل إلغاءَ الفاتورة لا يعرف
         * كم يُعيد إلى العميل. يُرجع الكودُ وحده (Revert PR).
         */
        if (DB::table('customer_invoices')->where('loyalty_points', '>', 0)->exists()) {
            throw new RuntimeException('نقاط الولاء على فواتير العملاء: في القاعدة فواتيرُ صُرفت عليها نقاط — لا تُسقط العمودين. أرجِع الكود وحده.');
        }

        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->dropColumn(['loyalty_points', 'loyalty_discount']);
        });
    }
};
