<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * شاشةُ الإعدادات عمودٌ لا فسيفساء.
 *
 * ═══ ما كان ═══
 *
 * كلُّ شاشةِ إعدادٍ كانت تخترع تخطيطَها: «ربط خرائط Google» عمودان —
 * النموذجُ في الواسع، ومفتاحُ Places الاختياريُّ في الضيّق بجواره بالقوّة
 * البصريّة نفسها. و«الظهور في البحث» ثلاثةُ أعمدة. و«المتجر» مقبضان في
 * ثلثين وتقريران في ثلث. فيقف التاجر أمام صندوقين لا يتبع أحدُهما الآخر،
 * يبحث بعينه عن الخطوة الأولى.
 *
 * ═══ ما صار ═══
 *
 * عمودٌ واحد بعرض قراءة، من أعلى إلى أسفل: الحالُ، ثمّ المهمّة، ثمّ ما
 * يُقرأ بعدها، ثمّ المتقدّمُ مطويًّا. والعمودُ الجانبيّ يبقى حيث له سببٌ
 * حقيقيّ — معاينةٌ حيّة في «التصميم» — لا حيث تُملأ مساحة.
 *
 * وهذا الملفّ يحرس البنية لا الشكل: الشكلُ يتغيّر، والبنيةُ إن انفرطت عاد
 * كلُّ ملفٍّ يخترع تخطيطه من جديد — وهو ما وقع أوّل مرّة.
 */
class ASettingsScreenIsAColumnNotAMosaicTest extends TestCase
{
    /** الشاشات التي أُعيد بناؤها على الهيكل المشترك */
    private const SCREENS = [
        'Admin/Integrations/Google.tsx',
        'Admin/Integrations/GoogleBusiness.tsx',
        'Admin/Integrations/Whatsapp.tsx',
        'Admin/Marketing/Seo.tsx',
        'Admin/Marketing/Whatsapp.tsx',
        'Admin/Website/Domain.tsx',
        'Admin/Website/Store.tsx',
        'Admin/Website/Seo.tsx',
        'Admin/Setup/Index.tsx',
        'Admin/Settings/Index.tsx',
    ];

    private function screen(string $page): string
    {
        return file_get_contents(resource_path('js/Pages/'.$page));
    }

    /* ---------------------------- الهيكل المشترك ---------------------------- */

    public function test_every_reworked_screen_stands_on_the_shared_shell(): void
    {
        foreach (self::SCREENS as $page) {
            $this->assertStringContainsString(
                "from '@/Components/Settings'",
                $this->screen($page),
                $page.' يخترع تخطيطه بدل الهيكل المشترك',
            );
        }
    }

    /**
     * ولا عمودَ جانبيّ في شاشةِ مهمّة.
     *
     * `lg:col-span-2` علامةُ التقسيم إلى واسعٍ وضيّق: ما كان في الضيّق
     * مفتاحًا اختياريًّا أو تقريرًا يُقرأ، وكلاهما لا يزاحم المهمّة.
     */
    public function test_no_task_screen_splits_itself_into_columns(): void
    {
        foreach (self::SCREENS as $page) {
            $this->assertStringNotContainsString(
                'lg:col-span-2',
                $this->screen($page),
                $page.' عاد واسعًا وضيّقًا',
            );
        }

        /*
            وثلاثةُ الأعمدة كذلك — إلّا في لوحة الإعدادات.

            لوحتُها دليلُ أقسامٍ لا مهمّة: بطاقاتٌ متساويةُ الوزن تُمسح
            بالعين ويُنقر إحداها، وشبكتُها ثلاثةٌ لأنّ ذلك ما يسعها. وفرقٌ
            بين شبكةِ اختيارٍ وعمودٍ جانبيٍّ يزاحم نموذجًا.
        */
        foreach (array_diff(self::SCREENS, ['Admin/Settings/Index.tsx']) as $page) {
            $this->assertStringNotContainsString(
                'lg:grid-cols-3',
                $this->screen($page),
                $page.' عاد ثلاثة أعمدة',
            );
        }
    }

    /**
     * ولا بطاقةٌ داخل بطاقة.
     *
     * `SettingsSection` هي السطح، وما تحتها يُجمَّع بـ`SettingsGroup` —
     * وبطاقةٌ ثانيةٌ داخلها تُضاعف الحدَّ والظلَّ ولا تُضيف معنًى.
     */
    public function test_no_card_is_drawn_inside_a_section(): void
    {
        foreach (self::SCREENS as $page) {
            $this->assertStringNotContainsString(
                "from '@/Components/ui/card'",
                $this->screen($page),
                $page.' ما زال يرسم بطاقاتِه بيده',
            );
        }
    }

