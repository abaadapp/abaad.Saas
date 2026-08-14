<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\DeliveryNote;
use App\Models\Expense;
use App\Models\ExpenseType;
use App\Models\FixedAsset;
use App\Models\Invoice;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\Plan;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Review;
use App\Models\StockAdjustment;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * بناء متجرٍ تجريبيّ ممتلئ — ومحوُه.
 *
 * الغرض عرضُ النظام كما يبدو بعد سنةٍ من العمل لا بعد يومٍ منه: شاشةٌ فارغة
 * لا تبيع شيئًا، والتاجر الذي يفتح «المالية» فيجد صفرًا لا يعرف ماذا اشترى.
 *
 * وكلّ كتابةٍ هنا تمرّ من `DemoGuard` — انظره لِمَ.
 *
 * والدفتر يبقى متوازنًا: القيود تُرحَّل بـ`Ledger::post` كما ترحّلها الشاشات،
 * فما يُعرَض ميزانٌ حقيقيّ لا أرقامٌ مرصوصة. ومبيعات الشهر تُرحَّل قيدًا
 * واحدًا لا قيدًا لكل فاتورة: ثلاثة آلاف قيدٍ تُبنى في دقائق وتُقرأ في
 * ثوانٍ، والملخّص الشهريّ ممارسةٌ محاسبيّة قائمة لا اختصارًا مصطنعًا.
 */
class DemoStore
{
    public const PASSWORD = 'abaad@12345';

    public const PIN = '4321';

    /**
     * البريد يحمل معرّف المتجر.
     *
     * كان ثابتًا (`Demo@abaadapp.om`)، فأوّل متجرٍ تجريبيّ ثانٍ يصطدم بقيد
     * التفرّد على `users.email` ويسقط البناء في منتصفه. والمتاجر التجريبيّة
     * تُنشأ أكثر من واحد: واحدٌ للعرض وآخرُ للتجربة على حجمٍ أكبر.
     */
    public static function ownerEmail(int $businessId): string
    {
        return "demo{$businessId}@abaadapp.om";
    }

    public static function cashierEmail(int $businessId): string
    {
        return "demo{$businessId}-cashier@abaadapp.om";
    }

    /**
     * الأحجام — عددُ المنتجات والعملاء والفواتير وشهور التاريخ.
     *
     * و«كبير» ليس بلا حدّ: الخادم بذاكرةٍ واحدة، والبناء يجري في طلبٍ واحد.
     * ما فوق هذا يحتاج طابورًا — وهو معطّلٌ على الخادم اليوم.
     */
    public const SIZES = [
        'صغير' => ['products' => 40, 'customers' => 60, 'orders' => 250, 'months' => 6, 'suppliers' => 6],
        'متوسط' => ['products' => 120, 'customers' => 250, 'orders' => 1200, 'months' => 12, 'suppliers' => 12],
        'كبير' => ['products' => 300, 'customers' => 700, 'orders' => 3500, 'months' => 18, 'suppliers' => 20],
    ];


    private const CITIES = ['مسقط', 'صلالة', 'صحار', 'نزوى', 'صور', 'البريمي', 'الرستاق', 'إبراء'];

    private const FIRST = ['سالم', 'أحمد', 'خالد', 'ناصر', 'يوسف', 'سعيد', 'راشد', 'حمد', 'ماجد', 'طلال',
        'نورة', 'ريم', 'مها', 'هدى', 'أسماء', 'فاطمة', 'شيماء', 'لطيفة', 'عائشة', 'منى'];

    private const LAST = ['الحارثي', 'البلوشي', 'الشعيلي', 'الكندي', 'المعمري', 'الرواحي', 'السيابي',
        'الهنائي', 'العامري', 'البوسعيدي', 'الزدجالي', 'الغيثي'];

    /**
     * عشوائيّةٌ مُبذَّرة.
     *
     * `mt_srand` بمعرّف المتجر: يُعاد بناء الديمو فيخرج مثلَ سابقه، فيقارن
     * من يعرض النظام لقطتين ولا يجد الأرقام تبدّلت تحته. ولا تُستعمل هنا
     * عشوائيّةٌ للأمان — أسماءٌ وأسعار.
     */
    private function __construct(private readonly Business $business, private readonly array $size)
    {
        mt_srand($business->id * 7919);
    }

    /* ===================================================================== */

    /** المتاجر التجريبيّة كلّها */
    public static function all()
    {
        return Business::demo()->orderBy('id')->get();
    }

