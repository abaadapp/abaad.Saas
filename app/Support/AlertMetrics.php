<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\CustomAlert;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Product;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;

/**
 * قيم المقاييس التي تُبنى عليها التنبيهات المخصّصة.
 *
 * كل مقياس استعلامٌ مكتوب هنا لا صيغة تصل من المستخدم — انظر
 * CustomAlert::METRICS.
 */
class AlertMetrics
{
    /** كم يومًا بلا شراء يُعدّ العميل بعدها راكدًا (قابل للضبط من الإعدادات) */
    public static function dormantDays(int $businessId): int
    {
        $value = \App\Models\Setting::where('business_id', $businessId)
            ->where('key', 'dormant_customer_days')->value('value');

        return max(1, (int) ($value ?: 60));
    }

    /**
     * العملاء الراكدون: اشتروا يومًا ثم انقطعوا.
     *
     * من لم يشترِ قطّ ليس راكدًا بل لم يبدأ — خلطهما يُغرق التنبيه بأسماء لا
     * معنى لمتابعتها. ولذلك يُشترط وجود طلبٍ سابق.
     */
    public static function dormantCustomers(int $businessId, ?int $days = null)
    {
        $days = $days ?? self::dormantDays($businessId);
        $cutoff = now()->subDays($days);

        $lastOrder = Order::selectRaw('customer_id, MAX(ordered_at) as last_at')
            ->where('business_id', $businessId)
            ->where('is_held', false)
            ->whereNotNull('customer_id')
            ->groupBy('customer_id');

        return Customer::where('customers.business_id', $businessId)
            ->joinSub($lastOrder, 'o', fn ($j) => $j->on('o.customer_id', '=', 'customers.id'))
            ->where('o.last_at', '<', $cutoff)
            ->orderBy('o.last_at')
            ->select('customers.*', 'o.last_at')
            ->get();
    }

    /**
     * البطاقات الاختيارية للوحة — مقاييس التقارير نفسها معروضةً كبطاقات.
     *
     * المصدر واحد مقصود: هذه المقاييس تُبنى عليها التنبيهات المخصّصة أصلًا،
     * فلو بُنيت للّوحة قائمةٌ ثانية لافترقتا عند أوّل تعديل — يقول التنبيه
     * رقمًا وتقول البطاقة غيره، ولا يعرف التاجر أيّهما يصدّق.
     *
     * وكلٌّ منها يقود إلى قسمه: البطاقة سؤال، وقسمها هو الجواب المفصَّل.
     */
    public static function catalog(int $businessId): array
    {
        $icons = [
            'daily_sales' => 'shopping-bag', 'monthly_expenses' => 'arrow-down-circle',
            'pending_orders' => 'clock', 'low_stock_products' => 'alert-triangle',
            'dormant_customers' => 'user-x', 'open_purchase_orders' => 'clipboard-list',
            'today_profit' => 'piggy-bank', 'open_shift_hours' => 'clock',
        ];
        $colors = [
            'daily_sales' => 'primary', 'monthly_expenses' => 'danger',
            'pending_orders' => 'warning', 'low_stock_products' => 'warning',
            'dormant_customers' => 'secondary', 'open_purchase_orders' => 'info',
            'today_profit' => 'success', 'open_shift_hours' => 'warning',
        ];

        return collect(\App\Models\CustomAlert::METRICS)
            ->map(function ($m, $key) use ($businessId, $icons, $colors) {
                $value = self::value($key, $businessId);

                return [
                    'key' => $key,
                    'label' => __($m['label']),
                    'value' => $m['unit'] === 'money' ? Demo::money($value) : (string) (int) $value,
                    'icon' => $icons[$key] ?? 'activity',
                    'color' => $colors[$key] ?? 'primary',
                    'url' => self::sectionUrl($m['section']),
                ];
            })
            ->values()->all();
    }

    /** قسم المقياس → مساره. غيابه يعني بطاقة لا تقود إلى شيء */
    private static function sectionUrl(string $section): ?string
    {
        $name = \App\Support\Permissions::ROUTES[$section] ?? null;

        return $name && auth()->user()?->allows($section) ? route($name) : null;
    }

    /** القيمة الحالية لمقياس */
    public static function value(string $metric, int $businessId): float
    {
        return match ($metric) {
            'daily_sales' => (float) Order::where('business_id', $businessId)
                ->where('is_held', false)
                ->whereDate('ordered_at', today())->sum('total'),

            'monthly_expenses' => (float) Expense::where('business_id', $businessId)
                ->whereBetween('spent_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->sum('amount'),

            'pending_orders' => (float) Order::where('business_id', $businessId)
                ->where('is_held', false)
                ->whereIn('status', ['جديد', 'قيد التجهيز'])->count(),

            'low_stock_products' => (float) Product::where('business_id', $businessId)
                ->whereColumn('quantity', '<', 'alert_qty')->count(),

            'dormant_customers' => (float) self::dormantCustomers($businessId)->count(),

            'open_purchase_orders' => (float) PurchaseOrder::where('business_id', $businessId)
                ->where('status', '!=', 'مستلم')->count(),

            // الإيراد ناقص التكلفة ناقص مصروفات اليوم
            'today_profit' => self::todayProfit($businessId),

            // كم ساعة والوردية مفتوحة؟ صفرٌ إن لم تكن مفتوحة أصلًا
            'open_shift_hours' => (function () use ($businessId) {
                $opened = \App\Models\Shift::where('business_id', $businessId)
                    ->where('status', \App\Models\Shift::OPEN)
                    ->min('opened_at');

                return $opened ? round(\Illuminate\Support\Carbon::parse($opened)->diffInMinutes(now()) / 60, 1) : 0.0;
            })(),

            default => 0.0,
        };
    }

    private static function todayProfit(int $businessId): float
    {
        $revenue = (float) Order::where('business_id', $businessId)
            ->where('is_held', false)->whereDate('ordered_at', today())->sum('total');

        $cost = (float) DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.business_id', $businessId)
            ->where('orders.is_held', false)
            ->whereDate('orders.ordered_at', today())
            ->sum(DB::raw('COALESCE(products.cost, 0) * order_items.quantity'));

        $expenses = (float) Expense::where('business_id', $businessId)
            ->whereDate('spent_at', today())->sum('amount');

        return round($revenue - $cost - $expenses, 3);
    }

    /** هل تحقّق شرط القاعدة الآن؟ */
    public static function triggered(CustomAlert $alert, int $businessId): bool
    {
        $value = self::value($alert->metric, $businessId);
        $threshold = (float) $alert->threshold;

        return $alert->operator === '>' ? $value > $threshold : $value < $threshold;
    }
}
