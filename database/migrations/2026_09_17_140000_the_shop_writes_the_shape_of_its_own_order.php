<?php

use App\Models\CustomOrderTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * قوالبُ الطلب المخصَّص — المتجرُ يكتب شكلَ طلبه بنفسه.
 *
 * ═══ العطب ═══
 *
 * الطلبُ المخصَّص بُني لمحلّ ورد: «قيمة الورد» و«ألوان الورد» و«ملاحظات
 * المنسّق»، ونوعا المادّة `flower` و`packaging`. فمن يبيع العطر أو الحلوى أو
 * الأثاث لا يجد في الشاشة ما يصف طلبَه، ويكتب لونَ العطر في خانةٍ اسمُها
 * «ألوان الورد» — أو لا يستعمل الميزة أصلًا.
 *
 * ═══ ولمَ لا `business_type` ═══
 *
 * أن يعرف النظامُ صناعةَ التاجر يعني أن يحمل قائمةَ الصناعات كلِّها، وأن
 * يُضاف إليه فرعٌ لكلّ صناعةٍ جديدة — وقائمةٌ تُكتب باليد تنسى التاليَ
 * دائمًا. فلا يعرف النظامُ صناعةً: يعرف **قالبًا** كتبه صاحبُ النشاط،
 * وحقولًا سمّاها هو، وخياراتٍ اختارها هو.
 *
 * ═══ ثلاثةُ جداول لا واحد ═══
 *
 * القالبُ يملك حقولًا، والحقلُ يملك خيارات. وحشرُها في JSON واحدٍ كان
 * يمنع الفهرسة والقيد المرجعيّ، ويجعل «هذا الخيار من هذا الحقل؟» سؤالًا
 * يُجاب بقراءة نصّ.
 *
 * ولا `business_id` على الحقل والخيار: مالكُهما القالبُ، ومالكُ القالب
 * المتجر. وعمودان يقولان الملكيّة نفسَها يفترقان يومًا — يُنقل قالبٌ ولا
 * يُنقل حقلُه، فيقرأ متجرٌ حقلَ متجرٍ آخر. والحصرُ يمرّ بالقالب دائمًا،
 * كما يمرّ `order_item_addons` ببنده.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_order_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();

            // الاسمُ بلغتين — بيانُ تاجرٍ لا مفتاحُ ترجمة. انظر `Bilingual`
            $table->string('name');
            $table->string('name_en')->nullable();

            /*
             * أوضاعُ التسعير المسموحة — مصفوفةٌ لا عمودان منطقيّان.
             *
             * العمودان يسمحان بحالةٍ لا معنى لها: كلاهما مطفأ، فقالبٌ لا
             * يُسعَّر. والمصفوفةُ الفارغة تُردّ في التحقّق بقاعدةٍ واحدة.
             */
            $table->json('modes');
            $table->string('default_mode', 20)->nullable();

            // تسميةُ القيمة الأساسية كما يريدها التاجر — «قيمة الورد» أو «قيمة الطلب»
            $table->string('base_label')->nullable();
            $table->string('base_label_en')->nullable();

            $table->boolean('allow_components')->default(true);
            $table->boolean('allow_addons')->default(true);

            /*
             * أتعود موادُّ هذا القالب إلى الرفّ عند الإلغاء؟
             *
             * ═══ ولمَ لا يُستنبط من نوع المادّة ═══
             *
             * كانت السياسة `restockable = kind === packaging` — أي أنّ
             * النظام يفترض أنّ التغليف يعود وأنّ ما سواه لا يعود. وهو صحيحٌ
             * لمحلّ ورد وخطأٌ لمن يؤجّر: عنده كلُّ قطعةٍ تعود. ولا لمن يبيع
             * الطعام: عنده لا شيء يعود، ولا حتى العلبة.
             *
             * فالسياسةُ يقولها التاجر في قالبه، ويُصحّحها لكلّ مادّةٍ على
             * حدة في الصندوق إن شاء. والافتراضُ «لا يعود»: ردُّ ما لم يُردّ
             * يُضخّم الرفَّ صامتًا ولا يكشفه إلّا الجرد، وتركُ ما كان يعود
             * نقصٌ ظاهرٌ يُصلَح بتعديلٍ يدويّ.
             */
            $table->boolean('components_restockable_default')->default(false);

            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(0);

            // القالبُ يُحذف حذفًا ليّنًا: الطلبُ يحمل لقطتَه فلا يفقد معناه،
            // ومحوُ الصفّ يمحو ما قد يُستعاد
            $table->softDeletes();
            $table->timestamps();

            $table->index(['business_id', 'active', 'sort_order']);
        });

        Schema::create('custom_order_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('custom_order_templates')->cascadeOnDelete();

            $table->string('label');
            $table->string('label_en')->nullable();

            // short_text · long_text · number · select · multi_select · checkbox
            $table->string('type', 20);

            $table->boolean('required')->default(false);

            /*
             * حقلٌ داخليّ — يراه من يجهّز ولا يراه العميل.
             *
             * «ملاحظات المنسّق» كانت داخليّةً بالاسم لا بالقاعدة: مكتوبٌ في
             * `DocumentPaper` أنّها لا تُطبع. والقاعدةُ هنا صريحة، فمن أنشأ
             * حقلًا داخليًّا لا يحتاج أن يعدّل قالبَ فاتورة.
             */
            $table->boolean('internal')->default(false);

            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['template_id', 'active', 'sort_order']);
        });

        Schema::create('custom_order_field_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_id')->constrained('custom_order_fields')->cascadeOnDelete();

            $table->string('label');
            $table->string('label_en')->nullable();

            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['field_id', 'active', 'sort_order']);
        });

        /*
         * ولقطةُ نوع المادّة تصير اختياريّة.
         *
         * `kind` كان `default('flower')` — أي أنّ كلَّ مادّةٍ لا يُقال نوعُها
         * تُكتب «ورد». وهو افتراضُ صناعةٍ في عمودٍ يُكتب مرّةً ويُقرأ سنين.
         *
         * ولا يُحذف العمود: صفوفُ الطلبات الماضية فيه، وحذفُه يمحو معناها.
         * يُنزع افتراضُه وحدَه، فتُكتب الصفوفُ الجديدة بلا نوعٍ ما لم يُقل،
         * وتبقى القديمةُ تُقرأ كما كُتبت.
         */
        Schema::table('order_item_components', function (Blueprint $table) {
            $table->string('kind')->nullable()->default(null)->change();
        });

        /*
         * وكلُّ متجرٍ قائمٍ يفتح ومعه قالبُه.
         *
         * الميزةُ تعمل اليوم بلا قالب. فلو اشترطتُ القالبَ بلا هذا لَانقطعت
         * عن كلّ متجرٍ يستعملها حتى ينشئ واحدًا — ميزةٌ تختفي بترقية.
         *
         * والقالبُ الافتراضيّ يصف السلوك القائم بالحرف: الوضعان كلاهما،
         * والموادُّ والإضافات مفتوحة، ولا حقولَ فيه. ومن أراد حقولًا أضافها.
         */
        foreach (DB::table('businesses')->pluck('id') as $bid) {
            CustomOrderTemplate::seedDefault((int) $bid);
        }
    }

    /**
     * والرجوعُ يُسقط الجداول ويعيد الافتراض — ولا يمسّ صفًّا كُتب.
     *
     * الطلباتُ التي حملت لقطةَ قالبٍ تبقى مقروءةً بعد الرجوع: اللقطةُ في
     * `order_items.custom_details` لا في هذه الجداول.
     */
    public function down(): void
    {
        Schema::dropIfExists('custom_order_field_options');
        Schema::dropIfExists('custom_order_fields');
        Schema::dropIfExists('custom_order_templates');

        Schema::table('order_item_components', function (Blueprint $table) {
            $table->string('kind')->default('flower')->change();
        });
    }
};