    /**
     * ينشئ متجرًا تجريبيًّا موسومًا ويملؤه.
     *
     * الوسم يُكتب مع الإنشاء لا بعده: متجرٌ يُنشأ ثمّ يُوسَم يعيش لحظةً
     * حقيقيًّا — تكفي ليدخل عدّادًا أو تقريرًا يُقرأ في تلك اللحظة.
     */
    public static function create(string $name, string $size = 'متوسط'): Business
    {
        $spec = self::SIZES[$size] ?? self::SIZES['متوسط'];
        $plan = Plan::where('name', 'الباقة الاحترافية')->first() ?? Plan::first();
        $registered = now()->startOfMonth()->subMonths($spec['months'])->addDays(8);

        $business = Business::create([
            'name' => $name,
            'is_demo' => true,
            'type' => 'محل ورود',
            'owner_name' => 'سالم الحارثي',
            'phone' => '+968 90000000',
            'country' => 'عُمان',
            'city' => 'مسقط',
            'address' => 'مسقط — عُمان',
            'plan_id' => $plan?->id,
            'status' => 'نشط',
            'branches_count' => 2,
            'starts_at' => $registered,
            'ends_at' => $registered->copy()->addYear(),
        ]);

        $business->update(['email' => self::ownerEmail($business->id)]);

        (new self($business, $spec))->fill($registered, $plan);

        return $business->refresh();
    }

    /** يمحو بيانات المتجر ويعيد بناءها بحجمٍ جديد — والمتجر نفسه يبقى */
    public static function reseed(Business $business, string $size = 'متوسط'): Business
    {
        DemoGuard::assertDemo($business);

        $spec = self::SIZES[$size] ?? self::SIZES['متوسط'];
        self::wipe($business, keepBusiness: true);

        $plan = Plan::find($business->plan_id) ?? Plan::first();
        $registered = now()->startOfMonth()->subMonths($spec['months'])->addDays(8);

        (new self($business, $spec))->fill($registered, $plan);

        return $business->refresh();
    }

    /** يحذف المتجر التجريبيّ وكلّ صفٍّ يخصّه */
    public static function destroy(Business $business): void
    {
        DemoGuard::assertDemo($business);
        self::wipe($business, keepBusiness: false);
    }

    /**
     * محوُ كلّ صفٍّ يحمل معرّف هذا المتجر.
     *
     * يمرّ على الجداول التي فيها `business_id` بدل سردها يدويًّا، فلا يتخلّف
     * جدولٌ يُستحدَث لاحقًا ويترك بياناتٍ وهميّة معلّقة. والأبناء الذين لا
     * يحملون المعرّف يُحذفون عبر آبائهم أوّلًا.
     */
    private static function wipe(Business $business, bool $keepBusiness): void
    {
        DemoGuard::assertDemo($business);

        DB::transaction(function () use ($business, $keepBusiness) {
            $bid = $business->id;

            $viaParent = [
                'order_items' => ['orders', 'order_id'],
                'purchase_order_items' => ['purchase_orders', 'purchase_order_id'],
                'delivery_note_items' => ['delivery_notes', 'delivery_note_id'],
                'payroll_lines' => ['payroll_runs', 'payroll_run_id'],
                'journal_lines' => ['journal_entries', 'journal_entry_id'],
                'customer_addresses' => ['customers', 'customer_id'],
                'dismissed_notifications' => ['users', 'user_id'],
            ];

            foreach ($viaParent as $child => [$parent, $key]) {
                if (! Schema::hasTable($child) || ! Schema::hasTable($parent)) {
                    continue;
                }
                DB::table($child)->whereIn($key, DB::table($parent)->where('business_id', $bid)->pluck('id'))->delete();
            }

            foreach (self::scopedTables() as $table) {
                DB::table($table)->where('business_id', $bid)->delete();
            }

            if (! $keepBusiness) {
                $business->delete();
            }
        });
    }

    /** @return list<string> الجداول التي تحمل عمود business_id */
    private static function scopedTables(): array
    {
        $skip = ['migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
            'sessions', 'password_reset_tokens', 'businesses'];

        return collect(Schema::getTableListing())
            ->map(fn ($t) => str_contains($t, '.') ? substr(strrchr($t, '.'), 1) : $t)
            ->reject(fn ($t) => in_array($t, $skip, true) || str_starts_with($t, 'sqlite_'))
            ->filter(fn ($t) => Schema::hasColumn($t, 'business_id'))
            ->values()
            ->all();
    }

    /* ===================================================================== */

    private function fill(Carbon $registered, ?Plan $plan): void
    {
        DemoGuard::assertDemo($this->business);

        DB::transaction(function () use ($registered, $plan) {
            $branches = $this->branches();
            $staff = $this->staff($branches);
            $this->defaults();

            Ledger::seedChart($this->business->id);
            $this->bank($registered);

            $products = $this->products($branches);
            $customers = $this->customers();
            $suppliers = $this->suppliers();

            $this->purchases($suppliers, $products, $registered);
            $this->sales($products, $customers, $staff, $branches, $registered);
            $this->expenses($registered);
            $this->payroll($staff, $registered);
            $this->assets($registered);
            $this->inventory($products, $staff, $branches, $registered);
            $this->marketing($products, $customers);
            $this->platform($plan, $registered);
            $this->activity($staff);
        });
    }

