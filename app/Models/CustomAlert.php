<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomAlert extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'threshold' => 'decimal:3',
            'due_at' => 'datetime',
            'active' => 'boolean',
        ];
    }

    /**
     * المقاييس التي يجوز بناء قاعدة عليها.
     *
     * قائمة مغلقة عمدًا: كل مقياس هنا يقابله استعلامٌ مكتوب في الكود، فلا
     * يصل شيءٌ من إدخال المستخدم إلى قاعدة البيانات كشرط.
     *
     * `unit` تُستعمل في الواجهة لتعرف هل الحدّ مبلغٌ أم عدد أيام أم عدد.
     */
    public const METRICS = [
        'daily_sales' => ['label' => 'مبيعات اليوم', 'unit' => 'money', 'section' => 'reports'],
        'monthly_expenses' => ['label' => 'مصروفات الشهر', 'unit' => 'money', 'section' => 'expenses'],
        'pending_orders' => ['label' => 'الطلبات المعلّقة', 'unit' => 'count', 'section' => 'orders'],
        'low_stock_products' => ['label' => 'المنتجات تحت حد التنبيه', 'unit' => 'count', 'section' => 'inventory'],
        'dormant_customers' => ['label' => 'العملاء الراكدون', 'unit' => 'count', 'section' => 'customers'],
        'open_purchase_orders' => ['label' => 'أوامر شراء لم تُستلم', 'unit' => 'count', 'section' => 'purchases'],
        'today_profit' => ['label' => 'صافي ربح اليوم', 'unit' => 'money', 'section' => 'profitability'],
        // وردية تُترك مفتوحة لا يلاحظها أحد: تتراكم ويصير فرقها بلا معنى
        'open_shift_hours' => ['label' => 'ساعات الوردية المفتوحة', 'unit' => 'count', 'section' => 'pos'],
    ];

    public const OPERATORS = ['>', '<'];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    /** المسار الذي تفتحه نقرة التنبيه */
    public function url(): string
    {
        $routes = [
            'reports' => 'admin.reports.index',
            'expenses' => 'admin.expenses.index',
            'orders' => 'admin.orders.index',
            'inventory' => 'admin.inventory.index',
            'customers' => 'admin.customers.index',
            'purchases' => 'admin.purchases.index',
            'profitability' => 'admin.profitability.index',
            'products' => 'admin.products.index',
            'employees' => 'admin.employees.index',
            'finance' => 'admin.finance.index',
        ];

        return route($routes[$this->section] ?? 'admin.dashboard');
    }
}
