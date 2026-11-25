<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * حركةُ البوتيك تسمّي مستندَها وحركتَها الماليّة.
 *
 * سدادُ قطع ورقةٍ، واستردادُ رصيدٍ من بوتيك، وتحصيلُ مطالبة مرتجع: قيدٌ في الدفتر بلا صفٍّ
 * في «الحركات» — فلا تُقرأ في الحركة الماليّة ولا تُطابَق بكشف البنك. فصار لكلٍّ منها صفُّه،
 * ومستندُه يشير إليه.
 *
 * وبندُ بوتيكٍ أُنقص أو حُذف قبل أن تحمله ورقةٌ لم يكن يبقى منه أثرٌ بنصيب صاحبه (حذفُ البند
 * يمحو صفَّه): فصار صفُّ التصحيح يحمل البوتيكَ والبندَ وما تغيّر من نصيبه.
 *
 * أعمدةٌ تقبل الفراغ، ولا تُملأ لماضٍ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boutique_settlements', fn (Blueprint $table) => $table->unsignedBigInteger('transaction_id')->nullable());
        Schema::table('boutique_return_recoveries', fn (Blueprint $table) => $table->unsignedBigInteger('transaction_id')->nullable());

        Schema::table('order_edits', function (Blueprint $table) {
            $table->unsignedBigInteger('boutique_id')->nullable();
            // لا مفتاحَ أجنبيّ: البندُ قد يُحذف بهذا التصحيح نفسِه
            $table->unsignedBigInteger('boutique_item_id')->nullable();
            $table->decimal('boutique_share', 14, 3)->nullable();
            $table->index('boutique_id');
        });
    }

    /** ولا يُتراجع عنها وفيها ما يُفقد — الرجوعُ بالكود وحده تبقى معه الأعمدة */
    public function down(): void
    {
        $held = array_filter([
            'boutique_settlements.transaction_id' => DB::table('boutique_settlements')->whereNotNull('transaction_id')->count(),
            'boutique_return_recoveries.transaction_id' => DB::table('boutique_return_recoveries')->whereNotNull('transaction_id')->count(),
            'order_edits.boutique_share' => DB::table('order_edits')->whereNotNull('boutique_share')->count(),
        ]);

        if ($held !== []) {
            throw new RuntimeException(
                'لا يُتراجع عن هجرة حركات البوتيك وفيها ما يُفقد — '
                .implode('، ', array_map(fn ($t, $n) => $t.': '.$n, array_keys($held), $held))
                .'. ارجع بالكود وحده (scripts/rollback-code.sh مع ALLOW_MIGRATIONS) فتبقى الأعمدة وبياناتها.'
            );
        }

        Schema::table('order_edits', function (Blueprint $table) {
            $table->dropIndex(['boutique_id']);
            $table->dropColumn(['boutique_id', 'boutique_item_id', 'boutique_share']);
        });
        Schema::table('boutique_return_recoveries', fn (Blueprint $table) => $table->dropColumn('transaction_id'));
        Schema::table('boutique_settlements', fn (Blueprint $table) => $table->dropColumn('transaction_id'));
    }
};
