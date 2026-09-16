<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الطلبُ المخصَّص: باقةٌ تُركَّب على الطاولة، ومكوّناتُها تُعرف.
 *
 * ═══ العطب الذي يسدّه ═══
 *
 * الزبون يقول «ورد بعشرين ريالًا في كيسٍ أسود». والصندوقُ اليوم لا يبيع إلا
 * صنفًا مُعرَّفًا مسبقًا — فيضطرّ الكاشير إلى بيع أقربِ باقةٍ شبيهةٍ بسعرٍ
 * مُعدَّل. والنتيجةُ أنّ الرفَّ يُخصم منه **ما في وصفة تلك الباقة** لا ما
 * أُخذ منه فعلًا: وردٌ أحمرُ يَنقص في الدفتر وهو في الدلو، وأبيضُ يَنفد وهو
 * موجودٌ في الدفتر. ولا يظهر ذلك إلا في جردٍ بعد شهر.
 *
 * ═══ ولمَ جدولٌ واحدٌ وعمودٌ واحد ═══
 *
 * البندُ نفسُه لا يحتاج جدولًا: `order_items.product_id` **nullable** ويُكتب
 * فارغًا اليوم للإضافة المستقلّة — فالطلبُ المخصَّص بندٌ مثلُه.
 *
 * والناقصُ شيئان:
 *
 *  ١ — **لقطةُ المكوّنات.** الوصفةُ العاديّة معرَّفةٌ في `recipe_items` قبل
 *      البيع، وهذه تُختار لكلّ طلبٍ على حدة. فهي «وصفةٌ ديناميكيّة» تُحفظ
 *      مع الطلب لا مع المنتج. ولقطةٌ لا علاقة: اسمُ الصنف ورمزُه وتكلفتُه
 *      تُنسخ كما نُسخت في `order_item_addons` — فطلبُ الشهر الماضي يبقى
 *      مفهومًا ولو تغيّر اسمُ الورد أو سعرُه أو حُذف.
 *
 *  ٢ — **ما لا يُخصم من الرفّ.** الوضعُ المختار، وقيمةُ الورد، وتفضيلاتُ
 *      ألوانٍ لا صنفَ لها، وملاحظاتُ المنسّق. وهذه وصفٌ لا كيان، فعمودُ JSON
 *      على البند أصدقُ من ستّة أعمدةٍ تُملأ ثلاثةٌ منها.
 *
 * ورسالةُ الكرت لا تدخل هنا: `orders.card_message` قائمٌ ويُملأ من الصندوق.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            /*
             * الصنفُ يبقى مرجعًا للتجميع، واللقطةُ هي ما يُعرض.
             *
             * `nullOnDelete` لا `cascade`: حذفُ صنفٍ من الكتالوج لا يجوز أن
             * يمحو سطرًا من تاريخ طلبٍ سُلّم — الاسمُ والرمزُ والتكلفة هنا.
             */
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('sku')->nullable();
            /*
             * ما نوعُه في الورقة: ورد، أم تغليف، أم غيرهما.
             *
             * لا يُغيّر خصمًا ولا تكلفة — يقرؤه المنسّقُ وحده ليُجمَّع له
             * «الورد» في مكانٍ و«التغليف» في مكان. ولولا العمود لَاحتاجت
             * لوحةُ التجهيز أن تخمّن من اسم الصنف.
             */
            $table->string('kind')->default('flower');
            // الكميّةُ كسريّةٌ كما في `recipe_items` — والرفعُ إلى الصحيح عند الخصم
            $table->decimal('quantity', 12, 3);
            // لقطةُ التكلفة لحظتها — كما في `order_items.cost`، فلا يتحرّك ربحُ الماضي
            $table->decimal('unit_cost', 12, 3)->default(0);
            $table->decimal('total_cost', 12, 3)->default(0);
            /*
             * أيُردّ إلى الرفّ إن أُلغي الطلب؟
             *
             * مزهريّةٌ لم تُفتح تُردّ، ووردٌ قُصّ ورُكّب لا يُردّ. والسياسةُ
             * تُكتب في الصفّ لحظةَ البيع لا تُخمَّن يوم الإلغاء.
             */
            $table->boolean('restockable')->default(false);
            $table->timestamps();

            $table->index('product_id');
        });

        Schema::table('order_items', function (Blueprint $table) {
            // وصفُ الطلب المخصَّص — ما لا يُخصم من الرفّ. انظر أعلاه.
            $table->json('custom_details')->nullable()->after('addons_total');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('custom_details');
        });

        Schema::dropIfExists('order_item_components');
    }
};
