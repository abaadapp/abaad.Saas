<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سطرُ القيد يُوجد بقيده — فهرسٌ على `journal_lines.journal_entry_id`.
 *
 * ═══ ما قيس ═══
 *
 * `foreignId()->constrained()` لا يبني فهرسًا على PostgreSQL، فلا فهرسَ على
 * هذا العمود منذ `accounting_core`. فكلُّ قراءةٍ تبدأ بالقيود — متجرٌ ومدّة
 * بفهرس `(business_id, entry_date)` — ثمّ تطلب سطورَها، تمسح جدولَ السطور
 * كلَّه لكلّ المتاجر.
 *
 * وقيس على PostgreSQL بأربعمئة ألف قيدٍ وثمانمئة ألف سطرٍ لخمسة متاجر
 * (`CostsAndLosses::amounts` لشهرٍ واحد):
 *
 *   متجرٌ صغير (٥٪ من الدفتر):  Parallel Seq Scan على السطور، 34.9ms
 *                              ← Index Scan بالقيد، 2.2ms
 *   تفصيلُ صفٍّ لمتجرٍ صغير:    35.3ms ← 2.4ms
 *   المتجرُ الأكبر (٨٠٪):       يبقى المسحُ أرخصَ عند المخطِّط، 39ms ← 26ms
 *
 * فالكلفةُ بلا الفهرس تكبر بحجم المنصّة كلِّها لا بحجم المتجر. ويقرأ العمودَ
 * نفسَه غيرُ التقرير: `JournalEntry::lines()` في كلّ ترحيلٍ (العدّ والمجموعان
 * في `post`)، وكلّ ربطٍ بين القيد وسطوره.
 *
 * فهرسٌ وحده — لا عمودٌ ولا بيانٌ يتغيّر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->index('journal_entry_id');
        });
    }

    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropIndex(['journal_entry_id']);
        });
    }
};