    /* ------------------------------- الأساس ------------------------------- */

    /** @return list<Branch> */
    private function branches(): array
    {
        return collect(['الفرع الرئيسي', 'فرع القرم', 'فرع صحار'])
            ->map(fn ($n, $i) => Branch::create([
                'business_id' => $this->business->id, 'name' => $n,
                'phone' => '+968 2400000' . $i, 'address' => self::CITIES[$i],
            ]))->all();
    }

    /** @return list<User> */
    private function staff(array $branches): array
    {
        $bid = $this->business->id;
        $main = $branches[0]->name;

        $users = [User::create([
            'business_id' => $bid, 'name' => 'سالم الحارثي', 'email' => self::ownerEmail($bid),
            'role' => 'admin', 'phone' => '+968 90000000', 'password' => Hash::make(self::PASSWORD),
            'status' => 'نشط', 'branch' => $main, 'job_title' => 'مدير',
            'basic_salary' => 1800, 'allowances' => 250, 'last_login_at' => now(),
        ])];

        $users[] = User::create([
            'business_id' => $bid, 'name' => 'نورة البلوشي', 'email' => self::cashierEmail($bid),
            'role' => 'cashier', 'phone' => '+968 90000001', 'password' => Hash::make(self::PASSWORD),
            'pin' => self::PIN, 'status' => 'نشط', 'branch' => $main, 'job_title' => 'كاشير',
            'basic_salary' => 420, 'allowances' => 60, 'last_login_at' => now()->subHours(3),
        ]);

        $rest = [
            ['خالد الشعيلي', 'manager', 'مدير', 950, 150],
            ['ريم الكندي', 'accountant', 'محاسب', 780, 110],
            ['ماجد المعمري', 'sales', 'موظف مبيعات', 500, 80],
            ['هدى الرواحي', 'sales', 'موظف مبيعات', 500, 80],
            ['يوسف السيابي', 'inventory', 'أمين مخزن', 460, 70],
            ['أسماء الهنائي', 'cashier', 'كاشير', 420, 60],
        ];

        foreach ($rest as $i => [$name, $role, $title, $basic, $allow]) {
            $users[] = User::create([
                'business_id' => $bid, 'name' => $name, 'email' => "demo{$bid}-{$role}-{$i}@abaadapp.om",
                'role' => $role, 'password' => Hash::make(self::PASSWORD), 'status' => 'نشط',
                'branch' => $branches[$i % count($branches)]->name, 'job_title' => $title,
                'basic_salary' => $basic, 'allowances' => $allow,
                'last_login_at' => now()->subDays($i + 1),
            ]);
        }

        return $users;
    }

    private function defaults(): void
    {
        $bid = $this->business->id;

        Currency::create([
            'business_id' => $bid, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع',
            'rate' => 1, 'is_base' => true, 'active' => true,
        ]);

        foreach (JobTitle::ROLES as $roleKey => $roleLabel) {
            JobTitle::create(['business_id' => $bid, 'name' => $roleLabel, 'role' => $roleKey]);
        }

        foreach ([
            'إيجار' => 'إيجار المحل والمستودعات',
            'كهرباء وماء' => 'فواتير الكهرباء والمياه',
            'تسويق' => 'الإعلانات والحملات',
            'صيانة' => 'صيانة المعدات والمحل',
            'نقل وتوصيل' => 'مصاريف الشحن',
            'اتصالات' => 'الهاتف والإنترنت',
            'قرطاسية' => 'مستلزمات مكتبية',
        ] as $name => $desc) {
            ExpenseType::create(['business_id' => $bid, 'name' => $name, 'description' => $desc]);
        }
    }

    private function bank(Carbon $registered): void
    {
        $account = Ledger::account($this->business->id, 'bank');

        BankAccount::create([
            'business_id' => $this->business->id,
            'account_id' => $account?->id,
            'label' => 'بنك مسقط — الجاري',
            'bank_name' => 'بنك مسقط', 'account_name' => 'متجر أبعاد',
            'iban' => 'OM12 0018 0000 1234 5678 9012',
            'opening_balance' => 12500, 'opening_date' => $registered->toDateString(),
            'active' => true, 'is_primary' => true,
        ]);

        Ledger::post($this->business->id, 'رصيد افتتاحيّ — بنك مسقط', [
            ['account' => 'bank', 'debit' => 12500],
            ['account' => 'capital', 'credit' => 12500],
        ], $registered, 'افتتاحي');
    }

    /* ------------------------------ الكتالوج ------------------------------ */

