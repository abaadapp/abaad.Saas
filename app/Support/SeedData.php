<?php

namespace App\Support;

/**
 * بيانات تجريبية ثابتة لنظام Abad POS.
 *
 * جميع الدوال ترجع مصفوفات PHP جاهزة للعرض في Blade.
 * عند إضافة Backend لاحقًا يكفي استبدال هذه الدوال باستعلامات حقيقية
 * دون تغيير أي من ملفات العرض.
 */
class SeedData
{
    /** تنسيق مبلغ بالريال العماني: 12.500 ر.ع */
    public static function money($value): string
    {
        return number_format((float) $value, 3, '.', ',').' ر.ع';
    }

    /** صورة Placeholder */
    public static function image(string $seed, int $w = 400, int $h = 400): string
    {
        return "https://picsum.photos/seed/{$seed}/{$w}/{$h}";
    }

    /* ============================ Super Admin ============================ */

    public static function superStats(): array
    {
        return [
            ['label' => 'إجمالي الشركات', 'value' => '128', 'icon' => 'building-2', 'trend' => '+12%', 'up' => true, 'color' => 'primary'],
            ['label' => 'الشركات النشطة', 'value' => '104', 'icon' => 'circle-check', 'trend' => '+8%', 'up' => true, 'color' => 'success'],
            ['label' => 'محلات الورود', 'value' => '37', 'icon' => 'flower', 'trend' => '+5%', 'up' => true, 'color' => 'secondary'],
            ['label' => 'المستخدمون', 'value' => '642', 'icon' => 'users', 'trend' => '+18%', 'up' => true, 'color' => 'info'],
            ['label' => 'الاشتراكات النشطة', 'value' => '96', 'icon' => 'badge-check', 'trend' => '+6%', 'up' => true, 'color' => 'success'],
            ['label' => 'الاشتراكات المنتهية', 'value' => '24', 'icon' => 'badge-x', 'trend' => '-3%', 'up' => false, 'color' => 'danger'],
            ['label' => 'الإيرادات الشهرية', 'value' => '4,820.000 ر.ع', 'icon' => 'wallet', 'trend' => '+14%', 'up' => true, 'color' => 'warning'],
            ['label' => 'الإيرادات السنوية', 'value' => '52,640.000 ر.ع', 'icon' => 'trending-up', 'trend' => '+21%', 'up' => true, 'color' => 'primary'],
        ];
    }

    public static function businesses(): array
    {
        // نفس قائمة الأنواع التي تُعرض في نموذج الإنشاء ولها تصنيفات بداية
        $types = BusinessTypes::TYPES;
        $plans = ['أساسية', 'احترافية', 'مؤسسات'];
        $cities = ['مسقط', 'صلالة', 'صحار', 'نزوى', 'صور'];
        $names = ['زهرة مسقط', 'لمسة ورد', 'روائع الزهور', 'بيت الباقة', 'ورد الجوري', 'قهوة الصباح', 'مطعم البركة', 'بقالة المدينة', 'صيدلية الشفاء', 'أناقة الأزياء', 'عطر الورد', 'حديقة الزهور'];
        $owners = ['أحمد محمد', 'خالد علي', 'سارة حسن', 'مريم عبدالله', 'سالم راشد', 'نورا علي', 'عبدالله سعيد', 'هدى ناصر', 'ماجد سليمان', 'ريم خالد', 'يوسف حمد', 'ليلى أحمد'];
        $rows = [];
        for ($i = 0; $i < count($names); $i++) {
            $status = $i % 5 === 0 ? 'معطل' : ($i % 4 === 0 ? 'منتهي' : 'نشط');
            $rows[] = [
                'id' => 1000 + $i,
                'name' => $names[$i],
                'type' => $i < 5 ? 'محل ورود' : $types[$i % count($types)],
                'owner' => $owners[$i],
                'phone' => '+968 9'.rand(1000000, 9999999),
                'email' => 'info'.$i.'@example.com',
                'plan' => $plans[$i % 3],
                'status' => $status,
                'registered' => '2025-0'.(($i % 9) + 1).'-1'.($i % 9),
                'branches' => rand(1, 6),
                'logo' => self::image('biz'.$i, 100, 100),
                'city' => $cities[$i % count($cities)],
                'country' => 'عُمان',
            ];
        }

        return $rows;
    }

