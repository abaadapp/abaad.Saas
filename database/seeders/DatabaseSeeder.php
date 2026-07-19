<?php

namespace Database\Seeders;

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
use App\Models\Setting;
use App\Models\Shift;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Support\SeedData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /** خريطة الأدوار العربية → مفاتيح النظام */
    private array $roleMap = [
        'مالك النشاط' => 'admin', 'مدير' => 'manager', 'كاشير' => 'cashier',
        'موظف مبيعات' => 'sales', 'محاسب' => 'accountant', 'مسؤول مخزون' => 'inventory',
        'مندوب توصيل' => 'delivery',
    ];

    public function run(): void
    {
        /* ---------------- الباقات ---------------- */
        $planByName = [];
        $limits = [
            'الباقة الأساسية' => [1, 3, 100],
            'الباقة الاحترافية' => [3, 15, 100000],
            'باقة المؤسسات' => [999, 999, 1000000],
        ];
        foreach (SeedData::plans() as $p) {
            $lim = $limits[$p['name']] ?? [1, 3, 100];
            $plan = Plan::create([
                'name' => $p['name'],
                'monthly_price' => $p['monthly'],
                'yearly_price' => $p['yearly'],
                'max_branches' => $lim[0],
                'max_employees' => $lim[1],
                'max_products' => $lim[2],
                'features' => $p['features'],
                'color' => $p['color'],
                'is_popular' => $p['popular'],
            ]);
            $planByName[$p['name']] = $plan->id;
        }
        // أسماء مختصرة تُستخدم في بيانات الشركات/الاشتراكات
        foreach (['الباقة الأساسية' => 'أساسية', 'الباقة الاحترافية' => 'احترافية', 'باقة المؤسسات' => 'مؤسسات'] as $full => $short) {
            if (isset($planByName[$full])) {
                $planByName[$short] = $planByName[$full];
            }
        }

        /* ---------------- الشركات ---------------- */
        $bizByName = [];
        foreach (SeedData::businesses() as $bi => $b) {
            // توزيع تواريخ التسجيل على آخر 6 أشهر (لإثراء مخطط نمو الشركات)
            $registered = now()->subMonths($bi % 6)->subDays(($bi * 3) % 25);
            $biz = Business::create([
                'name' => $b['name'],
                'type' => $b['type'],
                'owner_name' => $b['owner'],
                'phone' => $b['phone'],
                'email' => $b['email'],
                'country' => $b['country'],
                'city' => $b['city'],
                'address' => $b['city'] . ' — عُمان',
                'plan_id' => $planByName[$b['plan']] ?? null,
                'logo' => $b['logo'],
                'status' => $b['status'],
                'branches_count' => $b['branches'],
                'starts_at' => $registered,
                'ends_at' => $registered->copy()->addYear(),
            ]);
            $bizByName[$b['name']] = $biz;
        }
        $primary = $bizByName['زهرة مسقط'];

        /* ---------------- المستخدمون ---------------- */
        // مدير المنصة
        User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'super@abadpos.com',
            'role' => 'super_admin', 'password' => Hash::make('password'), 'status' => 'نشط',
            'avatar' => SeedData::image('superadmin', 100, 100), 'last_login_at' => now(),
        ]);

        // مالك لكل نشاط
        foreach ($bizByName as $name => $biz) {
            $email = $biz->id === $primary->id ? 'admin@abadpos.com' : 'owner' . $biz->id . '@abadpos.com';
            User::create([
                'business_id' => $biz->id, 'name' => $biz->owner_name, 'email' => $email,
                'role' => 'admin', 'phone' => $biz->phone, 'password' => Hash::make('password'),
                'status' => 'نشط', 'branch' => 'الفرع الرئيسي',
                'avatar' => SeedData::image('owner' . $biz->id, 100, 100), 'last_login_at' => now(),
            ]);
        }

        // مستخدمو المنصة الإضافيون
        foreach (SeedData::platformUsers() as $u) {
            $biz = $bizByName[$u['business']] ?? $primary;
            User::firstOrCreate(['email' => $u['email']], [
                'business_id' => $biz->id, 'name' => $u['name'], 'phone' => $u['phone'],
                'role' => $this->roleMap[$u['role']] ?? 'sales', 'status' => $u['status'],
                'password' => Hash::make('password'), 'avatar' => $u['avatar'],
                'last_login_at' => Carbon::parse($u['last_login']),
            ]);
        }

        // موظفو النشاط الأساسي
        foreach (SeedData::employees() as $i => $e) {
            $email = $i === 1 ? 'cashier@abadpos.com' : $e['email'];
            $role = $i === 1 ? 'cashier' : ($this->roleMap[$e['role']] ?? 'sales');
            User::firstOrCreate(['email' => $email], [
                'business_id' => $primary->id, 'name' => $e['name'], 'phone' => $e['phone'],
                'role' => $role, 'status' => $e['status'], 'branch' => $e['branch'],
                'sales_total' => $e['sales'], 'avatar' => $e['avatar'],
                'password' => Hash::make('password'), 'last_login_at' => now(),
            ]);
        }

        /* -------- محتوى النشاط الأساسي (زهرة مسقط) -------- */
        $branches = [];
        foreach (['الفرع الرئيسي', 'فرع صلالة'] as $bn) {
            $branches[] = $primary->branches()->create(['name' => $bn, 'phone' => '+968 24000000', 'address' => 'مسقط']);
        }

        $catByName = [];
        foreach (SeedData::categories() as $c) {
            $cat = Category::create([
                'business_id' => $primary->id, 'name' => $c['name'],
                'icon' => $c['icon'], 'color' => $c['color'],
            ]);
            $catByName[$c['name']] = $cat->id;
        }

        $products = [];
        foreach (SeedData::products() as $p) {
            $products[] = Product::create([
                'business_id' => $primary->id, 'category_id' => $catByName[$p['cat']] ?? null,
                'name' => $p['name'], 'description' => 'منتج ' . $p['name'] . ' من متجر زهرة مسقط.',
                'sku' => $p['sku'], 'barcode' => $p['barcode'], 'price' => $p['price'], 'cost' => $p['cost'],
                'quantity' => $p['qty'], 'alert_qty' => $p['alert'], 'tax' => $p['tax'],
                'discount' => $p['discount'], 'image' => $p['image'], 'active' => $p['active'],
            ]);
        }

        $customersByName = [];
        foreach (SeedData::customers() as $c) {
            $cust = Customer::create([
                'business_id' => $primary->id, 'name' => $c['name'], 'phone' => $c['phone'],
                'email' => $c['email'], 'points' => $c['points'], 'address' => 'مسقط',
            ]);
            $customersByName[$c['name']] = $cust->id;
        }

        // الطلبات + العناصر (توزيع التواريخ على آخر 6 أشهر لإثراء المخططات)
        foreach (SeedData::orders() as $idx => $o) {
            $tax = round($o['total'] - $o['total'] / 1.05, 3);
            $branch = $branches[$idx % count($branches)];
            $order = Order::create([
                'business_id' => $primary->id, 'number' => $o['id'],
                'branch_id' => $branch->id,
                'customer_id' => $customersByName[$o['customer']] ?? null,
                'customer_name' => $o['customer'], 'employee_name' => $o['employee'],
                'branch' => $branch->name, 'status' => $o['status'], 'payment_method' => $o['payment'],
                'payment_status' => in_array($o['status'], ['ملغي']) ? 'غير مدفوع' : 'مدفوع',
                'subtotal' => round($o['total'] - $tax, 3), 'tax' => $tax, 'total' => $o['total'],
                'ordered_at' => now()->subDays(($idx * 9) % 175)->setTime(10 + ($idx % 8), ($idx * 7) % 60),
            ]);
            $count = max(1, $o['items_count']);
            for ($k = 0; $k < min($count, 3); $k++) {
                $pr = $products[($order->id + $k) % count($products)];
                $qty = rand(1, 3);
                $order->items()->create([
                    'product_id' => $pr->id, 'name' => $pr->name, 'price' => $pr->price,
                    'quantity' => $qty, 'total' => round($pr->price * $qty, 3),
                ]);
            }
        }

        // طلبات معلّقة
        foreach (SeedData::heldOrders() as $h) {
            Order::create([
                'business_id' => $primary->id, 'number' => $h['id'], 'customer_name' => $h['customer'],
                'employee_name' => $h['employee'], 'status' => 'معلّق', 'is_held' => true,
                'subtotal' => $h['total'], 'total' => $h['total'], 'ordered_at' => now(),
            ]);
        }

        foreach (SeedData::movements() as $m) {
            InventoryMovement::create([
                'business_id' => $primary->id, 'product_name' => $m['product'], 'sku' => $m['sku'],
                'type' => $m['type'], 'quantity' => $m['qty'], 'employee_name' => $m['employee'],
                'created_at' => Carbon::parse($m['date']), 'updated_at' => Carbon::parse($m['date']),
            ]);
        }

        foreach (SeedData::expenses() as $e) {
            Expense::create([
                'business_id' => $primary->id, 'type' => $e['type'], 'description' => $e['description'],
                'amount' => $e['amount'], 'method' => $e['method'], 'employee_name' => $e['employee'],
                'spent_at' => Carbon::parse($e['date']),
            ]);
        }

        foreach (SeedData::transactions() as $ti => $t) {
            Transaction::create([
                'business_id' => $primary->id, 'reference' => $t['id'], 'description' => $t['description'],
                'method' => $t['method'], 'type' => $t['type'], 'amount' => $t['amount'],
                'employee_name' => $t['employee'], 'occurred_at' => now()->subDays(($ti * 12) % 175),
            ]);
        }

        // وردية مفتوحة
        Shift::create([
            'business_id' => $primary->id, 'employee_name' => 'سارة حسن',
            'opened_at' => now()->setTime(9, 0), 'opening_balance' => 50.000,
            'cash_sales' => 214.500, 'card_sales' => 168.000, 'returns' => 12.000,
            'expenses' => 8.500, 'expected_balance' => 412.000, 'actual_balance' => 0,
            'difference' => 0, 'status' => 'مفتوحة',
        ]);

        /* ---------------- الاشتراكات والفواتير ---------------- */
        foreach (SeedData::subscriptions() as $s) {
            $biz = $bizByName[$s['business']] ?? null;
            if (! $biz) continue;
            Subscription::create([
                'business_id' => $biz->id, 'plan_id' => $planByName[$s['plan']] ?? null,
                'starts_at' => Carbon::parse($s['start']), 'ends_at' => Carbon::parse($s['end']),
                'amount' => $s['amount'], 'payment_status' => $s['payment'], 'status' => $s['status'],
            ]);
        }
        foreach (SeedData::invoices() as $ii => $inv) {
            $biz = $bizByName[$inv['business']] ?? null;
            if (! $biz) continue;
            Invoice::create([
                'number' => $inv['number'], 'business_id' => $biz->id,
                'plan_id' => $planByName[$inv['plan']] ?? null, 'amount' => $inv['amount'],
                'issued_at' => now()->subMonths($ii % 6)->subDays($ii % 20), 'status' => $inv['status'],
            ]);
        }

        /* ---------------- الإعدادات ---------------- */
        $settings = [
            [null, 'platform_name', 'Abad POS'],
            [null, 'currency', 'ريال عماني'],
            [null, 'currency_decimals', '3'],
            [null, 'vat_rate', '5'],
            [$primary->id, 'business_name', 'زهرة مسقط'],
            [$primary->id, 'vat_number', 'OM100234567'],
            [$primary->id, 'delivery_fee', '2.000'],
        ];
        foreach ($settings as [$bid, $key, $val]) {
            Setting::create(['business_id' => $bid, 'key' => $key, 'value' => $val]);
        }

        /* ---------------- سجل النشاط (بيانات أولية) ---------------- */
        $acts = [
            [$primary->id, 'سارة حسن', 'checkout', 'أتمّ بيعًا INV-78901 بقيمة 24.500 ر.ع', 'shopping-cart', 'success'],
            [$primary->id, 'أحمد محمد', 'created', 'أضاف منتجًا: باقة ورد أحمر', 'plus-circle', 'success'],
            [$primary->id, 'صاحب النشاط', 'updated', 'عدّل المنتج: بوكيه زفاف', 'pencil', 'info'],
            [$primary->id, 'سارة حسن', 'status', 'غيّر حالة الطلب ORD-10503 إلى جاهز', 'refresh-cw', 'warning'],
            [$primary->id, 'خالد علي', 'created', 'سجّل مصروف إيجار بقيمة 250.000', 'plus-circle', 'success'],
            [$primary->id, 'صاحب النشاط', 'settings', 'حدّث إعدادات النشاط', 'settings', 'primary'],
            [$primary->id, 'سارة حسن', 'login', 'سجّلت الدخول إلى النظام', 'log-in', 'primary'],
            [null, 'مدير المنصة', 'created', 'أضاف شركة: عطر الورد', 'plus-circle', 'success'],
            [null, 'مدير المنصة', 'settings', 'حدّث إعدادات المنصة', 'settings', 'primary'],
        ];
        foreach ($acts as $ai => [$bid, $name, $action, $desc, $icon, $color]) {
            \App\Models\ActivityLog::create([
                'business_id' => $bid, 'user_name' => $name, 'action' => $action,
                'description' => $desc, 'icon' => $icon, 'color' => $color, 'ip' => '127.0.0.1',
                'created_at' => now()->subHours($ai * 3), 'updated_at' => now()->subHours($ai * 3),
            ]);
        }

        // العملة الوحيدة المعتمدة: الريال العماني (الأساسية)
        $defaultCurrencies = [
            ['code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true],
        ];
        foreach (\App\Models\Business::pluck('id') as $bizId) {
            foreach ($defaultCurrencies as $cur) {
                \App\Models\Currency::create(array_merge($cur, ['business_id' => $bizId, 'active' => true]));
            }
        }
    }
}
