<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * القيد المُرحَّل يُعكَس ولا يُعاد كتابته.
 *
 * فاتورةٌ تُصحَّح أو تُلغى بعد ترحيلها لا يجوز أن تُغيَّر سطورُ قيدها في
 * مكانها: من قرأ ميزان المراجعة أمس قرأ رقمًا، ومن يقرؤه اليوم يقرأ غيره،
 * ولا شيء في الدفتر يقول إنّ شيئًا تغيّر ولا من غيّره ولا متى. وهذا نقضٌ
 * لمعنى الدفتر: هو سجلُّ ما وقع لا صورةُ ما هو صحيح الآن.
 *
 * فالتصحيح قيدان: عكسيٌّ يُلغي الأوّل، وجديدٌ بالقيم المصحَّحة. والعمودان
 * هنا يربطان الثلاثة: `reverses_id` يقول «هذا يعكس ذاك»، و`reversed_at`
 * يقول «هذا عُكس فلا يُعكس مرّتين، ولا يُقرأ قيدًا حيًّا».
 *
 * والثلاثة تبقى معلَّقةً بمستندها الواحد (`sourceable`)، فيُقرأ تاريخ
 * الفاتورة كاملًا من الدفتر: ما رُحّل، وما عُكس، ولماذا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignId('reverses_id')->nullable()->after('sourceable_id')
                ->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable()->after('posted_at');
        });

        /*
         * نوع المصروف يعرف حسابه.
         *
         * كان كلّ مصروفٍ يُرحَّل إلى «مصروفات أخرى» (5900) مهما كان نوعه،
         * فيقرأ المحاسب قائمةَ دخلٍ فيها سطرٌ واحد اسمه «أخرى» يبتلع الإيجار
         * والكهرباء والصيانة معًا — ولا يُعرف منه أين يذهب مال المتجر.
         *
         * والربط اختياريّ: النوع بلا حساب يبقى على «أخرى» كما كان، فلا تنكسر
         * أنواعُ متجرٍ قائم ولا بياناتُه.
         */
        Schema::table('expense_types', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->after('description')
                ->constrained('accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expense_types', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_id');
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reverses_id');
            $table->dropColumn('reversed_at');
        });
    }
};