    /** @return list<Product> */
    private function products(array $branches): array
    {
        $bid = $this->business->id;
        $names = ['باقة ورد', 'شتلة', 'مزهرية', 'صندوق هدايا', 'بوكيه', 'إكليل', 'نبتة زينة',
            'سلة فواكه', 'شمعة معطّرة', 'بطاقة تهنئة', 'تربة زراعية', 'سماد', 'مرشّة', 'أصيص'];
        $adjectives = ['أحمر', 'أبيض', 'ملكي', 'فاخر', 'صغير', 'كبير', 'مستورد', 'محلي', 'مميّز', 'كلاسيكي'];

        $products = [];

        for ($i = 0; $i < $this->size['products']; $i++) {
            $cost = round(mt_rand(500, 45000) / 100, 3);
            $price = round($cost * (1 + mt_rand(20, 85) / 100), 3);
            $qty = mt_rand(0, 260);

            $products[] = $p = Product::create([
                'business_id' => $bid,
                'name' => $names[$i % count($names)] . ' ' . $adjectives[intdiv($i, count($names)) % count($adjectives)] . ' ' . ($i + 1),
                'description' => 'صنفٌ من كتالوج المتجر التجريبيّ',
                'sku' => 'DEMO-' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
                'barcode' => '628' . str_pad((string) (1000000 + $i), 10, '0', STR_PAD_LEFT),
                'price' => $price, 'cost' => $cost, 'quantity' => $qty,
                'alert_qty' => mt_rand(5, 25), 'tax' => 5, 'discount' => $i % 11 === 0 ? 10 : 0,
                'active' => $i % 17 !== 0,
            ]);

            // الرصيد موزَّعٌ على الفروع ومجموعُه كميّة المنتج — وإلا اختلّ التوازن
            $left = $qty;
            foreach ($branches as $k => $branch) {
                $share = $k === count($branches) - 1 ? $left : intdiv($qty, count($branches));
                BranchStock::create([
                    'business_id' => $bid, 'branch_id' => $branch->id,
                    'product_id' => $p->id, 'quantity' => max(0, $share),
                ]);
                $left -= $share;
            }
        }

        return $products;
    }

    /** @return list<Customer> */
    private function customers(): array
    {
        $bid = $this->business->id;
        $customers = [];

        for ($i = 0; $i < $this->size['customers']; $i++) {
            $name = self::FIRST[$i % count(self::FIRST)] . ' ' . self::LAST[intdiv($i, count(self::FIRST)) % count(self::LAST)];

            $customers[] = $c = Customer::create([
                'business_id' => $bid, 'name' => $name,
                'phone' => '+9689' . str_pad((string) mt_rand(0, 9999999), 7, '0', STR_PAD_LEFT),
                'email' => 'demo.customer' . ($i + 1) . '@example.om',
                'points' => mt_rand(0, 900),
                'address' => self::CITIES[$i % count(self::CITIES)],
                'tax_number' => $i % 9 === 0 ? 'OM' . mt_rand(100000, 999999) : null,
            ]);

            CustomerAddress::create([
                'customer_id' => $c->id, 'label' => 'المنزل',
                'city' => self::CITIES[$i % count(self::CITIES)],
                'area' => 'حي ' . (1 + $i % 12), 'street' => 'شارع ' . (1 + $i % 40),
                'is_default' => true,
            ]);
        }

        return $customers;
    }

    /** @return list<Supplier> */
    private function suppliers(): array
    {
        $bid = $this->business->id;
        $names = ['مشاتل الخليج', 'ورود الشرق', 'مؤسسة النخيل', 'الرياحين للتجارة', 'بستان عُمان',
            'زهور المتوسط', 'مستلزمات الحدائق', 'التغليف الحديث', 'الواحة للتوريد', 'أصيل للزراعة'];

        $suppliers = [];
        for ($i = 0; $i < $this->size['suppliers']; $i++) {
            $suppliers[] = Supplier::create([
                'business_id' => $bid,
                'name' => $names[$i % count($names)] . ($i >= count($names) ? ' ' . (intdiv($i, count($names)) + 1) : ''),
                'phone' => '+9682' . str_pad((string) mt_rand(0, 9999999), 7, '0', STR_PAD_LEFT),
                'email' => 'supplier' . ($i + 1) . '@example.om',
                'contact_person' => self::FIRST[$i % count(self::FIRST)] . ' ' . self::LAST[$i % count(self::LAST)],
                'notes' => 'مورّدٌ في المتجر التجريبيّ',
            ]);
        }

        return $suppliers;
    }

    /* ------------------------------ المشتريات ------------------------------ */

