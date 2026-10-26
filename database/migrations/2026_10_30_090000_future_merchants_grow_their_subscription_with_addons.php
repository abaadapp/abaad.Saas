<?php

use App\Models\Plan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * إضافاتُ الاشتراك — للمتاجر التي تُسجَّل بعد إطلاقها وحدها.
 *
 * ═══ ما يُكتب ═══
 *
 *   - `businesses.pricing_policy`: سياسةُ تسعير المتجر مثبَّتةً على صفّه.
 *     افتراضُها `legacy` — فكلُّ متجرٍ قائمٍ يُقرأ على سياسته السابقة بلا
 *     كتابةٍ في صفّه، ولا يُعاد تصنيفُه بمرور الوقت ولا بالتجديد.
 *   - `subscription_addons`: كتالوجُ الإضافات — مستقلٌّ عن `addons` (إضافاتُ
 *     منتجات المتاجر).
 *   - `subscription_addon_plans`: لكلّ باقةٍ ما يُشترى فيها وما يُشمل بها.
 *   - `business_addons`: استحقاقُ متجرٍ لإضافة — بدايتُه ونهايتُه وسعرُه المتّفق.
 *   - `addon_orders`: طلبُ الإضافة وفاتورتُه — ولا طلبان مفتوحان للشيء نفسه.
 *   - `plan_policy_prices`: سعرُ الباقة في سياسةٍ بعينها — يبقى سعرُ الباقة
 *     القديم لأصحابه كما هو.
 *
 * ═══ وما لا يُمسّ ═══
 *
 * لا باقةَ ولا سعرَ ولا قدرةَ ولا اشتراكَ ولا فاتورةَ قائمة. والكتالوجُ يُزرع
 * بأسعار المالك الأوّليّة ولا يفتح شيئًا: لا متجرَ على السياسة الجديدة حتى
 * يُفتح الإطلاقُ من لوحة المنصّة بعد اعتماد سعر الباقة الأساسيّة فيها.
 */
