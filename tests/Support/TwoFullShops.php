<?php

namespace Tests\Support;

use App\Models\Business;
use App\Models\User;
use App\Support\DemoStore;
use App\Support\TenantTables;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * متجران ممتلئان — لكلّ بابٍ صفٌّ في كلٍّ منهما يُجرَّب عليه.
 *
 * `DemoStore` يملأ الطلبات والزبائن والمنتجات والدفاتر، ويترك فارغًا ما لا
 * يحتاجه الديمو: المتغيّرات والإضافات والمواسم والبوتيكات والأجهزة وفواتير
 * العملاء والموقع. وبابٌ لا صفَّ له في متجر الجار يمرّ من اختبار العزل بلا
 * أن يُجرَّب. فيُكمَّل هنا صفٌّ في كلٍّ منها — بالأعمدة الإلزاميّة وحدها،
 * والمراجعُ صريحةٌ إلى صفوف المتجر نفسِه.
 */
trait TwoFullShops
{
    protected Business $a;

    protected Business $b;

    protected User $ownerA;

    /** @var array<string, array<string, mixed>> صفوفٌ بعينها في كلّ متجر — `ids['b']['product']` */
    protected array $ids = [];

    protected function buildTwoShops(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $this->a = DemoStore::create('متجر A', 'صغير');
        $this->b = DemoStore::create('متجر B', 'صغير');

        $this->ids['a'] = $this->complete($this->a->id, 'A');
        $this->ids['b'] = $this->complete($this->b->id, 'B');

        // ومعرّفٌ صفريّ يجعل البابَ يُردّ ٤٠٤ بلا أن يُجرَّب — فلا يُقبل
        foreach ($this->ids as $shop => $ids) {
            foreach ($ids as $key => $id) {
                if ($id === 0 || $id === '') {
                    throw new \RuntimeException("لا صفَّ «{$key}» في متجر {$shop} — البابُ لن يُجرَّب");
                }
            }
        }

        $this->ownerA = User::where('business_id', $this->a->id)->where('role', 'admin')->orderBy('id')->firstOrFail();
    }

