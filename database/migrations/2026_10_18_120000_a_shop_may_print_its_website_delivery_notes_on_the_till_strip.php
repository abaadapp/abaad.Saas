<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سندُ التسليم الحراريّ لطلبات الموقع — مفتاحٌ على النشاط.
 *
 * - `businesses.thermal_delivery_note_enabled`: مغلقٌ لكلّ نشاطٍ افتراضًا،
 *   ويفتحه مديرُ المنصّة من شاشة النشاط كالإهداء (`DeliveryPaper::thermalOn`).
 *   ومن فُتح له يطبع سندَ طلبات **موقعه** على شريط طابعة الصندوق بدل ورقة
 *   A4، وسائرُ طلباته — والصندوقُ منها — على ورقتها كما كانت.
 *
 * ولا يُفتح هنا لأحد: لا اسمَ ولا رقمَ في الكود، ولا تُكتب بياناتُ متجرٍ
 * بالهجرة. والإضافةُ وحدها — لا يُحذف شيءٌ ولا يُعاد تسميتُه.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('businesses', 'thermal_delivery_note_enabled')) {
            Schema::table('businesses', function (Blueprint $table) {
                $table->boolean('thermal_delivery_note_enabled')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('businesses', 'thermal_delivery_note_enabled')) {
            Schema::table('businesses', fn (Blueprint $t) => $t->dropColumn('thermal_delivery_note_enabled'));
        }
    }
};