    public static function flowerShops(): array
    {
        $names = ['زهرة مسقط', 'لمسة ورد', 'روائع الزهور', 'بيت الباقة', 'ورد الجوري', 'عطر الورد', 'حديقة الزهور'];
        $owners = ['أحمد محمد', 'خالد علي', 'سارة حسن', 'مريم عبدالله', 'سالم راشد', 'نورا علي', 'عبدالله سعيد'];
        $cities = ['مسقط', 'صلالة', 'صحار', 'نزوى', 'صور', 'مسقط', 'صلالة'];
        $plans = ['أساسية', 'احترافية', 'مؤسسات'];
        $rows = [];
        for ($i = 0; $i < count($names); $i++) {
            $rows[] = [
                'id' => 2000 + $i,
                'name' => $names[$i],
                'logo' => self::image('flower'.$i, 200, 200),
                'owner' => $owners[$i],
                'city' => $cities[$i],
                'branches' => rand(1, 4),
                'employees' => rand(3, 18),
                'products' => rand(40, 220),
                'orders' => rand(120, 980),
                'status' => $i % 4 === 0 ? 'منتهي' : 'نشط',
                'plan' => $plans[$i % 3],
                'sales' => rand(3000, 22000),
            ];
        }

        return $rows;
    }

    public static function plans(): array
    {
        return [
            [
                'name' => 'الباقة الأساسية', 'monthly' => 9.900, 'yearly' => 99.000, 'color' => 'primary', 'popular' => false,
                'features' => ['فرع واحد', '3 موظفين', '100 منتج', 'تقارير أساسية', 'دعم بالبريد الإلكتروني'],
                /*
                 * وما تفتحه الباقة يُكتب مفتاحًا لا سطرًا.
                 *
                 * السطر أعلاه يُقرأ في صفحة التسعير، وهذه يقرؤها الحارس —
                 * انظر `PlanFeatures`. و«تقارير أساسية» تعني أنّ التحليل
                 * والتصدير ليسا فيها، و«الصلاحيات المخصّصة» ليست في سطورها.
                 */
                'capabilities' => ['loyalty', 'whatsapp'],
            ],
            [
                'name' => 'الباقة الاحترافية', 'monthly' => 24.900, 'yearly' => 249.000, 'color' => 'secondary', 'popular' => true,
                'features' => ['3 فروع', '15 موظف', 'منتجات غير محدودة', 'تقارير متقدمة', 'صلاحيات مخصصة', 'دعم فني على مدار الساعة'],
                'capabilities' => ['loyalty', 'whatsapp', 'reports_advanced', 'custom_permissions'],
            ],
            [
                'name' => 'باقة المؤسسات', 'monthly' => 59.900, 'yearly' => 599.000, 'color' => 'primary', 'popular' => false,
                'features' => ['فروع غير محدودة', 'موظفون غير محدودين', 'منتجات غير محدودة', 'تقارير مؤسسية', 'مدير حساب مخصص'],
                'capabilities' => ['loyalty', 'whatsapp', 'reports_advanced', 'custom_permissions'],
            ],
        ];
    }

    public static function subscriptions(): array
    {
        $biz = self::businesses();
        $rows = [];
        foreach ($biz as $i => $b) {
            $rows[] = [
                'business' => $b['name'],
                'plan' => $b['plan'],
                'start' => '2025-0'.(($i % 9) + 1).'-01',
                'end' => '2026-0'.(($i % 9) + 1).'-01',
                'amount' => [99, 249, 599][$i % 3],
                'payment' => $i % 3 === 0 ? 'غير مدفوع' : 'مدفوع',
                'status' => $b['status'],
            ];
        }

        return $rows;
    }

    public static function invoices(): array
    {
        $biz = self::businesses();
        $rows = [];
        foreach ($biz as $i => $b) {
            $rows[] = [
                'number' => 'INV-'.(2025000 + $i),
                'business' => $b['name'],
                'plan' => $b['plan'],
                'amount' => [99, 249, 599][$i % 3],
                'date' => '2025-0'.(($i % 9) + 1).'-05',
                'status' => $i % 4 === 0 ? 'غير مدفوعة' : 'مدفوعة',
            ];
        }

        return $rows;
    }

