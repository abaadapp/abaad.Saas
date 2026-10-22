<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * رصيدُ المتجر على البوتيك يُقاصّ أجزاءً — وكلُّ جزءٍ سطرٌ مسجَّل.
 *
 * ومعه حالُ كلّ قطعةٍ بقي مستحقُّها عند الإلغاء (`kept_state`).
 *
 * كان صفُّ الرصيد (`boutique_component_dues.kind = credit`) يُحجز كلُّه
 * بورقةٍ واحدة، فرصيدٌ بسبعة لا يُقاصّ به مستحقٌّ بثلاثة: ينتظر أو يُستردّ.
 * وصار لكلّ مقاصّةٍ أو استردادٍ سطرٌ هنا: من أيّ رصيد، وبأيّ ورقة، وكم.
 * والباقي من الرصيد = قيمتُه ناقصًا ما سُجّل عليه — يُقرأ ولا يُخزَّن.
 *
 * ولا يُقاصّ الرصيدُ الواحد بالورقة الواحدة مرّتين (فهرسٌ فريد)، ولا
 * بأكثر من باقيه: الورقةُ تقفل صفوفَ الرصيد قبل أن تكتب — انظر
 * `BoutiqueDues::claim`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // وحالُ القطعة التي بقي مستحقُّها بعد الإلغاء: لم تعد (`lost`) أو لم يُتحقَّق منها (`unverified`)
        Schema::table('boutique_component_dues', function (Blueprint $table) {
            $table->string('kept_state', 16)->nullable()->after('kept_reason');
        });

        Schema::create('boutique_credit_offsets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('boutique_id');
            $table->foreignId('credit_id')->constrained('boutique_component_dues')->cascadeOnDelete();
            $table->foreignId('boutique_settlement_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 3);
            $table->timestamps();

            $table->unique(['credit_id', 'boutique_settlement_id']);
            $table->index(['business_id', 'boutique_id']);
            $table->index('boutique_settlement_id');
        });

        // ما حُجز كاملًا قبل هذا الجدول يصير سطرًا فيه بقيمته — والصفُّ يعود حرًّا
        foreach (DB::table('boutique_component_dues')->where('kind', 'credit')->whereNotNull('boutique_settlement_id')->get() as $row) {
            DB::table('boutique_credit_offsets')->insert([
                'business_id' => $row->business_id, 'boutique_id' => $row->boutique_id,
                'credit_id' => $row->id, 'boutique_settlement_id' => $row->boutique_settlement_id,
                'amount' => -(float) $row->amount, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('boutique_component_dues')->where('id', $row->id)->update(['boutique_settlement_id' => null]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('boutique_credit_offsets');

        Schema::table('boutique_component_dues', function (Blueprint $table) {
            $table->dropColumn('kept_state');
        });
    }
};
