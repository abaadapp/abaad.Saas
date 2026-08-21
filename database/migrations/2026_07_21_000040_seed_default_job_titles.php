<?php

use App\Models\JobTitle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * أدوار ذلك اليوم بتسمياتها — مكتوبةٌ هنا لا مقروءةٌ من النموذج.
     *
     * الهجرة تُنفَّذ مرّةً بحالة النظام وقتها؛ فلو قرأت قائمةً حيّة لتغيّر
     * ما تفعله كلّما غُيّرت القائمة، واختلفت قاعدةُ من رقّى عن قاعدة من
     * ثبّت من جديد.
     */
    private const ROLES_THEN = [
        'manager' => 'مدير',
        'cashier' => 'كاشير',
        'sales' => 'موظف مبيعات',
        'accountant' => 'محاسب',
        'inventory' => 'مسؤول مخزون',
        'delivery' => 'مندوب توصيل',
    ];

    public function up(): void
    {
        // الوظائف الافتراضية لكل نشاط قائم = نفس أدوار النظام المستخدمة سابقًا
        foreach (DB::table('businesses')->pluck('id') as $bid) {
            foreach (self::ROLES_THEN as $role => $label) {
                JobTitle::firstOrCreate(
                    ['business_id' => $bid, 'name' => $label],
                    ['role' => $role]
                );
            }
        }

        // اربط الموظفين الحاليين باسم وظيفة مطابق لدورهم
        // ملاحظة: admin (صاحب النشاط) ليس ضمن الوظائف القابلة للإسناد لكنه يُعرض كـ«مدير»
        $map = self::ROLES_THEN + ['admin' => 'مدير'];
        foreach (DB::table('users')->whereNotNull('role')->get(['id', 'role']) as $u) {
            $label = $map[$u->role] ?? null;
            if ($label) {
                DB::table('users')->where('id', $u->id)->update(['job_title' => $label]);
            }
        }
    }

    public function down(): void
    {
        DB::table('job_titles')->delete();
        DB::table('users')->update(['job_title' => null]);
    }
};
