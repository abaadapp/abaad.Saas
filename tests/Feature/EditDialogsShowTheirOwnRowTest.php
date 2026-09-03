<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * نافذةُ التعديل تعرض صفَّها هو — لا الصفَّ الذي فُتح قبله.
 *
 * وهذا حارسٌ على نمطٍ لا على شاشة: `useForm` في Inertia تحفظ `defaults` في
 * حالةِ تفاعلٍ لا في مرجع، و`setDefaults` تجدولُ تغييرَها. فمن كتب
 * `setDefaults(row); reset();` في معالجٍ واحد جعل `reset` تقرأ `defaults`
 * **كما هي في هذه الدورة** — أي قيمَ الصفّ السابق. فتتأخّر النافذة خطوةً
 * دائمًا.
 *
 * وقعَ ذلك في «الحسابات البنكية»: يفتح التاجر الحساب الثاني فيرى بيانات
 * حسابه الرئيسي. وليس عرضًا خاطئًا وحده — يظنّها بيانات الثاني فيصحّح حرفًا
 * ويحفظ، فيُكتب آيبانُ الأوّل على الثاني ولا يقول شيءٌ إنّ حسابين صارا
 * واحدًا.
 *
 * ولا يكشفه اختبارُ خادم: الخطأ كلُّه في حالة الشاشة، والطلب الذي يصل
 * الخادم صحيحُ الشكل — يحمل بياناتٍ صحيحةً لصفٍّ خاطئ.
 */
class EditDialogsShowTheirOwnRowTest extends TestCase
{
    public function test_no_screen_fills_a_form_by_setting_defaults_then_resetting(): void
    {
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('resources/js'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (! in_array($file->getExtension(), ['tsx', 'ts'], true)) {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            /*
             * `setDefaults(...)` ثمّ `reset()` بينهما فراغٌ أو أسطر — أيًّا كان
             * اسم النموذج. والقوسان متوازنان في المصادر كلّها، فالمطابقة على
             * أنّ الاستدعاءين متتاليان لا على شكل ما بينهما.
             */
            if (preg_match('/\.setDefaults\s*\(.*?\)\s*;\s*\n?\s*\w+\.reset\s*\(\s*\)\s*;/s', $source)) {
                $offenders[] = $file->getFilename();
            }
        }

        $this->assertSame([], $offenders, 'نافذةٌ تُملأ بـsetDefaults ثمّ reset — تعرض الصفَّ السابق');
    }

    /**
     * والشاشتان اللتان أُصلحتا تُملآن بـ`setData` صراحةً.
     *
     * الحارس الأوّل يمنع عودة النمط الخاطئ، وهذا يثبت أنّ الصحيح موضعه —
     * فحذفُ السطر كلِّه يُرضي الأوّل ويترك النافذة فارغة.
     */
    public function test_the_two_fixed_screens_fill_their_forms_from_the_row(): void
    {
        foreach ([
            'resources/js/Pages/Admin/Finance/Banks.tsx',
            'resources/js/Pages/Admin/Settings/panels/ChartPanel.tsx',
        ] as $path) {
            $source = file_get_contents(base_path($path));

            $this->assertStringContainsString('form.setData(values)', $source, $path.': لا تُملأ الحقول من الصفّ');
            $this->assertStringContainsString('form.setDefaults(values)', $source, $path.': «هل تغيّر شيء» تُقاس من قيمٍ قديمة');
        }
    }
}
