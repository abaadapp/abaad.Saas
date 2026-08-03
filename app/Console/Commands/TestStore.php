<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Addon;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseType;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Support\SeedData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * متجر تجريبي واحد ببيانات وهمية — لتجربة المنصة دون تلويث نظام العميل.
 *
 * منفصل عن DemoSeeder (١٢ متجرًا يُعاد بناء القاعدة من أجلها) وعن البذرة
 * النظيفة: يُضاف فوق أي قاعدة قائمة ويُحذف بأمر واحد قبل الإطلاق.
 *
 *   php artisan abaad:test-store          إنشاء (أو إعادة إنشاء)
 *   php artisan abaad:test-store --drop   حذف المتجر وكل صفوفه
 */
class TestStore extends Command
{
    protected $signature = 'abaad:test-store {--drop : حذف المتجر التجريبي وكل بياناته}';

    protected $description = 'إنشاء أو حذف متجر تجريبي ببيانات وهمية لتجربة المنصة';

    /**
     * الاسم المعروض. محايد بلا كلمة «تجريبي»: هذا المتجر يُعرَض على العملاء
     * أثناء البيع، وكانت الكلمة تتصدّر الشريط الجانبي وعنوان اللوحة وكل فاتورة.
     */
    private const NAME = 'متجر أبعاد';

    /**
     * العلامة التي يُعرَف بها المتجر — بريد المالك لا الاسم: الاسم نصّ معروض
     * قابل للتغيير من اللوحة، فلو تغيّر لم يعد --drop يجد ما يحذفه.
     */
    private const OWNER_EMAIL = 'Demo@abaadapp.om';

    private const CASHIER_EMAIL = 'test-cashier@abaad.app';

    private const PASSWORD = 'abaad@12345';

    private const PIN = '4321';

    /** المتجر التجريبي إن وُجد — يستعمله أمر الفحص abaad:preflight أيضًا */
    public static function find(): ?Business
    {
        $ownerBusinessId = User::where('email', self::OWNER_EMAIL)->value('business_id');

        return $ownerBusinessId ? Business::find($ownerBusinessId) : null;
    }

    public function handle(): int
    {
        $existing = self::find();

        if ($this->option('drop')) {
            if (! $existing) {
                $this->warn('لا يوجد متجر تجريبي.');

                return self::SUCCESS;
            }
            $this->purge($existing);
            $this->info('حُذف المتجر التجريبي وكل بياناته.');

            return self::SUCCESS;
        }

        if ($existing) {
            $this->warn('يوجد متجر تجريبي — يُحذف ثم يُعاد إنشاؤه.');
            $this->purge($existing);
        }

        DB::transaction(fn () => $this->build());

        $this->newLine();
        $this->info('✓ جاهز — ادخل بأيٍّ من الحسابين:');
        $this->line('  صاحب المتجر : ' . self::OWNER_EMAIL . '  /  ' . self::PASSWORD);
        $this->line('  الكاشير      : ' . self::CASHIER_EMAIL . '  /  ' . self::PASSWORD . '   (رمز نقطة البيع: ' . self::PIN . ')');
        $this->newLine();
        $this->comment('قبل الإطلاق للعملاء:  php artisan abaad:test-store --drop');

        return self::SUCCESS;
    }

    /**
     * حذف كل صف يخص هذا المتجر.
     *
     * يمرّ على الجداول التي فيها عمود business_id بدل سردها يدويًا، فلا يتخلّف
     * جدول جديد عن الحذف لاحقًا ويترك بيانات تجريبية معلّقة في نظام حيّ.
     */
    private function purge(Business $business): void
    {
        DB::transaction(function () use ($business) {
            // الأبناء الذين لا يحملون business_id يُحذفون عبر آبائهم أولًا
            DB::table('order_items')->whereIn(
                'order_id',
                DB::table('orders')->where('business_id', $business->id)->pluck('id')
            )->delete();

            DB::table('purchase_order_items')->whereIn(
                'purchase_order_id',
                DB::table('purchase_orders')->where('business_id', $business->id)->pluck('id')
            )->delete();

            DB::table('dismissed_notifications')->whereIn(
                'user_id',
                DB::table('users')->where('business_id', $business->id)->pluck('id')
            )->delete();

            foreach ($this->scopedTables() as $table) {
                DB::table($table)->where('business_id', $business->id)->delete();
            }

            $business->delete();
        });
    }

