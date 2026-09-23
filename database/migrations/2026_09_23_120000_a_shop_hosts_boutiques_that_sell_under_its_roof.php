<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المحلُّ يُؤوي بوتيكاتٍ تبيع تحت سقفه — ويأخذ نسبةً ممّا باعت.
 *
 * ═══ ولمَ لا نظامَ ثانٍ ═══
 *
 * البوتيكُ ليس متجرًا في أبعاد ولا فرعًا ولا مستأجرًا له لوحة: هو **نسبةُ
 * أصنافٍ قائمة**. فالصنفُ صنفٌ كما هو — يُباع في الصندوق وفي الموقع، ويُخصم
 * من الرفّ، ويدخل التقارير — ويُنسب إلى صاحبه. وهي طبقةٌ فوق الأصناف كما
 * «المواسم» طبقةٌ فوقها، لا تُعيد تعريفها.
 *
 * ═══ واللقطةُ على البند لا القراءةُ من الصلة ═══
 *
 * «أيُّ الأصناف لأيّ بوتيك اليوم» لا يقول «أيُّ بيعةٍ كانت لأيّه». وصنفٌ
 * يُنقل من بوتيكٍ إلى آخر تنتقل معه بيعاتُ الشهر الماضي، وكشفُ حسابٍ صدر
 * يصير كذبًا.
 *
 * والنسبةُ كذلك — وهي أخطرُ: بوتيكٌ تُرفع نسبتُه من ١٥٪ إلى ٢٠٪ اليوم
 * تُعاد به حسبةُ كلّ ما بِيع قبل اليوم، فيُطالَب بمالٍ لم يتّفق عليه.
 *
 * فيُكتب الثلاثةُ على البند ساعةَ البيع: المعرّفُ والاسمُ والنسبة. والاسمُ
 * معهما لأنّ بوتيكًا يُغادر ويُحذف لا يُفقد كشفَ حسابٍ صدر معناه.
 *
 * ولا مفتاحَ أجنبيًّا يُسقط البندَ مع البوتيك: البندُ مالٌ وقع ولا يُمحى.
 *
 * ═══ والبضاعةُ أمانة ═══
 *
 * البوتيكُ يملكها حتّى تُباع — فلا تدخل قيمةَ مخزون المحلّ ولا يُكتب لها
 * قيدُ تكلفةِ بضاعةٍ مباعة. والكميّاتُ تُعدّ وتُخصم كالمعتاد كي يُعرف ما
 * نفد. انظر `Boutiques::CONSIGNED`.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * والبابُ مغلقٌ افتراضًا.
         *
         * هذه ميزةُ محلٍّ يُؤجّر أركانَه، لا ميزةُ كلّ متجر. ومن لا بوتيك عنده
         * لا يُزاد في شاشته تبويبٌ يسأل عمّا لا يعرفه — فالمفتاح على الشركة،
         * يفتحه مديرُ المنصّة لمن طلبه.
         */
        Schema::table('businesses', function (Blueprint $table) {
            if (! Schema::hasColumn('businesses', 'boutiques_enabled')) {
                $table->boolean('boutiques_enabled')->default(false)->after('storefront_theme');
            }
        });

        Schema::create('boutiques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('contact_person')->nullable();

            /*
             * نسبةُ المحلّ من مبيعاته — مئويّةً بمنزلتين.
             *
             * وتُحفظ على البوتيك لتكون الافتراضَ، وتُنسخ على كلّ بندٍ يُباع.
             * فتغييرُها يسري على ما يأتي لا على ما مضى.
             */
            $table->decimal('commission_rate', 5, 2)->default(0);

            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'active']);
        });

        Schema::table('products', function (Blueprint $table) {
            /*
             * وصنفٌ بلا بوتيك هو صنفُ المحلّ — وهو الحال الغالب.
             *
             * و`nullOnDelete`: بوتيكٌ يُحذف تبقى أصنافُه في الرفّ وتصير
             * للمحلّ. وحذفُها معه يمحو بضاعةً موجودةً على الرفّ فعلًا.
             */
            $table->foreignId('boutique_id')->nullable()->after('category_id')
                ->constrained('boutiques')->nullOnDelete();
        });

        Schema::table('order_items', function (Blueprint $table) {
            // ولا مفتاحَ أجنبيّ: لقطةٌ لا صلة — انظر ترويسة الملفّ
            $table->unsignedBigInteger('boutique_id')->nullable()->after('product_id');
            $table->string('boutique_name')->nullable()->after('boutique_id');
            $table->decimal('boutique_rate', 5, 2)->nullable()->after('boutique_name');

            $table->index(['boutique_id']);
        });

        /*
         * التسويةُ الشهريّة — مستندٌ يُصدَر مرّةً للشهر الواحد.
         *
         * والتفرّدُ على الثلاثة (المحلّ، البوتيك، الشهر) لا على الرقم وحده:
         * ضغطتان على «أصدِر» تُخرجان تسويتين لشهرٍ واحد، فيُطالَب المحلُّ
         * بالمبلغ مرّتين — وهو أكثرُ ما يقع في شاشةٍ تُفتح آخرَ الشهر.
         */
        Schema::create('boutique_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40);
            // الشهر كما يُكتب: «2027-02» — يُقارن نصًّا ويُرتَّب نصًّا
            $table->string('period', 7);

            $table->decimal('gross', 14, 3)->default(0);
            $table->decimal('commission', 14, 3)->default(0);
            $table->decimal('net', 14, 3)->default(0);
            $table->unsignedInteger('lines_count')->default(0);

            /*
             * والمصروفُ الذي وُلد منها — به تدخل «المبالغ المستحقة» وتُسدَّد.
             *
             * ولا مسارَ سدادٍ ثانٍ يُبنى: المحلُّ يدفع للبوتيك كما يدفع لأيّ
             * مستحقٍّ عليه، ومن كتب مسارًا ثانيًا كتب معه شاشةً ثانيةً
             * وقيدًا ثانيًا يُنسى تحديثُه.
             */
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->nullOnDelete();

            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'boutique_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boutique_settlements');

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['boutique_id']);
            $table->dropColumn(['boutique_id', 'boutique_name', 'boutique_rate']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('boutique_id');
        });

        Schema::dropIfExists('boutiques');

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('boutiques_enabled');
        });
    }
};
