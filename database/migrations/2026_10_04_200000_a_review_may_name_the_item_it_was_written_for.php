<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * رأيٌ في صنفٍ بعينه — بجانب الرأي في الطلب، لا مكانَه.
 *
 * ═══ ما كان ═══
 *
 * رأيٌ واحدٌ لكلّ طلب (فهرسٌ فريدٌ على `order_id`). والطلبُ قد يحمل ثلاثة
 * أصناف، فلا يُعرف عن أيّها كُتب — فلا يصلح لصفحة صنف.
 *
 * ═══ ما يُضاف ═══
 *
 *  • `type`: «order» لكلّ ما كُتب قبل اليوم — لا يُنسب لصنفٍ لا يُعرف — و«product»
 *    لرأيٍ في بندٍ بعينه.
 *  • `order_item_id`: البندُ الذي اشتُري فعلًا. ومنه يُقرأ `product_id`
 *    (العمودُ قائمٌ منذ أُنشئ الجدول) في الخادم لا من المتصفّح.
 *
 * ═══ والتفرّدُ في القاعدة ═══
 *
 *  • رأيٌ واحدٌ في الطلب — كما كان، لكن لنوع «order» وحده (فهرسٌ جزئيّ،
 *    يفهمه PostgreSQL وSQLite معًا).
 *  • ورأيٌ واحدٌ في البند (`order_item_id` فريد). والصنفُ نفسُه في طلبٍ آخر
 *    بندٌ آخر — فيُكتب فيه رأيٌ آخر.
 *
 * ولا يُمسّ صفٌّ قائم: الافتراضيُّ «order» يقع على القديم كما هو.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->string('type', 10)->default('order')->after('business_id');
            $table->foreignId('order_item_id')->nullable()->after('order_id')
                ->constrained('order_items')->nullOnDelete();

            $table->unique('order_item_id');
            // صفحةُ الصنف: متجرُه وصنفُه ونوعُ الرأي وحالُه
            $table->index(['business_id', 'product_id', 'type', 'status'], 'reviews_product_page_index');
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->dropUnique(['order_id']);
        });

        DB::statement("CREATE UNIQUE INDEX reviews_one_order_review_unique ON reviews (order_id) WHERE type = 'order'");
    }

    /**
     * والرجوعُ يُعيد «رأيًا واحدًا لكلّ طلب» — ولا يصحّ ما دامت في الجدول
     * آراءُ أصنافٍ لطلبٍ واحد: تُنقل أو تُحذف بقرارٍ قبله، لا هنا بصمت.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX reviews_one_order_review_unique');

        Schema::table('reviews', function (Blueprint $table) {
            $table->dropIndex('reviews_product_page_index');
            $table->dropUnique(['order_item_id']);
            $table->dropConstrainedForeignId('order_item_id');
            $table->dropColumn('type');
            $table->unique('order_id');
        });
    }
};
