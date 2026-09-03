<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * وبندُ الإضافة يتذكّر ما أكله — لا يسأل عنه اليوم.
 *
 * الاسم والسعر كانا لقطةً منذ البدء، والمخزون لم يكن. فإلغاءُ فاتورةٍ كان
 * يقرأ إعدادَ الإضافة اليوم لا إعدادَها يوم البيع: إضافةٌ كانت ثلاث وردات
 * فصارت خمسًا تردّ خمسًا عن بيعةٍ أخذت ثلاثًا — فيربح الرفّ وردتين لا
 * وجود لهما. وإضافةٌ حُذفت تردّ لا شيء، لأنّ العلاقة صارت فراغًا.
 *
 * فالعمودان لقطةٌ لا علاقة:
 *
 *   inventory_product_id — أيّ صنفٍ أُخذ منه لحظتها
 *   inventory_quantity   — وكم أُخذ لكلّ إضافة
 *
 * وكلاهما يقبل الفراغ، والفراغ يعني «خدمةٌ لا بضاعة» — أو صفًّا كُتب قبل
 * هذه الهجرة. والقديم يُقرأ كما كان يُقرأ: من الإضافة الحيّة بواحدةٍ لكلّ
 * إضافة، وهو ما كان يفعله النظام تمامًا. فلا صفَّ تاريخيّ يُعاد كتابته
 * هنا — الماضي يُقرأ بقاعدته، والجديد يحمل لقطته.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_item_addons', function (Blueprint $table) {
            // يُفرَّغ ولا يُسقط الصفّ: فاتورةٌ قديمة لا تختفي بندًا لأنّ
            // الصنف مُحي — والمنتجات تُحذف حذفًا ناعمًا أصلًا
            $table->foreignId('inventory_product_id')->nullable()->after('cost')
                ->constrained('products')->nullOnDelete();
            $table->decimal('inventory_quantity', 12, 3)->nullable()->after('inventory_product_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_item_addons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inventory_product_id');
            $table->dropColumn('inventory_quantity');
        });
    }
};
