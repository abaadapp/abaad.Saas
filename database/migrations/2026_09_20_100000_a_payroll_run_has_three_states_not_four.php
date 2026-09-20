<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * مسيرةُ الرواتب ثلاثُ حالات لا أربع — و«مدفوعة» ليست منها.
 *
 * بذرةُ المتجر التجريبيّ كانت تكتب «مدفوعة» في `payroll_runs.status`، والنظامُ
 * يعرف «مسودة | معتمدة | مصروفة» وحدَها. قيس على الإنتاج: ستُّ مسيراتٍ من سبع
 * لا تظهر في شاشة الصرف، ولا لونَ لحالها في القائمة. وطريقةُ الدفع في
 * سطورها «تحويل» والصندوقُ يكتب «تحويل بنكي».
 *
 * فتُردّ الصفوفُ إلى الألفاظ التي يقرؤها النظام. والدفترُ لا يُمسّ: قيودُها
 * كُتبت صحيحةً من أوّل يوم.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('payroll_runs')->where('status', 'مدفوعة')->update(['status' => 'مصروفة']);
        DB::table('payroll_lines')->where('payment_method', 'تحويل')->update(['payment_method' => 'تحويل بنكي']);
    }

    public function down(): void
    {
        // لا رجوع: «مدفوعة» لفظٌ لم يكن صحيحًا يومًا
    }
};
