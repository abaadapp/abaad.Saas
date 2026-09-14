<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * القالب بنيةٌ لا لوحةُ ألوان — فله عمودٌ ثانٍ.
 *
 * كان الموقع يحمل `theme`: ستّةَ رموزِ لونٍ وخطّ. وكانت القوالب الستّة تختلف
 * بها وحدها — فكانت صفحةً واحدة بألوانٍ متبدّلة: الترويسةُ واحدة والواجهةُ
 * واحدة وبطاقةُ المنتج واحدة. والتاجر يرى ذلك ويسأل لماذا يشبه متجرُه متجرَ
 * جاره.
 *
 * فهذا عمودُ البنية: عرضُ المحتوى وكثافةُ الفراغ وسلّمُ العناوين وشكلُ
 * الترويسة والواجهة والبطاقة والشبكة والتصنيفات والتذييل — ثلاثةَ عشرَ رمزًا
 * يقرؤها العارضُ فيرسم بناءً آخر لا لونًا آخر. انظر `App\Support\Website\Layout`.
 *
 * ولماذا عمودٌ مستقلٌّ لا مفاتيحُ في `theme`؟ لأنّ `Theme::normalize` تردّ
 * ما تعرفه وحده — فمفتاحُ بنيةٍ يُكتب فيها يسقط صامتًا عند أوّل حفظِ لون.
 * وفصلُهما يقول أيضًا ما هما: ألوانٌ يبدّلها التاجر كلّ يوم، وبنيةٌ يختارها
 * مع قالبه ويندر أن يمسّها.
 *
 * و`nullable` لا هجرةَ بيانات: موقعٌ قائمٌ يصل بلا رموز بنية، فيأخذ
 * `Layout::defaults()` — وهي رسمُ ما قبل هذه الطبقة حرفيًّا. فلا يستيقظ
 * تاجرٌ على موقعٍ غُيّر تحته، ولا تُلمس نشرةٌ منشورة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->json('layout')->nullable()->after('theme');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn('layout');
        });
    }
};
