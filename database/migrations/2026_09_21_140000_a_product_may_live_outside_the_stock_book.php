<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * صنفٌ قد يعيش خارج دفتر المخزون.
 *
 * خدمةٌ أو رسمُ توصيلٍ أو صنفٌ يُصنع عند الطلب: لا رفَّ له يُعدّ. كان
 * النظام يعامله كبضاعة — يخصم من كميّةٍ لا معنى لها، ويمنع بيعه حين
 * «ينفد»، ويرنّ الجرسُ عليه كلَّ صباح. فصار لكلّ صنفٍ مفتاح: مرتبطٌ
 * بالمخزون (الافتراض — وهو ما كان) أو لا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('tracks_stock')->default(true)->after('alert_qty');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('tracks_stock');
        });
    }
};
