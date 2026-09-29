<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * والإضافةُ المبيعةُ بندًا مستقلًّا تتذكّر أيَّ إضافةٍ كانت.
 *
 * الإضافةُ على بند منتجٍ تحمل معرّفَها منذ البدء (`order_item_addons.addon_id`).
 * والإضافةُ التي تُضغط من شريط الصندوق فتدخل السلّة سطرًا وحدَها كانت تُكتب
 * `order_items` بلا منتجٍ وبلا معرّف: اسمُها وسعرُها وتكلفتُها لقطةً، وأيُّ
 * إضافةٍ هي — لا شيء. `SaleLines` يعرفها لحظةَ البيع (`standalone_addon`) ثمّ
 * يُسقطها حين يُكتب البند.
 *
 * فتقريرُ الإضافات لا يستطيع أن يجمع «شوكولاتة» على الشريط و«شوكولاتة» على
 * البوكيه في صفٍّ واحد إلّا بالاسم — والاسمُ يتبدّل، وإضافتان قد تتشابهان.
 *
 * ═══ ولا يُملأ الماضي ═══
 *
 * العمودُ فارغٌ لكلّ صفٍّ قديم ويبقى فارغًا. ملؤه بمطابقة الاسم ادّعاءُ ربطٍ
 * لا دليلَ عليه: قبل 2026-07-30 كان معرّفُ المنتج يأتي من الشاشة نفسها،
 * فسطرٌ بلا منتجٍ من تلك الأيام قد لا يكون إضافةً أصلًا. فيُعرض القديمُ في
 * التقرير «غير مربوط» باسمه يومَ البيع — صادقًا — والجديدُ يحمل معرّفه.
 *
 * ولا يمسّ شيئًا ممّا يُحسب: لا سعرَ ولا مجموعَ ولا ضريبةَ ولا رفًّا. هويّةٌ
 * تُكتب بجانب البند، والفراغُ يُقرأ كما كان يُقرأ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // يُفرَّغ ولا يُسقط الصفّ: فاتورةٌ لا تفقد بندًا لأنّ الإضافة حُذفت
            // بعدها — كما في `order_item_addons.addon_id`
            $table->foreignId('standalone_addon_id')->nullable()->after('product_id')
                ->constrained('addons')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('standalone_addon_id');
        });
    }
};
