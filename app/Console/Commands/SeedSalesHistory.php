<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Demo;
use App\Support\SeedData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * توليد تاريخ مبيعات واقعي لآخر 12 شهرًا (نمو تدريجي + موسمية أسبوعية + تباين يومي)
 * ليعكس رسم «حركة المبيعات» ولوحة المالية أرقامًا حقيقية بدل شهر واحد.
 *
 * الطلبات المولّدة تُوسَم برقم يبدأ بـ H- لتكون قابلة للتمييز والحذف الآمن.
 * تشغيل: php artisan demo:seed-sales            (يتوقّف إن كانت موجودة)
 *        php artisan demo:seed-sales --fresh    (يحذف المولّدة سابقًا ويعيد التوليد)
 *
 * ولحسابٍ بعينه (لقطاتُ العرض): --email يختار متجرَ صاحبه بدل أوّل متجر، فإن
 * كان فارغًا زُرع له كتالوجٌ موسومٌ بـ MK- ليُحذف وحده. و--shop-name يسمّي المتجر
 * فيختفي شريط التهيئة. و--purge يزيل كلّ ما زرعه الأمر لذلك المتجر.
 *        php artisan demo:seed-sales --email=x@y.om --months=3 --shop-name="زهرة مسقط"
 *        php artisan demo:seed-sales --email=x@y.om --purge
 */
class SeedSalesHistory extends Command
{
    protected $signature = 'demo:seed-sales {--fresh : حذف المبيعات المولّدة سابقًا وإعادة توليدها} {--months=12 : عدد الأشهر للخلف} {--email= : متجر صاحب هذا البريد بدل أوّل متجر} {--shop-name= : تسمية المتجر وإقرار هويّته} {--purge : حذف كلّ ما زرعه الأمر لهذا المتجر}';

    protected $description = 'توليد تاريخ مبيعات واقعي لآخر 12 شهرًا لرسم حركة المبيعات والمالية';

