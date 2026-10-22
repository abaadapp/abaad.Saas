<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * كشفُ البوتيك يحمل مستحقّات المكوّنات مع بيعه المنفرد — وتُسدَّد من 2160.
 *
 * ═══ صفوفُ المستحقّ ═══
 *
 *   - `boutique_settlement_id`: الورقةُ التي حملت الصفّ — يُحجز بها كما
 *     يُحجز بندُ المنفرد (`order_items.boutique_settlement_id`).
 *   - `kind`: `due` مستحقّ، و`reversal` عكسُ مستحقٍّ لم يُسوَّ (يُحمل مع
 *     أصله في ورقةٍ واحدة فيتقاصّان)، و`credit` عكسُ مستحقٍّ سُوِّي — رصيدٌ
 *     للمتجر على البوتيك يُقاصّ بما بعده أو يُستردّ.
 *   - `period_at`: الشهرُ الذي يدخل كشفَه. للمستحقّ يومُ البيع، ولعكسٍ لم
 *     يُسوَّ أصلُه يومُ أصله، ولرصيد المتجر يومُ العكس.
 *   - `kept_reason`: طلبٌ أُلغي وقطعةُ البوتيك لم تعد إلى الرفّ — المستحقُّ
 *     باقٍ، ويُقال لماذا.
 *
 * ═══ ومستندُ التسوية ═══
 *
 * جزءُ المنفرد كما كان (`gross`, `commission`, `net` ومصروفه). وجزءُ
 * المكوّنات: ما حمله (`components_due`)، وما قاصّه من رصيد المتجر
 * (`components_credit`)، والباقي (`components_net`)، ويومُ سداده. و`kind`
 * يميّز ورقةَ التسوية من سند استرداد رصيد المتجر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boutique_component_dues', function (Blueprint $table) {
            $table->unsignedBigInteger('boutique_settlement_id')->nullable()->after('amount');
            $table->string('kind', 16)->default('due')->after('source');
            $table->timestamp('period_at')->nullable()->after('occurred_at');
            $table->string('kept_reason')->nullable()->after('reason');
            $table->index('boutique_settlement_id');
            $table->index(['boutique_id', 'period_at']);
        });

        DB::table('boutique_component_dues')->whereNotNull('reverses_id')->update(['kind' => 'reversal']);
        DB::table('boutique_component_dues')->whereNull('period_at')->update(['period_at' => DB::raw('occurred_at')]);

        Schema::table('boutique_settlements', function (Blueprint $table) {
            $table->string('kind', 16)->default('settlement')->after('number');
            $table->decimal('components_due', 14, 3)->default(0)->after('net');
            $table->decimal('components_credit', 14, 3)->default(0)->after('components_due');
            $table->decimal('components_net', 14, 3)->default(0)->after('components_credit');
            $table->timestamp('components_paid_at')->nullable()->after('components_net');
            $table->string('components_method', 32)->nullable()->after('components_paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('boutique_settlements', function (Blueprint $table) {
            $table->dropColumn(['kind', 'components_due', 'components_credit', 'components_net', 'components_paid_at', 'components_method']);
        });

        Schema::table('boutique_component_dues', function (Blueprint $table) {
            $table->dropIndex(['boutique_settlement_id']);
            $table->dropIndex(['boutique_id', 'period_at']);
            $table->dropColumn(['boutique_settlement_id', 'kind', 'period_at', 'kept_reason']);
        });
    }
};
