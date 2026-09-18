<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * يعرّف كلَّ جهازٍ بحسابه البنكيّ، ويختم كلَّ بيعةٍ ومعاملةٍ بالحساب الذي دخلته.
 *
 * كان البيعُ بالبطاقة يذهب إلى ورقة الحساب **الرئيسيّ** دائمًا، وللمتجر قد
 * يكون حسابان وجهازا شبكةٍ لكلٍّ بنكُه. فيقرأ التاجر رصيدَ حسابٍ لا يتحرّك
 * ومالَه كلَّه في ورقة الآخر — وهو العطب نفسه الذي عُولج يوم صار لكلّ حسابٍ
 * ورقة، وبقي نصفُه: الورقةُ صارت لكلّ حساب، والوجهةُ بقيت واحدة.
 *
 * وعلى الإنتاج متجرٌ له حسابان بالفعل.
 *
 * ═══ ولماذا على الجهاز لا على الشاشة ═══
 *
 * جهازُ الشبكة موصولٌ ببنكٍ بعينه في العتاد — لا يختاره الكاشير ولا يبدّله
 * في منتصف اليوم. وسؤالُ الكاشير عنه يفتح بابًا لخطأ لا يُكتشف إلا في
 * المطابقة بعد شهر.
 *
 * ═══ ولماذا يُختم على الطلب والمعاملة أيضًا ═══
 *
 * المدير ينقل الجهاز إلى بنكٍ آخر، فتصير قراءةُ الجهاز اليوم تقول عن بيعةٍ
 * قديمة غيرَ ما وقع. واللقطةُ تُقرأ في موضعين: الدفتر يُرحَّل إليها،
 * ومطابقةُ كشف البنك تُرشِّح بها — فكشفُ حسابٍ لا يُطابَق بحركةٍ لم تمرّ به.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_devices', function (Blueprint $table) {
            $table->foreignId('bank_account_id')->nullable()->after('branch_id')
                ->constrained('bank_accounts')->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('bank_account_id')->nullable()->after('pos_device_id')
                ->constrained('bank_accounts')->nullOnDelete();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('bank_account_id')->nullable()->after('branch_id')
                ->constrained('bank_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['pos_devices', 'orders', 'transactions'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('bank_account_id');
            });
        }
    }
};
