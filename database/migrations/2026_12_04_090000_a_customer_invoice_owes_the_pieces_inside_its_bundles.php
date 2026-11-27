<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * قطعةُ البوتيك داخل باقةٍ أو إضافةٍ في فاتورة عميل — لقطةٌ بجانب لقطة الصنف المنفرد.
 *
 * ═══ ولمَ هنا لا في `boutique_component_dues` ═══
 *
 * ذاك الجدولُ يحمله الكشفُ ويُسدَّد بلا سؤالٍ عن التحصيل، وكودٌ أقدمُ رُجع إليه يقرؤه كذلك:
 * قطعةٌ في فاتورةٍ لم تُقبض كان سيدفعها. وبنودُ الفواتير لا يعرفها الكودُ الأقدم، وورقةٌ تحمل
 * بندًا منها لا يسدّدها إلّا كودٌ قرأ ما عُلّق (القادح `boutique_sheet_holds_are_read`). فترث
 * القطعةُ انتظارَ التحصيل والتعليقَ والحمايةَ والسجلّ، وحسابُها حسابُ الصندوق نفسُه.
 *
 * ═══ وكلُّه إضافة ═══
 *
 * بندٌ سُجّل قبلها نوعُه `line` ومفتاحُه فارغ: كما كان. ولا صفَّ يُكتب لفاتورةٍ صدرت قبلها.
 * و`boutiques.bundle_commission_rate` عمولةُ القطعة داخل الباقة باتّفاقٍ مستقلّ — فارغةٌ
 * للجميع: القاعدةُ القائمة (لا عمولة)، ولا بابَ يكتبها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boutique_invoice_lines', function (Blueprint $table) {
            $table->string('kind', 8)->default('line');
            // أيُّ قطعةٍ من بنده: «r12» مكوّنُ وصفة، و«a7» إضافة. وفارغٌ للصنف المنفرد
            $table->string('piece_key', 60)->default('');
            $table->string('source', 16)->nullable();
            $table->unsignedBigInteger('recipe_item_id')->nullable();
            $table->unsignedBigInteger('addon_id')->nullable();
            // الباقةُ كما كُتبت في الفاتورة
            $table->string('parent_name')->nullable();
            // كم قطعةً في الواحدة من بنده
            $table->decimal('unit_quantity', 12, 3)->nullable();
            // قطعٌ سُئل عنها بإشعارٍ دائن، وما لم يعد منها
            $table->decimal('asked_quantity', 12, 3)->default(0);
            $table->decimal('lost_quantity', 12, 3)->default(0);
        });

        Schema::table('boutique_invoice_lines', function (Blueprint $table) {
            $table->dropUnique(['customer_invoice_item_id']);
            // الاستعمالُ الواحد لقطةٌ واحدة — ونداءان للإصدار لا يكتبان مستحقّين
            $table->unique(['customer_invoice_item_id', 'piece_key'], 'bil_item_piece_unique');
        });

        Schema::table('boutiques', function (Blueprint $table) {
            $table->decimal('bundle_commission_rate', 5, 2)->nullable();
        });
    }

    /** ولا يُتراجع عنها وفيها قطعةٌ مستحقّة أو اتّفاق — الرجوعُ بالكود وحده تبقى معه الأعمدة */
    public function down(): void
    {
        $held = array_filter([
            'boutique_invoice_lines (pieces)' => DB::table('boutique_invoice_lines')->where('kind', 'piece')->count(),
            'boutiques.bundle_commission_rate' => DB::table('boutiques')->whereNotNull('bundle_commission_rate')->count(),
        ]);

        if ($held !== []) {
            throw new RuntimeException(
                'لا يُتراجع عن هجرة قطع فواتير العملاء وفيها ما يُفقد — '
                .implode('، ', array_map(fn ($t, $n) => $t.': '.$n, array_keys($held), $held))
                .'. ارجع بالكود وحده (scripts/rollback-code.sh مع ALLOW_MIGRATIONS) فتبقى الأعمدة وبياناتها.'
            );
        }

        Schema::table('boutiques', fn (Blueprint $table) => $table->dropColumn('bundle_commission_rate'));

        Schema::table('boutique_invoice_lines', function (Blueprint $table) {
            $table->dropUnique('bil_item_piece_unique');
            $table->unique('customer_invoice_item_id');
        });
        Schema::table('boutique_invoice_lines', fn (Blueprint $table) => $table->dropColumn([
            'kind', 'piece_key', 'source', 'recipe_item_id', 'addon_id', 'parent_name', 'unit_quantity', 'asked_quantity', 'lost_quantity',
        ]));
    }
};