    /** يُكمل المتجر بما تركه الديمو فارغًا — ويردّ معرّفاتِ ما يُجرَّب عليه */
    private function complete(int $bid, string $tag): array
    {
        $first = fn (string $table) => (int) TenantTables::scope($table, $bid)->orderBy('id')->value('id');

        $branch = $first('branches');
        $product = $first('products');
        $component = (int) TenantTables::scope('products', $bid)->orderBy('id')->skip(1)->value('id');
        $customer = $first('customers');
        $order = (int) DB::table('orders')->where('business_id', $bid)->where('is_held', false)->orderBy('id')->value('id');
        /*
         * وأرقامُ الطلبات تتكرّر بين المتجرين — الديمو يبدأ كلَّ متجرٍ من الرقم
         * نفسِه، والرقمُ فريدٌ في متجره لا على المنصّة. فرقمُ B يُفرَد: وإلّا
         * طرق A بابَ «طلبٍ لـB» فوجد طلبَه هو ومرّ الاختبارُ بلا سؤال.
         */
        DB::table('orders')->where('id', $order)->update(['number' => "ONLY-{$tag}-{$order}"]);
        $item = (int) DB::table('order_items')->where('order_id', $order)->value('id');

        $imagePath = "products/{$bid}/gallery-{$tag}.jpg";
        Storage::disk('public')->put($imagePath, 'IMG-'.$tag);
        $attachPath = "customer-invoices/{$bid}/doc-{$tag}.pdf";
        Storage::disk('local')->put($attachPath, 'DOC-'.$tag);

        $season = $this->row('seasons', ['business_id' => $bid, 'name' => "موسم {$tag}", 'starts_at' => now()->toDateString(), 'ends_at' => now()->addMonth()->toDateString()]);
        $device = $this->row('pos_devices', ['business_id' => $bid, 'branch_id' => $branch, 'name' => "صندوق {$tag}", 'token_hash' => hash('sha256', Str::random(20))]);
        $template = $this->row('custom_order_templates', ['business_id' => $bid, 'name' => "قالب {$tag}", 'modes' => '[]']);
        $field = $this->row('custom_order_fields', ['template_id' => $template, 'label' => "حقل {$tag}", 'type' => 'text']);
        $invoice = $this->row('customer_invoices', ['business_id' => $bid, 'customer_id' => $customer, 'number' => "CI-{$tag}-1", 'status' => 'draft', 'total' => 10]);
        $website = $this->row('websites', ['business_id' => $bid, 'name' => "موقع {$tag}", 'theme' => '{}']);
        $held = $this->row('orders', ['business_id' => $bid, 'branch_id' => $branch, 'number' => "HELD-{$tag}", 'is_held' => true, 'subtotal' => 5, 'total' => 5, 'status' => 'معلّق', 'ordered_at' => now()]);
        $addon = $this->row('addons', ['business_id' => $bid, 'name' => "إضافة {$tag}", 'price' => 1]);

        return [
            'bid' => $bid,
            'branch' => $branch,
            'product' => $product,
            'customer' => $customer,
            'address' => (int) DB::table('customer_addresses')->where('customer_id', $customer)->value('id'),
            'order' => $order,
            'order_number' => (string) DB::table('orders')->where('id', $order)->value('number'),
            'item' => $item,
            'item_addon' => $this->row('order_item_addons', ['order_item_id' => $item, 'name' => "زيادة {$tag}", 'unit_price' => 1, 'total' => 1]),
            'held' => $held,
            'employee' => (int) DB::table('users')->where('business_id', $bid)->where('role', '!=', 'admin')->orderBy('id')->value('id'),
            'job_title' => $first('job_titles'),
            'expense' => $first('expenses'),
            'expense_type' => $first('expense_types'),
            'supplier' => $first('suppliers'),
            'purchase' => $first('purchase_orders'),
            'supplier_invoice' => $first('supplier_invoices'),
            'coupon' => $first('coupons'),
            'bank' => $first('bank_accounts'),
            'account' => (int) DB::table('accounts')->where('business_id', $bid)->whereNotNull('parent_id')->orderBy('id')->value('id'),
            'asset' => $first('fixed_assets'),
            'journal' => $first('journal_entries'),
            'payroll' => $first('payroll_runs'),
            'payroll_line' => $first('payroll_lines'),
            'review' => $first('reviews'),
            'category' => $this->row('categories', ['business_id' => $bid, 'name' => "فئة {$tag}"]),
            'boutique' => $this->row('boutiques', ['business_id' => $bid, 'name' => "بوتيك {$tag}"]),
            'season' => $season,
            'reminder' => $this->row('season_reminders', ['business_id' => $bid, 'season_id' => $season, 'type' => 'custom', 'message' => "تذكير {$tag}"]),
            'variant' => $this->row('product_variants', ['business_id' => $bid, 'product_id' => $product, 'name' => "مقاس {$tag}"]),
            'image' => $this->row('product_images', ['business_id' => $bid, 'product_id' => $product, 'path' => $imagePath]),
            'image_path' => $imagePath,
            'addon' => $addon,
            'product_addon' => $this->row('product_addons', ['business_id' => $bid, 'product_id' => $product, 'addon_id' => $addon]),
            'recipe' => $this->row('recipe_items', ['business_id' => $bid, 'product_id' => $product, 'component_product_id' => $component, 'quantity' => 1]),
            'device' => $device,
            'peripheral' => $this->row('pos_peripherals', ['business_id' => $bid, 'pos_device_id' => $device, 'name' => "طابعة {$tag}", 'type' => 'printer']),
            // تذكيرٌ صالح كما يكتبه التحقّق — قاعدةٌ بلا مقياسٍ تُسقط حسابَ التنبيهات في كلّ شاشة
            'alert' => $this->row('custom_alerts', ['business_id' => $bid, 'type' => 'reminder', 'section' => 'inventory', 'message' => "تنبيه {$tag}", 'due_at' => now()->addDay()]),
            'template' => $template,
            'invoice' => $invoice,
            'invoice_attachment' => $this->row('customer_invoice_attachments', ['business_id' => $bid, 'customer_invoice_id' => $invoice, 'path' => $attachPath, 'name' => 'doc.pdf']),
            'attach_path' => $attachPath,
            'payment' => $this->row('customer_payments', ['business_id' => $bid, 'customer_id' => $customer, 'number' => "RC-{$tag}-1", 'amount' => 5, 'method' => 'cheque', 'occurred_at' => now()->toDateString()]),
            'grn' => $this->row('goods_receipt_notes', ['business_id' => $bid, 'number' => "GRN-{$tag}-1", 'received_at' => now()->toDateString(), 'status' => 'pending']),
            'website' => $website,
            'page' => $this->row('website_pages', ['website_id' => $website, 'business_id' => $bid, 'key' => 'about', 'title' => "صفحة {$tag}", 'slug' => 'about-'.strtolower($tag)]),
            'section' => $this->row('website_sections', ['website_id' => $website, 'business_id' => $bid, 'type' => 'text', 'data' => '{}']),
            'version' => $this->row('website_versions', ['business_id' => $bid, 'number' => 1, 'payload' => '{}']),
        ];
    }

