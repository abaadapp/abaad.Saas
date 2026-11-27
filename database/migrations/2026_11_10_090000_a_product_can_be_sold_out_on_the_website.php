<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «نفدت الكمية» في الموقع — قرارُ التاجر بيده، لا حسبةُ مخزون.
 *
 * ═══ ولمَ عمودٌ ثالث ═══
 *
 * `published` يُخفي الصنفَ من الموقع كلِّه، و`active` يوقفه في كلّ قناة.
 * وهذا يُبقيه معروضًا بصورته وسعره ويمنع طلبه وحده: باقةُ العيد نفدت
 * اليوم وتعود غدًا، والزبونُ يراها ويعرف أنّها تُباع هنا.
 *
 * ولا يمسّ الكمّيّةَ ولا الرفوفَ ولا الحركات: من علّمه نفدت لم يبع شيئًا.
 * والصندوقُ لا يقرؤه.
 *
 * ═══ ولا يُنقل شيء ═══
 *
 * عمودٌ جديدٌ بـ`false`: كلُّ صنفٍ قائمٍ يبقى كما كان.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('website_sold_out')->default(false)->after('published');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('website_sold_out');
        });
    }
};
