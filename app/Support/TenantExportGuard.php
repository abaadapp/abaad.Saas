<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ملفُّ النشاط لا يخرج إلّا للنشاط الذي كانت الشاشةُ تعرضه.
 *
 * ═══ العطب ═══
 *
 * تبويبٌ يعرض تقريرَ النشاط A يبقى مفتوحًا. وفي تبويبٍ آخر تتبدّل الجلسة:
 * دخولٌ بحسابٍ آخر، أو مديرُ المنصّة يدخل النشاطَ B نيابةً عنه، أو يخرج منه.
 * ثمّ يعود إلى التبويب الأوّل — وما زال يقول «A» — ويضغط «تصدير». فيُطلب
 * الملفُّ بجلسة B، ويخرج ملفُّ B تحت شاشةٍ تقول A: ترويستُه ومحتواه.
 *
 * ═══ والحارس: توقُّعٌ يُقارَن، لا اختيارٌ يُطاع ═══
 *
 * كلُّ رابطِ تصديرٍ تبنيه الشاشة يحمل `expected_business_id`: رقمَ النشاط الذي
 * رُسمت له الصفحة (من `auth.user.businessId` نفسِه، لا مصدرٍ ثانٍ). والخادمُ
 * **لا يختار به شيئًا**: النشاطُ المقروء يبقى `Demo::bid()` — من الجلسة
 * الموثوقة وحدها. وإنّما يقارن: هل ما زالت الجلسةُ للنشاط الذي ظنّته الشاشة؟
 * فإن لم تكن، وقف الطلبُ قبل المتحكّم: لا يُقرأ صفٌّ، ولا يُبنى ملفّ، ولا
 * يُكتب «صدّر» في السجلّ — لا للقديم ولا للجديد.
 *
 * ═══ ونوعان من الأبواب ═══
 *
 * - `REQUIRED`: ملفٌّ يجمع بيانَ النشاط كلِّه برابطٍ واحدٍ لكلّ نشاط (تقرير،
 *   قائمة، نسخة احتياطيّة). الرابطُ نفسُه يُخرج A أو B بحسب الجلسة، فلا يخرج
 *   بلا توقُّع: رابطٌ بلا توقُّع لم تبنِه شاشةٌ حاليّة (تبويبٌ من قبل النشر،
 *   أو رابطٌ محفوظ) فيُطلب تحديثُ الصفحة.
 * - `DOCUMENTS`: مستندٌ بمعرّفه (طلب، فاتورة، كشف، سند). يُقرأ أصلًا تحت
 *   النشاط الحاليّ، لكنّ أرقامَ الطلبات تتكرّر بين الأنشطة (`INV-000204`
 *   عند كلٍّ منها) فيفتح التبويبُ القديم مستندًا آخرَ بالرقم نفسِه. فيُفحص
 *   التوقُّعُ إن حمله الرابط — والشاشاتُ كلُّها تحمله — ولا يُشترط: روابطُ
 *   الطباعة المحفوظة ونقطةُ البيع القديمة لا تنكسر، وهي لا تُخرج إلّا مستندَ
 *   الجلسة نفسِها.
 *
 * ومساراتُ المنصّة (`super-admin.*`) وروابطُ المستندات العامّة (`/i/{token}`)
 * خارجُه: الأولى ليست لنشاط، والثانية رمزٌ لا جلسة.
 */
final class TenantExportGuard
{
    /** اسمُ المعامل في الرابط — توقُّعٌ لا اختيار */
    public const PARAM = 'expected_business_id';

    public const STALE = 'stale';

    public const MISSING = 'missing';

    public const INVALID = 'invalid';

    /** مفتاحُ ما تحقّق منه الحارس في الطلب — انظر `businessId` */
    private const VERIFIED = 'tenant_export.business_id';

