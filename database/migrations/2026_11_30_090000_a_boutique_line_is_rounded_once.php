<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * قاعدةُ حسابٍ واحدة لمستحقّ البوتيك — والورقةُ تحمل قاعدتَها، والبندُ ضريبتَه إن أُخذت منه.
 *
 * - `boutique_settlements.rounding`: ‏`line` لورقةٍ قُرّبت بندًا بندًا. والفراغُ ورقةٌ صدرت
 *   قبلها (كلُّ صنفٍ يُجمع ثمّ يُقرَّب): مرتجعُها يُحسب بقاعدتها هي، فتجمع الأجزاءُ إليها.
 * - `boutique_settlements.vat`: ما أُخرج من ضريبةٍ داخل الأسعار قبل العمولة — صفرٌ في القاعدة القائمة.
 * - `order_items.boutique_vat_rate`: نسبةُ الضريبة المشمولة التي تُخرَج من مبلغ البند قبل
 *   عمولته، لقطةً ساعةَ البيع. والفراغُ = كما سُجّل، وهو حالُ كلّ بندٍ اليوم.
 * - `businesses.boutique_commission_before_vat_from`: من هذا اليوم تُحسب عمولةُ هذا المتجر
 *   قبل الضريبة. **فارغٌ لكلّ متجرٍ قائمٍ وقادم، ولا بابَ في النظام يكتبه**: قاعدةٌ معروضة
 *   على المالك والمحاسب، لم تُعتمد.
 *
 * وكلُّه إضافة: لا صفَّ يُعاد حسابُه، ولا ورقةَ ولا قيدَ يتغيّر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'boutique_vat_rate')) {
                $table->decimal('boutique_vat_rate', 5, 2)->nullable()->after('boutique_rate');
            }
        });

        Schema::table('boutique_settlements', function (Blueprint $table) {
            if (! Schema::hasColumn('boutique_settlements', 'rounding')) {
                $table->string('rounding', 8)->nullable()->after('kind');
            }
            if (! Schema::hasColumn('boutique_settlements', 'vat')) {
                $table->decimal('vat', 14, 3)->default(0)->after('gross');
            }
        });

        // ومرتجعٌ بعد ورقةٍ قُرّبت بندًا بندًا يحمل قاعدتَه: ما كتبه كودٌ أقدم بلا علامة يُراجَع بيد
        Schema::table('boutique_returns', function (Blueprint $table) {
            if (! Schema::hasColumn('boutique_returns', 'rounding')) {
                $table->string('rounding', 8)->nullable();
            }
        });

        Schema::table('businesses', function (Blueprint $table) {
            if (! Schema::hasColumn('businesses', 'boutique_commission_before_vat_from')) {
                $table->date('boutique_commission_before_vat_from')->nullable()->after('boutiques_enabled');
            }
        });
    }

    /** ورقةٌ حملت قاعدتَها لا تُنزع عنها: مرتجعُها بعدها يُحسب بغيرها فلا يجمع إليها */
    public function down(): void
    {
        $held = array_filter([
            'boutique_settlements.rounding' => Schema::hasColumn('boutique_settlements', 'rounding') ? DB::table('boutique_settlements')->whereNotNull('rounding')->count() : 0,
            'order_items.boutique_vat_rate' => Schema::hasColumn('order_items', 'boutique_vat_rate') ? DB::table('order_items')->whereNotNull('boutique_vat_rate')->count() : 0,
            'businesses.boutique_commission_before_vat_from' => Schema::hasColumn('businesses', 'boutique_commission_before_vat_from')
                ? DB::table('businesses')->whereNotNull('boutique_commission_before_vat_from')->count() : 0,
        ]);

        if ($held !== []) {
            throw new RuntimeException(
                'لا يُتراجع عن هجرة توحيد حساب البوتيك وفيها ما يُفقد — '
                .implode('، ', array_map(fn ($t, $n) => $t.': '.$n, array_keys($held), $held))
                .'. ارجع بالكود وحده (scripts/rollback-code.sh مع ALLOW_MIGRATIONS) فتبقى الأعمدة.'
            );
        }

        Schema::table('boutique_returns', fn (Blueprint $table) => $table->dropColumn('rounding'));
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('boutique_vat_rate'));
        Schema::table('boutique_settlements', fn (Blueprint $table) => $table->dropColumn(['rounding', 'vat']));
        Schema::table('businesses', fn (Blueprint $table) => $table->dropColumn('boutique_commission_before_vat_from'));
    }
};