    public static function platformUsers(): array
    {
        $names = ['أحمد محمد', 'خالد علي', 'سارة حسن', 'مريم عبدالله', 'سالم راشد', 'نورا علي', 'عبدالله سعيد', 'هدى ناصر', 'ماجد سليمان', 'ريم خالد'];
        $roles = ['مالك النشاط', 'مدير', 'كاشير', 'محاسب', 'مسؤول مخزون'];
        $biz = ['زهرة مسقط', 'لمسة ورد', 'روائع الزهور', 'بيت الباقة', 'ورد الجوري'];
        $rows = [];
        foreach ($names as $i => $n) {
            $rows[] = [
                'id' => 5000 + $i,
                'name' => $n,
                'email' => 'user'.$i.'@example.com',
                'phone' => '+968 9'.rand(1000000, 9999999),
                'business' => $biz[$i % count($biz)],
                'role' => $roles[$i % count($roles)],
                'status' => $i % 5 === 0 ? 'موقوف' : 'نشط',
                'last_login' => '2026-07-1'.($i % 7).' 0'.(($i % 9) + 1).':30',
                'avatar' => self::image('user'.$i, 100, 100),
            ];
        }

        return $rows;
    }

    public static function activities(): array
    {
        return [
            ['text' => 'اشتركت شركة «زهرة مسقط» في الباقة الاحترافية', 'time' => 'قبل 5 دقائق', 'icon' => 'badge-check', 'color' => 'success'],
            ['text' => 'سجّلت شركة جديدة «عطر الورد»', 'time' => 'قبل 22 دقيقة', 'icon' => 'building-2', 'color' => 'primary'],
            ['text' => 'تم تجديد اشتراك «بيت الباقة»', 'time' => 'قبل ساعة', 'icon' => 'refresh-cw', 'color' => 'info'],
            ['text' => 'انتهى اشتراك «مطعم البركة»', 'time' => 'قبل 3 ساعات', 'icon' => 'badge-x', 'color' => 'danger'],
            ['text' => 'دفعت فاتورة INV-2025007', 'time' => 'أمس', 'icon' => 'wallet', 'color' => 'warning'],
        ];
    }

    /* ============================ Admin ============================ */

    public static function adminStats(): array
    {
        return [
            ['label' => 'مبيعات اليوم', 'value' => '482.500 ر.ع', 'icon' => 'shopping-bag', 'trend' => '+9%', 'up' => true, 'color' => 'primary'],
            ['label' => 'مبيعات الشهر', 'value' => '12,640.000 ر.ع', 'icon' => 'trending-up', 'trend' => '+15%', 'up' => true, 'color' => 'success'],
            ['label' => 'عدد الطلبات', 'value' => '318', 'icon' => 'receipt', 'trend' => '+11%', 'up' => true, 'color' => 'info'],
            ['label' => 'متوسط قيمة الطلب', 'value' => '18.750 ر.ع', 'icon' => 'calculator', 'trend' => '+3%', 'up' => true, 'color' => 'secondary'],
            ['label' => 'عدد العملاء', 'value' => '214', 'icon' => 'users', 'trend' => '+7%', 'up' => true, 'color' => 'primary'],
            ['label' => 'منتجات منخفضة المخزون', 'value' => '9', 'icon' => 'alert-triangle', 'trend' => 'تنبيه', 'up' => false, 'color' => 'warning'],
            ['label' => 'المصروفات', 'value' => '1,240.000 ر.ع', 'icon' => 'arrow-down-circle', 'trend' => '-5%', 'up' => false, 'color' => 'danger'],
            ['label' => 'صافي الأرباح', 'value' => '11,400.000 ر.ع', 'icon' => 'piggy-bank', 'trend' => '+17%', 'up' => true, 'color' => 'success'],
        ];
    }

    public static function categories(): array
    {
        return [
            ['id' => 1, 'name' => 'باقات ورد', 'products' => 42, 'icon' => '🌹', 'color' => 'secondary'],
            ['id' => 2, 'name' => 'مناسبات', 'products' => 28, 'icon' => '🎉', 'color' => 'primary'],
            ['id' => 3, 'name' => 'هدايا', 'products' => 19, 'icon' => '🎁', 'color' => 'info'],
            ['id' => 4, 'name' => 'شوكولاتة', 'products' => 15, 'icon' => '🍫', 'color' => 'warning'],
            ['id' => 5, 'name' => 'نباتات', 'products' => 23, 'icon' => '🌱', 'color' => 'success'],
            ['id' => 6, 'name' => 'إضافات', 'products' => 11, 'icon' => '➕', 'color' => 'primary'],
            ['id' => 7, 'name' => 'خدمات تنسيق', 'products' => 8, 'icon' => '✨', 'color' => 'secondary'],
        ];
    }

