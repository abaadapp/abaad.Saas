<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المصروفُ لفرعٍ بعينه، أو موزَّعٌ على فروع — أو للنشاط كلِّه كما كان.
 *
 * ═══ ولمَ ═══
 *
 * صافي ربح الفرع لا يُقرأ بلا مصروفاته، وجدولُ المصروفات لم يكن فيه ما يقول
 * لمن هي: الإيجارُ والكهرباء والرواتب كلُّها «للمتجر». فكان تقريرُ الفرع
 * يكتفي بمُجمل ربحه (انظر `Demo::reportSummary`).
 *
 * ═══ ثلاثُ حالاتٍ من مصدرٍ واحد ═══
 *
 *   النشاطُ كلُّه     ← `branch_id` فارغ، ولا صفوفَ توزيع
 *   فرعٌ محدّد        ← `branch_id` مملوء، ولا صفوفَ توزيع
 *   موزَّعٌ على فروع   ← `branch_id` فارغ، وصفوفُ توزيعٍ مجموعُها مبلغُ المصروف
 *
 * والمصروفُ الموزَّع **صفٌّ واحد** وقيدٌ واحد ودفعةٌ واحدة: صفوفُ التوزيع نسبةٌ
 * تحليليّة لا مالٌ ثانٍ. ولا ملءَ لما مضى: ما سُجّل قبل هذا يبقى للنشاط كلِّه.
 *
 * ═══ ولا مفتاحَ أجنبيًّا إلى الفروع — قرارُ المالك ═══
 *
 * الفرعُ يُحذف حذفًا ناعمًا ثمّ يُمحى نهائيًّا (`trash:purge`، و`BusinessPurge`).
 * و`nullOnDelete` يُحوّل مصروفَ الفرع إلى مصروفٍ عامّ فيُعيد كتابة ربح الفترات
 * الماضية؛ و`cascadeOnDelete` يمحو حصصَ الفرع من المصروف الموزَّع؛ و
 * `restrictOnDelete` يُسقط المحوَ المجدول. فالعمودُ رقمٌ مفهرسٌ كـ`orders.branch_id`
 * — يبقى في التاريخ كما تبقى مبيعاتُ الفرع — وملكيّةُ الفرع تُتحقَّق في الكود
 * (`App\Support\ExpenseScope`) لا في القاعدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->nullable()->after('business_id')->index();
        });

        Schema::create('expense_branch_allocations', function (Blueprint $table) {
            $table->id();
            // والحصّةُ تذهب مع مصروفها إن مُحي — لا تبقى رقمًا بلا أصل
            $table->foreignId('expense_id')->constrained('expenses')->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->index();
            // بدقّة مبلغ المصروف نفسِه: ثلاثُ خاناتٍ عشريّة
            $table->decimal('amount', 12, 3);
            $table->timestamps();

            // فرعٌ واحد مرّةً واحدة في المصروف الواحد
            $table->unique(['expense_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_branch_allocations');

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['branch_id']);
            $table->dropColumn('branch_id');
        });
    }
};