    /**
     * ملفّاتٌ تجمع بيانَ النشاط برابطٍ واحدٍ لكلّ نشاط — لا تخرج بلا توقُّع.
     *
     * @var list<string>
     */
    public const REQUIRED = [
        // تقرير المبيعات وعائلةُ التقارير
        'admin.reports.pdf',
        'admin.reports.xlsx',
        'admin.reports.export.pdf',
        'admin.reports.export.xlsx',
        'admin.reports.export.csv',
        // CSV
        'admin.export.reports',
        'admin.export.products',
        'admin.export.orders',
        'admin.export.customers',
        'admin.export.suppliers',
        'admin.export.transactions',
        'admin.export.inventory',
        'admin.export.expenses',
        // القوائم: Excel وPDF وملفّ الاستيراد
        'admin.products.xlsx',
        'admin.products.exportPdf',
        'admin.products.export.xlsx',
        'admin.products.export.pdf',
        'admin.customers.xlsx',
        'admin.customers.export.xlsx',
        'admin.customers.export.pdf',
        'admin.suppliers.xlsx',
        'admin.suppliers.export.xlsx',
        'admin.suppliers.export.pdf',
        'admin.orders.xlsx',
        'admin.orders.exportPdf',
        // وسجلُّ عمليات البيع في «فواتير العملاء» — الطلباتُ والفواتيرُ معًا
        'admin.customerInvoices.sales.xlsx',
        'admin.customerInvoices.sales.csv',
        'admin.customerInvoices.sales.pdf',
        'admin.inventory.xlsx',
        'admin.inventory.exportPdf',
        'admin.expenses.xlsx',
        'admin.expenses.exportPdf',
        'admin.finance.xlsx',
        'admin.finance.pdf',
        // ونسخةُ النشاط كلِّه
        'admin.backup.download',
        'admin.backup.latest.download',
    ];

    /**
     * مستنداتٌ بمعرّفها — يُفحص التوقُّعُ إن حمله الرابط.
     *
     * @var list<string>
     */
    public const DOCUMENTS = [
        'admin.orders.pdf',
        'admin.orders.receipt',
        'admin.orders.deliveryNote',
        'admin.orders.deliveryNoteThermal',
        'admin.orders.taxInvoice',
        'admin.preparation.receipt',
        'admin.preparation.deliveryNote',
        'admin.preparation.deliveryNoteThermal',
        // ومهامُّ فواتير العملاء على اللوحة: سندُ التسليم والنسخةُ الداخليّة — بمعرّفٍ يتكرّر بين الأنشطة
        'admin.preparation.invoiceDeliveryNote',
        'admin.preparation.invoiceInternalPdf',
        'admin.customerInvoices.pdf',
        'admin.customerInvoices.creditNotes.pdf',
        'admin.customerInvoices.debitNotes.pdf',
        'admin.customerInvoices.attachment',
        'admin.customerPayments.pdf',
        'admin.customers.statement',
        'admin.finance.customerStatement.pdf',
        'admin.purchases.pdf',
        'admin.purchases.attachment',
        'admin.purchases.receiptFile',
        'admin.purchases.invoices.pdf',
        'admin.purchases.invoices.attachment',
        'admin.inventory.receipts.pdf',
        'admin.inventory.receipts.attachment',
        'admin.expenses.attachment',
        'admin.archives.download',
        'pos.receipt.pdf',
        // وورقةُ طابور الطباعة يحجزها الصندوقُ وينزّلها بجلسته — بمعرّفٍ يتكرّر بين الأنشطة
        'pos.printJobs.pdf',
        // ونصُّ الإيصال للمعاينة في نقطة البيع — بالرقم نفسِه الذي يتكرّر بين الأنشطة
        'pos.receipt.paper',
    ];

    /**
     * ما يُخرج ملفًّا في لوحة النشاط ولا يحرسه — وسببُه مكتوب.
     *
     * يقرؤه حارسُ الجرد في الاختبارات: مسارٌ جديدٌ يُخرج ملفًّا ولا يُصنَّف
     * هنا ولا فوق يُسقط الاختبار.
     *
     * @var array<string, string>
     */
    public const EXEMPT = [
        'admin.whatsappInbox.attachment' => 'مرفقُ محادثة واتساب مع زبون — يُقرأ بمعرّفه داخل متجر الجلسة؛ التبويبُ القديم يلقى 404 لا ملفَّ نشاطٍ آخر',
        'admin.help.attachment' => 'مرفقُ محادثة الدعم مع أبعاد — بمعرّفٍ عامّ، ويُقرأ بمحادثة النشاط الحاليّ؛ التبويبُ القديم يلقى 404 لا ملفَّ نشاطٍ آخر',
        'admin.finance.customerStatement' => 'شاشةُ Inertia لا ملفّ — تُرسم بجلسة الساعة',
        'admin.finance.statement' => 'شاشةُ Inertia لا ملفّ — تُرسم بجلسة الساعة',
        'admin.inventory.receipts' => 'شاشةُ قائمة سندات الاستلام — لا ملفّ',
        'admin.inventory.receipts.show' => 'شاشةُ سند الاستلام — لا ملفّ',
    ];

