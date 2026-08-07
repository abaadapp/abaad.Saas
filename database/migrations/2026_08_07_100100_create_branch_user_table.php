<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * فروع الموظف — علاقة متعدّد لمتعدّد.
 *
 * كان `users.branch` عمودًا نصيًّا حرًّا يُكتب فيه اسم فرعٍ ولا يُقارن بشيء:
 * تغييرُ اسم الفرع يتركه معلّقًا على اسمٍ لا وجود له، والموظف الذي يعمل في
 * فرعين لا يمكن التعبير عنه أصلًا. وأهمّ من ذلك: لم يكن يُفحص عند الدخول —
 * فكاشير الخوير يدخل على جهاز السيب ويبيع.
 *
 * والعمود القديم يبقى ولا يُحذف: تقارير وشاشات قائمة تقرأه، وحذفُه تغييرٌ
 * كاسر لا داعي له. يبقى «الفرع الأساسي» للعرض، والجدول هذا مصدرَ الإذن.
 *
 * وقاعدة الفراغ مقصودة: صفوفٌ صفر = كل فروع متجره.
 * موظفوك الحاليون كلّهم بلا صفوف، فلو كان الفارغ يعني «لا فرع» لأُقفل كلّ
 * كاشير صباح النشر — ترقيةٌ تُوقف المحلّ ليست ترقية.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // لا يُمنح الإذن مرّتين — والتكرار كان سيضاعف الصفوف عند كل حفظ
            $table->unique(['user_id', 'branch_id']);
            $table->index('branch_id');
        });

        /*
         * نقل ما يمكن نقله من العمود النصّي.
         *
         * الاسم يُطابَق داخل متجر المستخدم وحده — «الفرع الرئيسي» اسمٌ شائع
         * في كل المتاجر، ومطابقته بلا قيد كانت ستمنح موظفًا فرعًا في متجر
         * غيره. وما لا يطابق اسمًا قائمًا يُترك بلا صفوف، أي «كل الفروع» —
         * وهو سلوكه اليوم بالضبط، فلا يفقد أحدٌ وصولًا كان يملكه.
         */
        $users = DB::table('users')
            ->whereNotNull('branch')->where('branch', '!=', '')
            ->whereNotNull('business_id')
            ->get(['id', 'business_id', 'branch']);

        foreach ($users as $u) {
            $branchId = DB::table('branches')
                ->where('business_id', $u->business_id)
                ->where('name', $u->branch)
                ->value('id');

            if ($branchId) {
                DB::table('branch_user')->insert([
                    'user_id' => $u->id,
                    'branch_id' => $branchId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_user');
    }
};
