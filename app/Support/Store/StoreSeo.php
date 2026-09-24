<?php

namespace App\Support\Store;

use App\Models\Business;
use App\Support\MarketingSettings;
use Illuminate\Support\Str;

/**
 * ما يقرؤه غوغل من متجر الواجهة الخاصّة — عنوانٌ ووصفٌ وإذنُ فهرسة.
 *
 * ═══ ولمَ صار لصاحبه بابٌ إليه ═══
 *
 * كان عنوانُ الصفحة اسمَ النشاط ووصفُها أوّلَ مئةٍ وخمسين حرفًا من نبذته،
 * يُكتبان في `<head>` ولا يملك صاحبُ المتجر إليهما بابًا. وسائرُ متاجر
 * أبعاد لها شاشةُ «الظهور في البحث» تكتبهما بيدها منذ البداية.
 *
 * والعنوانُ في نتيجة البحث هو ما يُضغط أو لا يُضغط — وسطرٌ يقول «ريبون»
 * وحدَه لا يقول لمن يبحث عن «ورد توصيل مسقط» شيئًا.
 *
 * ═══ والفراغُ يعني «ما كان» ═══
 *
 * قاعدةُ `StorePage` نفسُها. فترقيةٌ تُنزل المفتاحين فارغين على متجرٍ
 * مفهرَسٍ منذ شهور لا تُبدّل عنوانَه في نتائج غوغل.
 */
final class StoreSeo
{
    /**
     * ولا يُقصّ ما كتبه: الحدُّ إرشادٌ في الشاشة، والقصُّ هنا يُخفي عنه
     * ما حفظه. وغوغل يقصّ في نتيجته ولا يُعاقب على الطول.
     */
    public const TITLE_MAX = 70;

    public const DESC_MAX = 170;

    /**
     * ترويسةُ الصفحة — عنوانٌ ووصفٌ وإذنُ فهرسة.
     *
     * والعنوانُ يُبنى على ثلاث مراتب: ما كتبه، ثمّ اسمُ النشاط مع اسم
     * الصفحة، ثمّ اسمُ النشاط وحدَه للرئيسية. واسمُ الصفحة يُضمّ إليه لا
     * يُبدّله: نتيجتان في غوغل بعنوانٍ واحد لا تُميَّزان.
     *
     * @param  array<string, string>  $t  نصوصُ الواجهة بلغة الزائر
     * @param  array<string, string>  $identity
     * @return array{brand: string, title: string, description: string, index: bool}
     */
    public static function head(Business $business, string $page, array $t, array $identity): array
    {
        $bid = (int) $business->id;
        $site = MarketingSettings::group($bid, 'website');

        $name = (string) $business->name;
        $written = trim((string) ($site['store_seo_title'] ?? ''));
        $label = $page === StoreNav::HOME ? '' : (string) ($t[StoreNav::LABELS[$page] ?? ''] ?? '');

        $base = $written !== '' ? $written : $name;

        $desc = trim((string) ($site['store_seo_desc'] ?? ''));

        if ($desc === '') {
            $desc = $identity['about'] !== '' ? $identity['about'] : $name;
        }

        return [
            /*
             * و`brand` ما يُذيَّل به عنوانُ كلّ صفحةٍ تكتب عنوانَها بنفسها
             * — صفحةُ الصنف والسلّة والإتمام. ولولاه لَبقي اسمُ النشاط
             * مكتوبًا هناك وما كتبه صاحبُه لا يبلغ إلّا صفحتين.
             */
            'brand' => $base,
            'title' => $label === '' ? $base : $label.' — '.$base,
            'description' => Str::limit($desc, 155),
            /*
             * و«لا تُفهرسني» يُكتب `noindex` ولا يُحذف شيء.
             *
             * من بنى متجره على عنوانٍ حقيقيّ وهو يجرّب لا يريده في نتائج
             * البحث بعد. وإخفاؤه بإطفاء النشر يُغلقه على زبائنه أيضًا —
             * وهذان سؤالان لا سؤال.
             */
            'index' => ($site['store_seo_index'] ?? '1') === '1',
        ];
    }
}
