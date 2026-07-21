<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Plan;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Shift;
use App\Models\Supplier;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;

/**
 * طبقة الوصول للبيانات لواجهات Abad POS.
 *
 * تقرأ الآن من قاعدة البيانات مع مراعاة المستأجر الحالي (business_id)،
 * وتُعيد نفس أشكال المصفوفات التي تعتمد عليها ملفات Blade
 * (لذلك لم تتغيّر الواجهات). المصدر الثابت للبيانات التجريبية في App\Support\SeedData.
 */
class Demo
{
    /* ============================ مساعدات ============================ */

    private static $baseCur = null;
    private static $displayCur = null;

    /** العملة الأساسية للنشاط (الافتراضي: ريال عماني) */
    public static function baseCurrency(): array
    {
        if (self::$baseCur !== null) {
            return self::$baseCur;
        }
        $c = \App\Models\Currency::where('business_id', self::bid())->where('is_base', true)->first();

        return self::$baseCur = $c
            ? ['code' => $c->code, 'symbol' => $c->symbol ?: $c->code, 'rate' => (float) $c->rate, 'is_base' => true]
            : ['code' => 'OMR', 'symbol' => 'ر.ع', 'rate' => 1.0, 'is_base' => true];
    }

    /** عملة العرض المختارة (من الجلسة) أو الأساسية */
    public static function displayCurrency(): array
    {
        if (self::$displayCur !== null) {
            return self::$displayCur;
        }
        $code = session('display_currency');
        if ($code) {
            $c = \App\Models\Currency::where('business_id', self::bid())->where('code', $code)->where('active', true)->first();
            if ($c) {
                return self::$displayCur = ['code' => $c->code, 'symbol' => $c->symbol ?: $c->code, 'rate' => (float) $c->rate, 'is_base' => (bool) $c->is_base];
            }
        }

        return self::$displayCur = self::baseCurrency();
    }

    private static function formatMoney(float $value, array $cur): string
    {
        $decimals = in_array($cur['code'], ['OMR', 'KWD', 'BHD']) ? 3 : 2;

        return number_format($value, $decimals, '.', ',') . ' ' . $cur['symbol'];
    }

    /** المبلغ بعملة العرض المختارة (تحويل تلقائي حسب سعر الصرف) */
    public static function money($value): string
    {
        $cur = self::displayCurrency();

        return self::formatMoney((float) $value * $cur['rate'], $cur);
    }

    /** المبلغ بالعملة الأساسية دائمًا (للفواتير والإيصالات وكشوف الحساب) */
    public static function moneyBase($value): string
    {
        return self::formatMoney((float) $value, self::baseCurrency());
    }

    public static function image(string $seed, int $w = 400, int $h = 400): string
    {
        return "https://picsum.photos/seed/{$seed}/{$w}/{$h}";
    }

    /** معرّف المستأجر الحالي (أو النشاط الأساسي احتياطيًا) */
    public static function bid(): int
    {
        $u = auth()->user();
        if ($u && $u->business_id) {
            return (int) $u->business_id;
        }
        return (int) (Business::where('name', 'زهرة مسقط')->value('id') ?? Business::min('id') ?? 0);
    }

    /** الفرع الحالي المختار (من الجلسة) — null = كل الفروع */
    public static function currentBranchId(): ?int
    {
        return session('current_branch') ? (int) session('current_branch') : null;
    }

    public static function currentBranchName(): string
    {
        $id = self::currentBranchId();
        return $id ? (\App\Models\Branch::where('id', $id)->value('name') ?? 'كل الفروع') : 'كل الفروع';
    }