    public static function addons(): array
    {
        return [
            ['name' => 'تغليف هدية', 'price' => 1.500, 'icon' => '🎁'],
            ['name' => 'بطاقة إهداء', 'price' => 0.500, 'icon' => '💌'],
            ['name' => 'شريط تزيين', 'price' => 0.750, 'icon' => '🎀'],
            ['name' => 'توصيل سريع', 'price' => 2.000, 'icon' => '🚚'],
            ['name' => 'تنسيق خاص', 'price' => 3.000, 'icon' => '✨'],
        ];
    }

    public static function products(): array
    {
        $items = [
            ['name' => 'باقة ورد أحمر', 'cat' => 'باقات ورد', 'price' => 12.500, 'cost' => 6.000, 'qty' => 34],
            ['name' => 'باقة ورد أبيض', 'cat' => 'باقات ورد', 'price' => 11.000, 'cost' => 5.500, 'qty' => 21],
            ['name' => 'بوكيه تخرج', 'cat' => 'مناسبات', 'price' => 18.750, 'cost' => 9.000, 'qty' => 12],
            ['name' => 'بوكيه زفاف', 'cat' => 'مناسبات', 'price' => 45.000, 'cost' => 22.000, 'qty' => 6],
            ['name' => 'صندوق ورد وشوكولاتة', 'cat' => 'هدايا', 'price' => 25.000, 'cost' => 12.000, 'qty' => 18],
            ['name' => 'وردة مفردة', 'cat' => 'باقات ورد', 'price' => 2.500, 'cost' => 1.000, 'qty' => 120],
            ['name' => 'تنسيق طاولة', 'cat' => 'خدمات تنسيق', 'price' => 35.000, 'cost' => 15.000, 'qty' => 4],
            ['name' => 'هدية مولود', 'cat' => 'هدايا', 'price' => 22.000, 'cost' => 10.000, 'qty' => 9],
            ['name' => 'صندوق شوكولاتة فاخر', 'cat' => 'شوكولاتة', 'price' => 15.500, 'cost' => 7.000, 'qty' => 27],
            ['name' => 'نبتة زينة داخلية', 'cat' => 'نباتات', 'price' => 8.750, 'cost' => 3.500, 'qty' => 3],
            ['name' => 'باقة ورد صناعي', 'cat' => 'باقات ورد', 'price' => 9.000, 'cost' => 4.000, 'qty' => 40],
            ['name' => 'بطاقة تهنئة', 'cat' => 'إضافات', 'price' => 1.000, 'cost' => 0.300, 'qty' => 0],
        ];
        $rows = [];
        foreach ($items as $i => $it) {
            $status = $it['qty'] === 0 ? 'نفد المخزون' : ($it['qty'] < 10 ? 'منخفض' : 'متوفر');
            $rows[] = array_merge($it, [
                'id' => 3000 + $i,
                'sku' => 'FLW-'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
                'barcode' => '628'.rand(100000000, 999999999),
                'image' => self::image('prod'.$i, 400, 400),
                'stock_status' => $status,
                'active' => $i % 7 !== 0,
                'alert' => 10,
                'tax' => 5,
                'discount' => $i % 3 === 0 ? 10 : 0,
            ]);
        }

        return $rows;
    }

    public static function orders(): array
    {
        $customers = ['محمد سالم', 'فاطمة أحمد', 'عبدالله راشد', 'نورا علي', 'سالم خميس', 'مريم سعيد', 'أحمد يوسف', 'هدى ناصر'];
        $employees = ['أحمد محمد', 'خالد علي', 'سارة حسن', 'مريم عبدالله'];
        $statuses = ['جديد', 'قيد التجهيز', 'جاهز', 'خرج للتوصيل', 'مكتمل', 'ملغي'];
        $payments = ['نقدي', 'بطاقة', 'تحويل بنكي'];
        $rows = [];
        for ($i = 0; $i < 14; $i++) {
            $rows[] = [
                'id' => 'ORD-'.(10500 + $i),
                'customer' => $customers[$i % count($customers)],
                'employee' => $employees[$i % count($employees)],
                'branch' => 'الفرع الرئيسي',
                'items_count' => rand(1, 6),
                'total' => rand(5, 90) + (rand(0, 999) / 1000),
                'payment' => $payments[$i % count($payments)],
                'status' => $statuses[$i % count($statuses)],
                'date' => '2026-07-'.str_pad((string) (17 - ($i % 15)), 2, '0', STR_PAD_LEFT).' 1'.($i % 9).':2'.($i % 9),
            ];
        }

        return $rows;
    }

