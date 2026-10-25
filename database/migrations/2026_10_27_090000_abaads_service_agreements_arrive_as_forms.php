<?php

use App\Support\DocumentForms;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * اتفاقياتُ أبعاد كما صمّمها المالك — نماذجُ لا بنودٌ فقط.
 *
 * ═══ القالبُ نموذج ═══
 *
 * اتفاقيةُ الاشتراك وطلباتُ الخدمات الأربع (تجهيز المتجر، تخصيص الموقع، ربط
 * الدفع، ربط واتساب) أقسامٌ فيها نصٌّ وحقولٌ يعبّئها العميل وقوائمُ يؤشّر عليها
 * وجداولُ وحقولٌ يعبّئها فريقُ أبعاد. فيُحفظ ذلك كلُّه في `form` (انظر
 * `DocumentForms`)، ونصُّه في `database/data/abaad-documents/` كما خرج من التصميم.
 *
 * ═══ والمستندُ نسختُه وإجاباتُه ═══
 *
 * يُنسخ النموذجُ في المستند ساعةَ الإصدار (`form`)، ومعه ما كتبه الفريق (`team`)،
 * ثمّ ما أجاب به العميلُ ساعةَ التوقيع (`answers`). والثلاثةُ داخل بصمة المستند.
 *
 * ═══ ولا يُمسّ ما صدر ═══
 *
 * أعمدةٌ تقبل الفراغ وحدها، وصفوفُ قوالب جديدة. وقالبُ «عقد اشتراك أبعاد» الأوّل
 * يُعطَّل للإصدار ولا يُحذف: مستنداتٌ صدرت منه تبقى كما وُقِّعت.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_document_templates', function (Blueprint $table) {
            $table->json('form')->nullable();
            $table->decimal('default_amount', 12, 3)->nullable();
            $table->string('default_amount_label', 190)->nullable();
        });

        Schema::table('platform_documents', function (Blueprint $table) {
            $table->json('form')->nullable();
            $table->json('team')->nullable();
            $table->json('answers')->nullable();
        });

        DocumentForms::seedTemplates();
    }

    public function down(): void
    {
        // إسقاطُ الأعمدة يمحو إجاباتِ عملاء وقّعوا — فلا تراجعَ بعد أوّل مستندٍ بنموذج
        if (DB::table('platform_documents')->whereNotNull('form')->exists()) {
            throw new RuntimeException('صدرت مستنداتٌ بالنماذج — التراجعُ يمحو إجابات العملاء، فلا يُنفَّذ.');
        }

        $names = array_map(fn ($slug) => DocumentForms::seed($slug)['name'], DocumentForms::SEEDED);
        DB::table('platform_document_templates')->whereIn('name', $names)->delete();
        DB::table('platform_document_templates')->where('name', 'عقد اشتراك أبعاد')->update(['active' => true, 'updated_at' => now()]);

        Schema::table('platform_documents', function (Blueprint $table) {
            $table->dropColumn(['form', 'team', 'answers']);
        });
        Schema::table('platform_document_templates', function (Blueprint $table) {
            $table->dropColumn(['form', 'default_amount', 'default_amount_label']);
        });
    }
};