    private function purchases(array $suppliers, array $products, Carbon $from): void
    {
        $bid = $this->business->id;
        $count = max(8, intdiv($this->size['orders'], 20));

        for ($i = 0; $i < $count; $i++) {
            $supplier = $suppliers[$i % count($suppliers)];
            $at = $from->copy()->addDays(intdiv($i * 380, max(1, $count)));
            if ($at->isFuture()) {
                $at = now()->subDays($i % 20);
            }

            $lines = [];
            $total = 0.0;
            for ($k = 0; $k < 1 + ($i % 4); $k++) {
                $p = $products[($i * 3 + $k) % count($products)];
                $qty = mt_rand(5, 60);
                $total += $p->cost * $qty;
                $lines[] = [$p, $qty];
            }
            $total = round($total, 3);

            $po = PurchaseOrder::create([
                'business_id' => $bid, 'number' => 'PO-' . str_pad((string) ($i + 1), 5, '0', STR_PAD_LEFT),
                'supplier_id' => $supplier->id, 'supplier_name' => $supplier->name,
                'status' => $i % 7 === 0 ? 'مُرسل' : 'مستلم', 'total' => $total,
                'ordered_at' => $at, 'received_at' => $i % 7 === 0 ? null : $at->copy()->addDays(3),
            ]);

            foreach ($lines as [$p, $qty]) {
                $po->items()->create([
                    'product_id' => $p->id, 'name' => $p->name, 'cost' => $p->cost,
                    'quantity' => $qty, 'received_quantity' => $i % 7 === 0 ? 0 : $qty,
                ]);
            }

            // السند يُنشئ الذمّة — وأمر الشراء لا يُنشئها
            if ($i % 7 === 0) {
                continue;
            }

            $paid = $i % 3 === 0 ? 0.0 : ($i % 5 === 0 ? round($total / 2, 3) : $total);
            $invoice = SupplierInvoice::create([
                'business_id' => $bid, 'supplier_id' => $supplier->id, 'purchase_order_id' => $po->id,
                'supplier_ref' => 'SB-' . (2000 + $i),
                'issued_at' => $at->copy()->addDays(3)->toDateString(),
                'due_at' => $at->copy()->addDays(33)->toDateString(),
                'subtotal' => $total, 'tax' => 0, 'total' => $total, 'paid' => $paid,
                'status' => $paid <= 0 ? 'غير مدفوع' : ($paid < $total ? 'جزئي' : 'مدفوع'),
            ]);

            Ledger::post($bid, 'فاتورة مشتريات ' . $invoice->supplier_ref, [
                ['account' => 'inventory', 'debit' => $total],
                ['account' => 'payable', 'credit' => $total],
            ], $at->copy()->addDays(3), 'مشتريات', null, null, $invoice);

            if ($paid > 0) {
                Ledger::post($bid, 'سداد للمورّد ' . $supplier->name, [
                    ['account' => 'payable', 'debit' => $paid],
                    ['account' => 'bank', 'credit' => $paid],
                ], $at->copy()->addDays(10), 'مشتريات');
            }
        }
    }

    /* ------------------------------ المبيعات ------------------------------ */

    private function sales(array $products, array $customers, array $staff, array $branches, Carbon $from): void
    {
        $bid = $this->business->id;
        // ‏`diffInDays` تُرجع عددًا عشريًّا في Carbon الحديث، و`intdiv` لا تقبله
        $days = max(1, (int) $from->diffInDays(now()));
        $sellers = array_values(array_filter($staff, fn ($u) => in_array($u->role, ['cashier', 'sales'], true)));
        $statuses = ['مكتمل', 'مكتمل', 'مكتمل', 'مكتمل', 'قيد التجهيز', 'جاهز', 'خرج للتوصيل', 'ملغي'];
        $methods = ['نقدي', 'بطاقة', 'تحويل', 'نقدي', 'بطاقة'];

        /** مجاميع الشهر لترحيلها قيدًا واحدًا — انظر ترويسة الصنف */
        $monthly = [];

        for ($i = 0; $i < $this->size['orders']; $i++) {
            $at = $from->copy()->addDays(intdiv($i * $days, $this->size['orders']))
                ->setTime(9 + ($i % 12), ($i * 13) % 60);
            $branch = $branches[$i % count($branches)];
            $customer = $customers[($i * 7) % count($customers)];
            $seller = $sellers[$i % max(1, count($sellers))] ?? $staff[0];
            $status = $statuses[$i % count($statuses)];

            $items = [];
            $subtotal = 0.0;
            $cost = 0.0;
            for ($k = 0; $k < 1 + ($i % 5); $k++) {
                $p = $products[($i * 5 + $k * 3) % count($products)];
                $qty = 1 + ($i + $k) % 4;
                $subtotal += $p->price * $qty;
                $cost += $p->cost * $qty;
                $items[] = [$p, $qty];
            }

            $subtotal = round($subtotal, 3);
            $tax = round($subtotal * 0.05, 3);
            $total = round($subtotal + $tax, 3);

            $order = Order::create([
                'business_id' => $bid, 'number' => 'DEMO-' . str_pad((string) (10000 + $i), 6, '0', STR_PAD_LEFT),
                'branch_id' => $branch->id, 'branch' => $branch->name,
                'customer_id' => $customer->id, 'customer_name' => $customer->name,
                'employee_name' => $seller->name, 'status' => $status,
                'payment_method' => $methods[$i % count($methods)],
                'payment_status' => $status === 'ملغي' ? 'غير مدفوع' : 'مدفوع',
                'subtotal' => $subtotal, 'tax' => $tax, 'total' => $total,
                'ordered_at' => $at,
            ]);

            foreach ($items as [$p, $qty]) {
                $order->items()->create([
                    'product_id' => $p->id, 'name' => $p->name, 'price' => $p->price,
                    'quantity' => $qty, 'total' => round($p->price * $qty, 3),
                ]);
            }

            if ($status === 'ملغي') {
                continue;
            }

            $key = $at->format('Y-m');
            $monthly[$key] ??= ['subtotal' => 0.0, 'tax' => 0.0, 'total' => 0.0, 'cost' => 0.0, 'date' => $at->copy()->endOfMonth()];
            $monthly[$key]['subtotal'] += $subtotal;
            $monthly[$key]['tax'] += $tax;
            $monthly[$key]['total'] += $total;
            $monthly[$key]['cost'] += $cost;
        }

        foreach ($monthly as $month => $m) {
            $date = $m['date']->isFuture() ? now() : $m['date'];

            Ledger::post($bid, "مبيعات شهر {$month}", [
                ['account' => 'cash', 'debit' => round($m['total'], 3)],
                ['account' => 'sales', 'credit' => round($m['subtotal'], 3)],
                ['account' => 'tax_payable', 'credit' => round($m['tax'], 3)],
            ], $date, 'مبيعات');

            Ledger::post($bid, "تكلفة مبيعات شهر {$month}", [
                ['account' => 'cogs', 'debit' => round($m['cost'], 3)],
                ['account' => 'inventory', 'credit' => round($m['cost'], 3)],
            ], $date, 'مبيعات');
        }
    }

