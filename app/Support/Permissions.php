<?php

namespace App\Support;

/**
 * صلاحيات الأقسام حسب الدور داخل لوحة النشاط.
 * '*' = كل الأقسام. dashboard و pos متاحة دائمًا لأي موظف يصل للوحة.
 */
class Permissions
{
    public const MAP = [
        'admin' => ['*'],
        'manager' => ['*'],
        'accountant' => ['dashboard', 'orders', 'customers', 'finance', 'expenses', 'reports', 'profitability', 'vat', 'pos'],
        'inventory' => ['dashboard', 'products', 'categories', 'inventory', 'suppliers', 'purchases', 'reports', 'pos'],
        'sales' => ['dashboard', 'orders', 'customers', 'products', 'pos'],
        'cashier' => ['dashboard', 'pos'],
        'delivery' => ['dashboard', 'orders', 'pos'],
    ];

    public static function abilities(?string $role): array
    {
        return self::MAP[$role] ?? ['*'];
    }

    public static function allows(?string $role, string $section): bool
    {
        if ($role === 'super_admin') {
            return true;
        }
        $abilities = self::abilities($role);
        if (in_array('*', $abilities, true)) {
            return true;
        }
        if (in_array($section, ['dashboard', 'pos', 'branch'], true)) {
            return true;
        }
        return in_array($section, $abilities, true);
    }

    /** استخراج القسم من اسم المسار: admin.products.index → products */
    public static function sectionFromRoute(?string $route): string
    {
        if (! $route) {
            return 'dashboard';
        }
        if (str_starts_with($route, 'pos.')) {
            return 'pos';
        }
        $parts = explode('.', $route);
        return $parts[1] ?? 'dashboard';
    }
}
