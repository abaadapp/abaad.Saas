<?php

namespace Tests\Feature;

use App\Support\RestoreCrossesTenant;
use App\Support\RestoreIsolation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\TwoFullShops;
use Tests\TestCase;

/**
 * كلُّ بابِ كتابةٍ يحمل معرّفًا يُردّ عن صفّ متجرٍ آخر — ولا يمسّه.
 *
 * ═══ كيف يُقاس ═══
 *
 * متجران ممتلئان (`TwoFullShops`). مالكُ A يطرق كلَّ باب بمعرّفِ صفٍّ من B
 * — الأبُ والابنُ من B، ثمّ الأبُ من A والابنُ من B في الأبواب المتداخلة.
 * وبعد كلّ طرقةٍ يُسأل سؤالان:
 *
 *   ١) هل تغيّر في B شيء؟ — بصمةُ كلّ جداوله وكلّ ملفٍّ على القرصين، لا
 *      عددُ صفوفه وحده: تعديلُ عمودٍ واحدٍ في صفٍّ واحدٍ يُغيّرها.
 *   ٢) هل صار في A صفٌّ يُشير إلى صفٍّ من B؟ — متغيّرٌ لمنتج B، أو عنوانٌ
 *      لزبونه (`RestoreIsolation::assertReferences`).
 *
 * وبابٌ جديدٌ يُضاف بسطرٍ في `doors()`: قسمُه وفعلُه ومسارُه ومعاملاتُه.
 * وبابٌ ذو معرّفٍ لم يُذكر هنا يُسقط `test_no_id_door_is_left_out`.
 */