    /* ------------------------------- المالية ------------------------------- */

    private function expenses(Carbon $from): void
    {
        $bid = $this->business->id;
        $types = ExpenseType::where('business_id', $bid)->pluck('name')->all();
        $months = $this->size['months'];

        for ($m = $months; $m >= 0; $m--) {
            $month = now()->startOfMonth()->subMonths($m);
            if ($month->lt($from->copy()->startOfMonth())) {
                continue;
            }

            foreach ($types as $i => $type) {
                $amount = round(mt_rand(2000, 90000) / 100, 3);
                $at = $month->copy()->addDays(2 + $i * 3);
                if ($at->isFuture()) {
                    continue;
                }

                $paid = ! ($m === 0 && $i % 3 === 0);

                Expense::create([
                    'business_id' => $bid,
                    'reference' => 'EXP-' . $at->format('Ym') . '-' . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                    'type' => $type, 'description' => $type . ' — ' . $at->translatedFormat('F Y'),
                    'amount' => $amount, 'method' => $i % 2 ? 'تحويل' : 'نقدي',
                    'employee_name' => 'ريم الكندي', 'spent_at' => $at,
                    'due_date' => $at->copy()->addDays(15),
                    'status' => $paid ? 'مدفوع' : 'غير مدفوع',
                ]);

                if ($paid) {
                    Ledger::post($bid, 'مصروف ' . $type, [
                        ['account' => 'other_expenses', 'debit' => $amount],
                        ['account' => $i % 2 ? 'bank' : 'cash', 'credit' => $amount],
                    ], $at, 'مصروفات');
                }
            }
        }
    }

    private function payroll(array $staff, Carbon $from): void
    {
        $bid = $this->business->id;
        $months = min(6, $this->size['months']);

        for ($m = $months; $m >= 1; $m--) {
            $period = now()->startOfMonth()->subMonths($m);
            if ($period->lt($from->copy()->startOfMonth())) {
                continue;
            }

            $gross = 0.0;
            $deductions = 0.0;
            $rows = [];

            foreach ($staff as $i => $u) {
                $basic = (float) ($u->basic_salary ?? 0);
                if ($basic <= 0) {
                    continue;
                }
                $allow = (float) ($u->allowances ?? 0);
                $ded = $i % 4 === 0 ? round($basic * 0.02, 3) : 0.0;
                $net = round($basic + $allow - $ded, 3);

                $gross += $basic + $allow;
                $deductions += $ded;
                $rows[] = [$u, $basic, $allow, $ded, $net];
            }

            if (! $rows) {
                return;
            }

            $net = round($gross - $deductions, 3);

            $run = PayrollRun::create([
                'business_id' => $bid, 'number' => 'PR-' . $period->format('Ym'),
                'period' => $period->toDateString(), 'status' => 'مدفوعة',
                'gross' => round($gross, 3), 'deductions' => round($deductions, 3), 'net' => $net,
                'approved_at' => $period->copy()->endOfMonth(),
                'paid_at' => $period->copy()->endOfMonth()->addDay(),
            ]);

            foreach ($rows as [$u, $basic, $allow, $ded, $lineNet]) {
                PayrollLine::create([
                    'payroll_run_id' => $run->id, 'user_id' => $u->id, 'employee_name' => $u->name,
                    'basic' => $basic, 'allowances' => $allow, 'overtime' => 0,
                    'deductions' => $ded, 'net' => $lineNet,
                    'payment_method' => 'تحويل', 'paid' => true,
                    'paid_at' => $period->copy()->endOfMonth()->addDay(),
                ]);
            }

            $at = $period->copy()->endOfMonth();

            Ledger::post($bid, 'رواتب ' . $period->format('Y-m'), [
                ['account' => 'salaries', 'debit' => round($gross, 3)],
                ['account' => 'salaries_payable', 'credit' => $net],
                ['account' => 'other_income', 'credit' => round($deductions, 3)],
            ], $at, 'رواتب', null, null, $run);

            Ledger::post($bid, 'صرف رواتب ' . $period->format('Y-m'), [
                ['account' => 'salaries_payable', 'debit' => $net],
                ['account' => 'bank', 'credit' => $net],
            ], $at->copy()->addDay(), 'رواتب');
        }
    }

