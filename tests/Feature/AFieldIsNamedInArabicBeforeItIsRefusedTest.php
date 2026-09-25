<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * الحقلُ يُنادى باسمه العربيّ حين يُردّ — لا باسم عمودٍ في قاعدة البيانات.
 *
 * ═══ ما كان يقرؤه التاجر ═══
 *
 *     حقل purchased at مطلوب.
 *     يجب أن يكون حقل lines.0.debit رقمًا.
 *     حقل cooldown hours مطلوب.
 *
 * والخريطةُ موجودةٌ من قبل في `lang/ar/validation.php` تحت `attributes`،
 * وإنّما تُنسى عند كلّ حقلٍ جديد. ولارافل تقرؤها بالمفتاح كما هو — ومفاتيح
 * المصفوفات بالنجمة (`lines.*.debit`) تُقرأ كذلك.
 *
 * ═══ ولمَ حارسٌ يمشي على الملفّات لا قائمةٌ تُكتب بيد ═══
 *
 * قائمةٌ أكتبها تحرس ما تذكّرتُه يومَ كتبتُها. وهذا يقرأ نداءات `validate`
 * نفسَها، فيمسك **الحقلَ القادم** الذي يُضاف غدًا بلا اسم — وهو الذي لا
 * يمسكه شيءٌ آخر.
 *
 * ═══ ومداه لوحةُ التاجر كلُّها ═══
 *
 * لا قائمةَ أقسامٍ تُكتب بيد: يمشي على `app/Http/Controllers/Admin` كلِّه.
 * وقائمةٌ مكتوبةٌ تحرس ما فيها وتسكت عمّا حولها — وأوّلُ كتابةٍ لهذا الملفّ
 * كانت كذلك: المالية وواتساب وحدهما، و**٢٢٣ حقلًا** في ٧٣ ملفًّا آخرَ تُنادى
 * بأسماء أعمدتها ولا يقول الحارسُ شيئًا، لأنّه لا يقرؤها أصلًا.
 *
 * ومتحكّمُ المنصّة (`SuperAdmin`) خارجَه: من يقرأ رسائلَه مالكُ المنصّة، لا
 * تاجرٌ يفتح شاشةً بالعربيّة.
 */
