<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            /*
             * ثمن العرض منفصلًا عن ثمن الولاء.
             *
             * كان `discount` يجمع خصم الكوبون ونقاط العميل في رقمٍ واحد، فلا
             * يُعرف كم كلّف كوبونٌ بعينه — وهو السؤال الوحيد الذي يقرّر
             * إعادةَ العرض أو إيقافه.
             */
            $table->decimal('coupon_discount', 12, 3)->default(0)->after('coupon_code');
        });

        Schema::table('expenses', function (Blueprint $table) {
            // المصروف المسجَّل من شاشة المالية له صفٌّ هناك أيضًا — يُربط بها
            // كي لا يُعدّ مرّتين ولا يُحذف أحدهما فيبقى الآخر يتيمًا
            $table->foreignId('transaction_id')->nullable()->after('employee_name')
                ->constrained('transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('expenses', 'transaction_id')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->dropConstrainedForeignId('transaction_id');
            });
        }
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('coupon_discount');
        });
    }
};
