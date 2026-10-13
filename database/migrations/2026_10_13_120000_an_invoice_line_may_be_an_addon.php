<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بندُ فاتورة العميل قد يكون إضافةً — ويتذكّر أيَّها كان.
 *
 * فاتورةُ العميل اليدويّة تقبل الآن أيَّ إضافةٍ نشطةٍ للمتجر بندًا مستقلًّا
 * («شوكولاتة ٣٫٠٠٠» بلا بوكيه). والبندُ كان يعرف منتجَه وحده
 * (`product_id`)، فإضافةٌ تُكتب فيه إمّا بلا هويّة — سطرٌ يدويٌّ لا يُعرف
 * أنّه إضافة — وإمّا بمنتجٍ وهميّ يُخترع لها.
 *
 * فعمودٌ لها: منتجٌ أو إضافةٌ أو لا شيء (بندٌ يدويّ)، ولا يجتمع الاثنان.
 *
 * ═══ والهويّةُ إشارةٌ لا مصدر ═══
 *
 * الاسمُ والسعرُ والضريبةُ لقطةٌ في `description` و`unit_price` و`tax_rate`
 * كسائر البنود — تُقرأ منها الورقةُ أبدًا. فإضافةٌ يُغيَّر اسمُها أو سعرُها
 * أو تُعطَّل لا تمسّ ورقةً صدرت، وإضافةٌ تُحذف **يُفرَّغ** معرّفُها ولا يُحذف
 * البند (`nullOnDelete`) — كما في `order_item_addons.addon_id`
 * و`order_items.standalone_addon_id`.
 *
 * ═══ ولا يُملأ الماضي ═══
 *
 * العمودُ فارغٌ لكلّ بندٍ قائم ويبقى فارغًا: مطابقةُ بندٍ يدويٍّ بإضافةٍ
 * تشبهه اسمًا ادّعاءُ ربطٍ لا دليلَ عليه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_invoice_items', function (Blueprint $table) {
            $table->foreignId('addon_id')->nullable()->after('product_id')
                ->constrained('addons')->nullOnDelete();

            // وكلُّ عمودِ ربطٍ له فهرس — انظر `EveryForeignKeyColumnIsIndexedTest`
            $table->index('addon_id');
        });
    }

    public function down(): void
    {
        Schema::table('customer_invoice_items', function (Blueprint $table) {
            $table->dropIndex(['addon_id']);
            $table->dropConstrainedForeignId('addon_id');
        });
    }
};
