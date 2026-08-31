<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الإضافة قد تأكل أكثر من واحدة — وقد لا تُعرض مع الجميع.
 *
 * كان الربط بالمخزون سؤالًا واحدًا: أيّ صنفٍ ينقص؟ والجواب كان يكفي «دبًّا»
 * — قطعةٌ مقابل قطعة. ولا يكفي «زيادة ثلاث ورداتٍ حمراء»: صنفُها الورد،
 * ونقصانُها ثلاثةٌ لا واحد. فكان صاحب المحلّ يبيعها ويرى وردةً واحدة تنقص،
 * ويجد الرفّ ناقصًا ثلاثًا آخر اليوم بلا تفسير.
 *
 *   inventory_quantity — كم يُستهلك من ذلك الصنف لكلّ إضافةٍ تُباع.
 *                        فارغةً تعني واحدة: هو ما كان يفعله النظام حرفًا
 *                        بحرف، فكلّ إضافةٍ قائمة تبقى على حالها بلا لمسة.
 *
 *   scope              — مدى الإضافة. فارغًا يعني «مع الجميع» — وهو مدى كلّ
 *                        إضافةٍ اليوم — و«محدّد» يعني صفوف product_addons
 *                        وحدها. والمملوكة لمنتج (product_id) لا تحتاج
 *                        الحقل: ملكيّتُها مداها.
 *
 * ولا صفّ يُمسّ: العمودان يقبلان الفراغ، والفراغ هو سلوك اليوم بعينه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addons', function (Blueprint $table) {
            // عشريّة كما في recipe_items.quantity: نصف متر شريط، وربع لفّة
            $table->decimal('inventory_quantity', 12, 3)->nullable()->after('inventory_product_id');
            $table->string('scope', 20)->nullable()->after('inventory_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('addons', function (Blueprint $table) {
            $table->dropColumn(['inventory_quantity', 'scope']);
        });
    }
};
