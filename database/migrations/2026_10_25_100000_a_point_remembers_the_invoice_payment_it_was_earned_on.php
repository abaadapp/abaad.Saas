<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * حركةُ النقاط تعرف الفاتورةَ والتحصيلَ اللذين اكتُسبت عليهما — ومصدرَها مرّةً واحدة.
 *
 * كانت الحركةُ تعرف الطلبَ وحده (`order_id`): النقاطُ لا تُكتسب إلّا في الصندوق.
 * وسدادُ فاتورة عميلٍ برابط Paymob يكتسب الآن (`InvoiceLoyalty`)، فتحمل الحركةُ
 * فاتورتَها وتحصيلَها لتُعرض في الفاتورة وفي سجلّ العميل.
 *
 * و`source_key` فريدٌ: إشعاران متزامنان لا يكتبان حركتين — القاعدةُ تردّ الثانيةَ
 * ولو أخطأ الكودُ قبلها. وأعمدةٌ تقبل الفراغ: الحركاتُ القائمة تبقى كما هي، ولا
 * يُمسّ رصيدٌ ولا تُكتب نقطة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('point_transactions', function (Blueprint $table) {
            $table->foreignId('customer_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_key', 64)->nullable()->unique();

            $table->index('customer_invoice_id');
            $table->index('customer_payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('point_transactions', function (Blueprint $table) {
            $table->dropIndex(['customer_invoice_id']);
            $table->dropIndex(['customer_payment_id']);
            $table->dropUnique(['source_key']);
            $table->dropConstrainedForeignId('customer_payment_id');
            $table->dropConstrainedForeignId('customer_invoice_id');
            $table->dropColumn('source_key');
        });
    }
};
