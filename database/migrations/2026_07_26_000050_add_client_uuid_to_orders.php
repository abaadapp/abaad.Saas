<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * معرّف فريد يولّده جهاز الكاشير لكل عملية بيع (صمود الانقطاع).
 * يمنع تكرار الطلب عند إعادة رفعه بعد عودة الاتصال: إن وصل نفس المعرّف مرتين
 * يتجاهله الخادم ويعيد الفاتورة الأصلية بدل إنشاء طلب مكرّر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('client_uuid', 64)->nullable()->after('number');
            $table->index(['business_id', 'client_uuid']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'client_uuid']);
            $table->dropColumn('client_uuid');
        });
    }
};
