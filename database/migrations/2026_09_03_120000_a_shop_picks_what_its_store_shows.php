<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ما يُعرض في المتجر اختيارُ صاحبه، لا كلُّ ما في الجرد.
 *
 * كانت الصفحة العامّة تعرض كلَّ صنفٍ `active`، و`active` تعني «يُباع في نقطة
 * البيع» لا «يراه الزبون على الإنترنت». وفي جرد كلّ محلٍّ ما لا يُراد عرضه:
 * أوراق تغليفٍ تُشترى ولا تُباع وحدها، ومكوّناتُ باقاتٍ لها أسعارُ كلفة،
 * وأصنافُ جملةٍ بسعرها، وباقاتٌ تحت التجهيز. ولم يكن لصاحب المحلّ أن يمنع
 * صنفًا واحدًا إلّا بإطفائه في نقطة البيع أيضًا — أي أن يوقف بيعه ليُخفيه.
 *
 * والافتراضيّ `true` عمدًا، خلافًا لِما يُنصح به في نظامٍ جديد: متاجرُ مفتوحةٌ
 * الآن تعرض كلَّ ما فيها، وعمودٌ افتراضُه `false` يُفرغها كلَّها لحظةَ الهجرة.
 * فالمقبض يُضاف ليُخفي من أراد، لا ليُطفئ ما يعمل.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('published')->default(true)->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('published');
        });
    }
};