class AFieldIsNamedInArabicBeforeItIsRefusedTest extends TestCase
{
    /**
     * متحكّمات لوحة التاجر كلُّها — بمسارها النسبيّ، مرتَّبةً.
     *
     * @return list<string>
     */
    private static function sections(): array
    {
        $base = app_path('Http/Controllers/Admin');
        $found = [];

        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));

        foreach ($walk as $file) {
            if ($file->getExtension() === 'php') {
                $found[] = str_replace([$base.DIRECTORY_SEPARATOR, '.php'], '', $file->getPathname());
            }
        }

        sort($found);

        return $found;
    }

    /** مفتاحُ قاعدةٍ في `validate` يبدأ بإحدى هذه — به يُعرف أنّه حقلٌ لا خيار */
    private const RULE_HEADS = 'required|nullable|sometimes|boolean|array|integer|numeric|string|date|file|image|in:';

    /**
     * الحقولُ التي يطلبها هذا المصدرُ ولا اسمَ عربيَّ لها.
     *
     * منفصلةٌ عن قراءة القرص كي تُسأل بنصٍّ مصنوع: حارسٌ يمشي على الملفّات
     * لا يُثبت أنّه **يرى** ما يمرّ به، وأوّلُ ما يعمى عنه لا يُكتشف إلّا
     * يوم يشتكي تاجر.
     *
     * @param  array<string, string>  $names
     * @return list<string>
     */
    private static function nameless(string $source, array $names): array
    {
        /*
         * ورسائلُ الأخطاء المُعادة ليست أسماءَ حقول.
         *
         * `withErrors(['ar' => __('اكتب النصّ')])` تُشبه تمريرَ اسمٍ إلى
         * `validate` حرفًا بحرف — فكان الحارسُ يعدّ الحقلَ مسمًّى وهو عارٍ.
         * وهكذا مرّ `ar` في شاشة الردّ التلقائيّ تحت أنفِه، ومعه `parent_id`
         * و`side` في المالية.
         */
        $named = preg_replace('/withErrors\(\[.*?\]\)/s', '', $source) ?? $source;

        preg_match_all(
            "/'([a-z0-9_.*]+)'\s*=>\s*\[\s*'(?:".self::RULE_HEADS.')/',
            $source,
            $found,
        );

        $bare = [];

        foreach (array_unique($found[1]) as $field) {
            if (isset($names[$field])) {
                continue;
            }

            // اسمٌ مُمرَّر في نداء `validate` نفسِه — وهو كافٍ
            if (preg_match("/'".preg_quote($field, '/')."'\s*=>\s*__\(/", $named)) {
                continue;
            }

            $bare[] = $field;
        }

        return $bare;
    }

    public function test_no_field_in_these_screens_is_called_by_its_column_name(): void
    {
        $names = (require base_path('lang/ar/validation.php'))['attributes'];
        $nameless = [];

        foreach (self::sections() as $section) {
            $path = app_path('Http/Controllers/Admin/'.$section.'.php');

            $this->assertFileExists($path, 'مسارُ متحكّمٍ تبدّل — والحارسُ يمشي على ملفٍّ لا وجود له');

            foreach (self::nameless((string) file_get_contents($path), $names) as $field) {
                $nameless[] = $section.' › '.$field;
            }
        }

        $this->assertSame([], $nameless, "حقولٌ تُنادى باسمها البرمجيّ في وجه التاجر:\n".implode("\n", $nameless));
    }

    /**
     * والحارسُ يرى ما يمرّ به — يُسأل بنصٍّ مصنوع.
     *
     * حارسُ «لا مخالفة» أعماه أنّه أخضرُ حين لا يفحص شيئًا: يُضيَّق مداه
     * أو يعمى عن شكلٍ من الأشكال فيبقى ساكتًا. فيُسأل هنا عن ثلاثةٍ:
     * أيمسك العاريَ؟ أيقبل المُسمَّى في الخريطة؟ أيقبل المُمرَّر في النداء
     * — ولا يُخدع بـ`withErrors` تحمل الاسمَ نفسَه؟
     */
    public function test_the_walker_sees_what_it_walks_over(): void
    {
        $source = <<<'PHP'
            $data = $request->validate([
                'cooldown_hours' => ['required', 'integer'],
                'phone' => ['required', 'string'],
                'note_to_driver' => ['nullable', 'string'],
            ], [], ['note_to_driver' => __('ملاحظة السائق')]);

            return back()->withErrors(['bare_field' => __('اكتب شيئًا.')]);
            $more = $request->validate(['bare_field' => ['required', 'string']]);
            PHP;

        $bare = self::nameless($source, ['phone' => 'الهاتف']);

        $this->assertContains('cooldown_hours', $bare, 'لم يُمسَك حقلٌ عارٍ');
        $this->assertNotContains('phone', $bare, 'أُنكر اسمٌ في الخريطة');
        $this->assertNotContains('note_to_driver', $bare, 'أُنكر اسمٌ مُمرَّرٌ في النداء');
        $this->assertContains('bare_field', $bare, 'خدعته رسالةُ `withErrors` فحسبها اسمًا');
    }

    /**
     * ومداه لوحةُ التاجر كلُّها — لا قسمًا منها.
     *
     * حارسُ «لا مخالفة» يبقى أخضرَ حين يضيق مداه: لا يجد مخالفةً فيما لم
     * يعد يقرؤه. فيُسأل هنا عن العدد وعن أسماءٍ من أقسامٍ متباعدة — لو
     * ضاقت الشجرةُ إلى مجلّدٍ واحد سقط هذا قبله.
     */
    public function test_the_walk_covers_the_whole_merchant_panel(): void
    {
        $sections = self::sections();

        $this->assertGreaterThan(60, count($sections), 'ضاق المدى إلى '.count($sections).' ملفًّا');

        foreach (['Finance/JournalController', 'WhatsAppOnboardingController',
            'Marketing/MarketingController', 'ProductController', 'Payroll/PayrollRunController'] as $section) {
            $this->assertContains($section, $sections, 'خرج «'.$section.'» من مدى الحارس');
        }

        $seen = [];

        foreach ($sections as $section) {
            $path = app_path('Http/Controllers/Admin/'.$section.'.php');

            foreach (self::nameless((string) file_get_contents($path), []) as $field) {
                $seen[] = $field;
            }
        }

        foreach (['cooldown_hours', 'mode', 'entry_date', 'store_headline', 'basic_salary'] as $field) {
            $this->assertContains($field, $seen, 'لم يمرّ الحارسُ على «'.$field.'» أصلًا');
        }
    }

    /**
     * والخريطةُ تُقرأ فعلًا — لا تُكتب فتُهمَل.
     *
     * الحارسان فوقه يقرآن الملفَّ نصًّا، فلو تبدّل موضعُ `attributes` أو
     * تبدّلت طريقةُ لارافل في قراءته لَبقيا أخضرين على رسائلَ إنجليزيّة.
     * فيُسأل المترجمُ نفسُه: أيُخرج عربيًّا؟
     */
    public function test_the_map_is_what_the_translator_actually_reads(): void
    {
        app()->setLocale('ar');

        $validator = validator(['lines' => [['debit' => 'س']]], [
            'purchased_at' => ['required', 'date'],
            'cooldown_hours' => ['required', 'integer'],
            'store_headline' => ['required', 'string'],
            'lines.*.debit' => ['required', 'numeric'],
        ]);

        $this->assertTrue($validator->fails());

        $said = implode(' | ', $validator->errors()->all());

        foreach (['تاريخ الشراء', 'المهلة بين ردّين', 'عنوان المتجر', 'المدين'] as $arabic) {
            $this->assertStringContainsString($arabic, $said);
        }

        $this->assertDoesNotMatchRegularExpression('/[a-z_]{4,}/', $said, 'بقي اسمُ عمودٍ في الرسالة: '.$said);
    }
}
