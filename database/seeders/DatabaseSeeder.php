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

        foreach (SeedData::expenses() as $i => $e) {
            $spentAt = Carbon::parse($e['date']);
            Expense::create([
                'business_id' => $primary->id,
                'reference' => 'EXP-' . (1001 + $i),
                'type' => $e['type'], 'description' => $e['description'],
                'amount' => $e['amount'], 'method' => $e['method'], 'employee_name' => $e['employee'],
                'spent_at' => $spentAt,
                'due_date' => $spentAt->copy()->addDays(15),
                'status' => $i % 4 === 0 ? 'غير مدفوع' : 'مدفوع',
            ]);
        }

        // أنواع المصروفات الافتراضية لكل نشاط
        $defaultExpenseTypes = [
            'إيجار' => 'إيجار المحل والمستودعات',
            'رواتب' => 'رواتب وأجور الموظفين',
            'كهرباء وماء' => 'فواتير الكهرباء والمياه',
            'مواد خام' => 'شراء المواد والبضاعة',
            'تسويق' => 'الإعلانات والحملات التسويقية',
            'صيانة' => 'صيانة المعدات والمحل',
            'نقل وتوصيل' => 'مصاريف الشحن والتوصيل',
        ];
        foreach (Business::pluck('id') as $bizId) {
            foreach ($defaultExpenseTypes as $typeName => $typeDesc) {
                \App\Models\ExpenseType::firstOrCreate(
                    ['business_id' => $bizId, 'name' => $typeName],
                    ['description' => $typeDesc]
                );
            }
        }

        // الوظائف الافتراضية لكل نشاط + ربط الموظفين الحاليين بها
        // (كل وظيفة مرتبطة بصلاحية نظام حتى لا يفقد الموظف الدخول)
        $roleLabels = \App\Models\JobTitle::ROLES + ['admin' => 'مدير'];
        foreach (Business::pluck('id') as $bizId) {
            foreach (\App\Models\JobTitle::ROLES as $roleKey => $roleLabel) {
                \App\Models\JobTitle::firstOrCreate(
                    ['business_id' => $bizId, 'name' => $roleLabel],
                    ['role' => $roleKey]
                );
            }
        }
        foreach (User::whereNotNull('role')->get(['id', 'role']) as $u) {
            if ($label = $roleLabels[$u->role] ?? null) {
                User::where('id', $u->id)->update(['job_title' => $label]);
            }
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

        // أهداف وعمولات تجريبية للموظفين
        foreach (\App\Models\User::where('role', '!=', 'super_admin')->get() as $i => $u) {
            $u->update([
                'monthly_target' => [3000, 4500, 6000, 2500, 5000][$i % 5],
                'commission_rate' => [2, 3, 2.5, 1.5, 4][$i % 5],
            ]);
        }

        // مورّدون + أوامر شراء + ذمم تجريبية لكل متجر
        $supplierNames = [
            ['name' => 'مؤسسة الخليج للتوريدات', 'phone' => '96824601122', 'contact_person' => 'سالم الراشدي'],
            ['name' => 'شركة النور للجملة', 'phone' => '96824703344', 'contact_person' => 'يوسف البلوشي'],
            ['name' => 'مزارع ظفار', 'phone' => '96823805566', 'contact_person' => 'أحمد المعشني'],
        ];
        foreach (\App\Models\Business::pluck('id') as $bizId) {
            $supplierIds = [];
            foreach ($supplierNames as $sn) {
                $supplierIds[] = \App\Models\Supplier::create(array_merge($sn, [
                    'business_id' => $bizId,
                    'email' => 'sales@' . \Illuminate\Support\Str::random(6) . '.com',
                ]))->id;
            }

            $products = \App\Models\Product::where('business_id', $bizId)->take(4)->get();
            if ($products->count()) {
                // أمر شراء مُرسل (لم يُستلم بعد)
                $po1 = \App\Models\PurchaseOrder::create([
                    'business_id' => $bizId, 'number' => 'PO-' . rand(10000, 99999),
                    'supplier_id' => $supplierIds[0], 'supplier_name' => $supplierNames[0]['name'],
                    'status' => 'مُرسل', 'total' => 0, 'ordered_at' => now()->subDays(3),
                ]);
                $t1 = 0;
                foreach ($products->take(3) as $p) {
                    $qty = 20;
                    $po1->items()->create(['product_id' => $p->id, 'name' => $p->name, 'cost' => $p->cost, 'quantity' => $qty]);
                    $t1 += $p->cost * $qty;
                }
                $po1->update(['total' => $t1]);

                // أمر شراء مستلم
                $po2 = \App\Models\PurchaseOrder::create([
                    'business_id' => $bizId, 'number' => 'PO-' . rand(10000, 99999),
                    'supplier_id' => $supplierIds[1], 'supplier_name' => $supplierNames[1]['name'],
                    'status' => 'مستلم', 'total' => 0, 'ordered_at' => now()->subDays(15), 'received_at' => now()->subDays(12),
                ]);
                $t2 = 0;
                foreach ($products->take(2) as $p) {
                    $qty = 15;
                    $po2->items()->create(['product_id' => $p->id, 'name' => $p->name, 'cost' => $p->cost, 'quantity' => $qty, 'received_quantity' => $qty]);
                    $t2 += $p->cost * $qty;
                }
                $po2->update(['total' => $t2]);
            }
        }

        // كوبونات تجريبية + ورديات مغلقة + إعدادات ضريبية (للمتجر الأساسي)
        $mainBiz = \App\Models\Business::whereHas('users', fn ($q) => $q->where('role', 'admin'))->value('id')
            ?? \App\Models\Business::value('id');
        if ($mainBiz) {
            $coupons = [
                ['code' => 'WELCOME10', 'type' => 'نسبة', 'value' => 10, 'min_order' => 5, 'max_uses' => 100, 'used_count' => 12],
                ['code' => 'SUMMER5', 'type' => 'مبلغ', 'value' => 5, 'min_order' => 20, 'max_uses' => null, 'used_count' => 4],
                ['code' => 'VIP20', 'type' => 'نسبة', 'value' => 20, 'min_order' => 50, 'max_uses' => 30, 'used_count' => 0],
            ];
            foreach ($coupons as $cp) {
                \App\Models\Coupon::create(array_merge($cp, [
                    'business_id' => $mainBiz, 'active' => true,
                    'expires_at' => now()->addMonths(2),
                ]));
            }

            // إعدادات الضريبة
            \App\Models\Setting::updateOrCreate(['business_id' => $mainBiz, 'key' => 'vat_rate'], ['value' => '5']);
            \App\Models\Setting::updateOrCreate(['business_id' => $mainBiz, 'key' => 'vat_number'], ['value' => 'OM1100234567']);

            // ورديات مغلقة سابقة
            $adminUser = \App\Models\User::where('business_id', $mainBiz)->where('role', 'admin')->first();
            $shifts = [
                ['days' => 2, 'open' => 50, 'cash' => 320.500, 'card' => 180.000, 'actual' => 370.000],
                ['days' => 1, 'open' => 50, 'cash' => 415.750, 'card' => 210.250, 'actual' => 465.750],
            ];
            foreach ($shifts as $sh) {
                $expected = $sh['open'] + $sh['cash'];
                \App\Models\Shift::create([
                    'business_id' => $mainBiz, 'user_id' => $adminUser?->id, 'employee_name' => $adminUser?->name ?? 'الكاشير',
                    'opened_at' => now()->subDays($sh['days'])->setTime(8, 0), 'closed_at' => now()->subDays($sh['days'])->setTime(22, 0),
                    'opening_balance' => $sh['open'], 'cash_sales' => $sh['cash'], 'card_sales' => $sh['card'],
                    'expected_balance' => $expected, 'actual_balance' => $sh['actual'], 'difference' => $sh['actual'] - $expected,
                    'status' => 'مغلقة',
                ]);
            }
        }
    }
}
