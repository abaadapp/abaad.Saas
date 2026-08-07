<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حذف الفرع كان يهدم ما حوله، لا الفرع وحده.
 *
 * `$branch->delete()` صفٌّ واحد بلا أي حارس — لا يسأل عن محتوى الفرع ولا
 * يمنع حذف فرعٍ فيه تاريخ. وقيود المفاتيح الأجنبية تكمل الهدم:
 *
 *   pos_devices.branch_id       ON DELETE CASCADE   ← تُمحى صناديق الفرع كلّها
 *   branch_user.branch_id       ON DELETE CASCADE   ← تُمحى إذون الموظفين
 *   inventory_movements         ON DELETE SET NULL  ← يُيتَّم سجلّ حركة المخزون
 *   orders / shifts / branch_stocks  بلا قيد        ← تبقى معلّقةً على رقمٍ لا وجود له
 *
 * أي أن ضغطةً واحدة تُلغي تفعيل كل صندوقٍ في الفرع، وتمحو إذون موظفيه، وتترك
 * مبيعاته وورديّاته تشير إلى فرعٍ شبح — يُعرض «—» إلى الأبد. ولا شيء من هذا
 * يظهر وقت الضغط.
 *
 * الحذف الناعم يُبطل هذا كلّه: الصفّ يبقى، فلا يتسلسل حذفٌ ولا تتعلّق إشارة.
 *
 * والعميل معه: لا زرّ يحذفه اليوم، والعمود حارسٌ ليوم يُضاف — ولا يجوز أن
 * يُضاف زرٌّ يمحو نقاط ولاءٍ وعناوين وتاريخ شراء بلا رجعة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