    /* ------------------------------ بابٌ واحد ------------------------------ */

    /**
     * وبابُ «لم يُربط بعد» مكوّنٌ واحد لا ثلاثة.
     *
     * ثلاثُ شاشاتٍ كانت ترسمه حرفًا حرفًا — أيقونةٌ بقياس ٢٠ في مربّعٍ نصفُ
     * قطره ٢٤ — فاختلفت المقاسات في شيءٍ واحد.
     */
    public function test_the_gate_is_drawn_in_one_place_only(): void
    {
        $guilty = [];

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('js'), \FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isDir() || $file->getExtension() !== 'tsx') {
                continue;
            }

            // بيتُ الباب نفسه
            if (str_ends_with($file->getPathname(), 'Components/Gate.tsx')) {
                continue;
            }

            if (str_contains(file_get_contents($file->getPathname()), 'size-20 items-center justify-center rounded-[24px]')) {
                $guilty[] = basename($file->getPathname());
            }
        }

        $this->assertSame([], $guilty, 'بابُ الأداة يُرسم في أكثر من موضع');
    }

    /* --------------------------- الطيُّ والتركيز --------------------------- */

    /**
     * والمطويُّ `<details>` لا زرًّا بحالة.
     *
     * المتصفّح يمنح `<summary>` التركيزَ و`Enter` و`Space` وإعلانَ الحالة
     * للقارئ الآليّ. وزرٌّ نكتبه يحتاج `aria-expanded` و`aria-controls`
     * ونسيانُهما لا يظهر في شاشة — يظهر عند من لا فأرة له وحده.
     */
    public function test_the_collapsible_section_is_a_native_disclosure(): void
    {
        $shell = file_get_contents(resource_path('js/Components/Settings.tsx'));

        $this->assertStringContainsString('<details', $shell, 'الطيُّ بلا عنصرٍ أصليّ');
        $this->assertStringContainsString('<summary', $shell, 'المقبضُ بلا عنصرٍ أصليّ');
    }

    /* ---------------------------- سقفُ العرض ---------------------------- */

    /**
     * والسقفُ على النموذج لا على الصفحة.
     *
     * نموذجٌ حقلاه حقلان يمتدّ على ١٦٠٠ بكسل فتقع التسمية في طرفٍ وقيمتُها
     * في الطرف الآخر. وجدولُ الفروع والسجلُّ وشجرةُ الحسابات تحتاج العرض
     * كلَّه — فلو شملها السقف لَانكمش ما وُضع ليتّسع.
     */
    public function test_the_width_cap_covers_forms_and_spares_tables(): void
    {
        $tsx = $this->screen('Admin/Settings/Index.tsx');

        $start = strpos($tsx, 'const SECTION_WIDTH');
        $this->assertNotFalse($start, 'خريطةُ العرض غائبة');
        $map = substr($tsx, $start, strpos($tsx, '};', $start) - $start);

        foreach (['business', 'finance', 'permissions', 'templates', 'notifications'] as $form) {
            $this->assertStringContainsString("{$form}:", $map, "نموذج «{$form}» بلا سقفِ قراءة");
        }

        foreach (['branches', 'employees', 'devices', 'activity', 'trash', 'chart'] as $table) {
            $this->assertStringNotContainsString("{$table}:", $map, "جدول «{$table}» حُشر في عمودٍ ضيّق");
        }
    }

    /* ---------------------------- تهيئةُ المتجر ---------------------------- */

    /**
     * وتهيئةُ المتجر قائمةُ تحقّقٍ لا معالجٌ يُساق فيه.
     *
     * حقولُها الأربعة لا يشترط أحدُها الآخر، فلا «التالي» ولا «السابق» —
     * وخطوةٌ تُفرض على من يستطيع تخطّيها مقبضٌ لا يُدير شيئًا.
     */
    public function test_the_setup_screen_is_a_checklist_not_a_wizard(): void
    {
        $tsx = $this->screen('Admin/Setup/Index.tsx');

        foreach (['setStep', "t('التالي')", "t('السابق')"] as $needle) {
            $this->assertStringNotContainsString($needle, $tsx, 'التهيئة صارت معالجًا بلا تبعيّةٍ تُبرّره');
        }

        // وما يُقاس: تقدّمٌ يُقرأ، وبندٌ يُقال أمطلوبٌ هو أم اختياريّ
        $this->assertStringContainsString('SetupProgress', $tsx, 'لا شيء يقول كم بقي');
        $this->assertStringContainsString("t('مطلوب')", $tsx, 'البندُ لا يقول أهو مطلوبٌ أم لا');
    }
}
