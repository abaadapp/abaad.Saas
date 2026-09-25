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
 * ═══ وما لا يحرسه بعد ═══
 *
 * المدى هنا قسمان: المالية وواتساب. ومسحٌ على متحكّمات `Admin` كلِّها يوم
 * كُتب هذا وجد **٢٣٣ حقلًا** بلا اسمٍ عربيّ في ٧٥ ملفًّا — أكثرُها في
 * المتجر الإلكترونيّ والمنتجات والرواتب. فالبقيّةُ عطبٌ قائمٌ **مُصرَّحٌ
 * به** لا مسكوتٌ عنه، وتوسيعُ `SECTIONS` سطرٌ واحد متى أُريد إغلاقُه.
 */
class AFieldIsNamedInArabicBeforeItIsRefusedTest extends TestCase
{
    /**
     * المتحكّمات التي يُحرَس مداها — بمسارها تحت `app/Http/Controllers/Admin`.
     *
     * @var list<string>
     */
    private const SECTIONS = [
        // المالية
        'Finance/BankAccountController', 'Finance/ChartController', 'Finance/FixedAssetController',
        'Finance/JournalController', 'Finance/OverviewController', 'FinanceController',
        'ExpenseController', 'ExpenseTypeController', 'ChequeController',
        'BankStatementController', 'ReceivablesController',

        // واتساب
        'WhatsAppController', 'WhatsAppOnboardingController',
    ];

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

        foreach (self::SECTIONS as $section) {
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
     * ومداه يشمل واتساب فعلًا — لا المالية وحدها.
     *
     * حقولُ الردّ التلقائيّ وطريقةِ الإرسال تُقرأ من ملفّاتها، فلو ضاق
     * `SECTIONS` يومًا إلى المالية سقط هذا لا الحارسُ الأوّل: ذاك يبقى
     * أخضرَ لأنّه لا يجد مخالفةً فيما لم يعد يقرؤه.
     */
    public function test_the_walk_actually_covers_the_whatsapp_screens(): void
    {
        $seen = [];

        foreach (self::SECTIONS as $section) {
            $path = app_path('Http/Controllers/Admin/'.$section.'.php');

            foreach (self::nameless((string) file_get_contents($path), []) as $field) {
                $seen[] = $field;
            }
        }

        foreach (['cooldown_hours', 'mode', 'waba_id', 'purchased_at', 'entry_date'] as $field) {
            $this->assertContains($field, $seen, 'لم يمرّ الحارسُ على «'.$field.'» أصلًا');
        }
    }

    /**
     * والخريطةُ تُقرأ فعلًا — لا تُكتب فتُهمَل.
     *
     * الحارسُ أعلاه يقرأ الملفَّ نصًّا، فلو تبدّل موضعُ `attributes` أو
     * تبدّلت طريقةُ لارافل في قراءته لَبقي أخضرَ على رسائلَ إنجليزيّة. فيُسأل
     * المترجمُ نفسُه عن حقلٍ واحد: أيُخرج عربيًّا؟
     */
    public function test_the_map_is_what_the_translator_actually_reads(): void
    {
        app()->setLocale('ar');

        $validator = validator(['lines' => [['debit' => 'س']]], [
            'purchased_at' => ['required', 'date'],
            'cooldown_hours' => ['required', 'integer'],
            'lines.*.debit' => ['required', 'numeric'],
        ]);

        $this->assertTrue($validator->fails());

        $said = implode(' | ', $validator->errors()->all());

        foreach (['تاريخ الشراء', 'المهلة بين ردّين', 'المدين'] as $arabic) {
            $this->assertStringContainsString($arabic, $said);
        }

        $this->assertDoesNotMatchRegularExpression('/[a-z_]{4,}/', $said, 'بقي اسمُ عمودٍ في الرسالة: '.$said);
    }
}
