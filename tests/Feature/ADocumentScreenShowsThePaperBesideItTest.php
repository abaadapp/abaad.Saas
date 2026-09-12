<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * شاشةُ المستند قسمان — ولوحةُ الورقة واحدةٌ تتقاسمها الشاشاتُ كلُّها.
 *
 * ═══ العطبُ الذي يحرسه هذا الملفّ ═══
 *
 * كانت شاشةُ الطلب وحدها تعرض ورقتَها، وفيها ثلاثةُ أزرارٍ وإطارٌ ونافذةُ
 * تكبير — نحو سبعين سطرًا. ولمّا احتاجتها أمرُ الشراء وسندُ الاستلام
 * وفاتورةُ العميل كان أسهلُ ما يُفعل نسخَها. وأربعُ نسخٍ تعني أنّ إصلاحَ
 * زرٍّ في واحدةٍ يترك ثلاثًا معطوبة — وهو ما وقع فعلًا حين وُصلت **معاينةُ**
 * فاتورة البيع بالقالب الجديد ونُسي **زرُّ الطباعة**: يرى التاجر في المحرّر
 * ورقةً، ويخرج من الطابعة غيرُها، ولا شيء يقول إنّهما افترقتا — لأنّ
 * كليهما يعمل.
 *
 * فالفحصُ على المستودع نفسِه: لا شاشةَ مستندٍ ترسم إطارَ ورقةٍ بيدها.
 */
class ADocumentScreenShowsThePaperBesideItTest extends TestCase
{
    /**
     * شاشاتُ المستندات — لكلٍّ ورقةٌ تُطبع وتفاصيلُ تُقرأ.
     *
     * وسندُ المورّد ليس منها عمدًا: ورقةٌ **تصل** من المورّد لا تخرج من
     * أبعاد، فلا ورقةَ لنا نعرضها — ومرفقُها هو مستندُه. وقرارُه نافذةُ
     * مطابقةٍ ثلاثيّة (الأمرُ والمستلَمُ والمفوتَر)، وهي فعلٌ لا مستندٌ يُقرأ.
     */
    private const SCREENS = [
        'Admin/Orders/Show.tsx',
        'Admin/Purchases/Show.tsx',
        'Admin/Inventory/ReceiptShow.tsx',
        'Admin/CustomerInvoices/Show.tsx',
    ];

    private function source(string $screen): string
    {
        $path = resource_path('js/Pages/'.$screen);

        $this->assertFileExists($path, "شاشةٌ مفقودة: {$screen}");

        return (string) file_get_contents($path);
    }

    /** كلُّ شاشةِ مستندٍ تعرض ورقتَها باللوحة المشتركة */
    public function test_every_document_screen_uses_the_shared_panel(): void
    {
        foreach (self::SCREENS as $screen) {
            $code = $this->source($screen);

            $this->assertStringContainsString(
                "from '@/Components/DocumentPanel'",
                $code,
                "{$screen} لا تستعمل لوحة الورقة المشتركة",
            );
        }
    }

    /**
     * ولا شاشةَ ترسم الإطارَ أو نافذةَ التكبير بيدها.
     *
     * `PaperFrame` و`DocumentPreview` قطعتان تستعملهما اللوحةُ وحدها. ونداءٌ
     * لهما من شاشةٍ يعني نسخةً ثانية من الأزرار الثلاثة — تفترق عن أصلها
     * عند أوّل إصلاح.
     *
     * وشاشاتُ **الإنشاء** ومحرّرُ القوالب يستعملان `PaperFrame` بحقّ: لا
     * مستندَ محفوظًا هناك ولا بابَ PDF له، فلا تكبيرَ ولا تحميلَ ولا طباعة
     * — إطارٌ يُري ما يُكتب وحسب.
     */
    public function test_no_document_screen_draws_the_frame_itself(): void
    {
        foreach (self::SCREENS as $screen) {
            $code = $this->source($screen);

            foreach (['@/Components/PaperFrame', '@/Components/DocumentPreview'] as $part) {
                $this->assertStringNotContainsString(
                    "from '{$part}'",
                    $code,
                    "{$screen} ترسم الورقة بيدها بدل اللوحة المشتركة",
                );
            }
        }
    }

