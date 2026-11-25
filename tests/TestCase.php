<?php

namespace Tests;

use App\Models\Branch;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * الذاكرة الساكنة لا تعيش بين اختبارين.
     *
     * قاعدة البيانات تُلفّ وتُعاد، لكن `Demo::$baseCur` ساكنةٌ تعيش ما عاشت
     * العمليّة — فاختبارٌ يضبط عملته دولارًا يجعل الاختبار الذي يليه يقرأ
     * الدولار وقد مُحيت إعداداته. عطلٌ في الاختبار لا في النظام، وأسوأ منه
     * أنه يظهر ويختفي بترتيب التشغيل.
     */
    protected function setUp(): void
    {
        parent::setUp();

        \App\Support\Demo::flushCurrency();

        /*
         * وذاكرةُ `MarketingSettings` مثلُها: تحفظ ما قرأت في الطلب الواحد
         * فلا تسأل الجدولَ سبعَ عشرةَ مرّة، وتُبطَل عند كلّ كتابة (انظر
         * `Setting::booted`). لكنّ الاختبارات تتشارك العمليّة والجدولُ يُلفّ
         * بينها — فما قُرئ في اختبارٍ يُقرأ في الذي يليه وقد مُحيت قاعدتُه.
         */
        \App\Support\MarketingSettings::forget();

        /*
         * ومتجرُ النطاق الفرعيّ الحيّ يُذكر في اختباره وحده.
         *
         * `storefront.subdomain_businesses` معرّفٌ حرفيّ (5)، وفي قاعدةٍ لا
         * تُصفِّر عدّادها بين اختبارين يصير خامسُ متجرٍ يُنشأ «RIBBON» —
         * فتتحوّل صفحاتُه على `/s/…` إلى نطاقٍ فرعيّ لم يطلبه اختبارُه.
         */
        config(['storefront.subdomain_businesses' => []]);

        /*
         * ‏‏وصلةُ واتساب يكتبها اختبارٌ بيده وصلةٌ قائمةٌ قبل قفل الفوترة — ما لم يقل غير ذلك.
         *
         * القفلُ يشمل كلَّ وصلةٍ تُكتب بعده (`WhatsAppBillingLock`)، وأبوابُ الربط تكتب العمودَ صراحةً. والاختباراتُ
         * التي سبقته تصف وصلاتٍ تُرسل: يبقى معناها. وحارسُ القفل نفسه يثبت أنّ صفًّا بلا العمود **مقفل**.
         */
        \App\Models\WhatsAppConnection::creating(function ($c) {
            if (! array_key_exists('billing_exempt', $c->getAttributes())) {
                $c->billing_exempt = $c->owner_type === 'business';
            }
        });
    }

    /**
     * ‏‏تجربةُ فوترةٍ نجحت — فيسري اختيارُ «تطبيق أبعاد» للتجّار الجدد (`WhatsAppBillingTrials::launched`).
     *
     * للاختبارات التي تصف ما بعد الإطلاق. وما قبله يحرسه `AbaadNeverPaysForAShopsMessagesTest`.
     */
    protected function launchWhatsAppForAll(): void
    {
        $shop = \App\Models\Business::query()->orderBy('id')->first() ?? \App\Models\Business::create(['name' => 'متجر التجربة', 'status' => 'نشط']);

        \App\Models\WhatsAppBillingTrial::create([
            'business_id' => $shop->id, 'waba_id' => 'WABA-TRIAL', 'phone_number_id' => 'PN-TRIAL', 'state' => 'passed',
            'message_limit' => 1, 'messages_used' => 1, 'opened_at' => now()->subDay(), 'expires_at' => now(), 'passed_at' => now(),
            'opened_by_name' => 'مدير المنصة', 'proof' => ['kind' => 'billing_charge'],
        ]);
    }

    /**
     * ‏‏ثبتت فوترةُ هذا المتجر على هذا الحساب، وMeta لا تُطلع تطبيقَنا على وسيلة الدفع (`#10`) — كما هي حقًّا.
     *
     * لاختباراتٍ تصف رقمًا يُرسل بعد الربط ولا شأنَ لها بالإثبات نفسه.
     */
    protected function billedToTheShop(int $businessId, string ...$wabas): void
    {
        \Illuminate\Support\Facades\Http::fake(['*primary_funding_id*' => \Illuminate\Support\Facades\Http::response(['error' => ['code' => 10, 'message' => '(#10)']], 403)]);

        foreach ($wabas as $waba) {
            $this->proveWhatsAppBilling($businessId, $waba);
        }
    }

    /** ‏‏ثبتت الفوترةُ المباشرة لهذا المتجر على هذا الحساب — وبلا مالكٍ مسمًّى لا يُقاس به مالكُ اليوم */
    protected function proveWhatsAppBilling(int $businessId, string $waba, string $owner = ''): \App\Models\WhatsAppBilling
    {
        return \App\Models\WhatsAppBilling::updateOrCreate(['business_id' => $businessId, 'waba_id' => $waba], [
            'state' => 'proven', 'code' => null, 'proven_at' => now(), 'checked_at' => now(), 'owner_id' => $owner,
            'facts' => ['owner_known' => true, 'abaad_known' => true],
            'evidence' => ['kind' => 'payment_settings', 'reference' => 'صورة', 'waba_id' => $waba, 'owner_id' => $owner, 'reviewed_at' => now()->toIso8601String()],
        ]);
    }

    /**
     * رابطُ تصديرٍ كما تبنيه الشاشة — يحمل نشاطَ من يجلس الآن.
     *
     * كلُّ زرِّ تصديرٍ في اللوحة يحمل `expected_business_id` من خصائص صفحته،
     * والملفّاتُ التي تجمع بيانَ النشاط لا تخرج بدونه (`TenantExportGuard`).
     * والاختباراتُ التي تفحص محتوى الملفّ تطلبه كما تطلبه الشاشة: بنشاط
     * المستخدم المسجَّل — أو بنشاطٍ مذكورٍ لمن يفحص التبويبَ القديم.
     */
    protected function exportRoute(string $name, mixed $parameters = [], ?int $businessId = null): string
    {
        $url = route($name, $parameters);
        $businessId ??= auth()->user()?->business_id;

        return $businessId ? \App\Support\TenantExportGuard::url($url, (int) $businessId) : $url;
    }

    /**
     * وردية مفتوحة لهذا النشاط.
     *
     * البيع صار يتطلّبها، وهي شرطٌ للسيناريو لا موضوعُه: اختبارات المخزون
     * والحساب والفواتير تحتاج صندوقًا مفتوحًا لتصل إلى ما تفحصه. تُكتب هنا
     * مباشرةً لأن التهيئة تسبق تسجيل الدخول، فلا مستخدم بعدُ لتُشتقّ منه.
     */
    /**
     * اعتمادُ كلّ استلامٍ معلَّق — وبه تدخل البضاعةُ الرفّ.
     *
     * صار الاستلامُ خطوتين: تُكتب الورقة، ثمّ تُعتمد. وأكثرُ الاختبارات
     * تفحص أثرَ الشحنة على المخزون لا مسارَ الاعتماد نفسه، فالخطوةُ الثانية
     * فيها شرطُ السيناريو لا موضوعُه.
     *
     * وتمرّ بالخدمة لا بالمسار: المسارُ يُفحص في اختباره وحده.
     *
     * @return int عددُ ما اعتُمد
     */
    protected function approvePendingReceipts(int $businessId, ?\App\Models\User $by = null): int
    {
        $notes = \App\Models\GoodsReceiptNote::where('business_id', $businessId)
            ->where('status', \App\Support\GoodsReceipts::PENDING)->orderBy('id')->get();

        foreach ($notes as $note) {
            \App\Support\GoodsReceipts::approve($note, $by ?? auth()->user());
        }

        return $notes->count();
    }

    /**
     * اعتمادُ كلّ سند مورّدٍ معلَّق — وبه تنشأ الذمّة في الدفتر.
     *
     * وصار السندُ يُكتب أوّلًا ويُعتمد ثانيًا، كالاستلام. وأكثرُ الاختبارات
     * تفحص أثرَ الذمّة أو السداد لا مسارَ الاعتماد نفسه.
     *
     * ولا تُطابَق: التجاوزُ مُمرَّرٌ بسببٍ صريح كي تمرّ سنداتُ الاختبارات
     * التي لا أوامرَ مستلَمةً لها — والمطابقةُ تُفحص في اختبارها وحده.
     */
    protected function approvePendingSupplierInvoices(int $businessId, ?\App\Models\User $by = null): int
    {
        $invoices = \App\Models\SupplierInvoice::where('business_id', $businessId)
            ->where('approval_status', \App\Support\SupplierInvoices::PENDING)->orderBy('id')->get();

        foreach ($invoices as $invoice) {
            \App\Support\SupplierInvoices::approve($invoice, $by ?? auth()->user(), 'اعتمادُ اختبار');
        }

        return $invoices->count();
    }

    /**
     * جهاز نقطة بيع مفعَّل على هذا المتصفّح.
     *
     * صار البيع يتطلّبه: الجهاز هو من يعرف الفرع، وبلا تفعيل يعود الفرع إلى
     * جلسة المتصفّح — وهو العطب الذي جاء التفعيل ليغلقه. وهو في أكثر
     * الاختبارات شرطُ السيناريو لا موضوعُه، فيُهيَّأ هنا في سطر.
     */
    /**
     * ‏‏أصنافُ الصندوق كما تصل جهازَه — من الصفحة حين يُطفأ الكتالوجُ على الجهاز،
     * ومن كتالوجه كاملًا (`pos.catalog-feed`) حين يُشعَل (`pos.catalog_on_device`).
     *
     * الصفُّ هو هو في الطريقين (`Demo::tillProducts`)، والطريقُ وحده يتبدّل: فما
     * يُثبته اختبارٌ عن صفّ الصندوق يُثبته عمّا يصل الصندوقَ فعلًا.
     *
     * @return list<array<string, mixed>>
     */
    protected function tillProductsOf(\Illuminate\Testing\TestResponse $page): array
    {
        $props = $page->assertOk()->viewData('page')['props'];
        if (empty($props['catalog'])) {
            return $props['products'];
        }

        // ‏‏بكوكي الجهاز كما يرسله المتصفّح (`credentials: 'same-origin'`) — فرعُه فرعُ الصفحة
        return $this->withCredentials()->postJson(route('pos.catalog-feed'), ['scope' => $props['catalog']['scope']])->assertOk()->json('products');
    }

    protected function activatePosDevice(int $businessId, ?int $branchId = null): \App\Models\PosDevice
    {
        $raw = \Illuminate\Support\Str::random(64);

        $device = \App\Models\PosDevice::create([
            'business_id' => $businessId,
            'branch_id' => $branchId ?? Branch::where('business_id', $businessId)->orderBy('id')->value('id'),
            'name' => 'كاشير الاختبار',
            'token_hash' => hash('sha256', $raw),
            'status' => \App\Models\PosDevice::ACTIVE,
            'activated_at' => now(),
        ]);

        $this->withCookie(\App\Support\PosTerminal::COOKIE, $device->id.'|'.$raw);

        return $device;
    }

}