    /** فروع النشاط الحالي */
    public static function branches(): array
    {
        return \App\Models\Branch::where('business_id', self::bid())->orderBy('id')->get()
            ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'phone' => $b->phone, 'address' => $b->address])->all();
    }

    /* ============================ Super Admin ============================ */

    public static function superStats(): array
    {
        $total = Business::count();
        $active = Business::where('status', 'نشط')->count();
        $flowers = Business::where('type', 'محل ورود')->count();
        $users = User::count();
        $activeSubs = Subscription::where('status', 'نشط')->count();
        $expiredSubs = Subscription::where('status', '!=', 'نشط')->count();
        $monthly = (float) Invoice::where('status', 'مدفوعة')->sum('amount');
        $yearly = (float) Subscription::sum('amount');

        return [
            ['label' => 'إجمالي الشركات', 'value' => (string) $total, 'icon' => 'building-2', 'trend' => '+12%', 'up' => true, 'color' => 'primary'],
            ['label' => 'الشركات النشطة', 'value' => (string) $active, 'icon' => 'circle-check', 'trend' => '+8%', 'up' => true, 'color' => 'success'],
            ['label' => 'محلات الورود', 'value' => (string) $flowers, 'icon' => 'flower', 'trend' => '+5%', 'up' => true, 'color' => 'secondary'],
            ['label' => 'المستخدمون', 'value' => (string) $users, 'icon' => 'users', 'trend' => '+18%', 'up' => true, 'color' => 'info'],
            ['label' => 'الاشتراكات النشطة', 'value' => (string) $activeSubs, 'icon' => 'badge-check', 'trend' => '+6%', 'up' => true, 'color' => 'success'],
            ['label' => 'الاشتراكات المنتهية', 'value' => (string) $expiredSubs, 'icon' => 'badge-x', 'trend' => '-3%', 'up' => false, 'color' => 'danger'],
            ['label' => 'الإيرادات الشهرية', 'value' => self::money($monthly), 'icon' => 'wallet', 'trend' => '+14%', 'up' => true, 'color' => 'warning'],
            ['label' => 'الإيرادات السنوية', 'value' => self::money($yearly), 'icon' => 'trending-up', 'trend' => '+21%', 'up' => true, 'color' => 'primary'],
        ];
    }

    public static function businesses(): array
    {
        return Business::with('plan')->orderByDesc('id')->get()->map(fn ($b) => [
            'id' => $b->id,
            'name' => $b->name,
            'type' => $b->type,
            'owner' => $b->owner_name,
            'phone' => $b->phone,
            'email' => $b->email,
            'plan' => $b->plan?->name ?? '—',
            'status' => $b->status,
            'registered' => optional($b->starts_at)->format('Y-m-d') ?? '—',
            'branches' => $b->branches_count,
            'logo' => $b->logo,
            'city' => $b->city,
            'country' => $b->country,
        ])->all();
    }

    public static function flowerShops(): array
    {
        return Business::where('type', 'محل ورود')->with('plan')
            ->withCount(['products', 'users', 'orders'])->get()->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->name,
                'logo' => $b->logo,
                'owner' => $b->owner_name,
                'city' => $b->city,
                'branches' => $b->branches_count,
                'employees' => $b->users_count ?: (($b->id * 3) % 12 + 3),
                'products' => $b->products_count ?: (($b->id * 7) % 180 + 40),
                'orders' => $b->orders_count ?: (($b->id * 13) % 800 + 120),
                'status' => $b->status,
                'plan' => $b->plan?->name ?? '—',
                'sales' => (float) Order::where('business_id', $b->id)->sum('total') ?: (($b->id * 137) % 19000 + 3000),
            ])->all();
    }

    public static function plans(): array
    {
        return Plan::orderBy('id')->get()->map(fn ($p) => [
            'name' => $p->name,
            'monthly' => (float) $p->monthly_price,
            'yearly' => (float) $p->yearly_price,
            'color' => $p->color,
            'popular' => (bool) $p->is_popular,
            'features' => $p->features ?? [],
        ])->all();
    }

    public static function subscriptions(): array
    {
        return Subscription::with('business', 'plan')->orderByDesc('id')->get()->map(fn ($s) => [
            'business' => $s->business?->name ?? '—',
            'plan' => $s->plan?->name ?? '—',
            'start' => optional($s->starts_at)->format('Y-m-d') ?? '—',
            'end' => optional($s->ends_at)->format('Y-m-d') ?? '—',
            'amount' => (float) $s->amount,
            'payment' => $s->payment_status,
            'status' => $s->status,
        ])->all();
    }

    public static function invoices(): array
    {
        return Invoice::with('business', 'plan')->orderByDesc('id')->get()->map(fn ($i) => [
            'number' => $i->number,
            'business' => $i->business?->name ?? '—',
            'plan' => $i->plan?->name ?? '—',
            'amount' => (float) $i->amount,
            'date' => optional($i->issued_at)->format('Y-m-d') ?? '—',
            'status' => $i->status,
        ])->all();
    }

    public static function platformUsers(): array
    {
        return User::with('business')->orderByDesc('id')->get()->map(fn ($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'phone' => $u->phone,
            'business' => $u->business?->name ?? 'المنصة',
            'role' => $u->roleLabel(),
            'status' => $u->status,
            'last_login' => optional($u->last_login_at)->format('Y-m-d H:i') ?? '—',
            'avatar' => $u->avatar ?? self::image('user' . $u->id, 100, 100),
        ])->all();
    }

    /** أحدث الأنشطة من سجل النشاط (حسب الدور) */
    public static function activities(int $limit = 8): array
    {
        $u = auth()->user();
        $q = ActivityLog::query()->latest('id');
        if ($u && ! $u->isSuperAdmin()) {
            $q->where('business_id', self::bid());
        }
        return $q->limit($limit)->get()->map(fn ($a) => [
            'text' => $a->user_name . ' — ' . $a->description,
            'time' => optional($a->created_at)?->locale('ar')->diffForHumans() ?? '—',
            'icon' => $a->icon,
            'color' => $a->color,
        ])->all();
    }

    /* ============================ Admin ============================ */

    public static function adminStats(): array
    {
        $bid = self::bid();
        $ordersQ = Order::where('business_id', $bid)->where('is_held', false)
            ->when(self::currentBranchId(), fn ($q) => $q->where('branch_id', self::currentBranchId()));
        $ordersCount = (clone $ordersQ)->count();
        $salesMonth = (float) (clone $ordersQ)->sum('total');
        $salesToday = (float) (clone $ordersQ)->whereDate('ordered_at', today())->sum('total');
        if ($salesToday <= 0) {
            $salesToday = round($salesMonth * 0.06, 3);
        }
        $avg = $ordersCount ? $salesMonth / $ordersCount : 0;
        $customers = Customer::where('business_id', $bid)->count();
        $lowStock = Product::where('business_id', $bid)->whereColumn('quantity', '<', 'alert_qty')->count();
        $expenses = (float) Expense::where('business_id', $bid)->sum('amount');
        $net = $salesMonth - $expenses;

        return [
            ['label' => 'مبيعات اليوم', 'value' => self::money($salesToday), 'icon' => 'shopping-bag', 'trend' => '+9%', 'up' => true, 'color' => 'primary'],
            ['label' => 'مبيعات الشهر', 'value' => self::money($salesMonth), 'icon' => 'trending-up', 'trend' => '+15%', 'up' => true, 'color' => 'success'],
            ['label' => 'عدد الطلبات', 'value' => (string) $ordersCount, 'icon' => 'receipt', 'trend' => '+11%', 'up' => true, 'color' => 'info'],
            ['label' => 'متوسط قيمة الطلب', 'value' => self::money($avg), 'icon' => 'calculator', 'trend' => '+3%', 'up' => true, 'color' => 'secondary'],
            ['label' => 'عدد العملاء', 'value' => (string) $customers, 'icon' => 'users', 'trend' => '+7%', 'up' => true, 'color' => 'primary'],
            ['label' => 'منتجات منخفضة المخزون', 'value' => (string) $lowStock, 'icon' => 'alert-triangle', 'trend' => 'تنبيه', 'up' => false, 'color' => 'warning'],
            ['label' => 'المصروفات', 'value' => self::money($expenses), 'icon' => 'arrow-down-circle', 'trend' => '-5%', 'up' => false, 'color' => 'danger'],
            ['label' => 'صافي الأرباح', 'value' => self::money($net), 'icon' => 'piggy-bank', 'trend' => '+17%', 'up' => true, 'color' => 'success'],
        ];
    }

    public static function categories(): array
    {
        return Category::where('business_id', self::bid())->withCount('products')->orderBy('id')->get()->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'products' => $c->products_count,
            'icon' => $c->icon,
            'color' => $c->color,
        ])->all();
    }

    public static function products(): array
    {
        return Product::where('business_id', self::bid())->with('category')->orderBy('id')->get()->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'cat' => $p->category?->name ?? '—',
            'price' => (float) $p->price,
            'cost' => (float) $p->cost,
            'qty' => $p->quantity,
            'sku' => $p->sku,
            'barcode' => $p->barcode,
            'image' => $p->image,
            'stock_status' => $p->stock_status,
            'active' => (bool) $p->active,
            'alert' => $p->alert_qty,
            'tax' => (float) $p->tax,
            'discount' => (float) $p->discount,
        ])->all();
    }

    public static function orders(): array
    {
        return Order::where('business_id', self::bid())->where('is_held', false)
            ->when(self::currentBranchId(), fn ($q) => $q->where('branch_id', self::currentBranchId()))
            ->withCount('items')->orderByDesc('ordered_at')->get()->map(fn ($o) => [
                'id' => $o->number,
                'customer' => $o->customer_name ?? 'عميل نقدي',
                'employee' => $o->employee_name ?? '—',
                'branch' => $o->branch,
                'items_count' => $o->items_count,
                'total' => (float) $o->total,
                'payment' => $o->payment_method,
                'status' => $o->status,
                'date' => optional($o->ordered_at)->format('Y-m-d H:i') ?? '—',
            ])->all();
    }

    /** تفاصيل طلب كامل بأصنافه الحقيقية (حسب رقم الطلب) */
    public static function orderDetails($number): array
    {
        $o = Order::where('business_id', self::bid())->where('number', $number)->with('items')->first();
        if (! $o) {
            return [];
        }

        $items = $o->items->map(fn ($it) => [
            'id' => $it->id,
            'product_id' => $it->product_id,
            'name' => $it->name,
            'price' => (float) $it->price,
            'qty' => (int) $it->quantity,
            'total' => (float) $it->total,
        ])->all();

        return [
            'id' => $o->number,
            'db_id' => $o->id,
            'customer' => $o->customer_name ?? 'عميل نقدي',
            'employee' => $o->employee_name ?? '—',
            'branch' => $o->branch ?? 'الفرع الرئيسي',
            'status' => $o->status,
            'payment' => $o->payment_method,
            'payment_status' => $o->payment_status ?? 'مدفوع',
            'date' => optional($o->ordered_at)->format('Y-m-d H:i') ?? '—',
            'subtotal' => (float) $o->subtotal,
            'discount' => (float) $o->discount,
            'tax' => (float) $o->tax,
            'delivery' => (float) $o->delivery_fee,
            'total' => (float) $o->total,
            'notes' => $o->notes,
            'items' => $items,
        ];
    }

    public static function customers(): array
    {
        return Customer::where('business_id', self::bid())->withCount('orders')->orderBy('id')->get()->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'phone' => $c->phone,
            'email' => $c->email,
            'orders' => $c->orders_count,
            'total_spent' => (float) Order::where('customer_id', $c->id)->sum('total'),
            'last_order' => optional(Order::where('customer_id', $c->id)->max('ordered_at'))
                ? \Illuminate\Support\Carbon::parse(Order::where('customer_id', $c->id)->max('ordered_at'))->format('Y-m-d')
                : '—',
            'points' => $c->points,
            'avatar' => self::image('cust' . $c->id, 100, 100),
        ])->all();
    }

    /** عملات النشاط (مع العملة الأساسية) */
    public static function currencies(): array
    {
        return \App\Models\Currency::where('business_id', self::bid())->orderByDesc('is_base')->orderBy('code')->get()->map(fn ($c) => [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'symbol' => $c->symbol,
            'rate' => (float) $c->rate,
            'is_base' => (bool) $c->is_base,
            'active' => (bool) $c->active,
        ])->all();
    }

    /** طلبات عميل محدّد (سجل مشترياته) */
    public static function customerOrders($id): array
    {
        return Order::where('business_id', self::bid())->where('customer_id', $id)->where('is_held', false)
            ->withCount('items')->orderByDesc('ordered_at')->get()->map(fn ($o) => [
                'id' => $o->number,
                'items_count' => $o->items_count,
                'total' => (float) $o->total,
                'payment' => $o->payment_method,
                'status' => $o->status,
                'date' => optional($o->ordered_at)->format('Y-m-d H:i') ?? '—',
            ])->all();
    }

    public static function employees(): array
    {
        $bid = self::bid();

        // مبيعات كل موظف خلال الشهر الحالي (من الطلبات المرتبطة به)
        $monthly = Order::where('business_id', $bid)->where('is_held', false)
            ->whereBetween('ordered_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->whereNotNull('user_id')
            ->selectRaw('user_id, SUM(total) as s')->groupBy('user_id')->pluck('s', 'user_id');

        return User::where('business_id', $bid)->where('role', '!=', 'super_admin')
            ->orderBy('id')->get()->map(function ($u) use ($monthly) {
                $target = (float) $u->monthly_target;
                $rate = (float) $u->commission_rate;
                $achieved = (float) ($monthly[$u->id] ?? 0);

                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'avatar' => $u->avatar ?? self::image('emp' . $u->id, 100, 100),
                    'role' => $u->roleLabel(),
                    'branch' => $u->branch ?? 'الفرع الرئيسي',
                    'phone' => $u->phone,
                    'email' => $u->email,
                    'sales' => (float) $u->sales_total,
                    'status' => $u->status,
                    'target' => $target,
                    'commission_rate' => $rate,
                    'achieved' => $achieved,
                    'pct' => $target > 0 ? min(100, round($achieved / $target * 100, 1)) : 0,
                    'commission' => round($achieved * $rate / 100, 3),
                ];
            })->all();
    }

    /** ترتيب الموظفين حسب مبيعات الشهر (لوحة الأداء) */
    public static function employeeLeaderboard(): array
    {
        $rows = collect(self::employees())
            ->sortByDesc('achieved')->values();

        return $rows->map(function ($e, $i) {
            $e['rank'] = $i + 1;

            return $e;
        })->all();
    }

    /** بيانات كشف العمولة لموظف: طلباته خلال الشهر + الإجماليات */
    public static function employeeCommission($id): array
    {
        $bid = self::bid();
        $u = User::where('business_id', $bid)->where('role', '!=', 'super_admin')->findOrFail($id);

        $orders = Order::where('business_id', $bid)->where('is_held', false)->where('user_id', $u->id)
            ->whereBetween('ordered_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->orderBy('ordered_at')->get();

        $achieved = (float) $orders->sum('total');
        $rate = (float) $u->commission_rate;
        $target = (float) $u->monthly_target;

        return [
            'employee' => $u,
            'orders' => $orders,
            'achieved' => $achieved,
            'orders_count' => $orders->count(),
            'target' => $target,
            'pct' => $target > 0 ? min(100, round($achieved / $target * 100, 1)) : 0,
            'rate' => $rate,
            'commission' => round($achieved * $rate / 100, 3),
            'month' => now()->translatedFormat('F Y'),
        ];
    }

    /* ============================ المورّدون والمشتريات ============================ */

    public static function suppliers(): array
    {
        return Supplier::where('business_id', self::bid())->withCount('purchaseOrders')->orderBy('name')->get()->map(fn ($s) => [
            'id' => $s->id,
            'name' => $s->name,
            'phone' => $s->phone,
            'email' => $s->email,
            'contact' => $s->contact_person,
            'notes' => $s->notes,
            'orders_count' => $s->purchase_orders_count,
        ])->all();
    }

    public static function purchaseOrders(): array
    {
        return PurchaseOrder::where('business_id', self::bid())->withCount('items')
            ->orderByDesc('id')->get()->map(fn ($p) => [
                'id' => $p->id,
                'number' => $p->number,
                'supplier' => $p->supplier_name ?? optional($p->supplier)->name ?? '—',
                'status' => $p->status,
                'total' => (float) $p->total,
                'items_count' => $p->items_count,
                'ordered' => optional($p->ordered_at)->format('Y-m-d') ?? '—',
                'received' => optional($p->received_at)->format('Y-m-d'),
            ])->all();
    }

    public static function purchaseOrderStats(): array
    {
        $bid = self::bid();
        $base = PurchaseOrder::where('business_id', $bid);

        return [
            'total' => (clone $base)->count(),
            'pending' => (clone $base)->whereIn('status', ['مسودة', 'مُرسل', 'مستلم جزئيًا'])->count(),
            'received' => (clone $base)->where('status', 'مستلم')->count(),
            'value' => (float) (clone $base)->whereIn('status', ['مسودة', 'مُرسل', 'مستلم جزئيًا'])->sum('total'),
        ];
    }

    /** اقتراح إعادة الطلب: منتجات وصلت حدّ التنبيه (كمية مقترحة تُعيدها لضعف الحدّ) */
    public static function reorderSuggestions(): array
    {
        return Product::where('business_id', self::bid())->where('active', true)
            ->whereColumn('quantity', '<=', 'alert_qty')->orderBy('quantity')->get()->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'qty' => (int) $p->quantity,
                'alert' => (int) $p->alert_qty,
                'suggested' => max(1, (int) $p->alert_qty * 2 - (int) $p->quantity),
                'cost' => (float) $p->cost,
            ])->all();
    }

    /* ============================ التسويق والكوبونات ============================ */

    public static function coupons(): array
    {
        return \App\Models\Coupon::where('business_id', self::bid())->orderByDesc('id')->get()->map(fn ($c) => [
            'id' => $c->id,
            'code' => $c->code,
            'type' => $c->type,
            'value' => (float) $c->value,
            'min_order' => (float) $c->min_order,
            'max_uses' => $c->max_uses,
            'used_count' => (int) $c->used_count,
            'expires' => optional($c->expires_at)->format('Y-m-d'),
            'expired' => $c->expires_at && $c->expires_at->isPast(),
            'active' => (bool) $c->active,
            'display' => $c->type === 'نسبة' ? rtrim(rtrim(number_format($c->value, 2, '.', ''), '0'), '.') . '%' : self::money($c->value),
        ])->all();
    }

    public static function couponStats(): array
    {
        $bid = self::bid();

        return [
            'total' => \App\Models\Coupon::where('business_id', $bid)->count(),
            'active' => \App\Models\Coupon::where('business_id', $bid)->where('active', true)->count(),
            'redemptions' => (int) \App\Models\Coupon::where('business_id', $bid)->sum('used_count'),
        ];
    }

    /** شرائح العملاء للحملات التسويقية (مع أرقام واتساب) */
    public static function marketingSegment(string $segment = 'all'): array
    {
        $bid = self::bid();
        $q = Customer::where('business_id', $bid)->whereNotNull('phone');

        $customers = $q->get()->map(function ($c) {
            $last = Order::where('customer_id', $c->id)->max('ordered_at');

            return [
                'id' => $c->id,
                'name' => $c->name,
                'phone' => $c->phone,
                'wa' => preg_replace('/\D/', '', (string) $c->phone),
                'last_order' => $last ? \Illuminate\Support\Carbon::parse($last) : null,
                'spent' => (float) Order::where('customer_id', $c->id)->where('is_held', false)->sum('total'),
            ];
        });

        $filtered = match ($segment) {
            'inactive' => $customers->filter(fn ($c) => ! $c['last_order'] || $c['last_order']->lt(now()->subDays(60))),
            'top' => $customers->sortByDesc('spent')->take(10),
            default => $customers,
        };

        return $filtered->map(fn ($c) => [
            'id' => $c['id'],
            'name' => $c['name'],
            'phone' => $c['phone'],
            'wa' => $c['wa'],
            'spent' => $c['spent'],
            'last_order' => $c['last_order']?->format('Y-m-d') ?? 'لا يوجد',
        ])->values()->all();
    }

    /* ============================ ضريبة القيمة المضافة ============================ */

    public static function vatSettings(): array
    {
        $bid = self::bid();
        $get = fn ($k, $d) => \App\Models\Setting::where('business_id', $bid)->where('key', $k)->value('value') ?? $d;

        return [
            'rate' => (float) $get('vat_rate', 5),
            'number' => $get('vat_number', ''),
        ];
    }

    /** تقرير ضريبة القيمة المضافة لفترة (month|quarter|year) */
    public static function vatReport(string $period = 'quarter'): array
    {
        $bid = self::bid();
        $rate = self::vatSettings()['rate'];
        $now = now();
        [$start, $end, $label] = match ($period) {
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth(), 'هذا الشهر'],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear(), 'هذه السنة'],
            default => [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter(), 'هذا الربع'],
        };

        $orders = Order::where('business_id', $bid)->where('is_held', false)
            ->whereBetween('ordered_at', [$start, $end]);
        $outputVat = (float) (clone $orders)->sum('tax');
        $taxableSales = (float) (clone $orders)->sum('subtotal');
        $grossSales = (float) (clone $orders)->sum('total');

        // ضريبة المدخلات من المشتريات المستلمة (تُعامل قيمة الأمر كصافٍ قبل الضريبة)
        $purchases = PurchaseOrder::where('business_id', $bid)->where('status', 'مستلم')
            ->whereBetween('received_at', [$start, $end]);
        $inputBase = (float) (clone $purchases)->sum('total');
        $inputVat = round($inputBase * $rate / 100, 3);

        // تفصيل شهري ضمن الفترة
        $months = [];
        $cursor = $start->copy();
        while ($cursor <= $end) {
            $mStart = $cursor->copy()->startOfMonth();
            $mEnd = $cursor->copy()->endOfMonth();
            $mOut = (float) Order::where('business_id', $bid)->where('is_held', false)->whereBetween('ordered_at', [$mStart, $mEnd])->sum('tax');
            $mSales = (float) Order::where('business_id', $bid)->where('is_held', false)->whereBetween('ordered_at', [$mStart, $mEnd])->sum('subtotal');
            $months[] = ['label' => $cursor->translatedFormat('F Y'), 'taxable' => round($mSales, 3), 'vat' => round($mOut, 3)];
            $cursor->addMonthNoOverflow();
        }

        return [
            'label' => $label,
            'period' => $period,
            'rate' => $rate,
            'taxable_sales' => round($taxableSales, 3),
            'gross_sales' => round($grossSales, 3),
            'output_vat' => round($outputVat, 3),
            'input_base' => round($inputBase, 3),
            'input_vat' => $inputVat,
            'net_vat' => round($outputVat - $inputVat, 3),
            'months' => $months,
            'from' => $start->format('Y-m-d'),
            'to' => $end->format('Y-m-d'),
        ];
    }

    /* ============================ الربحية ============================ */

    /** ربح كل منتج = (سعر البيع - التكلفة) × الكمية المباعة، من عناصر الطلبات الفعلية */
    public static function productProfitability(): array
    {
        $bid = self::bid();
        $costs = Product::where('business_id', $bid)->pluck('cost', 'id');

        $rows = OrderItem::whereHas('order', fn ($q) => $q->where('business_id', $bid)->where('is_held', false))
            ->selectRaw('product_id, name, SUM(quantity) as qty, SUM(total) as revenue')
            ->groupBy('product_id', 'name')->get()->map(function ($r) use ($costs) {
                $cost = (float) ($costs[$r->product_id] ?? 0);
                $cogs = $cost * (int) $r->qty;
                $revenue = (float) $r->revenue;
                $profit = $revenue - $cogs;

                return [
                    'name' => $r->name,
                    'qty' => (int) $r->qty,
                    'revenue' => round($revenue, 3),
                    'cost' => round($cogs, 3),
                    'profit' => round($profit, 3),
                    'margin' => $revenue > 0 ? round($profit / $revenue * 100, 1) : 0,
                ];
            })->sortByDesc('profit')->values()->all();

        return $rows;
    }

    public static function categoryProfitability(): array
    {
        $bid = self::bid();
        // خريطة product_id -> [category, cost]
        $products = Product::where('business_id', $bid)->with('category')->get()
            ->keyBy('id')->map(fn ($p) => ['cat' => optional($p->category)->name ?? 'غير مصنّف', 'cost' => (float) $p->cost]);

        $agg = [];
        $items = OrderItem::whereHas('order', fn ($q) => $q->where('business_id', $bid)->where('is_held', false))
            ->selectRaw('product_id, SUM(quantity) as qty, SUM(total) as revenue')->groupBy('product_id')->get();
        foreach ($items as $it) {
            $info = $products[$it->product_id] ?? ['cat' => 'غير مصنّف', 'cost' => 0];
            $cat = $info['cat'];
            $agg[$cat] ??= ['revenue' => 0, 'cost' => 0];
            $agg[$cat]['revenue'] += (float) $it->revenue;
            $agg[$cat]['cost'] += $info['cost'] * (int) $it->qty;
        }

        $rows = [];
        foreach ($agg as $cat => $v) {
            $profit = $v['revenue'] - $v['cost'];
            $rows[] = [
                'name' => $cat,
                'revenue' => round($v['revenue'], 3),
                'profit' => round($profit, 3),
                'margin' => $v['revenue'] > 0 ? round($profit / $v['revenue'] * 100, 1) : 0,
            ];
        }
        usort($rows, fn ($a, $b) => $b['profit'] <=> $a['profit']);

        return $rows;
    }

    public static function profitSummary(): array
    {
        $prods = self::productProfitability();
        $revenue = array_sum(array_column($prods, 'revenue'));
        $cost = array_sum(array_column($prods, 'cost'));
        $profit = $revenue - $cost;

        return [
            'revenue' => round($revenue, 3),
            'cost' => round($cost, 3),
            'profit' => round($profit, 3),
            'margin' => $revenue > 0 ? round($profit / $revenue * 100, 1) : 0,
            'best' => $prods[0] ?? null,
            'worst' => ! empty($prods) ? end($prods) : null,
            'loss_makers' => array_values(array_filter($prods, fn ($p) => $p['profit'] < 0)),
        ];
    }

    public static function inventory(): array
    {
        return Product::where('business_id', self::bid())->orderBy('id')->get()->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'sku' => $p->sku,
            'qty' => $p->quantity,
            'min' => $p->alert_qty,
            'status' => $p->stock_status,
            'updated' => optional($p->updated_at)->format('Y-m-d') ?? '—',
        ])->all();
    }

    public static function movements(): array
    {
        return InventoryMovement::where('business_id', self::bid())->orderByDesc('id')->get()->map(fn ($m) => [
            'product' => $m->product_name,
            'sku' => $m->sku,
            'type' => $m->type,
            'qty' => $m->quantity,
            'employee' => $m->employee_name,
            'date' => optional($m->created_at)->format('Y-m-d') ?? '—',
        ])->all();
    }

    public static function expenses(): array
    {
        return Expense::where('business_id', self::bid())->orderByDesc('spent_at')->get()->map(fn ($e) => [
            'type' => $e->type,
            'description' => $e->description,
            'amount' => (float) $e->amount,
            'date' => optional($e->spent_at)->format('Y-m-d') ?? '—',
            'employee' => $e->employee_name,
            'method' => $e->method,
        ])->all();
    }

    /** أنواع المصروفات للنشاط الحالي مع إحصاء الاستخدام */
    public static function expenseTypes(): array
    {
        $bid = self::bid();

        // إجماليات المصروفات حسب النوع (نوع المصروف مخزّن كنص في جدول المصروفات)
        $usage = Expense::where('business_id', $bid)
            ->selectRaw('type, COUNT(*) as cnt, SUM(amount) as total')
            ->groupBy('type')->get()
            ->keyBy('type');

        return \App\Models\ExpenseType::where('business_id', $bid)->orderBy('name')->get()->map(fn ($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'description' => $t->description,
            'count' => (int) ($usage[$t->name]->cnt ?? 0),
            'total' => (float) ($usage[$t->name]->total ?? 0),
        ])->all();
    }

    /* ============================ المالية ============================ */

    public static function financeStats(): array
    {
        $bid = self::bid();
        $income = Transaction::where('business_id', $bid)->where('type', 'دخل');
        $total = (float) (clone $income)->sum('amount');
        $cash = (float) (clone $income)->where('method', 'نقدي')->sum('amount');
        $bank = (float) (clone $income)->where('method', 'تحويل بنكي')->sum('amount');
        $card = (float) (clone $income)->where('method', 'بطاقة')->sum('amount');

        return [
            ['label' => 'إجمالي الإيرادات', 'value' => self::money($total), 'icon' => 'wallet', 'trend' => '+12%', 'up' => true, 'color' => 'primary'],
            ['label' => 'المدفوعات النقدية', 'value' => self::money($cash), 'icon' => 'banknote', 'trend' => '+8%', 'up' => true, 'color' => 'success'],
            ['label' => 'التحويلات البنكية', 'value' => self::money($bank), 'icon' => 'landmark', 'trend' => '+15%', 'up' => true, 'color' => 'info'],
            ['label' => 'مدفوعات البطاقة (فيزا)', 'value' => self::money($card), 'icon' => 'credit-card', 'trend' => '+5%', 'up' => true, 'color' => 'secondary'],
        ];
    }

    public static function paymentMethods(): array
    {
        $bid = self::bid();
        $income = Transaction::where('business_id', $bid)->where('type', 'دخل');
        $grand = max(0.001, (float) (clone $income)->sum('amount'));
        $defs = [
            ['name' => 'نقدي', 'key' => 'نقدي', 'icon' => 'banknote', 'color' => 'success'],
            ['name' => 'تحويل بنكي', 'key' => 'تحويل بنكي', 'icon' => 'landmark', 'color' => 'info'],
            ['name' => 'بطاقة (فيزا)', 'key' => 'بطاقة', 'icon' => 'credit-card', 'color' => 'primary'],
        ];
        return array_map(function ($d) use ($income, $grand) {
            $total = (float) (clone $income)->where('method', $d['key'])->sum('amount');
            $count = (clone $income)->where('method', $d['key'])->count();
            return array_merge($d, [
                'total' => $total,
                'count' => $count,
                'percent' => (int) round($total / $grand * 100),
            ]);
        }, $defs);
    }

    public static function transactions(): array
    {
        return Transaction::where('business_id', self::bid())->orderByDesc('occurred_at')->get()->map(fn ($t) => [
            'id' => $t->reference,
            'date' => optional($t->occurred_at)->format('Y-m-d H:i') ?? '—',
            'description' => $t->description,
            'method' => $t->method,
            'type' => $t->type,
            'amount' => (float) $t->amount,
            'employee' => $t->employee_name,
        ])->all();
    }

    /* ============================ بيانات المخططات (من قاعدة البيانات) ============================ */

    private const AR_MONTHS = [1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل', 5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس', 9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر'];

    /** مبيعات آخر 6 أشهر للنشاط الحالي */
    public static function salesSeries(): array
    {
        $bid = self::bid();
        $labels = [];
        $data = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = now()->subMonths($i);
            $labels[] = self::AR_MONTHS[$m->month];
            $data[] = round((float) Order::where('business_id', $bid)->where('is_held', false)
                ->whereYear('ordered_at', $m->year)->whereMonth('ordered_at', $m->month)->sum('total'), 3);
        }
        return ['labels' => $labels, 'data' => $data];
    }

    /** توزيع المبيعات حسب وسيلة الدفع للنشاط الحالي */
    /** الاسم المعروض لوسيلة الدفع (المفتاح المخزّن في القاعدة يبقى كما هو) */
    public static function methodLabel(string $key): string
    {
        return ['بطاقة' => 'بطاقة (فيزا)'][$key] ?? $key;
    }

    public static function paymentDistribution(): array
    {
        $rows = Order::where('business_id', self::bid())->where('is_held', false)
            ->selectRaw('payment_method, SUM(total) as s')->groupBy('payment_method')->pluck('s', 'payment_method');
        return [
            'labels' => $rows->keys()->map(fn ($k) => self::methodLabel((string) $k))->all(),
            'series' => $rows->map(fn ($v) => round((float) $v, 3))->values()->all(),
        ];
    }

    /** أفضل 5 منتجات مبيعًا للنشاط الحالي */
    public static function topProducts(): array
    {
        $bid = self::bid();
        return OrderItem::whereHas('order', fn ($q) => $q->where('business_id', $bid)->where('is_held', false))
            ->selectRaw('name, SUM(quantity) as q, SUM(total) as t')
            ->groupBy('name')->orderByDesc('q')->limit(5)->get()
            ->map(fn ($r) => ['name' => $r->name, 'qty' => (int) $r->q, 'total' => round((float) $r->t, 3)])->all();
    }

    /** مؤشرات الأهداف (KPI): الهدف الشهري مقابل المحقّق */
    public static function kpi(): array
    {
        $bid = self::bid();
        $target = (float) (\App\Models\Setting::where('business_id', $bid)->where('key', 'monthly_target')->value('value') ?? 0);
        $now = now();
        $achieved = (float) Order::where('business_id', $bid)->where('is_held', false)
            ->whereBetween('ordered_at', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()])->sum('total');
        $daysInMonth = $now->daysInMonth;
        $dayNow = max(1, $now->day);
        $projected = $achieved / $dayNow * $daysInMonth;

        return [
            'target' => $target,
            'achieved' => $achieved,
            'pct' => $target > 0 ? min(100, round($achieved / $target * 100, 1)) : 0,
            'remaining' => max(0, $target - $achieved),
            'projected' => $projected,
            'days_left' => $daysInMonth - $now->day,
            'on_track' => $target > 0 ? $projected >= $target : null,
        ];
    }

    /** تنبيهات ذكية للمستأجر الحالي (للعرض في الداشبورد) */
    public static function smartAlerts(): array
    {
        return self::smartAlertsFor(self::bid());
    }

    /**
     * تنبيهات ذكية لمتجر محدّد: تراجع المبيعات، منتجات راكدة، عملاء غير نشطين.
     * مستقلّة عن الجلسة كي يستخدمها أمر البريد المجدول.
     */
    public static function smartAlertsFor(int $bid): array
    {
        $alerts = [];

        // تراجع المبيعات: هذا الشهر مقابل السابق
        $sum = fn ($start, $end) => (float) Order::where('business_id', $bid)->where('is_held', false)
            ->whereBetween('ordered_at', [$start, $end])->sum('total');
        $cur = $sum(now()->startOfMonth(), now()->endOfMonth());
        $prev = $sum(now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth());
        if ($prev > 0) {
            $delta = round(($cur - $prev) / $prev * 100, 1);
            if ($delta < 0) {
                $alerts[] = ['type' => 'تراجع المبيعات', 'text' => 'انخفضت مبيعات هذا الشهر بنسبة ' . abs($delta) . '% مقارنةً بالشهر السابق', 'icon' => 'trending-down', 'color' => 'danger', 'url' => route('admin.analytics.index')];
            }
        }

        $soldIds = OrderItem::whereHas('order', fn ($q) => $q->where('business_id', $bid)->where('ordered_at', '>=', now()->subDays(30)))
            ->pluck('product_id')->filter()->unique();
        $stagnant = Product::where('business_id', $bid)->where('quantity', '>', 0)->whereNotIn('id', $soldIds)
            ->orderByDesc('quantity')->limit(3)->get();
        foreach ($stagnant as $p) {
            $alerts[] = ['type' => 'منتج راكد', 'text' => 'المنتج «' . $p->name . '» لم يُبَع خلال 30 يومًا (' . $p->quantity . ' بالمخزون)', 'icon' => 'package', 'color' => 'warning', 'url' => route('admin.products.show', $p->id)];
        }

        $inactive = Customer::where('business_id', $bid)->whereHas('orders')->get()
            ->filter(function ($c) {
                $last = Order::where('customer_id', $c->id)->max('ordered_at');

                return $last && \Illuminate\Support\Carbon::parse($last)->lt(now()->subDays(60));
            })->take(3);
        foreach ($inactive as $c) {
            $alerts[] = ['type' => 'عميل متعثّر', 'text' => 'العميل «' . $c->name . '» لم يشترِ منذ أكثر من 60 يومًا', 'icon' => 'user-x', 'color' => 'info', 'url' => route('admin.customers.show', $c->id)];
        }

        return $alerts;
    }

    /** مقارنة أداء الشهر الحالي بالشهر السابق */
    public static function periodComparison(): array
    {
        $bid = self::bid();
        $now = now();
        $metric = function ($start, $end) use ($bid) {
            $q = Order::where('business_id', $bid)->where('is_held', false)->whereBetween('ordered_at', [$start, $end]);
            $sales = (float) (clone $q)->sum('total');
            $orders = (clone $q)->count();

            return ['sales' => $sales, 'orders' => $orders, 'avg' => $orders ? $sales / $orders : 0];
        };
        $cur = $metric($now->copy()->startOfMonth(), $now->copy()->endOfMonth());
        $prev = $metric($now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth());
        $delta = fn ($c, $p) => $p > 0 ? round(($c - $p) / $p * 100, 1) : ($c > 0 ? 100.0 : 0.0);

        return [
            ['label' => 'مبيعات الشهر', 'cur' => self::money($cur['sales']), 'prev' => self::money($prev['sales']), 'delta' => $delta($cur['sales'], $prev['sales']), 'icon' => 'trending-up'],
            ['label' => 'عدد الطلبات', 'cur' => (string) $cur['orders'], 'prev' => (string) $prev['orders'], 'delta' => $delta($cur['orders'], $prev['orders']), 'icon' => 'receipt'],
            ['label' => 'متوسط قيمة الطلب', 'cur' => self::money($cur['avg']), 'prev' => self::money($prev['avg']), 'delta' => $delta($cur['avg'], $prev['avg']), 'icon' => 'calculator'],
        ];
    }

    /** المبيعات حسب أيام الأسبوع */
    public static function salesByWeekday(): array
    {
        $labels = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
        $rows = Order::where('business_id', self::bid())->where('is_held', false)
            ->selectRaw("strftime('%w', ordered_at) as w, SUM(total) as s")->groupBy('w')->pluck('s', 'w');
        $data = [];
        for ($i = 0; $i < 7; $i++) {
            $data[] = round((float) ($rows[(string) $i] ?? 0), 3);
        }

        return ['labels' => $labels, 'data' => $data];
    }

    /** المبيعات حسب ساعات اليوم */
    public static function salesByHour(): array
    {
        $rows = Order::where('business_id', self::bid())->where('is_held', false)
            ->selectRaw("strftime('%H', ordered_at) as h, SUM(total) as s")->groupBy('h')->pluck('s', 'h');
        $labels = [];
        $data = [];
        for ($i = 8; $i <= 22; $i++) {
            $labels[] = sprintf('%02d:00', $i);
            $data[] = round((float) ($rows[sprintf('%02d', $i)] ?? 0), 3);
        }

        return ['labels' => $labels, 'data' => $data];
    }

    /** أفضل العملاء إنفاقًا */
    public static function topCustomers(int $limit = 7): array
    {
        return Order::where('business_id', self::bid())->where('is_held', false)->whereNotNull('customer_name')
            ->selectRaw('customer_name, SUM(total) as t, COUNT(*) as c')
            ->groupBy('customer_name')->orderByDesc('t')->limit($limit)->get()
            ->map(fn ($r) => ['name' => $r->customer_name, 'total' => round((float) $r->t, 3), 'orders' => (int) $r->c])->all();
    }

    /** المبيعات حسب التصنيف */
    public static function categorySales(): array
    {
        $rows = \Illuminate\Support\Facades\DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('orders.business_id', self::bid())->where('orders.is_held', false)
            ->selectRaw('COALESCE(categories.name, "غير مصنّف") as cat, SUM(order_items.total) as s')
            ->groupBy('cat')->orderByDesc('s')->get();

        return ['labels' => $rows->pluck('cat')->all(), 'series' => $rows->pluck('s')->map(fn ($v) => round((float) $v, 3))->all()];
    }

    /** إيرادات المنصة (فواتير) آخر 6 أشهر */
    public static function revenueSeries(): array
    {
        $labels = [];
        $data = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = now()->subMonths($i);
            $labels[] = self::AR_MONTHS[$m->month];
            $data[] = round((float) Invoice::whereYear('issued_at', $m->year)->whereMonth('issued_at', $m->month)->sum('amount'), 3);
        }
        return ['labels' => $labels, 'data' => $data];
    }

    /** نمو الشركات (عدد التسجيلات) آخر 6 أشهر */
    public static function businessesGrowthSeries(): array
    {
        $labels = [];
        $data = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = now()->subMonths($i);
            $labels[] = self::AR_MONTHS[$m->month];
            $data[] = Business::whereYear('starts_at', $m->year)->whereMonth('starts_at', $m->month)->count();
        }
        return ['labels' => $labels, 'data' => $data];
    }

    /** توزيع الشركات على الباقات */
    public static function planDistribution(): array
    {
        $rows = Business::with('plan')->get()->groupBy(fn ($b) => $b->plan?->name ?? 'بدون باقة')->map->count();
        return ['labels' => $rows->keys()->all(), 'series' => $rows->values()->all()];
    }

    /* ============================ باحثات مفردة (حسب المعرّف) ============================ */

    private static function findById(array $rows, $id, string $key = 'id'): array
    {
        $found = collect($rows)->firstWhere($key, is_numeric($id) ? (int) $id : $id);
        return $found ?? ($rows[0] ?? []);
    }

    public static function product($id): array { return self::findById(self::products(), $id); }
    public static function order($id): array { return self::findById(self::orders(), $id); }
    public static function customer($id): array { return self::findById(self::customers(), $id); }
    public static function employee($id): array { return self::findById(self::employees(), $id); }
    public static function business($id): array { return self::findById(self::businesses(), $id); }
    public static function flowerShop($id): array { return self::findById(self::flowerShops(), $id); }
    public static function platformUser($id): array { return self::findById(self::platformUsers(), $id); }

    /* ============================ POS ============================ */

    public static function posCategories(): array
    {
        $names = Category::where('business_id', self::bid())->orderBy('id')->pluck('name')->all();
        return array_merge(['الكل'], $names);
    }

    public static function heldOrders(): array
    {
        $name = auth()->user()?->name;
        return Order::where('business_id', self::bid())->where('is_held', true)
            ->when($name, fn ($q) => $q->where('employee_name', $name))
            ->withCount('items')->orderByDesc('id')->get()->map(fn ($o) => [
                'id' => $o->number,
                'customer' => $o->customer_name ?? 'عميل نقدي',
                'items' => $o->items_count ?: 1,
                'total' => (float) $o->total,
                'time' => optional($o->ordered_at)->format('H:i') ?? '—',
                'employee' => $o->employee_name ?? 'سارة حسن',
            ])->all();
    }

    public static function receipts(): array
    {
        $name = auth()->user()?->name;
        return Order::where('business_id', self::bid())->where('is_held', false)
            ->when($name, fn ($q) => $q->where('employee_name', $name))
            ->orderByDesc('ordered_at')->limit(12)->get()->map(fn ($o) => [
                'number' => $o->number,
                'customer' => $o->customer_name ?? 'عميل نقدي',
                'total' => (float) $o->total,
                'payment' => $o->payment_method,
                'time' => optional($o->ordered_at)->format('Y-m-d H:i') ?? '—',
                'employee' => $o->employee_name ?? 'سارة حسن',
            ])->all();
    }

    /* ============================ الإشعارات والوردية ============================ */

    /** إشعارات حقيقية حسب الدور (مخزون منخفض / طلبات / اشتراكات) */
    public static function notifications(): array
    {
        $u = auth()->user();
        $items = [];

        if ($u && $u->isSuperAdmin()) {
            $subs = Subscription::with('business')
                ->whereNotNull('ends_at')->whereDate('ends_at', '>=', now())
                ->whereDate('ends_at', '<=', now()->addDays(30))
                ->orderBy('ends_at')->limit(6)->get();
            foreach ($subs as $s) {
                $items[] = [
                    'text' => 'اشتراك «' . ($s->business?->name ?? '—') . '» ينتهي قريبًا',
                    'time' => optional($s->ends_at)->format('Y-m-d'),
                    'icon' => 'badge-x', 'color' => 'warning',
                    'url' => route('super-admin.subscriptions.index'),
                ];
            }
            return $items;
        }

        $bid = self::bid();
        $low = Product::where('business_id', $bid)->whereColumn('quantity', '<', 'alert_qty')
            ->orderBy('quantity')->limit(6)->get();
        foreach ($low as $p) {
            $items[] = [
                'text' => ($p->quantity <= 0 ? 'نفد المخزون: ' : 'مخزون منخفض: ') . $p->name . ' (' . $p->quantity . ' متبقٍ)',
                'time' => 'تنبيه مخزون',
                'icon' => 'alert-triangle', 'color' => $p->quantity <= 0 ? 'danger' : 'warning',
                'url' => route('admin.inventory.index'),
            ];
        }
        $pending = Order::where('business_id', $bid)->where('is_held', false)
            ->whereIn('status', ['جديد', 'قيد التجهيز'])->orderByDesc('id')->limit(3)->get();
        foreach ($pending as $o) {
            $items[] = [
                'text' => 'طلب ' . $o->number . ' بانتظار التجهيز',
                'time' => optional($o->ordered_at)->format('Y-m-d H:i'),
                'icon' => 'receipt', 'color' => 'info',
                'url' => route('admin.orders.show', $o->number),
            ];
        }
        return $items;
    }

    public static function notificationsCount(): int
    {
        return count(self::notifications());
    }

    /** بيانات وردية الموظف الحالي المحسوبة فعليًا */
    /** سجل الورديات المغلقة (آخر 10) */
    public static function shiftHistory(): array
    {
        return Shift::where('business_id', self::bid())->where('status', 'مغلقة')
            ->orderByDesc('closed_at')->limit(10)->get()->map(fn ($s) => [
                'employee' => $s->employee_name,
                'opened' => optional($s->opened_at)->format('Y-m-d H:i') ?? '—',
                'closed' => optional($s->closed_at)->format('Y-m-d H:i') ?? '—',
                'opening' => (float) $s->opening_balance,
                'cash_sales' => (float) $s->cash_sales,
                'card_sales' => (float) $s->card_sales,
                'expected' => (float) $s->expected_balance,
                'actual' => (float) $s->actual_balance,
                'difference' => (float) $s->difference,
            ])->all();
    }

    public static function currentShift(): array
    {
        $bid = self::bid();
        $name = auth()->user()?->name;
        $shift = Shift::where('business_id', $bid)->where('status', 'مفتوحة')->latest('id')->first();

        if (! $shift) {
            return ['exists' => false, 'employee' => $name ?? 'الموظف', 'opened_at' => '—',
                'opening' => 0, 'cash_sales' => 0, 'card_sales' => 0, 'returns' => 0,
                'expenses' => 0, 'expected' => 0, 'orders_count' => 0];
        }

        $since = $shift->opened_at ?? now()->startOfDay();
        $q = Order::where('business_id', $bid)->where('is_held', false)
            ->when($name, fn ($x) => $x->where('employee_name', $name))
            ->where('ordered_at', '>=', $since);
        $cash = (float) (clone $q)->where('payment_method', 'نقدي')->sum('total');
        $card = (float) (clone $q)->where('payment_method', 'بطاقة')->sum('total');
        $opening = (float) $shift->opening_balance;
        $returns = (float) $shift->returns;
        $expenses = (float) $shift->expenses;

        return [
            'exists' => true,
            'employee' => $shift->employee_name ?? $name,
            'opened_at' => optional($shift->opened_at)->format('Y-m-d H:i') ?? '—',
            'opening' => $opening,
            'cash_sales' => $cash,
            'card_sales' => $card,
            'returns' => $returns,
            'expenses' => $expenses,
            'expected' => $opening + $cash - $returns - $expenses,
            'orders_count' => (clone $q)->count(),
        ];
    }
}