    /** @return list<string> الجداول التي تحمل عمود business_id */
    private function scopedTables(): array
    {
        $skip = ['migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sessions', 'password_reset_tokens'];

        return collect(Schema::getTableListing())
            ->map(fn ($t) => str_contains($t, '.') ? substr(strrchr($t, '.'), 1) : $t)
            ->reject(fn ($t) => in_array($t, $skip, true) || str_starts_with($t, 'sqlite_'))
            ->filter(fn ($t) => Schema::hasColumn($t, 'business_id'))
            ->values()
            ->all();
    }

    private function build(): void
    {
        $plan = Plan::where('name', 'الباقة الاحترافية')->first() ?? Plan::first();
        // تاريخ التسجيل قبل ٤ أشهر: يعطي مخطط نمو الشركات نقطة غير اليوم
        $registered = now()->startOfMonth()->subMonths(4)->addDays(8);

        $business = Business::create([
            'name' => self::NAME,
            // النوع من قائمة PageController::TYPES بالحرف: قسم «محلات الورود»
            // في لوحة المنصة يُصفّي على هذه القيمة تمامًا، وأي صياغة أخرى
            // تُخرج المتجر من القسم بلا رسالة
            'type' => 'محل ورود',
            'owner_name' => 'سالم الحارثي',
            'phone' => '+968 90000000',
            'email' => self::OWNER_EMAIL,
            'country' => 'عُمان',
            'city' => 'مسقط',
            'address' => 'مسقط — عُمان',
            'plan_id' => $plan?->id,
            'status' => 'نشط',
            'branches_count' => 2,
            'starts_at' => $registered,
            'ends_at' => $registered->copy()->addYear(),
        ]);
        $bid = $business->id;

        /* ------------------------------ المستخدمون ------------------------------ */
        User::create([
            'business_id' => $bid, 'name' => 'سالم الحارثي', 'email' => self::OWNER_EMAIL,
            'role' => 'admin', 'phone' => '+968 90000000', 'password' => Hash::make(self::PASSWORD),
            'status' => 'نشط', 'branch' => 'الفرع الرئيسي', 'job_title' => 'مدير', 'last_login_at' => now(),
        ]);

        // كاشير له رمز حقيقي — بدونه لا يمكن تجربة شاشة الرمز
        User::create([
            'business_id' => $bid, 'name' => 'نورة البلوشي', 'email' => self::CASHIER_EMAIL,
            'role' => 'cashier', 'phone' => '+968 90000001', 'password' => Hash::make(self::PASSWORD),
            'pin' => self::PIN, 'status' => 'نشط', 'branch' => 'الفرع الرئيسي',
            'job_title' => 'كاشير', 'last_login_at' => now()->subHours(3),
        ]);

        foreach ([['خالد الشعيلي', 'manager', 'مدير'], ['ريم الكندي', 'accountant', 'محاسب']] as $i => [$name, $role, $title]) {
            User::create([
                'business_id' => $bid, 'name' => $name, 'email' => "test-{$role}@abaad.app",
                'role' => $role, 'password' => Hash::make(self::PASSWORD), 'status' => 'نشط',
                'branch' => 'الفرع الرئيسي', 'job_title' => $title,
                'last_login_at' => now()->subDays($i + 1),
            ]);
        }

        /* ------------------------------ الافتراضيات ------------------------------ */
        $branches = collect(['الفرع الرئيسي', 'فرع القرم'])->map(fn ($n) => Branch::create([
            'business_id' => $bid, 'name' => $n, 'phone' => '+968 24000000', 'address' => 'مسقط',
        ]))->all();

        Currency::create([
            'business_id' => $bid, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع',
            'rate' => 1, 'is_base' => true, 'active' => true,
        ]);

        foreach (JobTitle::ROLES as $roleKey => $roleLabel) {
            JobTitle::create(['business_id' => $bid, 'name' => $roleLabel, 'role' => $roleKey]);
        }

        foreach ([
            'إيجار' => 'إيجار المحل والمستودعات',
            'رواتب' => 'رواتب وأجور الموظفين',
            'كهرباء وماء' => 'فواتير الكهرباء والمياه',
            'مواد خام' => 'شراء المواد والبضاعة',
            'تسويق' => 'الإعلانات والحملات التسويقية',
            'صيانة' => 'صيانة المعدات والمحل',
            'نقل وتوصيل' => 'مصاريف الشحن والتوصيل',
        ] as $name => $desc) {
            ExpenseType::create(['business_id' => $bid, 'name' => $name, 'description' => $desc]);
        }

        /* ------------------------------ الكتالوج ------------------------------ */
        $catId = [];
        foreach (SeedData::categories() as $c) {
            $catId[$c['name']] = Category::create([
                'business_id' => $bid, 'name' => $c['name'], 'icon' => $c['icon'], 'color' => $c['color'],
            ])->id;
        }

        foreach (SeedData::addons() as $a) {
            Addon::create([
                'business_id' => $bid, 'name' => $a['name'], 'price' => $a['price'],
                'icon' => $a['icon'], 'active' => true,
            ]);
        }

        $products = [];
        foreach (SeedData::products() as $p) {
            $products[] = $product = Product::create([
                'business_id' => $bid, 'category_id' => $catId[$p['cat']] ?? null,
                'name' => $p['name'], 'description' => $p['name'],
                'sku' => $p['sku'], 'barcode' => $p['barcode'], 'price' => $p['price'], 'cost' => $p['cost'],
                'quantity' => $p['qty'], 'alert_qty' => $p['alert'], 'tax' => $p['tax'],
                'discount' => $p['discount'], 'image' => $p['image'], 'active' => $p['active'],
            ]);
            // الرصيد الافتتاحي على الفرع الرئيسي ليساوي مجموع الفروع كمية المنتج
            BranchStock::create([
                'business_id' => $bid, 'branch_id' => $branches[0]->id,
                'product_id' => $product->id, 'quantity' => (int) $product->quantity,
            ]);
        }

        $customerId = [];
        foreach (SeedData::customers() as $c) {
            $customerId[$c['name']] = Customer::create([
                'business_id' => $bid, 'name' => $c['name'], 'phone' => $c['phone'],
                'email' => $c['email'], 'points' => $c['points'], 'address' => 'مسقط',
            ])->id;
        }

        /* ------------------------------ الحركة ------------------------------ */
        foreach (SeedData::orders() as $i => $o) {
            $tax = round($o['total'] - $o['total'] / 1.05, 3);
            $branch = $branches[$i % count($branches)];
            $order = Order::create([
                'business_id' => $bid, 'number' => 'TEST-' . (10500 + $i), 'branch_id' => $branch->id,
                'customer_id' => $customerId[$o['customer']] ?? null,
                'customer_name' => $o['customer'], 'employee_name' => $o['employee'],
                'branch' => $branch->name, 'status' => $o['status'], 'payment_method' => $o['payment'],
                'payment_status' => $o['status'] === 'ملغي' ? 'غير مدفوع' : 'مدفوع',
                'subtotal' => round($o['total'] - $tax, 3), 'tax' => $tax, 'total' => $o['total'],
                // موزّعة على ١٧٥ يومًا حتى تمتلئ مخططات المبيعات الشهرية
                'ordered_at' => now()->subDays(($i * 9) % 175)->setTime(10 + ($i % 8), ($i * 7) % 60),
            ]);
            for ($k = 0; $k < min(max(1, $o['items_count']), 3); $k++) {
                $pr = $products[($i + $k) % count($products)];
                $qty = 1 + (($i + $k) % 3);
                $order->items()->create([
                    'product_id' => $pr->id, 'name' => $pr->name, 'price' => $pr->price,
                    'quantity' => $qty, 'total' => round($pr->price * $qty, 3),
                ]);
            }
        }

        // طلبات معلّقة — تظهر في شاشة «الطلبات» بنقطة البيع
        foreach (SeedData::heldOrders() as $i => $h) {
            Order::create([
                'business_id' => $bid, 'number' => 'TEST-HOLD-' . (300 + $i), 'customer_name' => $h['customer'],
                'employee_name' => $h['employee'], 'status' => 'معلّق', 'is_held' => true,
                'subtotal' => $h['total'], 'total' => $h['total'], 'ordered_at' => now(),
            ]);
        }

        foreach (SeedData::movements() as $m) {
            $at = \Illuminate\Support\Carbon::parse($m['date']);
            InventoryMovement::create([
                'business_id' => $bid, 'product_name' => $m['product'], 'sku' => $m['sku'],
                'type' => $m['type'], 'quantity' => $m['qty'], 'employee_name' => $m['employee'],
                'created_at' => $at, 'updated_at' => $at,
            ]);
        }

        foreach (SeedData::expenses() as $i => $e) {
            $spentAt = \Illuminate\Support\Carbon::parse($e['date']);
            Expense::create([
                'business_id' => $bid, 'reference' => 'TEST-EXP-' . (1001 + $i),
                'type' => $e['type'], 'description' => $e['description'], 'amount' => $e['amount'],
                'method' => $e['method'], 'employee_name' => $e['employee'], 'spent_at' => $spentAt,
                'due_date' => $spentAt->copy()->addDays(15),
                'status' => $i % 4 === 0 ? 'غير مدفوع' : 'مدفوع',
            ]);
        }

        foreach (SeedData::transactions() as $i => $t) {
            Transaction::create([
                'business_id' => $bid, 'reference' => 'TEST-' . $t['id'], 'description' => $t['description'],
                'method' => $t['method'], 'type' => $t['type'], 'amount' => $t['amount'],
                'employee_name' => $t['employee'], 'occurred_at' => now()->subDays(($i * 12) % 175),
            ]);
        }

        /* --------------------- الاشتراك والفواتير (لوحة المنصة) --------------------- */
        Subscription::create([
            'business_id' => $bid, 'plan_id' => $plan?->id,
            'starts_at' => $registered, 'ends_at' => $registered->copy()->addYear(),
            'amount' => $plan?->yearly_price ?? 0, 'payment_status' => 'مدفوع', 'status' => 'نشط',
        ]);

        // فاتورة لكل شهر منذ التسجيل — تملأ شاشة الفواتير ومخطط إيرادات المنصة
        for ($i = 4; $i >= 0; $i--) {
            $issued = now()->startOfMonth()->subMonths($i)->addDays(8);
            Invoice::create([
                'number' => 'TEST-INV-' . $issued->format('Ymd'),
                'business_id' => $bid, 'plan_id' => $plan?->id,
                'amount' => $plan?->monthly_price ?? 0,
                'issued_at' => $issued,
                'status' => $i === 0 ? 'غير مدفوعة' : 'مدفوعة',
            ]);
        }

        /* ------------------------------ سجل النشاط ------------------------------ */
        $acts = [
            ['نورة البلوشي', 'checkout', 'أتمّ بيعًا بقيمة 24.500 ر.ع', 'shopping-cart', 'success'],
            ['سالم الحارثي', 'created', 'أضاف منتجًا: باقة ورد أحمر', 'plus-circle', 'success'],
            ['خالد الشعيلي', 'status', 'غيّر حالة طلب إلى جاهز', 'refresh-cw', 'warning'],
            ['ريم الكندي', 'created', 'سجّل مصروف إيجار', 'plus-circle', 'success'],
            ['سالم الحارثي', 'settings', 'حدّث إعدادات المتجر', 'settings', 'primary'],
        ];
        foreach ($acts as $i => [$name, $action, $desc, $icon, $color]) {
            $at = now()->subHours($i * 5);
            ActivityLog::create([
                'business_id' => $bid, 'user_name' => $name, 'action' => $action,
                'description' => $desc, 'icon' => $icon, 'color' => $color, 'ip' => '127.0.0.1',
                'created_at' => $at, 'updated_at' => $at,
            ]);
        }
    }
}
