<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الباقة تعرف مقاسها ومكوّناتها وما يُضاف إليها.
 *
 * حتى اليوم كان المنتج رقمًا واحدًا: اسمٌ وسعرٌ وكمية. وهذا يكفي وردةً
 * مفردة، ولا يكفي باقةً تُباع بثلاثة مقاسات وتُصنع من اثنتي عشرة وردةً
 * وقطعة تغليف. فكان صاحب المحلّ يُنشئ ثلاثة منتجاتٍ لباقةٍ واحدة، ويخصم
 * الورد من المخزون بيده آخر اليوم — إن تذكّر.
 *
 * أربعة جداول لا أكثر:
 *
 *   product_variants   — المقاسات. المنتج بلا صفٍّ هنا يبقى منتجًا بسيطًا
 *                        كما هو اليوم، فلا تُجبَر مئةُ منتجٍ قائم على مقاسٍ
 *                        لا معنى له.
 *
 *   recipe_items       — المكوّنات، بلا جدول رأسٍ فوقها. رأسٌ يحمل `active`
 *                        يفتح بابًا لوصفتين لمقاسٍ واحد، فيُسأل أيّهما
 *                        يُخصم. والوصفة هنا هي صفوفُ (منتج، مقاس) — واحدةٌ
 *                        بحكم البناء لا بحكم قيد.
 *
 *   product_addons     — أيّ الإضافات تُعرض مع أيّ منتج. والمنتج بلا صفٍّ
 *                        هنا تُعرض معه إضافات المتجر كلّها كما هو الحال
 *                        اليوم — فلا تختفي إضافةٌ عن كاشيرٍ بعد الترقية.
 *
 *   order_item_addons  — ما اختاره الزبون فعلًا، بأسمائه وأسعاره لحظتها.
 *
 * ولا يُمسّ صفٌّ قائم: كلّ الأعمدة المضافة إلى الجداول القديمة تقبل الفراغ
 * أو لها قيمةٌ افتراضية تساوي سلوك اليوم.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * المقاسات.
         *
         * `name_en` لأن المشروع ثنائيّ اللغة في كلّ ما يُعرض للزبون — واسم
         * مقاسٍ يظهر على الفاتورة مثله. و`sort_order` لأن «صغير وسط كبير»
         * ترتيبٌ يعرفه صاحب المحلّ ولا يعرفه المعرّف التسلسليّ.
         */
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->decimal('price', 12, 3)->default(0);
            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            // المقاس يُخفى ولا يُمحى: فاتورةٌ قديمة تشير إليه، ولقطتُها تحميها
            // من تغيّر اسمه — لا من اختفاء صفّه حين يُقرأ للعرض
            $table->softDeletes();

            $table->index(['business_id', 'product_id']);
            // التفرّد على مستوى النشاط يُطبَّق في التحقّق كما في products —
            // انظر ProductController::store. وقيدٌ في القاعدة هنا وحدها كان
            // يخالف نمط المشروع ويسقط استيرادًا يعمل اليوم.
            $table->index(['business_id', 'sku']);
            $table->index(['business_id', 'barcode']);
        });

        /*
         * المكوّنات.
         *
         * `variant_id` فارغًا يعني وصفةَ المنتج نفسه — لمنتجٍ بلا مقاسات، أو
         * أساسًا يشترك فيه ما لا وصفة له من المقاسات. ومقاسٌ له صفوفُه
         * الخاصّة تُقرأ صفوفُه وحدها: لا جمعَ بين الاثنين، فالجمع يجعل
         * «الكبير» يستهلك أساسَ الصغير فوق ورده.
         *
         * والكمية عشريّة: نصف متر شريط، وربع لفّة تغليف.
         */
        Schema::create('recipe_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            // المكوّن يُقيَّد لا يُحذف معه: حذف منتجٍ يدخل في وصفةٍ قائمة يجب
            // أن يُمنع لا أن يُفرّغ الوصفة بصمت — والمنتجات تُحذف حذفًا ناعمًا
            // أصلًا، فالقيد لا يُستدعى في الاستعمال العاديّ
            $table->foreignId('component_product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
            // نسبة الفاقد: وردةٌ من كلّ عشرين تُكسر أثناء التجهيز. تُضاف إلى
            // المستهلك لا إلى التكلفة وحدها — الفاقد نقصٌ في الرفّ فعلًا
            $table->decimal('wastage_percent', 5, 2)->default(0);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['business_id', 'product_id', 'variant_id'], 'recipe_items_owner_index');
            $table->index('component_product_id');
        });

        /*
         * أيّ إضافةٍ مع أيّ منتج.
         *
         * الغياب يعني «الكلّ» لا «لا شيء»: قبل هذا الجدول كانت كلّ الإضافات
         * تظهر مع كلّ منتج، ولو قرأنا الغياب منعًا لاختفت الإضافات كلّها عن
         * كلّ منتجٍ لحظة الترقية.
         */
        Schema::create('product_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('addon_id')->constrained()->cascadeOnDelete();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'addon_id']);
            $table->index('business_id');
        });

        /*
         * ما اختاره الزبون — بلقطته.
         *
         * الاسم والسعر يُنسخان لا يُقرآن من `addons` لاحقًا: شوكولاتةٌ صارت
         * بخمسة اليوم لا تجعل فاتورة الشهر الماضي تقول خمسة. والمعرّف يبقى
         * لمن أراد التجميع، ويقبل الفراغ حتى لا تختفي بنودُ فاتورةٍ لأنّ
         * الإضافة حُذفت بعدها.
         */
        Schema::create('order_item_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('addon_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->decimal('unit_price', 12, 3);
            $table->integer('quantity')->default(1);
            $table->decimal('total', 12, 3);
            // تكلفة الإضافة لحظتها — فارغةٌ لإضافةٍ لا مخزون لها (خدمة لا بضاعة)
            $table->decimal('cost', 12, 3)->nullable();
            $table->timestamps();

            $table->index('addon_id');
        });

        /*
         * الإضافة قد تكون بضاعةً في الرفّ.
         *
         * «تغليف فاخر» خدمةٌ لا رصيد لها، و«دبّ» قطعةٌ تنقص من المخزون حين
         * تُباع. فالربط اختياريّ: من ربطه خُصم، ومن تركه بقي كما كان.
         */
        Schema::table('addons', function (Blueprint $table) {
            $table->foreignId('inventory_product_id')->nullable()->after('price')
                ->constrained('products')->nullOnDelete();
        });

        /*
         * لقطة المقاس على البند — والإضافات مجموعةً.
         *
         * `addons_total` عمودٌ مشتقّ عمدًا: `total` يبقى ثمنَ البند بمقاسه،
         * وإعادةُ حساب الفاتورة تجمع الاثنين. وبلا عمودٍ لها كان مجموع
         * الإضافات يُقرأ بضمّ جدولٍ في كلّ تقرير — أو يُنسى في أحدها.
         *
         * والصفر افتراضًا: كلّ بندٍ قديم يبقى حسابه كما هو حرفًا بحرف.
         */
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('variant_id')->nullable()->after('product_id')
                ->constrained('product_variants')->nullOnDelete();
            $table->string('variant_name')->nullable()->after('variant_id');
            $table->string('variant_sku')->nullable()->after('variant_name');
            $table->decimal('addons_total', 12, 3)->default(0)->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('variant_id');
            $table->dropColumn(['variant_name', 'variant_sku', 'addons_total']);
        });

        Schema::table('addons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inventory_product_id');
        });

        Schema::dropIfExists('order_item_addons');
        Schema::dropIfExists('product_addons');
        Schema::dropIfExists('recipe_items');
        Schema::dropIfExists('product_variants');
    }
};
