<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الموقع صار بنيةً لا ثمانيةَ حقول.
 *
 * كان «الموقع الإلكتروني» ثمانية مفاتيح نصّية في جدول الإعدادات: جملةُ تعريف،
 * ونبذة، وواتساب، وإنستغرام، ومفتاحان للعرض. خمسةٌ منها لا يقرؤها شيءٌ في
 * النظام كلّه — تُحفظ وتُعاد عرضًا في الشاشة التي حفظتها. ولا مسار عامّ ولا
 * قالب عرضٍ ولا صفحة: «الموقع» عنوانٌ يكتبه التاجر ويفتحه زرٌّ في الترويسة.
 *
 * وحقلٌ نصّيٌّ لا يصير موقعًا مهما كثر. الموقع صفحاتٌ، وفي كلّ صفحةٍ أقسامٌ
 * مرتّبة، ولكلّ قسمٍ محتواه — وهذا ما لا يحمله `key/value`.
 *
 * وأربعة جداول لا واحد، ولكلٍّ سببه:
 *
 * `websites` هويّة الموقع: قالبُه وألوانُه وحالتُه ووجهتُه المنشورة. واحدٌ
 * لكلّ نشاط (`unique`) — متجرٌ بموقعين يعني نطاقًا لا يُعرف أيَّهما يفتح.
 *
 * `website_pages` الصفحات بروابطها وسيوها. و`slug` فريدٌ داخل الموقع لا في
 * الجدول: نطاقان مختلفان يحتملان `/about` كلاهما.
 *
 * `website_sections` الأقسام. `page_id` لأقسام الصفحة، و`slot` للترويسة
 * والتذييل — وهما قسمان عامّان لا يخصّان صفحة. وكونهما في الجدول نفسه يجعل
 * محرّرًا واحدًا يكفيهما جميعًا.
 *
 * `website_versions` لقطةٌ كاملة لكلّ نشرة. وهي التي تفصل المسوّدة عن
 * المنشور: التاجر يعدّل في الجداول الحيّة بلا خوف، ولا يرى الزائرُ شيئًا حتى
 * يُنشر — فتُجمَّد الحالُ في `payload` ويشير إليها `published_version_id`.
 * والاستعادة كتابةُ لقطةٍ قديمة فوق الحيّ، فتاريخُ النسخ يعمل من أوّل يوم
 * بلا بناءٍ ثانٍ.
 *
 * ولا يُمسّ شيءٌ ممّا كان: `site_domain` وأخواته تبقى في مكانها يقرؤها
 * `Demo::websiteUrl` وشاشةُ الدومين، والحقول الخمسة الميتة تصير قيمًا أوّلية
 * لأقسام الموقع الجديد (انظر App\Support\Website\MerchantData).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('websites', function (Blueprint $table) {
            $table->id();
            // موقعٌ واحد لكلّ نشاط: النطاق يشير إلى واحد، فلا يُبنى ثانٍ
            $table->foreignId('business_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('name');
            /*
             * ما يريده التاجر من موقعه — والجواب يحدّد الصفحات والأقسام.
             *
             * store: يبيع · catalog: يعرض بلا طلب · profile: يعرّف بنشاطه.
             * وهو الذي يمنع عرضَ إعدادٍ لا يحتاجه: من اختار «تعريفيّ» لا يُسأل
             * عن سلّةٍ ولا عن عرض الأسعار.
             */
            $table->string('goal', 20)->default('store');
            $table->string('template', 40)->default('minimal');
            // رموز التصميم: لونٌ أساسيّ وخلفيةٌ ونصٌّ وخطٌّ وحوافّ وأزرار
            $table->json('theme');
            $table->json('seo')->nullable();
            $table->timestamp('published_at')->nullable();
            /*
             * وضع الصيانة هنا لا في «أخرى».
             *
             * هو حالٌ من أحوال الموقع كالمسوّدة والمنشور، لا إعدادٌ متفرّق:
             * الزائر يرى صفحة صيانة، واللوحة تعمل كما هي.
             */
            $table->boolean('maintenance')->default(false);
            $table->string('maintenance_message')->nullable();
            // آخر حفظٍ تلقائيّ — «تم الحفظ ✓» تُقرأ من هنا لا من الذاكرة
            $table->timestamp('draft_saved_at')->nullable();
            /*
             * «هل بعد النشر تعديل؟» يُجاب برقمٍ لا بمقارنة وقتين.
             *
             * الطابع الزمنيّ دقّتُه ثانية، ونشرٌ يعقبه تعديلٌ في الثانية نفسها
             * يجعل الوقتين متساويين — فتقول الشاشة «منشور ولا تغييرات» وفي
             * المسوّدة تغييرٌ لا يراه أحد. والعدّاد لا يلتبس: يزيد مع كلّ
             * تعديل، ويُنسخ إلى `published_revision` عند النشر.
             */
            $table->unsignedInteger('draft_revision')->default(0);
            $table->unsignedInteger('published_revision')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('website_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            /*
             * ومعرّف النشاط في كلّ جدول ولو كان مشتقًّا من الموقع.
             *
             * العزل شرطٌ في الاستعلام لا نيّةٌ في الكود: `where('business_id')`
             * تُكتب في كلّ قراءة، ولو لزم ضمُّ الموقع لقراءتها لسقطت من
             * استعلامٍ أو اثنين — وسقوطُها مرّةً واحدة يعني صفحةَ جارٍ في
             * محرّر جاره.
             */
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            $table->string('title');
            $table->string('slug', 120);
            $table->string('status', 20)->default('draft');
            $table->boolean('is_home')->default(false);
            // الرئيسية لا تُحذف: موقعٌ بلا صفحةٍ أولى نطاقٌ يردّ بلا شيء
            $table->boolean('removable')->default(true);
            $table->json('seo')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['website_id', 'slug']);
            $table->index(['business_id', 'website_id']);
        });

        Schema::create('website_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('page_id')->nullable()->constrained('website_pages')->cascadeOnDelete();
            // ترويسةٌ أو تذييل — قسمٌ عامٌّ لا صفحةَ له
            $table->string('slot', 20)->nullable();
            $table->string('type', 40);
            $table->unsignedInteger('position')->default(0);
            /*
             * الإخفاء لا الحذف.
             *
             * التاجر يجرّب: يُخفي «آراء العملاء» ليرى الموقع بدونها. والحذف
             * يُضيّع ما كتبه فيها، فلا يجرّب مرّةً ثانية.
             */
            $table->boolean('visible')->default(true);
            $table->json('data');
            $table->timestamps();

            $table->index(['website_id', 'page_id', 'position']);
            $table->index(['website_id', 'slot']);
        });

        Schema::create('website_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            // اللقطة كاملةً: الصفحات وأقسامها والقالب والألوان والسيو
            $table->json('payload');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['website_id', 'number']);
        });

        /*
         * وجهةُ النشر تُضاف بعد جدولها.
         *
         * الجدولان يشير كلٌّ منهما إلى الآخر: الموقع إلى نسخته المنشورة،
         * والنسخة إلى موقعها. وإنشاؤهما بمفتاحين متبادلين في أمرٍ واحد يسقط
         * على كلّ محرّك — فيُفصل المفتاح عن جدوله.
         */
        Schema::table('websites', function (Blueprint $table) {
            $table->foreignId('published_version_id')->nullable()->after('seo')
                ->constrained('website_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('published_version_id');
        });

        Schema::dropIfExists('website_versions');
        Schema::dropIfExists('website_sections');
        Schema::dropIfExists('website_pages');
        Schema::dropIfExists('websites');
    }
};
