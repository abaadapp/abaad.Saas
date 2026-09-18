<?php

namespace App\Models;

use App\Support\Permissions;
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
    ];

    public const OPERATORS = ['>', '<'];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * المسار الذي تفتحه نقرة التنبيه.
     *
     * ═══ وقائمتان لسؤالٍ واحد ═══
     *
     * كانت هنا قائمةٌ مكتوبةٌ باليد تُقابل القسمَ بمساره — و`Permissions::ROUTES`
     * تقول الشيء نفسه للّوحة كلِّها. فقسمٌ في تلك ولا في هذه (الموقع، المورّدون،
     * التسويق، لوحة التجهيز) كان يسقط إلى اللوحة: يكتب التاجرُ تنبيهًا على
     * «الموقع الإلكتروني» فتقوده نقرتُه إلى لوحة التحكّم.
     *
     * وصار ذلك أخطرَ منذ صار الجرسُ يحجب ما لا يُفتح: القسمُ يحرس، والوجهةُ
     * تُفتح — فلو افترقا حُرس صفٌّ بقسمٍ وفُتح غيرُه، وردّ الخادمُ من أُذن له.
     *
     * فالمصدرُ واحد، ويبقى استثناءان مقصودان:
     *
     *   `reports` → ملخّص المبيعات لا فهرس التقارير: التنبيه على رقمٍ يقود
     *   إلى الرقم، ومن نقر «مبيعات اليوم تجاوزت كذا» لا يريد قائمةً يختار منها.
     *
     *   `profitability` → لا بابَ لها في `ROUTES` أصلًا، ولها شاشةٌ قائمة.
     */
    public const URL_OVERRIDES = [
        'reports' => 'admin.reports.sales',
        'profitability' => 'admin.profitability.index',
    ];

    public function url(): string
    {
        $name = self::URL_OVERRIDES[$this->section]
            ?? Permissions::ROUTES[$this->section]
            ?? 'admin.dashboard';

        return route($name);
    }
}
