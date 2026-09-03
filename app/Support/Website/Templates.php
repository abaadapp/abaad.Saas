<?php

namespace App\Support\Website;

/**
 * القوالب — ألوانٌ وخطٌّ لا صفحاتٌ وكود.
 *
 * القالب هنا **إعدادٌ لمحرّكٍ واحد**، لا موقعٌ ثانٍ بملفّاته. ولهذا سبب عمليّ
 * لا جماليّ: قوالبُ كلٌّ منها كودُه تعني أنّ إصلاح عطبٍ في «آراء العملاء»
 * يُصلَح ثماني مرّات ويُنسى في السابعة، وأنّ إضافة قسمٍ جديد تعني ثماني
 * نسخٍ منه. فالقالب هنا صفٌّ في جدول: اسمٌ ووصفٌ وستّة رموز تصميم.
 *
 * ونتيجةُ ذلك أنّ إضافة قالبٍ جديد سطرٌ واحد هنا. لا هجرة، ولا شاشة، ولا
 * تعديلٌ في المحرّر ولا في العارض.
 *
 * ومَن يبدّل قالبه لا يفقد شيئًا: الصفحات والأقسام والمحتوى تبقى كما هي،
 * ويتبدّل ما يُشتقّ منها في العرض. وهذا لا يصحّ لو كان القالب صفحاتٍ.
 */
class Templates
{
    public const DEFAULT = 'minimal';

    /**
     * `goals` الوجهات التي يليق بها — يُرشَّح بها في شاشة الاختيار.
     * و`null` تعني «يليق بكلّها».
     */
    public const CATALOGUE = [
        'minimal' => [
            'label' => 'بسيط',
            'hint' => 'أبيضُ وأسود، مساحاتٌ واسعة، والمنتج هو البطل',
            'theme' => [
                'primary' => '#111111', 'background' => '#ffffff', 'text' => '#111111',
                'font' => 'system', 'radius' => 'small', 'button' => 'solid',
            ],
        ],
        'modern' => [
            'label' => 'عصري',
            'hint' => 'أزرقُ هادئ وحوافُّ مستديرة — يصلح لأكثر المتاجر',
            'theme' => [
                'primary' => '#2563eb', 'background' => '#ffffff', 'text' => '#0f172a',
                'font' => 'cairo', 'radius' => 'medium', 'button' => 'solid',
            ],
        ],
        'bold' => [
            'label' => 'جريء',
            'hint' => 'خلفيةٌ داكنة ولونٌ صارخ — للعلامات الشابّة',
            'theme' => [
                'primary' => '#f97316', 'background' => '#0b0b0f', 'text' => '#f5f5f5',
                'font' => 'rubik', 'radius' => 'medium', 'button' => 'solid',
            ],
        ],
        'luxury' => [
            'label' => 'فاخر',
            'hint' => 'ذهبيٌّ على فحميّ — للعطور والهدايا والمجوهرات',
            'theme' => [
                'primary' => '#b8860b', 'background' => '#14110f', 'text' => '#f2ede4',
                'font' => 'tajawal', 'radius' => 'none', 'button' => 'outline',
            ],
        ],
        'fashion' => [
            'label' => 'أزياء',
            'hint' => 'ورديٌّ ناعم ومساحاتٌ للصور — للملابس والتجميل',
            'theme' => [
                'primary' => '#be185d', 'background' => '#fffafc', 'text' => '#1f1220',
                'font' => 'almarai', 'radius' => 'large', 'button' => 'soft',
            ],
        ],
        'food' => [
            'label' => 'مطاعم وأطعمة',
            'hint' => 'أخضرُ دافئ وقائمةٌ تُقرأ بسرعة — للمطاعم والحلويات',
            'theme' => [
                'primary' => '#15803d', 'background' => '#fffdf7', 'text' => '#14210f',
                'font' => 'cairo', 'radius' => 'large', 'button' => 'solid',
            ],
        ],
    ];

    public static function exists(string $key): bool
    {
        return isset(self::CATALOGUE[$key]);
    }

    public static function key(?string $key): string
    {
        return $key !== null && self::exists($key) ? $key : self::DEFAULT;
    }

    /** رموز تصميم القالب — مضمونةَ القراءة كغيرها */
    public static function theme(string $key): array
    {
        return Theme::normalize(self::CATALOGUE[self::key($key)]['theme']);
    }

    /**
     * ما تعرضه شاشة اختيار القالب.
     *
     * ومع كلٍّ منها لوحةُ ألوانه لا صورةُ معاينة: الصورة تكذب بعد أوّل تعديل
     * على القالب، واللوحة هي القالب نفسه — والمعاينة الحقيقية بعد الإنشاء.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function options(?string $goal = null): array
    {
        $out = [];

        foreach (self::CATALOGUE as $key => $spec) {
            if ($goal !== null && isset($spec['goals']) && ! in_array($goal, $spec['goals'], true)) {
                continue;
            }

            $theme = Theme::tokens($spec['theme']);

            $out[] = [
                'key' => $key,
                'label' => __($spec['label']),
                'hint' => __($spec['hint']),
                'theme' => $theme,
                // ثلاثةُ ألوانٍ تكفي لتُقرأ الهويّة قبل الاختيار
                'swatch' => [$theme['primary'], $theme['background'], $theme['text']],
            ];
        }

        return $out;
    }
}
