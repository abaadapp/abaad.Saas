<?php

use App\Models\PlatformDocumentTemplate;
use App\Support\DocumentForms;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * اتفاقياتُ أبعاد كما صمّمها المالك — نماذجُ لا بنودٌ فقط، ولا تُصدر قبل اعتمادها.
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
 * ═══ ولا يُصدر قالبٌ لم يُعتمد ═══
 *
 * الاتفاقياتُ الخمس تصل مسوّداتٍ معطَّلة: نصُّها ورسومُها وبياناتُ مقدّم الخدمة
 * لم تُعتمد بعد. ويعتمدها مديرُ المنصّة من شاشة القوالب بكلمة مروره، ثمّ يفعّلها
 * — قراران منه لا من النشر. انظر `PlatformDocumentTemplate`.
 *
 * والقوالبُ القائمة قبلها (عقدُ الاشتراك القصير وما كتبه المدير) تبقى كما كانت:
 * مفعّلةً إن كانت مفعّلة، وتُوسم «قائمٌ قبل سياسة الاعتماد» ببصمة محتواها اليوم.
 * فإن عُدِّلت احتاجت اعتمادًا.
 *
 * ═══ ومقدّمُ الخدمة ═══
 *
 * اسمُه القانونيّ كما أعطاه المالك: «مشاريع الذهلي الجديدة»، واسمُ المنصّة
 * التجاريّ «أبعاد» — يُكتبان في إعدادات المنصّة إن غابا، ولا يُمسّ ما كُتب.
 * ورقمُ السجلّ والعنوان لا يُكتبان هنا: لم يُعطَيا، ولا يُخترعان. وبغيابهما لا
 * يُعتمد قالب.
 *
 * ═══ ولا يُمسّ ما صدر ═══
 *
 * أعمدةٌ تقبل الفراغ وحدها، وصفوفُ قوالب جديدة، ومفتاحا إعداداتٍ جديدان. لا
 * يُعدَّل مستندٌ ولا نصُّ قالبٍ قائم.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_document_templates', function (Blueprint $table) {
            $table->json('form')->nullable();
            $table->decimal('default_amount', 12, 3)->nullable();
            $table->string('default_amount_label', 190)->nullable();
            $table->string('amount_rule', 10)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('approved_hash', 64)->nullable();
            $table->string('approval_basis', 10)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->string('approved_by_name', 120)->nullable();
        });

        Schema::table('platform_documents', function (Blueprint $table) {
            $table->json('form')->nullable();
            $table->json('team')->nullable();
            $table->json('answers')->nullable();
            $table->unsignedInteger('template_version')->nullable();
        });

        // ما كان يُصدر منه قبل هذه السياسة يبقى يُصدر — ببصمة ما فيه اليوم
        PlatformDocumentTemplate::query()->whereNull('form')->get()->each(function (PlatformDocumentTemplate $t) {
            DB::table('platform_document_templates')->where('id', $t->id)->update([
                'approved_hash' => $t->contentHash(),
                'approval_basis' => PlatformDocumentTemplate::LEGACY,
                'approved_at' => now(),
            ]);
        });

        DocumentForms::seedTemplates();

        foreach (['legal_name' => 'مشاريع الذهلي الجديدة', 'legal_trade_name' => 'أبعاد'] as $key => $value) {
            if (! DB::table('settings')->whereNull('business_id')->where('key', $key)->exists()) {
                DB::table('settings')->insert(['business_id' => null, 'key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // إسقاطُ الأعمدة يمحو إجاباتِ عملاء وقّعوا — فلا تراجعَ بعد أوّل مستندٍ بنموذج
        if (DB::table('platform_documents')->whereNotNull('form')->exists()) {
            throw new RuntimeException('صدرت مستنداتٌ بالنماذج — التراجعُ يمحو إجابات العملاء، فلا يُنفَّذ.');
        }

        $names = array_map(fn ($slug) => DocumentForms::seed($slug)['name'], DocumentForms::SEEDED);
        DB::table('platform_document_templates')->whereIn('name', $names)->whereNotNull('form')->delete();

        Schema::table('platform_documents', function (Blueprint $table) {
            $table->dropColumn(['form', 'team', 'answers', 'template_version']);
        });
        Schema::table('platform_document_templates', function (Blueprint $table) {
            $table->dropColumn(['form', 'default_amount', 'default_amount_label', 'amount_rule', 'version', 'approved_hash', 'approval_basis', 'approved_at', 'approved_by', 'approved_by_name']);
        });

        // وبياناتُ مقدّم الخدمة تبقى: ربما كتب المالكُ فيها سجلَّه وعنوانه — ومحوُها لا يُستعاد
    }
};
