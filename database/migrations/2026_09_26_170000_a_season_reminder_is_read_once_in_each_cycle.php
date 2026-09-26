<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * التذكيرُ يُقرأ مرّةً في كلّ دورة.
 *
 * ═══ ولمَ تاريخٌ لا مفتاحٌ ثنائيّ ═══
 *
 * «أُخفيَ» وحدَها تكفي لموسمٍ يقع مرّةً ثمّ ينتهي. ومواسمُ التاجر تعود كلَّ
 * سنة، والهجريُّ منها يتقدّم أحدَ عشرَ يومًا في كلّ عام — فصاحبُه يُعيد
 * تأريخَ موسمه، والتذكيرُ الذي أُخفي العامَ الماضي يجب أن يعود هذا العام.
 *
 * فيُحفظ **أيُّ دورةٍ أُخفي فيها**: بدايةُ الموسم يومَ أخفاه. فإن كانت
 * بدايتُه اليوم هي نفسَها فقد قرأه في هذه الدورة ولا يُعاد عليه، وإن تبدّلت
 * فهي دورةٌ أخرى ويعود. ولا حقلَ ثانٍ يُضبط ولا مهمّةٌ دوريّةٌ تُصفّره.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('season_reminders', function (Blueprint $table) {
            $table->date('acknowledged_for')->nullable()->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('season_reminders', function (Blueprint $table) {
            $table->dropColumn('acknowledged_for');
        });
    }
};
