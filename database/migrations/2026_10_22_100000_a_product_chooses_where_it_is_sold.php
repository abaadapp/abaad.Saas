<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الصنفُ يختار أين يُباع: نقطةُ البيع، والمتجرُ على الإنترنت، أو كلاهما، أو لا شيء.
 *
 * ═══ ما كان ═══
 *
 * `active` تحكم الصندوقَ والموقعَ معًا، و`published` تُخفي من الموقع وحده
 * (`a_shop_picks_what_its_store_shows`). فمن أراد أن يُخفي صنفًا عن نقطة
 * البيع ويُبقيه في متجره على الإنترنت — باقةٌ تُطلب أونلاين وحدها، أو كرتٌ
 * لا يُباع على الطاولة — لم يجد إلّا `active`، فيُطفئه في الموقع أيضًا.
 *
 * ═══ وما يُضاف ═══
 *
 * `pos_enabled`: نظيرُ `published` لنقطة البيع. و`active` يبقى الحارسَ العامّ
 * فوقهما: الموقوفُ لا يُباع في أيّ قناة.
 *
 * والافتراضُ `true` عمدًا كأخيه: كلُّ صنفٍ نشطٍ اليوم يُباع على الصندوق،
 * وعمودٌ افتراضُه `false` يُفرغ شاشاتِ البيع كلَّها لحظةَ الهجرة. فلا يتغيّر
 * ظهورُ صنفٍ واحد، ولا مخزونٌ ولا سعرٌ ولا فاتورة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'pos_enabled')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('pos_enabled')->default(true)->after('published');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('products', 'pos_enabled')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('pos_enabled');
        });
    }
};