    private function assets(Carbon $from): void
    {
        $bid = $this->business->id;
        $assets = [
            ['ثلاجة عرض', 'أجهزة', 1450, 60],
            ['سيارة توصيل', 'مركبات', 6800, 84],
            ['أثاث المعرض', 'أثاث', 2300, 120],
            ['نظام نقاط بيع', 'أجهزة', 980, 48],
            ['مكيّفات', 'أجهزة', 1600, 96],
        ];

        foreach ($assets as $i => [$name, $category, $cost, $life]) {
            $at = $from->copy()->addDays($i * 12);

            FixedAsset::create([
                'business_id' => $bid, 'name' => $name,
                'code' => 'FA-' . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'category' => $category, 'purchased_at' => $at->toDateString(),
                'cost' => $cost, 'salvage_value' => round($cost * 0.1, 3),
                'life_months' => $life, 'method' => 'قسط ثابت',
                'accumulated' => 0, 'status' => 'قيد الاستخدام',
            ]);

            Ledger::post($bid, 'شراء أصل: ' . $name, [
                ['account' => 'fixed_assets', 'debit' => $cost],
                ['account' => 'bank', 'credit' => $cost],
            ], $at, 'أصول');
        }
    }

    /* ------------------------------- المخزون ------------------------------- */

    private function inventory(array $products, array $staff, array $branches, Carbon $from): void
    {
        $bid = $this->business->id;
        $count = max(6, intdiv($this->size['products'], 6));
        $reasons = ['تالف', 'جرد', 'هالك', 'تصحيح', 'مرتجع'];

        for ($i = 0; $i < $count; $i++) {
            $p = $products[($i * 11) % count($products)];
            $delta = $i % 3 === 0 ? mt_rand(1, 12) : -mt_rand(1, 8);
            $at = $from->copy()->addDays(intdiv($i * 300, max(1, $count)));
            if ($at->isFuture()) {
                $at = now()->subDays($i % 15);
            }
            $value = round(abs($delta) * $p->cost, 3);

            StockAdjustment::create([
                'business_id' => $bid, 'branch_id' => $branches[$i % count($branches)]->id,
                'product_id' => $p->id, 'number' => 'ADJ-' . str_pad((string) ($i + 1), 5, '0', STR_PAD_LEFT),
                'quantity_delta' => $delta, 'cost_at_time' => $p->cost,
                'reason' => $reasons[$i % count($reasons)],
                'notes' => 'تعديلٌ في المتجر التجريبيّ',
                'created_by' => $staff[0]->id, 'adjusted_at' => $at,
            ]);

            if ($value <= 0) {
                continue;
            }

            Ledger::post($bid, 'تعديل مخزون ' . $p->name, $delta < 0
                ? [['account' => 'other_expenses', 'debit' => $value], ['account' => 'inventory', 'credit' => $value]]
                : [['account' => 'inventory', 'debit' => $value], ['account' => 'other_income', 'credit' => $value]],
                $at, 'مخزون');
        }

        // إشعارات تسليم — بعضها مربوطٌ بطلبٍ وبعضها مستقلّ
        $orders = Order::where('business_id', $bid)->where('status', 'خرج للتوصيل')->limit(12)->get();
        foreach ($orders as $i => $order) {
            DeliveryNote::create([
                'business_id' => $bid, 'branch_id' => $branches[$i % count($branches)]->id,
                'customer_id' => $order->customer_id, 'order_id' => $order->id,
                'number' => 'DN-' . str_pad((string) ($i + 1), 5, '0', STR_PAD_LEFT),
                'delivered_at' => $order->ordered_at,
                'recipient' => $order->customer_name, 'driver' => 'ماجد المعمري',
                'address' => self::CITIES[$i % count(self::CITIES)],
                'status' => $i % 4 === 0 ? 'مسودة' : 'مُسلَّم',
            ]);
        }
    }

