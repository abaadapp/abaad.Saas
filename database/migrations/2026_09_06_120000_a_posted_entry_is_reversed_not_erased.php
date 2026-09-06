<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * القيد المُرحَّل يُعكس ولا يُمحى — وعمودان يحملان أثر ذلك.
 *
 * `reverses_id`: أيَّ قيدٍ يعكس هذا القيد. و`reversed_at`: متى عُكس ذاك.
 * وبهما يُقرأ التاريخ كاملًا: الأصلُ في مكانه، وعكسُه إلى جانبه، والصلةُ
 * بينهما مكتوبة — بدل أن يختفي الأصل فلا يعرف قارئُ الميزان أنّ شيئًا كان.
 *
 * والعمودان يُفحصان قبل أن يُضافا: قاعدةُ الإنتاج تحملهما منذ ٥ سبتمبر —
 * ركّبتهما هجرةٌ من فرعٍ لم يُدمج، بقي ملفُّها على الخادم فقرأه `migrate`.
 * فلو أُضيفا بلا فحصٍ سقطت الهجرة على عمودٍ موجود، ولو تُركا لم تُبنَ
 * قاعدةُ الاختبارات أصلًا. والفحصُ يجعل الطريقين يلتقيان عند شكلٍ واحد.
 *
 * ولا يُعاد `expense_types.account_id` من تلك الهجرة: مفتاحُ حساب النوع في
 * هذا النظام `account_key`، وعمودٌ ثانٍ لنفس المعنى يفترق عنه يومًا. وهو
 * على الإنتاج أثرٌ من ذلك الفرع، لا يقرؤه شيء.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('journal_entries', 'reverses_id')) {
                $table->foreignId('reverses_id')->nullable()->after('sourceable_id')
                    ->constrained('journal_entries')->nullOnDelete();
            }

            if (! Schema::hasColumn('journal_entries', 'reversed_at')) {
                $table->timestamp('reversed_at')->nullable()->after('posted_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            if (Schema::hasColumn('journal_entries', 'reverses_id')) {
                $table->dropConstrainedForeignId('reverses_id');
            }

            if (Schema::hasColumn('journal_entries', 'reversed_at')) {
                $table->dropColumn('reversed_at');
            }
        });
    }
};