    /**
     * ولا زرَّ «تصدير PDF» — الأفعالُ الثلاثة مفرَّقةٌ بأسمائها.
     *
     * «تصدير» فعلٌ واحد بثلاثة معانٍ: أيُريك أم يحفظ أم يطبع؟ وكان في
     * الشاشة زرّان على الرابط نفسِه تمامًا، أحدُهما يفتح لسانًا والآخر يحفظ.
     */
    public function test_no_screen_offers_an_export_button_instead_of_the_three(): void
    {
        foreach (self::SCREENS as $screen) {
            $this->assertStringNotContainsString(
                'تصدير PDF',
                self::code($this->source($screen)),
                "{$screen} لا تزال تعرض «تصدير PDF» بدل تكبير · تحميل · طباعة",
            );
        }
    }

    /**
     * الشفرةُ بلا تعليق — الفحصُ عمّا يُرسم لا عمّا يُحكى.
     *
     * والتعليقُ يحكي عن الزرّ الذي نُزع: «كان هنا تصدير PDF…». ولولا نزعُه
     * لَسقط الحارسُ على شرحٍ صحيح، ولَمُنع شرحُ ما جرى — وهو أنفعُ ما يبقى
     * في الملفّ.
     */
    private static function code(string $source): string
    {
        return (string) preg_replace(
            ['#/\*.*?\*/#su', '#(?<![:"\'])//[^\n]*#'],
            '',
            $source,
        );
    }

    /**
     * والورقةُ إلى جانب التفاصيل لا تحتها — على الشاشة الواسعة.
     *
     * وبقياسٍ واحد في الأربع: من ينتقل بين شاشةِ طلبٍ وشاشةِ أمرِ شراءٍ
     * يجد الورقةَ حيث تركها.
     */
    public function test_the_paper_sits_beside_the_details_at_one_measure(): void
    {
        foreach (self::SCREENS as $screen) {
            $code = $this->source($screen);

            $this->assertStringContainsString('xl:grid-cols-5', $code, "{$screen} بلا قسمة العمودين");
            $this->assertStringContainsString('xl:col-span-3', $code, "{$screen} عمودُ التفاصيل بغير قياسه");
            $this->assertStringContainsString('xl:col-span-2', $code, "{$screen} عمودُ الورقة بغير قياسه");
        }
    }

    /**
     * وشريطُ التعريف مشتركٌ كذلك — لا قائمةَ «عنوان … قيمة» تُكتب بيدها.
     *
     * وشاشةُ الطلب خارجَه اليوم: ترويستُها تحمل حالَ الطلب وعنوانَ التسليم
     * وبطاقةَ الزبون في كتلٍ لها أسبابُها. وإدخالُها في الشريط عملٌ لم
     * يُطلَب — والشرطُ هنا على الثلاث التي أُعيد ترتيبُها.
     */
    public function test_the_rebuilt_screens_share_one_identity_strip(): void
    {
        foreach (['Admin/Purchases/Show.tsx', 'Admin/Inventory/ReceiptShow.tsx', 'Admin/CustomerInvoices/Show.tsx'] as $screen) {
            $this->assertStringContainsString(
                "from '@/Components/DocumentMeta'",
                $this->source($screen),
                "{$screen} تكتب شريطَ تعريفها بيدها",
            );
        }
    }

    /**
     * ولا رقمَ مستندٍ في قائمةٍ يقف نصًّا لا يُفتح.
     *
     * سندُ الاستلام كان رقمُه يقود إلى **ملفّ PDF** في لسانٍ آخر، وسندُ
     * المورّد يذكر رقمَ أمره نصًّا رماديًّا لا يُضغط. ومن يراجع خلافًا يحتاج
     * أن ينتقل بينها لا أن يبحث عنها بالرقم في شاشةٍ أخرى.
     */
    public function test_every_list_row_opens_the_document_it_names(): void
    {
        $receipts = (string) file_get_contents(resource_path('js/Pages/Admin/Inventory/Receipts.tsx'));

        $this->assertStringContainsString('admin.inventory.receipts.show', $receipts, 'قائمةُ السندات لا تفتح سندَها');
        $this->assertStringContainsString('admin.purchases.show', $receipts, 'قائمةُ السندات لا تفتح أمرَها');

        $invoices = (string) file_get_contents(resource_path('js/Pages/Admin/Purchases/Invoices.tsx'));

        $this->assertStringContainsString('admin.purchases.show', $invoices, 'قائمةُ سندات المورّد لا تفتح أمرَها');

        $orders = (string) file_get_contents(resource_path('js/Pages/Admin/Purchases/Show.tsx'));

        $this->assertStringContainsString(
            'admin.inventory.receipts.show',
            $orders,
            'شاشةُ الأمر لا تفتح أوراقَ استلامه',
        );
    }
}