    /* ------------------------------ التسويق ------------------------------ */

    private function marketing(array $products, array $customers): void
    {
        $bid = $this->business->id;

        foreach ([
            ['WELCOME10', 'نسبة', 10, 5, 200],
            ['EID2026', 'نسبة', 15, 10, 500],
            ['FREESHIP', 'مبلغ', 2, 15, 300],
            ['VIP20', 'نسبة', 20, 30, 50],
        ] as $i => [$code, $type, $value, $min, $max]) {
            Coupon::create([
                'business_id' => $bid, 'code' => $code, 'type' => $type, 'value' => $value,
                'min_order' => $min, 'max_uses' => $max, 'used_count' => mt_rand(0, intdiv($max, 2)),
                'expires_at' => now()->addMonths(3 + $i), 'active' => $i !== 3,
            ]);
        }

        $comments = [
            'خدمة ممتازة وتوصيل سريع، شكرًا لكم.',
            'الورد وصل طازجًا وبتغليفٍ جميل.',
            'الأسعار مناسبة والجودة جيدة.',
            'تأخّر الطلب قليلًا لكن النتيجة أعجبتني.',
            'أنصح بالتعامل معهم — تعاملٌ راقٍ.',
            'الباقة كانت أصغر ممّا توقّعت.',
        ];

        $statuses = ['منشور', 'منشور', 'منشور', 'معلّق', 'مرفوض'];
        $count = min(40, count($customers));

        for ($i = 0; $i < $count; $i++) {
            $status = $statuses[$i % count($statuses)];
            Review::create([
                'business_id' => $bid,
                'customer_id' => $customers[$i]->id,
                'product_id' => $products[($i * 3) % count($products)]->id,
                'author_name' => $customers[$i]->name,
                'rating' => $i % 7 === 0 ? mt_rand(2, 3) : mt_rand(4, 5),
                'comment' => $comments[$i % count($comments)],
                'status' => $status,
                'reply' => $status === 'منشور' && $i % 3 === 0 ? 'شكرًا لك، سعداء بخدمتك.' : null,
                'replied_at' => $status === 'منشور' && $i % 3 === 0 ? now()->subDays($i) : null,
            ]);
        }

        MarketingSettings::save($bid, 'website', [
            'site_domain' => 'demo-flowers.abaadapp.om',
            'site_title' => 'متجر أبعاد للورود',
            'site_tagline' => 'ورودٌ تصل في وقتها',
        ]);
    }

    /* -------------------------- المنصّة وسجل النشاط -------------------------- */

    private function platform(?Plan $plan, Carbon $registered): void
    {
        $bid = $this->business->id;

        Subscription::create([
            'business_id' => $bid, 'plan_id' => $plan?->id,
            'starts_at' => $registered, 'ends_at' => $registered->copy()->addYear(),
            'amount' => $plan?->yearly_price ?? 0, 'payment_status' => 'مدفوع', 'status' => 'نشط',
        ]);

        for ($i = $this->size['months']; $i >= 0; $i--) {
            $issued = now()->startOfMonth()->subMonths($i)->addDays(8);
            if ($issued->lt($registered)) {
                continue;
            }

            Invoice::create([
                'number' => 'DEMO-INV-' . $issued->format('Ymd') . '-' . $bid,
                'business_id' => $bid, 'plan_id' => $plan?->id,
                'amount' => $plan?->monthly_price ?? 0,
                'issued_at' => $issued,
                'status' => $i === 0 ? 'غير مدفوعة' : 'مدفوعة',
            ]);
        }
    }

    private function activity(array $staff): void
    {
        $bid = $this->business->id;
        $acts = [
            ['checkout', 'أتمّ بيعًا بقيمة 24.500 ر.ع', 'shopping-cart', 'success'],
            ['created', 'أضاف منتجًا جديدًا', 'plus-circle', 'success'],
            ['status', 'غيّر حالة طلب إلى جاهز', 'refresh-cw', 'warning'],
            ['created', 'سجّل مصروف إيجار', 'plus-circle', 'success'],
            ['settings', 'حدّث إعدادات المتجر', 'settings', 'primary'],
            ['created', 'اعتمد مسيرة رواتب', 'check-circle', 'success'],
            ['deleted', 'حذف صنفًا من الكتالوج', 'trash-2', 'danger'],
        ];

        foreach ($acts as $i => [$action, $desc, $icon, $color]) {
            $at = now()->subHours($i * 5);
            ActivityLog::create([
                'business_id' => $bid, 'user_name' => $staff[$i % count($staff)]->name,
                'action' => $action, 'description' => $desc, 'icon' => $icon, 'color' => $color,
                'ip' => '127.0.0.1', 'created_at' => $at, 'updated_at' => $at,
            ]);
        }
    }
}
