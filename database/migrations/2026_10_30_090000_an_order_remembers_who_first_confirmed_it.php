<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الطلبُ يحفظ من أكّده أوّلَ مرّةٍ ومتى — `OrderTransition::apply`.
 *
 * ═══ ولمَ أعمدةٌ لا سجلُّ النشاط ═══
 *
 * سجلُّ النشاط يحفظ نقلَ الحالة جملةً تُقرأ («نقل الطلب … من «جديد» إلى
 * «مؤكّد»»)، لا حقلًا يُسأل. واستخراجُ المؤكِّد من نصٍّ تغيّرت صيغتُه بين
 * نسخةٍ وأخرى ظنٌّ لا دليل. فتُكتب الهويّةُ والوقتُ لحظةَ النقل، تحت قفل
 * الطلب نفسِه، مرّةً واحدة.
 *
 * ═══ و`employee_name` لا يُمسّ ═══
 *
 * يقول من أنشأ الطلب («الموقع الإلكتروني» أو الكاشير)، والتأكيدُ شيءٌ آخر.
 *
 * ═══ والطلباتُ القديمة لا تُملأ ═══
 *
 * لا اسمَ يُخترع لطلبٍ أُكّد قبل هذه الهجرة: يبقى فارغًا، وتقول الشاشة
 * «مؤكّد — الموظف غير معروف». ولا يُمسّ صفٌّ قائم: أعمدةٌ فارغةٌ وفهرس.
 *
 * والاسمُ لقطةٌ مع المعرّف: موظّفٌ حُذف حسابُه (`nullOnDelete`) يبقى اسمُه
 * على ما أكّده.
 */
return new class extends Migration
{
    // الفهرسُ يُبنى متزامنًا على PostgreSQL — ولا يجري ذلك داخل معاملة
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'confirmed_at')) {
                $table->timestamp('confirmed_at')->nullable();
            }
            if (! Schema::hasColumn('orders', 'confirmed_by')) {
                $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('orders', 'confirmed_by_name')) {
                $table->string('confirmed_by_name', 120)->nullable();
            }
        });

        // عمودُ ربطٍ بلا فهرسٍ يمسح الطلباتِ كلَّها حين يُحذف مستخدم — انظر `EveryForeignKeyColumnIsIndexedTest`
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS orders_confirmed_by_index ON orders (confirmed_by)');
        } elseif (! Schema::hasIndex('orders', 'orders_confirmed_by_index')) {
            Schema::table('orders', fn (Blueprint $table) => $table->index('confirmed_by', 'orders_confirmed_by_index'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'confirmed_by')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropForeign(['confirmed_by']);
                $table->dropIndex('orders_confirmed_by_index');
            });
        }

        foreach (['confirmed_by_name', 'confirmed_by', 'confirmed_at'] as $column) {
            if (Schema::hasColumn('orders', $column)) {
                Schema::table('orders', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