    /** سياسةُ هذا المسار — أو `null` إن لم يكن ممّا يُحرس */
    public static function policyFor(?string $route): ?string
    {
        return match (true) {
            $route === null => null,
            in_array($route, self::REQUIRED, true) => 'required',
            in_array($route, self::DOCUMENTS, true) => 'documents',
            default => null,
        };
    }

    /**
     * أيخرج هذا الطلب؟ — `null` إن خرج، وإلّا سببُ الوقوف.
     *
     * والمقارنةُ برقم الجلسة الموثوق وحده (`Demo::bid()` = `business_id`
     * المستخدم المسجَّل). ورقمُ الرابط لا يُستعلم به عن شيء: لا يُفتح به
     * نشاط، ولا يُسأل هل هو موجود — رقمٌ غيرُ رقم الجلسة يقف أيًّا كان.
     */
    public static function verdict(Request $request, string $policy): ?string
    {
        $raw = $request->query(self::PARAM);

        if ($raw === null || $raw === '') {
            return $policy === 'required' ? self::MISSING : null;
        }

        if (! is_string($raw) || ! ctype_digit($raw) || (int) $raw <= 0) {
            return self::INVALID;
        }

        $current = Demo::bid();

        // نصًّا لا عددًا: «01» ليس الرقمَ الذي تكتبه صفحةٌ لنشاطها
        if ($current <= 0 || $raw !== (string) $current) {
            return self::STALE;
        }

        $request->attributes->set(self::VERIFIED, $current);

        return null;
    }

    /**
     * النشاطُ الذي يُبنى له هذا الملف — رقمٌ واحدٌ للترويسة والمحتوى والسجلّ.
     *
     * ما تحقّق منه الحارسُ إن مرّ به الطلب، وإلّا رقمُ الجلسة نفسُه. وهما
     * رقمٌ واحدٌ في الطلب الواحد: الحارسُ لا يُمرّر إلّا ما ساوى `Demo::bid()`.
     */
    public static function businessId(): int
    {
        $verified = request()->attributes->get(self::VERIFIED);

        return is_int($verified) ? $verified : Demo::bid();
    }

    /** صفُّ النشاط للترويسة — من الرقم نفسِه الذي قُرئ به المحتوى */
    public static function business(): array
    {
        return Demo::business(self::businessId());
    }

    /**
     * رابطٌ يحمل توقُّعَ هذا النشاط — لما يبنيه الخادمُ من روابط المستندات.
     *
     * ويُستبدل ما كان فيه: رابطٌ لا يحمل إلّا توقُّعًا واحدًا، توقُّعَ الصفحة
     * التي تُرسم الآن.
     */
    public static function url(string $url, ?int $businessId = null): string
    {
        $businessId ??= Demo::bid();

        if ($businessId <= 0) {
            return $url;
        }

        [$base, $query] = array_pad(explode('?', $url, 2), 2, '');
        parse_str($query, $params);
        $params[self::PARAM] = (string) $businessId;

        return $base.'?'.http_build_query($params);
    }

    /**
     * الردُّ على تبويبٍ قديم — صفحةٌ تقول ما جرى، لا ملفّ.
     *
     * ‏409: الطلبُ صحيحٌ في ذاته ويتعارض مع حال الجلسة الآن. وصفحةٌ لا
     * تحويلة: زرُّ التصدير يطلب الملفَّ في إطارٍ خفيّ ويقرأ منه العلامة
     * `data-stale-export` (`downloadFile`)، وPDF التبويب الجديد يقرؤها صاحبُه.
     * ولا كعكةَ `download_token`: لم يبدأ تنزيلٌ.
     */
    public static function refuse(Request $request, string $reason): Response
    {
        $message = __('تغيّرت جلسة النشاط منذ فتح هذه الصفحة. حدّث الصفحة ثم أعد التصدير.');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'reason' => $reason], 409);
        }

        return response()->view('stale-export', [
            'message' => $message,
            'reason' => $reason,
            // والعودةُ إلى لوحة الجلسة الحاليّة — نقطةُ البيع لمن جاء منها
            'home' => str_starts_with((string) $request->route()?->getName(), 'pos.')
                ? route('pos.index')
                : route('admin.dashboard'),
        ], 409)->header('Cache-Control', 'no-store');
    }
}