    /**
     * صفٌّ بما أُعطي — وما بقي إلزاميًّا بلا قيمةٍ يُملأ بما يناسب نوعَه.
     *
     * @param  array<string, mixed>  $values
     */
    protected function row(string $table, array $values): int
    {
        foreach (Schema::getColumns($table) as $c) {
            $name = $c['name'];

            if ($name === 'id' || array_key_exists($name, $values) || $c['nullable'] || $c['default'] !== null) {
                continue;
            }

            $type = strtolower((string) $c['type_name']);
            $values[$name] = match (true) {
                str_contains($type, 'int') => 0,
                in_array($type, ['numeric', 'decimal', 'float', 'double', 'real', 'float8', 'float4'], true) => 0,
                in_array($type, ['bool', 'boolean'], true) => false,
                str_contains($type, 'date') || str_contains($type, 'time') => now(),
                in_array($type, ['json', 'jsonb'], true) => '{}',
                default => $table.'-'.Str::lower(Str::random(8)),
            };
        }

        foreach (['created_at', 'updated_at'] as $stamp) {
            if (! array_key_exists($stamp, $values) && Schema::hasColumn($table, $stamp)) {
                $values[$stamp] = now();
            }
        }

        return (int) DB::table($table)->insertGetId($values);
    }

    /**
     * بصمةُ متجرٍ كاملةً: كلُّ صفٍّ في كلّ جدولٍ له، وكلُّ ملفٍّ على القرصين.
     *
     * تتغيّر بتغيّر حرفٍ واحدٍ في صفٍّ واحد — أو بصفٍّ يُضاف أو يُحذف.
     */
    protected function fingerprint(int $bid): string
    {
        $parts = [];

        foreach (TenantTables::all() as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $rows = $table === 'users'
                ? DB::table('users')->where('business_id', $bid)
                : TenantTables::scope($table, $bid);

            $parts[$table] = md5(json_encode($rows->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
        }

        $parts['businesses'] = md5(json_encode((array) DB::table('businesses')->where('id', $bid)->first()));

        foreach (['local', 'public'] as $disk) {
            $files = Storage::disk($disk)->allFiles();
            sort($files);
            $parts['disk:'.$disk] = md5(implode('|', array_map(fn ($f) => $f.'#'.md5((string) Storage::disk($disk)->get($f)), $files)));
        }

        return md5(json_encode($parts));
    }

    /** أيُّ جدولٍ تغيّر بين بصمتين — لرسالة الفشل */
    protected function changedTables(int $bid, callable $act): array
    {
        $snap = function () use ($bid) {
            $out = [];
            foreach (TenantTables::all() as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                $rows = $table === 'users' ? DB::table('users')->where('business_id', $bid) : TenantTables::scope($table, $bid);
                $out[$table] = md5(json_encode($rows->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
            }

            return $out;
        };

        $before = $snap();
        $act();

        return array_keys(array_diff_assoc($snap(), $before));
    }
}
