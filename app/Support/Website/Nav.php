<?php

namespace App\Support\Website;

use App\Models\Website;
use Illuminate\Support\Collection;

/**
 * قائمةُ الموقع — روابطُها تعرف إلى أين تشير.
 *
 * ═══ ما كان يقع ═══
 *
 * كانت القائمة تُبنى وتُفرَز بشكل الرابط: `str_starts_with($href, '/')` تعني
 * «هذا رابطُ صفحةٍ يبنيه النظام»، وما سواه «رابطٌ زاده التاجر». ثمّ تُمحى
 * القائمةُ الأولى وتُبنى من الصفحات، ويُلحق بها الثاني.
 *
 * وثمنُه أنّ كلَّ رابطٍ داخليٍّ يكتبه التاجر بيده يُحذف: يضيف «العروض» يشير
 * إلى `/shop#sale`، فيحفظ فيراه في قائمته، ثمّ يبدّل عنوان صفحةٍ أخرى بعد
 * يومين — فتُنادى المزامنة ويسقط رابطُه بلا خبر. وهو لا يربط بين الأمرين
 * أبدًا: عدّل صفحةً فضاع رابطٌ في صفحةٍ أخرى.
 *
 * ═══ والعلاجُ أن يُقال نوعُ الرابط لا أن يُخمَّن من شكله ═══
 *
 * `page_id` هو ما يعرف النظام: رابطٌ يشير إلى صفحةٍ بعينها. وما لا `page_id`
 * له فهو للتاجر ولا يُمسّ — أيًّا كان شكلُه.
 *
 * وفائدةٌ ثانية: العنوانُ يتبع الصفحة. من بدّل رابط «من نحن» من `/about`
 * إلى `/who-we-are` كان رابطُ قائمته يبقى على القديم حتى تُعاد المزامنة —
 * والآن يُبنى من `page_id` في كلّ مرّة، فلا يُشار إلى «غير موجود».
 *
 * والنوعُ يُشتقّ ولا يُؤتمن: شاشةُ المحرّر لا تعرض `page_id` للتاجر (وهو
 * صواب — انظر «لا تُظهر البنية في الشاشة»)، فما يعود منها بلا معرّف. فيُعاد
 * الاشتقاقُ بمطابقة العنوان، ولا يضيع رابطٌ في الذهاب والإياب.
 */
final class Nav
{
    /** رابطٌ إلى صفحةٍ في هذا الموقع */
    public const PAGE = 'page';

    /** رابطٌ كتبه التاجر — داخليًّا كان أو خارجيًّا */
    public const EXTERNAL = 'external';

    public const TYPES = [self::PAGE, self::EXTERNAL];

    /**
     * قائمةُ الترويسة والتذييل تتبع الصفحات — وتبقي ما زاده التاجر.
     *
     * وثلاث قواعد تحكمها، وكلٌّ منها تمنع عطبًا رآه من يستعمل هذه الشاشات:
     *
     * ١) صفحةٌ منشورة لها رابطٌ في القائمة. من ينشئ صفحةً ولا يجدها في قائمة
     *    موقعه يظنّ أنّها لم تُحفظ.
     * ٢) رابطُ صفحةٍ لم تعد منشورةً أو حُذفت يسقط. قائمةٌ تقود الزائر إلى
     *    «غير موجود» أسوأ من قائمةٍ أقصر.
     * ٣) وما ليس رابطَ صفحة يبقى كما كتبه صاحبُه.
     */
    public static function sync(Website $website): void
    {
        $pages = $website->pages()
            ->where('status', \App\Models\WebsitePage::PUBLISHED)
            ->orderBy('position')->get();

        $links = $pages->map(fn ($p) => [
            'label' => $p->title,
            'href' => $p->slug,
            'type' => self::PAGE,
            'page_id' => (int) $p->id,
        ]);

        foreach (Sections::SLOTS as $slot) {
            $section = $website->slot($slot);

            if (! $section) {
                continue;
            }

            $custom = collect(self::normalize((array) ($section->data['links'] ?? []), $pages))
                ->reject(fn ($l) => $l['type'] === self::PAGE)
                ->values();

            $data = $section->data;
            $data['links'] = $links->concat($custom)->take(Content::MAX_ITEMS)->values()->all();

            $section->update(['data' => Content::clean($section->type, $data, $website->goal)]);
        }
    }

    /**
     * روابطُ قائمةٍ وقد عرف كلٌّ منها نوعَه.
     *
     * @param  array<int, mixed>  $links
     * @param  Collection<int, \App\Models\WebsitePage>  $pages  الصفحات المنشورة
     * @return list<array<string, mixed>>
     */
    public static function normalize(array $links, Collection $pages): array
    {
        $byId = $pages->keyBy('id');
        $bySlug = $pages->keyBy('slug');
        $out = [];

        foreach ($links as $link) {
            if (! is_array($link)) {
                continue;
            }

            $href = trim((string) ($link['href'] ?? ''));
            $label = trim((string) ($link['label'] ?? ''));
            $pageId = (int) ($link['page_id'] ?? 0);
            $type = (string) ($link['type'] ?? '');

            /*
             * ═══ المعرّفُ أوّلًا، والعنوانُ لمن لم يُقل نوعُه ═══
             *
             * المعرّف يبقى صحيحًا وإن بدّل التاجر رابط الصفحة. ومطابقةُ
             * العنوان تلتقط ما حُفظ قبل أن يوجد الحقلُ أصلًا — وتقع **مرّةً
             * واحدة**، إذ تُكتب النتيجةُ صريحةً بعدها.
             *
             * ولا تُعاد على من قال «أنا رابطٌ كتبه صاحبُ الموقع»: من كتب
             * «العروض» يشير إلى `/shop` يريد بابَه هو باسمه هو، ولا يُبدَّل
             * اسمُه باسم الصفحة في كلّ مزامنة.
             */
            $page = $byId[$pageId] ?? null;

            if ($page === null && $type === '' && $href !== '') {
                $page = $bySlug[$href] ?? null;
            }

            if ($page) {
                $out[] = [
                    'label' => $label !== '' ? $label : $page->title,
                    // العنوانُ من الصفحة لا من المحفوظ: رابطٌ لا يشيخ
                    'href' => $page->slug,
                    'type' => self::PAGE,
                    'page_id' => (int) $page->id,
                ];

                continue;
            }

            /*
             * ورابطُ صفحةٍ لم تعد موجودة يسقط — وما سواه يبقى.
             *
             * الفرقُ أنّ الأوّل وعدٌ من النظام أخلفه (صفحةٌ حُذفت أو أُخفيت)،
             * والثاني كلامُ التاجر عن موضعٍ يعرفه هو ولا نعرفه نحن.
             */
            if ($type === self::PAGE && $pageId > 0) {
                continue;
            }

            if ($href === '' && $label === '') {
                continue;
            }

            $out[] = [
                'label' => $label,
                'href' => $href,
                'type' => self::EXTERNAL,
                'page_id' => 0,
            ];
        }

        return $out;
    }
}
