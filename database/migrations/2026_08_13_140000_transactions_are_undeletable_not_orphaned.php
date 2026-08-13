<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * قيد الدفتر يتبع مصروفه في الحذف والاستعادة.
 *
 * كان حذف مصروفٍ يُخفيه من شاشته ويترك سطرَه في دفتر المالية: تقرأ
 * المصروفات فترى صفرًا، وتقرأ المالية فترى ٣٠٠ — رقمان متناقضان عن الشيء
 * نفسه، والقيد اليتيم يدخل المطابقة البنكية كأنّ مبلغًا خرج.
 *
 * ولا يُمحى القيد محوًا: الحذف في المصروفات ناعمٌ يُستدرَك من «المحذوفات»،
 * فلو مُحي قيدُه لعاد المصروف بلا أثرٍ في الدفتر، أو عاد بمرجعٍ جديد يكسر
 * تسلسل TRX. فيُخفى ويُستعاد معه بمرجعه نفسه.
 *
 * ويُنظَّف ما تركه العطب: قيودُ مصروفاتٍ محذوفة بقيت في الدفتر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->softDeletes();
        });

        // القيود اليتيمة: مصروفها محذوف وهي باقية
        $orphans = DB::table('expenses')
            ->whereNotNull('deleted_at')
            ->whereNotNull('transaction_id')
            ->pluck('transaction_id');

        if ($orphans->isNotEmpty()) {
            DB::table('transactions')->whereIn('id', $orphans)->update(['deleted_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
