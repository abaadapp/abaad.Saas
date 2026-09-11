<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الشيكُ ليس مالًا حتّى يُصرَف.
 *
 * ═══ ما كان ═══
 *
 * «شيك» وسيلةُ تحصيلٍ كغيرها: تُسجَّل فيُرحَّل القيدُ فورًا **مدين البنك /
 * دائن ذمم العملاء**. أي أنّ الدفتر يقول إنّ المال في البنك لحظةَ استلام
 * الورقة — وقد يكون بتاريخٍ بعد شهرين، وقد يرتدّ.
 *
 * فيقرأ التاجر رصيدًا بنكيًّا لا وجود له، وعميلًا سدّد ولم يسدّد. ولا يكتشف
 * حتّى يطابق كشف الحساب — إن طابقه.
 *
 * ═══ وما صار ═══
 *
 * للشيك عمرٌ: **تحت التحصيل** ← **محصَّل** أو **مرتجع**. وتحت التحصيل يجلس
 * المال في حسابٍ وسيطٍ اسمه «شيكات تحت التحصيل» — أصلٌ حقيقيّ وليس نقدًا،
 * فالذمّةُ انتقلت من العميل إلى الورقة ولم تصل البنك بعد.
 *
 * ═══ والقديمُ يُعدّ محصَّلًا ═══
 *
 * شيكاتٌ سُجّلت قبل هذه الهجرة قيدُها في الدفتر يقول «بنك». فلو وُسمت «تحت
 * التحصيل» لَقال النظام عنها ما لا يقوله دفترُها — حالٌ تُعرض تخالف قيدًا
 * مُرحَّلًا، ولا يُعرف أيُّهما الصادق. فتُوسم **محصَّلة** بتاريخ قبضها:
 * وهو ما عاملها به النظام فعلًا منذ كُتبت.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_payments', function (Blueprint $table) {
            /*
             * و`null` لغير الشيكات — لا «محصَّل».
             *
             * النقدُ لا يُحصَّل ولا يرتدّ، ووسمُه بحالِ شيكٍ يجعل كلَّ عدٍّ
             * للشيكات يبتلع كلَّ تحصيلات المتجر.
             */
            $table->string('cheque_status', 20)->nullable()->after('method');
            $table->date('cheque_due_at')->nullable()->after('cheque_status');
            $table->timestamp('cheque_settled_at')->nullable()->after('cheque_due_at');
            /* سببُ الارتداد — يُقرأ بعد شهرٍ حين يُسأل «ولماذا رجع؟» */
            $table->string('cheque_note', 200)->nullable()->after('cheque_settled_at');

            /* تُقرأ معًا في شاشة الشيكات: ما تحت التحصيل مرتّبًا باستحقاقه */
            $table->index(['business_id', 'cheque_status', 'cheque_due_at'], 'cp_cheque_watch');
        });

        /*
         * والقديمُ يُوسم محصَّلًا بتاريخ قبضه — لا بتاريخ اليوم.
         *
         * تاريخُ اليوم يجعل شيكًا قُبض في يناير يظهر محصَّلًا في سبتمبر،
         * فيقرأ من يبحث عن حركة يناير أنّ شيئًا لم يقع فيه.
         */
        DB::table('customer_payments')
            ->where('method', 'شيك')
            ->whereNull('cheque_status')
            ->update([
                'cheque_status' => 'محصَّل',
                'cheque_settled_at' => DB::raw('occurred_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('customer_payments', function (Blueprint $table) {
            $table->dropIndex('cp_cheque_watch');
            $table->dropColumn(['cheque_status', 'cheque_due_at', 'cheque_settled_at', 'cheque_note']);
        });
    }
};
