<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * لمتجر الواجهة الخاصّة مسوّدةٌ ونسخٌ — كما للموقع المبنيّ.
 *
 * ═══ ما كان ═══
 *
 * الواجهةُ الخاصّة تقرأ إعداداتِ صاحبها مباشرةً عند كلّ زيارة. فكلُّ حفظٍ
 * نشرٌ: يكتب التاجرُ نصفَ نبذةٍ ثمّ ينشغل، فيقرأ زبونُه نصفَ نبذة. ولا
 * «تغييراتٌ غير منشورة» لأنّه لا يوجد ما يُنشر، ولا رجوعَ إلى ما كان.
 *
 * ═══ ولمَ لا جدولَ نسخٍ ثانيًا ═══
 *
 * `website_versions` تحمل عقدًا مُرقًّى ودورةَ نشرٍ مُحكَمة — قفلٌ ورقمٌ
 * متسلسلٌ وحمايةٌ من الضغط مرّتين. وإنشاءُ جدولٍ ثانٍ بالشكل نفسِه يعني
 * نظامَي نشرٍ يفترقان عند أوّل إصلاح. فيُوسَّع القائم:
 *
 *  • `website_id` تصير قابلةً للفراغ — الواجهةُ الخاصّة لا صفَّ لها في
 *    `websites`، وصفوفُ البانِي تبقى كما هي لا يمسّها شيء.
 *  • و`kind` تفرّق بين محرّكَي العرض في التاريخ نفسِه.
 *
 * ولا تتعارض مع الفهرس الفريد `(website_id, number)`: الفراغُ لا يساوي
 * فراغًا في PostgreSQL، فصفوفُ الواجهة الخاصّة لا تتزاحم عليه — ولها
 * فهرسُها `(business_id, kind, number)`.
 *
 * ═══ ولا يُرحَّل موقعٌ قائمٌ هنا ═══
 *
 * هذه الهجرةُ تفتح البابَ ولا تُدخل أحدًا: متجرٌ بلا صفٍّ في `store_sites`
 * يعمل كما كان بالضبط — يُحفظ فيُنشر في اللحظة نفسِها. والترحيلُ أمرٌ
 * يُنادى لكلّ متجرٍ على حدة بعد أن تُلتقط حالُه المنشورة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained()->cascadeOnDelete();

            /*
             * المسوّدة: مفاتيحُ التصميم والمحتوى وحدَها — انظر
             * `StoreContent::VERSIONED`. ولا يدخلها سعرٌ ولا مخزونٌ ولا
             * رسمُ توصيلٍ ولا وسيلةُ دفع: تلك حالُ النشاط الآن، لا تصميمُ
             * موقعه. فنشرُ التصميم لا يُجمّد بضاعةً ولا يُعيد سعرًا قديمًا.
             */
            $table->json('draft');

            /*
             * والمراجعةُ عدّادٌ يزيد مع كلّ حفظ.
             *
             * بها يُعرف «فيه تغييراتٌ غير منشورة» بلا مقارنةِ مستندين حرفًا
             * بحرف، وبها يُمنع نشرُ موظّفٍ لمسوّدةٍ بدّلها زميلُه بعد أن
             * فتح الشاشة — وهي قاعدةُ `websites.draft_revision` نفسُها.
             */
            $table->unsignedInteger('draft_revision')->default(0);
            $table->timestamp('draft_saved_at')->nullable();
            $table->foreignId('draft_saved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('published_version_id')->nullable()
                ->constrained('website_versions')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('published_revision')->nullable();

            $table->timestamps();
        });

        Schema::table('website_versions', function (Blueprint $table) {
            // والقالبُ يُكتب في كلّ صفٍّ قديمٍ «بانٍ» — فلا يُقرأ فراغٌ بعد اليوم
            $table->string('kind', 20)->default('builder')->after('business_id');
        });

        /*
         * و`website_id` تصير قابلةً للفراغ بعد أن تمتلئ كلُّ صفوفها.
         *
         * وتُكتب بـSQL خام لا بـ`change()`: `change()` تُعيد بناءَ العمود من
         * وصفٍ يكتبه المُهاجِر بيده، فيسقط منه ما لم يُكتب — والمفتاحُ
         * الأجنبيُّ هنا أحدُ ما يسقط. وSQLite لا تعرف `ALTER COLUMN` أصلًا،
         * فتُبنى بنسخ الجدول — ولا تُنادى في الاختبار لأنّ الجدول يُبنى من
         * الهجرات كلِّها مرّةً واحدة.
         */
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::getConnection()->statement(
                'ALTER TABLE website_versions ALTER COLUMN website_id DROP NOT NULL'
            );
        } else {
            Schema::table('website_versions', function (Blueprint $table) {
                $table->unsignedBigInteger('website_id')->nullable()->change();
            });
        }

        Schema::table('website_versions', function (Blueprint $table) {
            $table->index(['business_id', 'kind', 'number'], 'website_versions_store_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_sites');

        Schema::table('website_versions', function (Blueprint $table) {
            $table->dropIndex('website_versions_store_number');
            $table->dropColumn('kind');
        });
    }
};
