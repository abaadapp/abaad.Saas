<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الموسمُ يعود كلَّ سنة — بتقويمه هو.
 *
 * ═══ ولمَ لا تُكتب تواريخُ السنة القادمة صفًّا جديدًا ═══
 *
 * صفٌّ لكلّ سنةٍ يعني نسخَ الأصناف والتذكيرات ومفاتيح الصندوق والموقع في كلّ
 * دورة — ويعني أنّ من نسي النسخَ انقطع موسمُه. ويعني تقاريرَ مبعثرةً على
 * صفوفٍ لا رابطَ بينها.
 *
 * فالصفُّ واحدٌ، وتاريخاه **مرساةٌ** لا موعدٌ جارٍ: الدورةُ تُحسب منهما في كلّ
 * قراءة (`SeasonCycle`). فتبقى الأصنافُ والإعدادات والتقاريرُ على صاحبها،
 * ولا يُكتب في القاعدة شيءٌ حين تدور السنة.
 *
 * ═══ وثلاثةُ أعمدة لا أكثر ═══
 *
 * - `repeats`: يعود أم مرّةً واحدة. والأصلُ «لا» — فالمواسمُ القائمةُ اليومَ
 *   تبقى كما أُرّخت، ولا يُفعَّل التكرارُ على أحدٍ بلا طلبه.
 * - `calendar`: بأيّ تقويمٍ يعود — ميلاديٍّ (١٤ فبراير كلَّ سنة) أم هجريٍّ
 *   (رمضان، والعيدان). والهجريُّ يتقدّم أحدَ عشرَ يومًا في السنة الميلاديّة،
 *   فلا يُحسب بإضافة عددٍ ثابت — انظر `App\Support\Hijri`.
 * - `cycle_overrides`: تصحيحُ دورةٍ بعينها بيد صاحبها. «أمّ القرى» حسابٌ
 *   مضبوطٌ سلفًا وقد يفترق يومًا عن إعلان الدولة — فالحسابُ يقترح، وهذا
 *   العمودُ يحكم. ويُخزَّن مفتاحًا بسنة الدورة: `{"1448": {...}}`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seasons', function (Blueprint $table) {
            $table->boolean('repeats')->default(false)->after('ends_at');
            $table->string('calendar', 10)->default('gregorian')->after('repeats');
            $table->json('cycle_overrides')->nullable()->after('calendar');
        });
    }

    public function down(): void
    {
        Schema::table('seasons', function (Blueprint $table) {
            $table->dropColumn(['repeats', 'calendar', 'cycle_overrides']);
        });
    }
};