    public static function customers(): array
    {
        $names = ['محمد سالم', 'فاطمة أحمد', 'عبدالله راشد', 'نورا علي', 'سالم خميس', 'مريم سعيد', 'أحمد يوسف', 'هدى ناصر', 'ريم خالد', 'ماجد حمد'];
        $rows = [];
        foreach ($names as $i => $n) {
            $rows[] = [
                'id' => 6000 + $i,
                'name' => $n,
                'phone' => '+968 9'.rand(1000000, 9999999),
                'email' => 'customer'.$i.'@example.com',
                'orders' => rand(1, 40),
                'total_spent' => rand(20, 900) + (rand(0, 999) / 1000),
                'last_order' => '2026-07-'.str_pad((string) (17 - ($i % 15)), 2, '0', STR_PAD_LEFT),
                'points' => rand(0, 800),
                'avatar' => self::image('cust'.$i, 100, 100),
            ];
        }

        return $rows;
    }

    public static function employees(): array
    {
        $names = ['أحمد محمد', 'خالد علي', 'سارة حسن', 'مريم عبدالله', 'سالم راشد', 'نورا علي', 'عبدالله سعيد'];
        $roles = ['مدير', 'كاشير', 'موظف مبيعات', 'محاسب', 'مسؤول مخزون', 'مندوب توصيل'];
        $rows = [];
        foreach ($names as $i => $n) {
            $rows[] = [
                'id' => 7000 + $i,
                'name' => $n,
                'avatar' => self::image('emp'.$i, 100, 100),
                'role' => $roles[$i % count($roles)],
                'branch' => 'الفرع الرئيسي',
                'phone' => '+968 9'.rand(1000000, 9999999),
                'email' => 'emp'.$i.'@example.com',
                'sales' => rand(500, 8000),
                'status' => $i % 6 === 0 ? 'موقوف' : 'نشط',
            ];
        }

        return $rows;
    }

    public static function inventory(): array
    {
        $rows = [];
        foreach (self::products() as $p) {
            $rows[] = [
                'name' => $p['name'],
                'sku' => $p['sku'],
                'qty' => $p['qty'],
                'min' => $p['alert'],
                'status' => $p['stock_status'],
                'updated' => '2026-07-1'.rand(0, 7),
            ];
        }

        return $rows;
    }

    public static function movements(): array
    {
        $types = ['إضافة كمية', 'خصم كمية', 'مرتجع', 'تلف', 'تعديل يدوي'];
        $products = self::products();
        $rows = [];
        for ($i = 0; $i < 12; $i++) {
            $p = $products[$i % count($products)];
            $rows[] = [
                'product' => $p['name'],
                'sku' => $p['sku'],
                'type' => $types[$i % count($types)],
                'qty' => ($i % 2 === 0 ? '+' : '-').rand(1, 25),
                'employee' => ['أحمد محمد', 'سارة حسن', 'خالد علي'][$i % 3],
                'date' => '2026-07-'.str_pad((string) (17 - ($i % 15)), 2, '0', STR_PAD_LEFT),
            ];
        }

        return $rows;
    }

    public static function expenses(): array
    {
        $types = ['إيجار', 'رواتب', 'كهرباء وماء', 'مواد خام', 'تسويق', 'صيانة', 'نقل وتوصيل'];
        $rows = [];
        for ($i = 0; $i < 10; $i++) {
            $rows[] = [
                'type' => $types[$i % count($types)],
                'description' => 'مصروف '.$types[$i % count($types)].' لشهر يوليو',
                'amount' => rand(20, 600) + (rand(0, 999) / 1000),
                'date' => '2026-07-'.str_pad((string) (17 - ($i % 15)), 2, '0', STR_PAD_LEFT),
                'employee' => ['أحمد محمد', 'سارة حسن', 'خالد علي'][$i % 3],
                'method' => ['نقدي', 'بطاقة', 'تحويل بنكي'][$i % 3],
            ];
        }

        return $rows;
    }

    /* ============================ المالية ============================ */

