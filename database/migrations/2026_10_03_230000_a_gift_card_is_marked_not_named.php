<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * كرتُ الهدية يُعرف بعلامةٍ صريحة — لا باسم عرضه.
 *
 * كان الكرتُ في متاجر `storefront.ribbon_gift_card_product_businesses` يُعرف
 * بالاسم العربيّ «كرت هدية» حرفًا بحرف (`GiftCardProduct::is`). فاسمٌ
 * بفارقِ حرفٍ أو بالإنجليزيّة في خانة العربيّ يُسقط الكرتَ صامتًا: يُعرض
 * صنفًا عاديًّا، ولا تُفتح خانةُ رسالته، لا في صفحته ولا في «أضف مع طلبك».
 *
 * فصار للصنف عمودٌ يقول «هذا هو الكرت»، وافتراضُه `false` — فلا يصير صنفٌ
 * قائمٌ كرتًا بهذه الهجرة. ومن يُعلَّم ومتى: `GiftCardProduct::forSave`،
 * والصنفُ القائم بالهجرة التالية (`..._230100_...`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_gift_card')->default(false)->after('tracks_stock');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_gift_card');
        });
    }
};