return new class extends Migration
{
    /** الكتالوجُ الأوّليّ كما اعتمده المالك — بالريال العُمانيّ */
    public const CATALOG = [
        ['code' => 'pos', 'name' => 'نظام الكاشير ونقاط البيع POS', 'name_en' => 'Cashier & point of sale (POS)', 'capability' => 'pos', 'pricing' => 'paid', 'unit' => 'store', 'monthly' => '9.900', 'yearly' => '99.000', 'sort' => 10],
        ['code' => 'website', 'name' => 'المتجر الإلكتروني وإدارة الطلبات', 'name_en' => 'Online store & order management', 'capability' => 'website', 'pricing' => 'paid', 'unit' => 'store', 'monthly' => '12.900', 'yearly' => '129.000', 'sort' => 20],
        ['code' => 'marketing', 'name' => 'العروض والخصومات والكوبونات', 'name_en' => 'Offers, discounts & coupons', 'capability' => 'marketing_tools', 'pricing' => 'paid', 'unit' => 'store', 'monthly' => '2.900', 'yearly' => '29.000', 'sort' => 30],
        ['code' => 'loyalty', 'name' => 'برنامج الولاء والمكافآت', 'name_en' => 'Loyalty & rewards', 'capability' => 'loyalty', 'pricing' => 'paid', 'unit' => 'store', 'monthly' => '3.900', 'yearly' => '39.000', 'sort' => 40],
        ['code' => 'whatsapp', 'name' => 'إشعارات واتساب', 'name_en' => 'WhatsApp notifications', 'capability' => 'whatsapp', 'pricing' => 'paid', 'unit' => 'store', 'monthly' => '2.900', 'yearly' => '29.000', 'sort' => 50],
        ['code' => 'extra_branch', 'name' => 'فرع إضافي', 'name_en' => 'Extra branch', 'capability' => 'branches', 'pricing' => 'paid', 'unit' => 'branch', 'monthly' => '9.900', 'yearly' => '99.000', 'sort' => 60],
        ['code' => 'gift_orders', 'name' => 'إدارة الهدايا والطلبات المخصصة', 'name_en' => 'Gift & custom orders', 'capability' => 'gift_orders', 'pricing' => 'paid', 'unit' => 'store', 'monthly' => '4.900', 'yearly' => '49.000', 'sort' => 70],
        ['code' => 'whatsapp_own_number', 'name' => 'ربط واتساب برقم المتجر', 'name_en' => 'WhatsApp on your own number', 'capability' => 'whatsapp_own_number', 'pricing' => 'quote', 'unit' => 'store', 'monthly' => null, 'yearly' => null, 'sort' => 80],
        ['code' => 'api_integrations', 'name' => 'التكامل مع الأنظمة الخارجية API', 'name_en' => 'External systems integration (API)', 'capability' => null, 'pricing' => 'quote', 'unit' => 'store', 'monthly' => null, 'yearly' => null, 'sort' => 90],
    ];

    /**
     * أين يُباع كلٌّ وأين يُشمل — كما في جدول المالك.
     *
     * الأساسيّةُ تشتري خمسًا؛ والاحترافيّةُ والمؤسساتُ تشملان الخمسَ نفسَها
     * (كما تشملها اليوم) وتشتريان ما فوقها.
     */
    public const PLANS = [
        'basic' => ['eligible' => ['pos', 'website', 'marketing', 'loyalty', 'whatsapp'], 'included' => []],
        'professional' => ['eligible' => ['extra_branch', 'gift_orders', 'whatsapp_own_number', 'api_integrations'], 'included' => ['pos', 'website', 'marketing', 'loyalty', 'whatsapp']],
        'enterprise' => ['eligible' => ['gift_orders', 'whatsapp_own_number', 'api_integrations'], 'included' => ['pos', 'website', 'marketing', 'loyalty', 'whatsapp']],
    ];

    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            // سياسةُ التسعير مثبَّتةً — والقائمُ كلُّه على السابقة بافتراض العمود، بلا كتابة
            $table->string('pricing_policy', 20)->default('legacy')->index();
        });

        Schema::create('subscription_addons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->text('description')->nullable();
            $table->text('description_en')->nullable();
            // ما تفتحه من قائمةٍ مغلقة (`Addons\Catalog::CAPABILITIES`) — والفراغُ خدمةٌ تقدّمها أبعاد بلا بابٍ في النظام
            $table->string('capability', 40)->nullable();
            $table->string('pricing', 10)->default('paid');
            $table->string('unit', 10)->default('store');
            $table->decimal('monthly_price', 10, 3)->nullable();
            $table->decimal('yearly_price', 10, 3)->nullable();
            $table->boolean('for_sale')->default(true);
            $table->boolean('for_new_merchants')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('subscription_addon_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_addon_id')->constrained('subscription_addons')->cascadeOnDelete();
            $table->foreignId('plan_id')->index()->constrained('plans')->cascadeOnDelete();
            $table->string('mode', 10);
            $table->timestamps();
            $table->unique(['subscription_addon_id', 'plan_id']);
        });

        Schema::create('business_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('subscription_addon_id')->index()->constrained('subscription_addons')->restrictOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('source', 10);
            $table->string('cycle', 10)->nullable();
            // سعرُ الوحدة للدورة كما اتُّفق — لا يتبع الكتالوج بعده
            $table->decimal('unit_price', 10, 3)->default(0);
            $table->date('starts_at');
            $table->date('ends_at')->nullable();
            $table->boolean('renews')->default(true);
            $table->timestamp('cancelled_at')->nullable();
            $table->string('reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['business_id', 'subscription_addon_id']);
        });

        Schema::create('addon_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('subscription_addon_id')->index()->constrained('subscription_addons')->restrictOnDelete();
            $table->foreignId('business_addon_id')->nullable()->index()->constrained('business_addons')->nullOnDelete();
            $table->string('kind', 10)->default('new');
            $table->string('cycle', 10);
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 10, 3)->nullable();
            $table->decimal('amount', 10, 3)->nullable();
            $table->string('status', 12);
            $table->foreignId('invoice_id')->nullable()->unique()->constrained('invoices')->nullOnDelete();
            // مفتاحُ الطلب المفتوح — فريدٌ، فطلبان معًا للشيء نفسه صفٌّ واحد؛ ويُفرَّغ حين يُحسم
            $table->string('open_key', 80)->nullable()->unique();
            $table->foreignId('requested_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('requested_by_name')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'status']);
        });

        Schema::create('plan_policy_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('policy', 20);
            $table->decimal('monthly_price', 10, 3);
            $table->decimal('yearly_price', 10, 3);
            $table->timestamps();
            $table->unique(['plan_id', 'policy']);
        });

        $this->seedCatalog();
    }

    public function down(): void
    {
        /*
         * ولا يُرجع ما بُني عليه: متجرٌ على السياسة الجديدة، أو استحقاقٌ، أو طلبٌ
         * — إسقاطُ الجداول يمحو حقَّ متجرٍ دفع. يُرجع الكودُ وحده (Revert PR).
         */
        $used = DB::table('businesses')->where('pricing_policy', '!=', 'legacy')->exists()
            || DB::table('business_addons')->exists()
            || DB::table('addon_orders')->exists();

        if ($used) {
            throw new RuntimeException('إضافات الاشتراك: في القاعدة متاجرُ على السياسة الجديدة أو استحقاقاتٌ أو طلبات — لا تُسقط الجداول. أرجِع الكود وحده.');
        }

        Schema::dropIfExists('plan_policy_prices');
        Schema::dropIfExists('addon_orders');
        Schema::dropIfExists('business_addons');
        Schema::dropIfExists('subscription_addon_plans');
        Schema::dropIfExists('subscription_addons');

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropIndex(['pricing_policy']);
            $table->dropColumn('pricing_policy');
        });
    }

    /**
     * الكتالوجُ وأماكنُ بيعه — يُزرع ولا يفتح شيئًا.
     *
     * والباقاتُ تُعرف برمزها، والاحترافيّةُ باسمها حين تكون واحدةً بلا رمز. وما
     * لا يُعرف بلا لبس لا يُربط: يربطه صاحبُ المنصّة من شاشة الإضافات.
     */
    private function seedCatalog(): void
    {
        $now = now();
        $ids = [];

        foreach (self::CATALOG as $row) {
            $ids[$row['code']] = DB::table('subscription_addons')->insertGetId([
                'code' => $row['code'], 'name' => $row['name'], 'name_en' => $row['name_en'],
                'capability' => $row['capability'], 'pricing' => $row['pricing'], 'unit' => $row['unit'],
                'monthly_price' => $row['monthly'], 'yearly_price' => $row['yearly'],
                'for_sale' => true, 'for_new_merchants' => true, 'sort' => $row['sort'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach (self::PLANS as $which => $modes) {
            $planId = $this->planId($which);
            if ($planId === null) {
                continue;
            }

            foreach ($modes as $mode => $codes) {
                foreach ($codes as $code) {
                    DB::table('subscription_addon_plans')->insert([
                        'subscription_addon_id' => $ids[$code], 'plan_id' => $planId, 'mode' => $mode,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    private function planId(string $which): ?int
    {
        $found = match ($which) {
            'basic' => DB::table('plans')->where('code', Plan::CODE_BASIC)->pluck('id'),
            'enterprise' => DB::table('plans')->where('code', Plan::CODE_ENTERPRISE)->pluck('id'),
            default => DB::table('plans')->whereNull('code')->where('name', 'الباقة الاحترافية')->pluck('id'),
        };

        return $found->count() === 1 ? (int) $found->first() : null;
    }
};