    /** بطاقات ملخص المالية */
    public static function financeStats(): array
    {
        return [
            ['label' => 'إجمالي الإيرادات', 'value' => '14,280.000 ر.ع', 'icon' => 'wallet', 'trend' => '+12%', 'up' => true, 'color' => 'primary'],
            ['label' => 'المدفوعات النقدية', 'value' => '6,120.000 ر.ع', 'icon' => 'banknote', 'trend' => '+8%', 'up' => true, 'color' => 'success'],
            ['label' => 'التحويلات البنكية', 'value' => '4,340.000 ر.ع', 'icon' => 'landmark', 'trend' => '+15%', 'up' => true, 'color' => 'info'],
            ['label' => 'مدفوعات البطاقة (فيزا)', 'value' => '3,820.000 ر.ع', 'icon' => 'credit-card', 'trend' => '+5%', 'up' => true, 'color' => 'secondary'],
        ];
    }

    /** توزيع وسائل الدفع */
    public static function paymentMethods(): array
    {
        return [
            ['name' => 'نقدي', 'key' => 'نقدي', 'icon' => 'banknote', 'color' => 'success', 'total' => 6120.000, 'count' => 184, 'percent' => 43],
            ['name' => 'تحويل بنكي', 'key' => 'تحويل بنكي', 'icon' => 'landmark', 'color' => 'info', 'total' => 4340.000, 'count' => 96, 'percent' => 30],
            ['name' => 'بطاقة (فيزا)', 'key' => 'بطاقة', 'icon' => 'credit-card', 'color' => 'primary', 'total' => 3820.000, 'count' => 128, 'percent' => 27],
        ];
    }

    /** المعاملات المالية (دخل/مصروف) */
    public static function transactions(): array
    {
        $methods = ['نقدي', 'تحويل بنكي', 'بطاقة'];
        $income = [
            'طلب ORD-10500', 'طلب ORD-10503', 'طلب ORD-10507', 'دفعة عميل — بيت الباقة',
            'طلب ORD-10511', 'مبيعات نقطة البيع', 'طلب ORD-10514', 'دفعة آجل مستحقة',
        ];
        $expense = [
            'مصروف إيجار المحل', 'رواتب الموظفين', 'شراء مواد خام — ورود', 'فاتورة كهرباء',
        ];
        $rows = [];
        for ($i = 0; $i < 14; $i++) {
            $isIncome = $i % 3 !== 0;
            $rows[] = [
                'id' => 'TRX-'.(52100 + $i),
                'date' => '2026-07-'.str_pad((string) (17 - ($i % 15)), 2, '0', STR_PAD_LEFT).' 1'.($i % 9).':'.str_pad((string) (($i * 7) % 60), 2, '0', STR_PAD_LEFT),
                'description' => $isIncome ? $income[$i % count($income)] : $expense[$i % count($expense)],
                'method' => $methods[$i % count($methods)],
                'type' => $isIncome ? 'دخل' : 'مصروف',
                'amount' => ($isIncome ? 1 : -1) * (rand(8, 250) + (rand(0, 999) / 1000)),
                'employee' => ['أحمد محمد', 'سارة حسن', 'خالد علي'][$i % 3],
            ];
        }

        return $rows;
    }

    /* ============================ POS ============================ */

    public static function posCategories(): array
    {
        return ['الكل', 'باقات ورد', 'مناسبات', 'هدايا', 'شوكولاتة', 'إضافات'];
    }

    public static function heldOrders(): array
    {
        $customers = ['محمد سالم', 'فاطمة أحمد', 'عبدالله راشد', 'عميل نقدي'];
        $rows = [];
        for ($i = 0; $i < 5; $i++) {
            $rows[] = [
                'id' => 'HOLD-'.(300 + $i),
                'customer' => $customers[$i % count($customers)],
                'items' => rand(1, 5),
                'total' => rand(5, 70) + (rand(0, 999) / 1000),
                'time' => '1'.(2 + $i).':'.rand(10, 59),
                'employee' => 'سارة حسن',
            ];
        }

        return $rows;
    }

    public static function receipts(): array
    {
        $rows = [];
        for ($i = 0; $i < 10; $i++) {
            $rows[] = [
                'number' => 'INV-'.(78900 + $i),
                'customer' => ['محمد سالم', 'فاطمة أحمد', 'عميل نقدي'][$i % 3],
                'total' => rand(5, 80) + (rand(0, 999) / 1000),
                'payment' => ['نقدي', 'بطاقة', 'تحويل بنكي'][$i % 3],
                'time' => '2026-07-17 1'.($i % 9).':3'.($i % 6),
                'employee' => 'سارة حسن',
            ];
        }

        return $rows;
    }
}
