<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\ExpenseType;
use App\Models\JobTitle;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Support\SeedData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * بذرة نظيفة — نظام خالٍ من بيانات العرض.
 *
 * تُنشئ الحدّ الأدنى الذي يحتاجه النظام ليعمل ويُسجَّل الدخول إليه فقط:
 * الباقات، حساب مدير منصة، متجر واحد فارغ بمالكه، وافتراضيات المتجر.
 *
 * لا منتجات ولا طلبات ولا عملاء ولا معاملات ولا موردين — تُدخلها بنفسك.
 *
 * لاستعادة بيانات العرض الكاملة (12 متجرًا ومنتجات وطلبات):
 *   php artisan db:seed --class=DemoSeeder
 */
class DatabaseSeeder extends Seeder
{
    /** بيانات الدخول — غيّرها من .env قبل النشر */
    private function cfg(string $key, string $default): string
    {
        return (string) env($key, $default);
    }

    public function run(): void
    {
        /* ---------------- الباقات (يحتاجها إنشاء المتاجر والاشتراكات) ---------------- */
        $limits = [
            'الباقة الأساسية' => [1, 3, 100],
            'الباقة الاحترافية' => [3, 15, 100000],
            'باقة المؤسسات' => [999, 999, 1000000],
        ];
        $planId = null;
        foreach (SeedData::plans() as $p) {
            [$branches, $employees, $products] = $limits[$p['name']] ?? [1, 3, 100];
            $plan = Plan::create([
                'name' => $p['name'],
                'monthly_price' => $p['monthly'],
                'yearly_price' => $p['yearly'],
                'max_branches' => $branches,
                'max_employees' => $employees,
                'max_products' => $products,
                'features' => $p['features'],
                'color' => $p['color'],
                'is_popular' => $p['popular'],
            ]);
            $planId ??= $plan->id;
        }

        /* ---------------- مدير المنصة ---------------- */
        User::create([
            'business_id' => null,
            'name' => 'مدير المنصة',
            'email' => $this->cfg('SEED_SUPER_EMAIL', 'super@abadpos.com'),
            'role' => 'super_admin',
            'password' => Hash::make($this->cfg('SEED_SUPER_PASSWORD', 'password')),
            'status' => 'نشط',
            'last_login_at' => now(),
        ]);

        /* ---------------- متجر واحد فارغ ---------------- */
        $ownerName = $this->cfg('SEED_OWNER_NAME', 'مالك النشاط');
        $business = Business::create([
            'name' => $this->cfg('SEED_BUSINESS_NAME', 'متجري'),
            'type' => 'عام',
            'owner_name' => $ownerName,
            'phone' => '',
            'email' => $this->cfg('SEED_ADMIN_EMAIL', 'admin@abadpos.com'),
            'country' => 'عُمان',
            'city' => '',
            'address' => '',
            'plan_id' => $planId,
            'status' => 'نشط',
            'branches_count' => 1,
            'starts_at' => now(),
            'ends_at' => now()->addYear(),
        ]);

        User::create([
            'business_id' => $business->id,
            'name' => $ownerName,
            'email' => $this->cfg('SEED_ADMIN_EMAIL', 'admin@abadpos.com'),
            'role' => 'admin',
            'password' => Hash::make($this->cfg('SEED_ADMIN_PASSWORD', 'password')),
            'status' => 'نشط',
            'branch' => 'الفرع الرئيسي',
            'job_title' => 'مدير',
            'last_login_at' => now(),
        ]);

        /* ---------------- افتراضيات المتجر ---------------- */
        // فرع رئيسي — نقطة البيع وحركات المخزون تحتاج فرعًا واحدًا على الأقل
        Branch::create([
            'business_id' => $business->id,
            'name' => 'الفرع الرئيسي',
            'phone' => '',
            'address' => '',
        ]);

        // العملة الأساسية — واجهة العرض تحتاج عملة أساسية واحدة
        Currency::create([
            'business_id' => $business->id,
            'code' => 'OMR',
            'name' => 'ريال عماني',
            'symbol' => 'ر.ع',
            'rate' => 1,
            'is_base' => true,
            'active' => true,
        ]);

        // الوظائف الافتراضية مرتبطة بصلاحيات النظام
        foreach (JobTitle::ROLES as $roleKey => $roleLabel) {
            JobTitle::create([
                'business_id' => $business->id,
                'name' => $roleLabel,
                'role' => $roleKey,
            ]);
        }

        // أنواع المصروفات الافتراضية
        $expenseTypes = [
            'إيجار' => 'إيجار المحل والمستودعات',
            'رواتب' => 'رواتب وأجور الموظفين',
            'كهرباء وماء' => 'فواتير الكهرباء والمياه',
            'مواد خام' => 'شراء المواد والبضاعة',
            'تسويق' => 'الإعلانات والحملات التسويقية',
            'صيانة' => 'صيانة المعدات والمحل',
            'نقل وتوصيل' => 'مصاريف الشحن والتوصيل',
        ];
        foreach ($expenseTypes as $name => $description) {
            ExpenseType::create([
                'business_id' => $business->id,
                'name' => $name,
                'description' => $description,
            ]);
        }

        /* ---------------- الإعدادات ---------------- */
        $settings = [
            [null, 'platform_name', 'Abad POS'],
            [null, 'currency', 'ريال عماني'],
            [null, 'currency_decimals', '3'],
            [null, 'vat_rate', '5'],
            [$business->id, 'business_name', $business->name],
            [$business->id, 'vat_rate', '5'],
        ];
        foreach ($settings as [$businessId, $key, $value]) {
            Setting::create(['business_id' => $businessId, 'key' => $key, 'value' => $value]);
        }
    }
}
