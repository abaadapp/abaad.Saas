<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * حسابٌ بنكيّ واحد لكل نشاط صار حسابات.
 *
 * الجدول أُنشئ بحسابٍ واحد لا غير (firstOrCreate على business_id)، وأكثرُ من
 * تاجر يفتح حسابين: واحدٌ للتحصيل وآخر للمصروفات، أو حسابٌ بالريال وآخر
 * بالدولار. فكان الكشف يُستورد إلى وعاءٍ واحد وتختلط الحركتان فلا تُطابق
 * أيّهما.
 *
 * و`account_id` يربط الحساب البنكي بورقته في شجرة الحسابات: بلا هذا الربط
 * يبقى الرصيد رقمًا في شاشةٍ لا يُقرأ في ميزان المراجعة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            // اسمٌ يميّزه في القوائم — «التحصيل» و«المصروفات» أوضح من رقمين
            $table->string('label')->nullable();
            $table->boolean('active')->default(true);
            /*
             * الحساب الرئيسي: وجهةُ ما لا يُنسب إلى حسابٍ بعينه.
             *
             * بلا واحدٍ يُقصد افتراضًا كانت الشاشات القديمة (المطابقة، كشف
             * الحساب) تأخذ «أوّل ما وجدت» — وأوّلُ ما توجد يتبدّل بترتيب
             * الصفوف، فيتبدّل الكشف بلا أن يمسّه أحد.
             */
            $table->boolean('is_primary')->default(false);
        });

        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->cascadeOnDelete();
        });

        /*
         * ما كان قائمًا يصير رئيسيًّا، وأسطرُه تُنسب إليه.
         *
         * بلا هذا يفتح التاجر الشاشة بعد الترقية فيجد كشفًا بلا حساب: أسطرٌ
         * مستوردة لا تظهر تحت أيّ حسابٍ من حساباته.
         */
        foreach (DB::table('bank_accounts')->orderBy('id')->get() as $account) {
            DB::table('bank_accounts')->where('id', $account->id)->update(['is_primary' => true]);
            DB::table('bank_statement_lines')
                ->where('business_id', $account->business_id)
                ->whereNull('bank_account_id')
                ->update(['bank_account_id' => $account->id]);
        }
    }

    public function down(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_account_id');
        });

        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_id');
            $table->dropColumn(['label', 'active', 'is_primary']);
        });
    }
};
