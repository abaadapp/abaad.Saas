<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سببُ الإشعار الدائن — تصحيحُ خطأ، أو إرجاعٌ وإلغاءُ توريد، أو خصمٌ بعد البيع.
 *
 * يُكتب للإشعار اليدويّ الجديد وحده. والإشعاراتُ السابقة تبقى بلا سبب
 * (`null`) — لا يُخمَّن لها سببٌ بأثرٍ رجعيّ، ولا لإشعارات إلغاء الطلبات.
 *
 * والعمودُ نفسُه يكتبه تصحيحُ الفاتورة (#195) إن سبق — فلا يُضاف مرّتين.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customer_credit_notes', 'basis')) {
            Schema::table('customer_credit_notes', function (Blueprint $table) {
                $table->string('basis', 20)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customer_credit_notes', 'basis')) {
            Schema::table('customer_credit_notes', function (Blueprint $table) {
                $table->dropColumn('basis');
            });
        }
    }
};
