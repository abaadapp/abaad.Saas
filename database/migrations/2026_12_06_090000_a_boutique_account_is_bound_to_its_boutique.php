<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * حسابُ صاحب البوتيك يُربط ببوتيكه — عمودٌ واحد، فارغٌ لكلّ حسابٍ قائم.
 *
 * لا يكتب هذا الملفُّ صفًّا ولا يُخمّن لمن حسابٌ: كلُّ حسابٍ يبقى كما كان حتى
 * يربطه التاجرُ بيده من شاشة الموظف (`BoutiqueAccount`). والربطُ يُضيّق ولا
 * يُوسّع — فلا حسابَ قديمٌ يأخذ به شيئًا لم يكن له.
 *
 * وفهرسٌ بلا مفتاحٍ أجنبيّ — عن قصد:
 *
 *   - استعادةُ نسخة المتجر تمحو بوتيكاته ثمّ تُدرجها بأرقامها (`BackupController`).
 *     ومفتاحٌ يمنع الحذفَ يُسقط الاستعادةَ كلَّها، ومفتاحٌ يُفرغ العمودَ عند الحذف
 *     يفكّ الربطَ بصمت فيفتح لصاحب البوتيك كلَّ ما في صلاحيّاته.
 *   - فيبقى الرقمُ، ويعود البوتيكُ برقمه فيعود الربطُ كما كان. وإن غاب البوتيكُ
 *     بقي الحسابُ مربوطًا إلى لا شيء: لا يرى صنفًا ولا يُنشئ (`BoutiqueAccount`)
 *     — يُغلق ولا يُفتح.
 *   - والحذفُ من الشاشة يُرفض وعليه حسابٌ مربوط (`BoutiqueController::destroy`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('boutique_id')->nullable()->index();
        });
    }

    /**
     * والنزولُ يُرفض وفي القاعدة حسابٌ مربوط — فكُّه بصمت يفتح له المتجرَ كلَّه.
     */
    public function down(): void
    {
        $bound = DB::table('users')->whereNotNull('boutique_id')->count();
        if ($bound > 0) {
            throw new RuntimeException('لا يُتراجع عن ربط حسابات البوتيك وفيها '.$bound.' حسابًا مربوطًا — فكُّ الربط بصمت يفتح لأصحابها كلَّ ما في صلاحيّاتهم. فُكّ الربطَ من شاشة الموظف أوّلًا، أو ارجع بالكود وحده.');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['boutique_id']);
            $table->dropColumn('boutique_id');
        });
    }
};
