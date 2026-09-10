<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أمرُ الشراء يقول كيف يُنوى سدادُه — ولا يسدّد شيئًا.
 *
 * ═══ ونيّةٌ لا حدث ═══
 *
 * `payment_method` هنا **خطّةُ سداد** لا سداد: أمرُ الشراء لا يكتب قيدًا
 * ولا يُنقص صندوقًا ولا يُنشئ ذمّة. الذمّةُ تنشأ باعتماد سند المورّد،
 * والمالُ يخرج بالسداد على السند — وهذان بابان آخران لا يمرّان من هنا.
 *
 * وتُحفظ لأنّ من يكتب الأمر يعرف كيف اتّفق مع مورّده، ومن يسدّد بعد شهرٍ
 * لا يعرف. وكانت تُكتب في «ملاحظات» أو لا تُكتب أصلًا، فيُسأل عنها هاتفيًّا.
 *
 * ═══ وشروطُ السداد ليست طريقتَه ═══
 *
 * «صافي ٣٠» مدّةٌ يمنحها المورّد، و«تحويل بنكي» وسيلةٌ يُسدَّد بها. وحقلٌ
 * واحد للاثنين يجعل من يختار «آجل» يفقد المدّة، ومن يكتب «٣٠ يومًا» لا
 * يقول بأيّ وسيلة.
 *
 * ═══ وملاحظتان لا واحدة ═══
 *
 * `notes` تُطبع على الورقة التي تصل المورّد. ومن أراد أن يكتب لنفسه «هذا
 * المورّد يتأخّر — لا تعتمد عليه في المواسم» كان يكتبها حيث يقرؤها المورّد.
 *
 * ═══ ولا قيمةَ افتراضيّة تُكتب على الصفوف القديمة ═══
 *
 * أمرٌ كُتب قبل هذه الهجرة لم تُختَر له وسيلة — فيبقى `null` ويُقرأ «لم
 * يُذكر». وملؤه بـ«نقدي» يجعل الشاشةَ تقول عن أمرٍ قديمٍ شيئًا لم يقله صاحبُه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('payment_method', 40)->nullable()->after('notes');
            $table->unsignedSmallInteger('payment_terms_days')->nullable()->after('payment_method');
            $table->string('payment_reference', 60)->nullable()->after('payment_terms_days');
            $table->text('internal_notes')->nullable()->after('payment_reference');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'payment_terms_days', 'payment_reference', 'internal_notes']);
        });
    }
};
