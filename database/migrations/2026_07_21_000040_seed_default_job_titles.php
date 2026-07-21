<?php

use App\Models\JobTitle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // الوظائف الافتراضية لكل نشاط قائم = نفس أدوار النظام المستخدمة سابقًا
        foreach (DB::table('businesses')->pluck('id') as $bid) {
            foreach (JobTitle::ROLES as $role => $label) {
                JobTitle::firstOrCreate(
                    ['business_id' => $bid, 'name' => $label],
                    ['role' => $role]
                );
            }
        }

        // اربط الموظفين الحاليين باسم وظيفة مطابق لدورهم
        // ملاحظة: admin (صاحب النشاط) ليس ضمن الوظائف القابلة للإسناد لكنه يُعرض كـ«مدير»
        $map = JobTitle::ROLES + ['admin' => 'مدير'];
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