    public function handle(): int
    {
        $bid = Demo::bid();
        if ($email = $this->option('email')) {
            $bid = (int) User::where('email', $email)->value('business_id');
            if ($bid === 0) {
                $this->error("لا متجر لصاحب البريد {$email}.");

                return self::FAILURE;
            }
        }
        $months = max(1, (int) $this->option('months'));

        if ($this->option('purge')) {
            $ids = Order::where('business_id', $bid)->where('number', 'like', 'H-%')->pluck('id');
            Transaction::whereIn('order_id', $ids)->delete();
            \App\Models\OrderItem::whereIn('order_id', $ids)->delete();
            Order::whereIn('id', $ids)->delete();
            Product::where('business_id', $bid)->where('sku', 'like', 'MK-%')->delete();
            Customer::where('business_id', $bid)->where('email', 'like', 'mk-%@example.com')->delete();
            $this->info("حُذفت {$ids->count()} طلب والكتالوج الموسوم MK-.");

            return self::SUCCESS;
        }

        if ($name = $this->option('shop-name')) {
            Business::whereKey($bid)->update(['name' => $name, 'identity_confirmed_at' => now()]);
            $this->line("سُمّي المتجر «{$name}».");
        }

        $this->ensureCatalog($bid);

        $existing = Order::where('business_id', $bid)->where('number', 'like', 'H-%')->count();
        if ($existing > 0) {
            if (! $this->option('fresh')) {
                $this->warn("يوجد {$existing} طلب مولّد مسبقًا. استخدم --fresh لإعادة التوليد.");

                return self::SUCCESS;
            }
            // حذف آمن: المولّدة فقط (H-) ومعاملاتها
            $ids = Order::where('business_id', $bid)->where('number', 'like', 'H-%')->pluck('id');
            Transaction::whereIn('order_id', $ids)->delete();
            \App\Models\OrderItem::whereIn('order_id', $ids)->delete();
            Order::whereIn('id', $ids)->delete();
            $this->line("حُذفت {$existing} طلب مولّد سابقًا.");
        }

        $products = Product::where('business_id', $bid)->get();
        if ($products->isEmpty()) {
            $this->error('لا توجد منتجات لهذا النشاط.');

            return self::FAILURE;
        }
        $customers = Customer::where('business_id', $bid)->pluck('name')->all();
        $employees = ['أحمد محمد', 'خالد علي', 'سارة حسن', 'مريم عبدالله'];
        $branch = Business::find($bid)?->branches()->orderBy('id')->first();

        // معاملات الدفع بأوزان واقعية
        $payPick = function () {
            $r = rand(1, 100);

            return $r <= 55 ? 'نقدي' : ($r <= 90 ? 'بطاقة' : 'تحويل بنكي');
        };
        // مضاعف اليوم من الأسبوع (0=الأحد .. 6=السبت) — محل ورود أنشط قرب نهاية الأسبوع
        $weekday = [0 => 0.9, 1 => 0.85, 2 => 0.9, 3 => 1.1, 4 => 1.3, 5 => 1.15, 6 => 1.2];

        $start = now()->subMonthsNoOverflow($months)->startOfDay();
        $end = now();
        $totalDays = $start->diffInDays($end);

        $seq = 0;
        $orderCount = 0;
        $txCount = 0;
        $sumTotal = 0.0;

        DB::transaction(function () use ($bid, $branch, $products, $customers, $employees, $payPick, $weekday, $start, $end, $totalDays, &$seq, &$orderCount, &$txCount, &$sumTotal) {
            $day = $start->copy();
            while ($day->lte($end)) {
                // نمو تدريجي: من ~0.45 قبل سنة إلى 1.0 الآن
                $progress = $totalDays > 0 ? $start->diffInDays($day) / $totalDays : 1;
                $growth = 0.45 + 0.55 * $progress;
                $base = 8; // متوسط الطلبات اليومي عند الذروة
                $jitter = rand(70, 130) / 100;
                $perDay = (int) round($base * $growth * ($weekday[$day->dayOfWeek] ?? 1) * $jitter);
                $perDay = max(1, $perDay);

                for ($i = 0; $i < $perDay; $i++) {
                    $seq++;
                    // سلّة واقعية: 1–4 أصناف بكميات صغيرة
                    $lines = rand(1, 4);
                    $subtotal = 0.0;
                    $items = [];
                    for ($k = 0; $k < $lines; $k++) {
                        $pr = $products[rand(0, $products->count() - 1)];
                        $qty = rand(1, 3);
                        $lineTotal = round((float) $pr->price * $qty, 3);
                        $subtotal += $lineTotal;
                        $items[] = ['product_id' => $pr->id, 'name' => $pr->name, 'price' => $pr->price, 'quantity' => $qty, 'total' => $lineTotal];
                    }
                    $subtotal = round($subtotal, 3);
                    $tax = round($subtotal * 0.05, 3);
                    $total = round($subtotal + $tax, 3);

                    // الحالة: القديمة مكتملة، والأيام الأخيرة مزيج، و~4% ملغاة
                    $ageDays = $day->diffInDays($end);
                    $cancelled = rand(1, 100) <= 4;
                    if ($cancelled) {
                        $status = 'ملغي';
                    } elseif ($ageDays <= 2) {
                        $status = ['جديد', 'قيد التجهيز', 'جاهز', 'مكتمل'][rand(0, 3)];
                    } else {
                        $status = 'مكتمل';
                    }

                    $customerName = (! empty($customers) && rand(1, 100) <= 60)
                        ? $customers[array_rand($customers)]
                        : 'عميل نقدي';
                    $method = $payPick();
                    $orderedAt = $day->copy()->setTime(rand(9, 21), rand(0, 59), rand(0, 59));

                    $order = Order::create([
                        'business_id' => $bid,
                        'number' => 'H-' . str_pad((string) $seq, 6, '0', STR_PAD_LEFT),
                        'customer_name' => $customerName,
                        'employee_name' => $employees[array_rand($employees)],
                        'branch_id' => $branch?->id,
                        'branch' => $branch?->name ?? 'الفرع الرئيسي',
                        'status' => $status,
                        'payment_method' => $method,
                        'payment_status' => $cancelled ? 'غير مدفوع' : 'مدفوع',
                        'subtotal' => $subtotal,
                        'tax' => $tax,
                        'total' => $total,
                        'is_held' => false,
                        'ordered_at' => $orderedAt,
                    ]);
                    foreach ($items as $it) {
                        $order->items()->create($it);
                    }
                    $orderCount++;
                    $sumTotal += $cancelled ? 0 : $total;

                    // معاملة دخل مطابقة (تُبقي المالية متّسقة) — عدا الملغاة
                    if (! $cancelled) {
                        Transaction::create([
                            'business_id' => $bid,
                            'reference' => $order->number,
                            'description' => 'مبيعات نقطة البيع',
                            'method' => $method,
                            'type' => 'دخل',
                            'amount' => $total,
                            'tax_amount' => $tax,
                            'employee_name' => $order->employee_name,
                            'order_id' => $order->id,
                            'occurred_at' => $orderedAt,
                        ]);
                        $txCount++;
                    }
                }

                $day->addDay();
            }
        });

        $this->info("تم توليد {$orderCount} طلب و{$txCount} معاملة دخل عبر {$months} شهرًا. إجمالي المبيعات ≈ " . number_format($sumTotal, 3) . ' ر.ع');

        return self::SUCCESS;
    }

    /**
     * متجرٌ بلا منتجات لا تُولَّد له مبيعات — فيُزرع له كتالوجٌ وعملاء موسومون
     * (MK- في الرمز، mk- في البريد) ليمسحهم --purge وحدهم دون ما أدخله صاحبه.
     */
    private function ensureCatalog(int $bid): void
    {
        if (Product::where('business_id', $bid)->exists()) {
            return;
        }

        $catByName = [];
        foreach (SeedData::categories() as $c) {
            $catByName[$c['name']] = Category::firstOrCreate(
                ['business_id' => $bid, 'name' => $c['name']],
                ['icon' => $c['icon'], 'color' => $c['color']]
            )->id;
        }
        foreach (SeedData::products() as $p) {
            Product::create([
                'business_id' => $bid, 'category_id' => $catByName[$p['cat']] ?? null,
                'name' => $p['name'], 'sku' => 'MK-'.$p['sku'], 'barcode' => $p['barcode'],
                'price' => $p['price'], 'cost' => $p['cost'], 'quantity' => max(10, $p['qty']),
                'alert_qty' => $p['alert'], 'tax' => $p['tax'], 'discount' => $p['discount'],
                'image' => $p['image'], 'active' => true,
            ]);
        }

        if (! Customer::where('business_id', $bid)->exists()) {
            foreach (SeedData::customers() as $i => $c) {
                Customer::create([
                    'business_id' => $bid, 'name' => $c['name'], 'phone' => $c['phone'],
                    'email' => 'mk-'.$i.'@example.com', 'points' => $c['points'], 'address' => 'مسقط',
                ]);
            }
        }

        $this->line('زُرع كتالوجٌ موسوم (MK-) لمتجرٍ فارغ.');
    }
}