class EveryMerchantWriteStaysInsideItsShopTest extends TestCase
{
    use RefreshDatabase;
    use TwoFullShops;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildTwoShops();
    }

    /**
     * [القسم, الفعل, المسار, [المعامل => مفتاح الصفّ], معاملاتٌ أبُها من A, حمولةٌ خاصّة]
     *
     * والحمولةُ الخاصّة لبابٍ يتحقّق من مدخلاته قبل أن يقرأ الصفّ: بلاها
     * يُردّ عند التحقّق فيمرّ الاختبارُ ولم يُسأل سؤالُ الملكيّة قطّ.
     *
     * @return array<string, list<array{0:string,1:string,2:string,3:array<string,string>,4?:list<string>,5?:array<string,mixed>}>>
     */
    public static function doors(): array
    {
        return [
            'catalog' => [
                ['المنتجات', 'put', 'admin.products.update', ['id' => 'product']],
                ['المنتجات', 'patch', 'admin.products.quick', ['id' => 'product']],
                ['المنتجات', 'post', 'admin.products.toggle', ['id' => 'product']],
                ['المنتجات', 'post', 'admin.products.duplicate', ['id' => 'product']],
                ['المنتجات', 'delete', 'admin.products.destroy', ['id' => 'product']],
                ['المنتجات', 'post', 'admin.products.restore', ['id' => 'product']],
                ['المنتجات', 'delete', 'admin.products.purge', ['id' => 'product']],
                ['صور المنتج', 'post', 'admin.products.images.store', ['id' => 'product']],
                ['صور المنتج', 'post', 'admin.products.images.promote', ['id' => 'product', 'imageId' => 'image'], ['id']],
                ['صور المنتج', 'delete', 'admin.products.images.destroy', ['id' => 'product', 'imageId' => 'image'], ['id']],
                ['صور المنتج', 'delete', 'admin.products.images.destroyMain', ['id' => 'product']],
                ['المتغيّرات', 'post', 'admin.products.variants.store', ['id' => 'product']],
                ['المتغيّرات', 'put', 'admin.products.variants.update', ['id' => 'product', 'variant' => 'variant'], ['id']],
                ['المتغيّرات', 'delete', 'admin.products.variants.destroy', ['id' => 'product', 'variant' => 'variant'], ['id']],
                ['التركيبة', 'post', 'admin.products.recipe.store', ['id' => 'product']],
                ['التركيبة', 'put', 'admin.products.recipe.update', ['id' => 'product', 'item' => 'recipe'], ['id']],
                ['التركيبة', 'delete', 'admin.products.recipe.destroy', ['id' => 'product', 'item' => 'recipe'], ['id']],
                ['الإضافات', 'put', 'admin.products.addons.sync', ['id' => 'product']],
                ['الإضافات', 'delete', 'admin.products.addons.destroy', ['id' => 'product', 'addon' => 'addon'], ['id']],
                ['الإضافات', 'put', 'admin.products.addons.update', ['addon' => 'addon']],
                ['الأقسام', 'patch', 'admin.products.categories.update', ['category' => 'category'], [], ['name' => 'مسروق', 'name_en' => 'Stolen']],
                ['المواسم', 'put', 'admin.seasons.update', ['id' => 'season']],
                ['المواسم', 'put', 'admin.seasons.cycle', ['id' => 'season']],
                ['المواسم', 'delete', 'admin.seasons.destroy', ['id' => 'season']],
                ['المواسم', 'post', 'admin.seasons.attach', ['id' => 'season']],
                ['المواسم', 'delete', 'admin.seasons.detach', ['id' => 'season', 'productId' => 'product'], ['id']],
                ['تذكيرات الموسم', 'post', 'admin.seasons.reminders.store', ['id' => 'season']],
                ['تذكيرات الموسم', 'patch', 'admin.seasons.reminders.update', ['id' => 'season', 'reminderId' => 'reminder'], ['id']],
                ['تذكيرات الموسم', 'delete', 'admin.seasons.reminders.destroy', ['id' => 'season', 'reminderId' => 'reminder'], ['id']],
                ['تذكيرات الموسم', 'post', 'admin.seasons.reminders.read', ['id' => 'season', 'reminderId' => 'reminder'], ['id']],
                ['البوتيكات', 'put', 'admin.boutiques.update', ['id' => 'boutique']],
                ['البوتيكات', 'delete', 'admin.boutiques.destroy', ['id' => 'boutique']],
                ['البوتيكات', 'post', 'admin.boutiques.attach', ['id' => 'boutique']],
                ['البوتيكات', 'post', 'admin.boutiques.settle', ['id' => 'boutique']],
                ['الكوبونات', 'post', 'admin.coupons.toggle', ['id' => 'coupon']],
                ['الكوبونات', 'patch', 'admin.coupons.limits', ['id' => 'coupon']],
                ['الكوبونات', 'delete', 'admin.coupons.destroy', ['id' => 'coupon']],
                ['الطلب المخصّص', 'put', 'admin.customOrders.update', ['id' => 'template']],
                ['الطلب المخصّص', 'delete', 'admin.customOrders.destroy', ['id' => 'template']],
            ],
            'sales' => [
                ['العملاء', 'put', 'admin.customers.update', ['id' => 'customer']],
                ['العملاء', 'delete', 'admin.customers.destroy', ['id' => 'customer']],
                ['العملاء', 'post', 'admin.customers.restore', ['id' => 'customer']],
                ['العملاء', 'delete', 'admin.customers.purge', ['id' => 'customer']],
                ['العملاء', 'post', 'admin.customers.internal', ['id' => 'customer']],
                ['العملاء', 'post', 'admin.customers.language', ['id' => 'customer']],
                ['العملاء', 'post', 'admin.customers.note', ['id' => 'customer']],
                ['العملاء', 'post', 'admin.customers.redeem', ['id' => 'customer']],
                ['عناوين العميل', 'post', 'admin.customers.addresses.save', ['id' => 'customer']],
                ['عناوين العميل', 'post', 'admin.customers.addresses.default', ['id' => 'customer', 'addressId' => 'address'], ['id']],
                ['عناوين العميل', 'delete', 'admin.customers.addresses.delete', ['id' => 'customer', 'addressId' => 'address'], ['id']],
                ['الطلبات', 'put', 'admin.orders.details.update', ['number' => 'order_number']],
                ['الطلبات', 'post', 'admin.orders.status', ['number' => 'order_number']],
                ['الطلبات', 'post', 'admin.orders.send', ['number' => 'order_number']],
                ['الطلبات', 'post', 'admin.orders.reviewRequest', ['number' => 'order_number']],
                ['الطلبات', 'post', 'admin.orders.reviewInvite', ['number' => 'order_number']],
                ['الطلبات', 'post', 'admin.orders.statusNotice', ['number' => 'order_number']],
                ['تصحيح الطلب', 'put', 'admin.orders.items.update', ['number' => 'order_number', 'item' => 'item'], ['number']],
                ['تصحيح الطلب', 'put', 'admin.orders.items.addons.update', ['number' => 'order_number', 'item' => 'item', 'addon' => 'item_addon'], ['number']],
                ['تصحيح الطلب', 'put', 'admin.orders.payment.update', ['number' => 'order_number']],
                ['التجهيز', 'post', 'admin.preparation.check', ['number' => 'order_number']],
                ['التجهيز', 'post', 'admin.preparation.move', ['number' => 'order_number']],
                ['فواتير العملاء', 'post', 'admin.customerInvoices.issue', ['id' => 'invoice']],
                ['فواتير العملاء', 'post', 'admin.customerInvoices.cancel', ['id' => 'invoice']],
                ['فواتير العملاء', 'post', 'admin.customerInvoices.creditNote', ['id' => 'invoice']],
                ['فواتير العملاء', 'post', 'admin.customerInvoices.remind', ['id' => 'invoice']],
                ['فواتير العملاء', 'post', 'admin.customerInvoices.attach', ['id' => 'invoice']],
                ['فواتير العملاء', 'delete', 'admin.customerInvoices.detach', ['id' => 'invoice', 'attachment' => 'invoice_attachment'], ['id']],
                ['سندات القبض', 'post', 'admin.customerPayments.cancel', ['id' => 'payment']],
                ['الذمم', 'put', 'admin.finance.customerTerms', ['customer' => 'customer']],
                ['الذمم', 'post', 'admin.finance.customerBill', ['customer' => 'customer']],
                ['الشيكات', 'post', 'admin.finance.cheques.clear', ['id' => 'payment']],
                ['الشيكات', 'post', 'admin.finance.cheques.bounce', ['id' => 'payment']],
                ['المراجعات', 'post', 'admin.marketing.reviews.status', ['id' => 'review']],
                ['المراجعات', 'post', 'admin.marketing.reviews.reply', ['id' => 'review']],
                ['المراجعات', 'delete', 'admin.marketing.reviews.destroy', ['id' => 'review']],
            ],
            'inventory' => [
                ['الموردون', 'put', 'admin.suppliers.update', ['id' => 'supplier']],
                ['الموردون', 'delete', 'admin.suppliers.destroy', ['id' => 'supplier']],
                ['المشتريات', 'post', 'admin.purchases.receive', ['id' => 'purchase']],
                ['المشتريات', 'post', 'admin.purchases.receipt', ['id' => 'purchase']],
                ['المشتريات', 'delete', 'admin.purchases.destroy', ['id' => 'purchase']],
                ['فواتير الموردين', 'post', 'admin.purchases.invoices.approve', ['id' => 'supplier_invoice']],
                ['فواتير الموردين', 'post', 'admin.purchases.invoices.reject', ['id' => 'supplier_invoice']],
                ['فواتير الموردين', 'post', 'admin.purchases.invoices.cancel', ['id' => 'supplier_invoice']],
                ['فواتير الموردين', 'post', 'admin.purchases.invoices.pay', ['id' => 'supplier_invoice']],
                ['فواتير الموردين', 'delete', 'admin.purchases.invoices.destroy', ['id' => 'supplier_invoice']],
                ['إذونات الاستلام', 'post', 'admin.inventory.receipts.approve', ['id' => 'grn']],
                ['إذونات الاستلام', 'post', 'admin.inventory.receipts.reject', ['id' => 'grn']],
                ['المصروفات', 'post', 'admin.expenses.paid', ['id' => 'expense']],
                ['المصروفات', 'put', 'admin.expenses.scope', ['id' => 'expense'], [], ['scope' => 'business']],
                ['المصروفات', 'delete', 'admin.expenses.destroy', ['id' => 'expense']],
                ['المصروفات', 'post', 'admin.expenses.restore', ['id' => 'expense']],
                ['المصروفات', 'delete', 'admin.expenses.purge', ['id' => 'expense']],
                ['أنواع المصروف', 'put', 'admin.expenseTypes.update', ['id' => 'expense_type']],
                ['أنواع المصروف', 'delete', 'admin.expenseTypes.destroy', ['id' => 'expense_type']],
            ],
            'finance' => [
                ['الحسابات البنكية', 'put', 'admin.finance.banks.update', ['id' => 'bank']],
                ['الحسابات البنكية', 'post', 'admin.finance.banks.primary', ['id' => 'bank']],
                ['الحسابات البنكية', 'delete', 'admin.finance.banks.destroy', ['id' => 'bank']],
                ['دليل الحسابات', 'put', 'admin.finance.chart.update', ['id' => 'account']],
                ['دليل الحسابات', 'post', 'admin.finance.chart.toggle', ['id' => 'account']],
                ['دليل الحسابات', 'delete', 'admin.finance.chart.destroy', ['id' => 'account']],
                ['الأصول', 'post', 'admin.finance.assets.dispose', ['id' => 'asset']],
                ['الأصول', 'delete', 'admin.finance.assets.destroy', ['id' => 'asset']],
                ['القيود', 'post', 'admin.finance.journal.reverse', ['id' => 'journal']],
                ['الرواتب', 'post', 'admin.payroll.approve', ['id' => 'payroll']],
                ['الرواتب', 'post', 'admin.payroll.pay', ['id' => 'payroll']],
                ['الرواتب', 'delete', 'admin.payroll.destroy', ['id' => 'payroll']],
                ['سطور الرواتب', 'put', 'admin.payroll.lines.update', ['id' => 'payroll_line']],
                ['سطور الرواتب', 'delete', 'admin.payroll.lines.destroy', ['id' => 'payroll_line']],
            ],
            'staff' => [
                ['الموظفون', 'put', 'admin.employees.update', ['id' => 'employee']],
                ['الموظفون', 'post', 'admin.employees.toggle', ['id' => 'employee']],
                ['الموظفون', 'post', 'admin.employees.resetPassword', ['id' => 'employee']],
                ['الوظائف', 'put', 'admin.jobTitles.update', ['id' => 'job_title']],
                ['الوظائف', 'delete', 'admin.jobTitles.destroy', ['id' => 'job_title']],
                ['الفروع', 'put', 'admin.branches.update', ['id' => 'branch']],
                ['الفروع', 'delete', 'admin.branches.destroy', ['id' => 'branch']],
                ['الفروع', 'post', 'admin.branches.restore', ['id' => 'branch']],
                ['التنبيهات', 'put', 'admin.alerts.update', ['id' => 'alert'], [], ['type' => 'reminder', 'section' => 'inventory', 'message' => 'HACKED', 'due_at' => '2030-01-01']],
                ['التنبيهات', 'delete', 'admin.alerts.destroy', ['id' => 'alert']],
                ['الأجهزة', 'put', 'admin.devices.update', ['id' => 'device']],
                ['الأجهزة', 'delete', 'admin.devices.revoke', ['id' => 'device']],
                ['الأجهزة', 'delete', 'admin.devices.destroy', ['id' => 'device']],
                ['الملحقات', 'post', 'admin.devices.peripherals.store', ['device' => 'device']],
                ['الملحقات', 'put', 'admin.devices.peripherals.update', ['device' => 'device', 'id' => 'peripheral'], ['device']],
                ['الملحقات', 'delete', 'admin.devices.peripherals.destroy', ['device' => 'device', 'id' => 'peripheral'], ['device']],
                ['Google', 'post', 'admin.integrations.google.branch.link', ['branch' => 'branch']],
                ['Google', 'post', 'admin.integrations.google.branch.refresh', ['branch' => 'branch']],
                ['Google', 'delete', 'admin.integrations.google.branch.unlink', ['branch' => 'branch']],
                ['Google Business', 'post', 'admin.integrations.googleBusiness.branch.link', ['branch' => 'branch']],
                ['Google Business', 'post', 'admin.integrations.googleBusiness.branch.sync', ['branch' => 'branch']],
            ],
            'website' => [
                ['صفحات الموقع', 'put', 'admin.website.pages.update', ['id' => 'page']],
                ['صفحات الموقع', 'delete', 'admin.website.pages.destroy', ['id' => 'page']],
                ['أقسام الموقع', 'put', 'admin.website.sections.update', ['id' => 'section']],
                ['أقسام الموقع', 'post', 'admin.website.sections.toggle', ['id' => 'section']],
                ['أقسام الموقع', 'post', 'admin.website.sections.duplicate', ['id' => 'section']],
                ['أقسام الموقع', 'delete', 'admin.website.sections.destroy', ['id' => 'section']],
                ['محرّر الموقع', 'post', 'admin.website.sections.add', ['id' => 'page']],
                ['محرّر الموقع', 'post', 'admin.website.sections.reorder', ['id' => 'page']],
                ['نسخ الموقع', 'post', 'admin.website.restore', ['id' => 'version']],
                ['نسخ المتجر', 'post', 'admin.website.store.restore', ['version' => 'version']],
            ],
            'pos' => [
                ['نقطة البيع', 'put', 'pos.orders.items.update', ['number' => 'order_number', 'item' => 'item'], ['number']],
                ['نقطة البيع', 'put', 'pos.orders.items.addons.update', ['number' => 'order_number', 'item' => 'item', 'addon' => 'item_addon'], ['number']],
                ['نقطة البيع', 'put', 'pos.orders.payment.update', ['number' => 'order_number']],
                ['نقطة البيع', 'delete', 'pos.orders.discard', ['id' => 'held']],
            ],
        ];
    }

    /**
     * ما يُرسل مع كلّ طرقة — بياناتٌ صالحةُ الشكل ما أمكن، ليبلغ الطلبُ
     * سؤالَ الملكيّة لا أن يُردّ عند التحقّق قبله.
     */
    private function payload(): array
    {
        $b = $this->ids['b'];

        return [
            'name' => 'HACKED', 'title' => 'HACKED', 'message' => 'HACKED', 'note' => 'HACKED', 'reply' => 'HACKED',
            'description' => 'HACKED', 'reason' => 'HACKED', 'label' => 'HACKED', 'body' => 'HACKED',
            'amount' => 1, 'quantity' => 1, 'qty' => 1, 'price' => 1, 'points' => 1, 'cost' => 1,
            'status' => 'مكتمل', 'active' => true, 'confirm' => true, 'is_default' => true,
            'phone' => '91234567', 'email' => 'hacked@evil.test', 'job_title' => 'كاشير',
            'starts_at' => now()->toDateString(), 'ends_at' => now()->addWeek()->toDateString(),
            'type' => 'custom', 'method' => 'cash', 'from' => 'cash', 'language' => 'en',
            'product_ids' => [$b['product']], 'addon_ids' => [$b['addon']], 'order_ids' => [$b['order']],
            'order' => [$b['section']], 'items' => [], 'lines' => [], 'data' => ['text' => 'HACKED'],
            'business_id' => $b['bid'],
        ];
    }

    /** يطرق الأبواب ويجمع ما مسّ متجرَ B أو ربط A بصفوفه */
    private function attack(string $group): void
    {
        $failures = [];
        $a = $this->ids['a'];
        $b = $this->ids['b'];

        foreach (self::doors()[$group] as $door) {
            [$section, $verb, $name, $params] = $door;
            $fromA = $door[4] ?? [];
            $body = ($door[5] ?? []) + $this->payload();

            if (! Route::has($name)) {
                $failures[] = "{$section} | {$name} — المسار غير موجود";

                continue;
            }

            foreach ($fromA === [] ? ['B'] : ['B', 'A+B'] as $shape) {
                $args = [];
                foreach ($params as $param => $key) {
                    $args[$param] = ($shape === 'A+B' && in_array($param, $fromA, true)) ? $a[$key] : $b[$key];
                }

                $uri = strtoupper($verb).' '.Route::getRoutes()->getByName($name)->uri();
                $what = "{$section} | {$uri} | ".json_encode($args, JSON_UNESCAPED_UNICODE)." | المالك المتوقَّع: متجر B | الشكل: {$shape}";

                $status = null;
                $changed = $this->changedTables($this->b->id, function () use ($verb, $name, $args, $body, &$status) {
                    $status = $this->actingAs($this->ownerA)->{$verb.'Json'}(route($name, $args), $body)->getStatusCode();
                });

                if ($changed !== []) {
                    $failures[] = "{$what} → HTTP {$status} · تغيّر في B: ".implode(', ', $changed);
                }

                if ($status >= 200 && $status < 300) {
                    $failures[] = "{$what} → HTTP {$status} (قُبل الطلب)";
                }

                try {
                    RestoreIsolation::assertReferences($this->a->id);
                } catch (RestoreCrossesTenant $e) {
                    $failures[] = "{$what} → صفٌّ في A يُشير إلى B: {$e->where}";
                }
            }
        }

        $this->assertSame([], $failures, "أبوابُ «{$group}» مسّت متجرًا آخر:\n".implode("\n", $failures));
    }

    public function test_catalog_doors_stay_inside_their_shop(): void
    {
        $this->attack('catalog');
    }

    public function test_sales_doors_stay_inside_their_shop(): void
    {
        $this->attack('sales');
    }

    public function test_inventory_and_purchasing_doors_stay_inside_their_shop(): void
    {
        $this->attack('inventory');
    }

    public function test_finance_doors_stay_inside_their_shop(): void
    {
        $this->attack('finance');
    }

    public function test_staff_branch_and_device_doors_stay_inside_their_shop(): void
    {
        $this->attack('staff');
    }

    public function test_website_doors_stay_inside_their_shop(): void
    {
        $this->attack('website');
    }

    public function test_pos_doors_stay_inside_their_shop(): void
    {
        $this->attack('pos');
    }

    /** والبصمةُ كلُّها في آخر المطاف — بعد كلّ الأبواب معًا */
    public function test_after_every_door_shop_b_is_byte_for_byte_the_same(): void
    {
        $before = $this->fingerprint($this->b->id);

        foreach (array_keys(self::doors()) as $group) {
            foreach (self::doors()[$group] as $door) {
                [, $verb, $name, $params] = $door;
                if (Route::has($name)) {
                    $args = array_map(fn ($key) => $this->ids['b'][$key], $params);
                    $this->actingAs($this->ownerA)->{$verb.'Json'}(route($name, $args), ($door[5] ?? []) + $this->payload());
                }
            }
        }

        $this->assertSame($before, $this->fingerprint($this->b->id), 'تغيّر في متجر B شيءٌ — صفٌّ أو ملفّ');
    }

    /**
     * ولا بابَ ذا معرّفٍ يُضاف ويُنسى هنا.
     *
     * كلُّ مسارِ كتابةٍ تحت `/admin` و`/pos` في عنوانه معرّفٌ إمّا في
     * `doors()` وإمّا في `NOT_A_ROW` بسببه.
     */
    private const NOT_A_ROW = [
        'admin.integrations.connect' => '{tool} اسمُ أداةٍ من قائمةٍ ثابتة لا صفّ',
        'admin.settings.templates.update' => '{type} نوعُ مستندٍ من قائمةٍ ثابتة — والإعدادُ بمتجر الجلسة',
        'admin.settings.templates.preview' => 'مثلُه',
        'admin.help.reply' => 'محادثاتُ الدعم تُقرأ بـ`Support::visibleTo` — حارسُها في اختبارات الدعم',
        'admin.integrations.googleBusiness.reply' => '{review} مراجعةُ Google لا تُنشأ بلا حسابٍ مربوط — مقيّدةٌ بفروع المتجر (GoogleBusinessController:164)',
    ];

    public function test_no_id_door_is_left_out(): void
    {
        $covered = [];
        foreach (self::doors() as $group) {
            foreach ($group as $door) {
                $covered[$door[2]] = true;
            }
        }

        $missing = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            $methods = array_diff($route->methods(), ['GET', 'HEAD']);

            if ($methods === [] || ! str_contains($uri, '{') || ! str_starts_with($uri, 'admin') && ! str_starts_with($uri, 'pos')) {
                continue;
            }

            $name = (string) $route->getName();
            if (! isset($covered[$name]) && ! isset(self::NOT_A_ROW[$name])) {
                $missing[] = implode('|', $methods).' '.$uri.' ('.$name.')';
            }
        }

        $this->assertSame([], $missing, "أبوابُ كتابةٍ بمعرّفٍ لا يطرقها هذا الاختبار:\n".implode("\n", $missing));
    }
}
