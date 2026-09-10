<?php

namespace App\Support\Website;

use App\Events\Website\WebsitePublished;
use App\Events\Website\WebsiteRestored;
use App\Models\Website;
use App\Models\WebsitePage;
use App\Models\WebsiteSection;
use App\Models\WebsiteVersion;
use Illuminate\Support\Facades\DB;

/**
 * النشر: تجميدُ حالِ الموقع الآن في لقطةٍ يقرؤها الزائر.
 *
 * وهذا هو الفرق بين محرّرٍ يُستعمل ومحرّرٍ يُخاف منه. ما دام الزائر يقرأ
 * لقطةً مجمّدة، فالتاجر يجرّب في المسوّدة ويحذف قسمًا ويبدّل قالبًا ويترك
 * الشاشة نصفَ عمل — ولا يرى زبونٌ شيئًا من ذلك. وبلا هذا الفصل يصير كلُّ
 * تعديلٍ نشرًا، فلا يُعدَّل موقعٌ يعمل إلا ليلًا وبقلبٍ واجف.
 *
 * وشكلُ اللقطة ليس هنا: هو عقدٌ له نسخةٌ ومُرقٍّ في `Publication`. وهذا
 * الملفّ يملك **متى** تُكتب و**كيف** تُكتب بلا أن تنكسر — لا **ماذا** فيها.
 */
class Publisher
{
    /**
     * نشرةٌ جديدة — لقطةٌ ورقمٌ ووقت.
     *
     * ═══ والعملية كلُّها تحت قفلِ صفِّ الموقع ═══
     *
     * كان الرقم يُحسب `MAX(number) + 1` بلا قفل. ونشرتان تقعان معًا — تاجرٌ
     * ضغط مرّتين، أو شريكان في اللوحة نفسها — تقرآن الأقصى نفسه وتكتبان
     * الرقم نفسه، فيصطدم أحدهما بالفهرس الفريد `(website_id, number)` ويرى
     * صاحبُه صفحةَ خطأ بلا سبب.
     *
     * وأخطرُ منه ما لا يُرى: اللقطة كانت تُقرأ خارج أيّ قفل، ومراجعةُ
     * المسوّدة تُقرأ من نسخةٍ في الذاكرة حُمّلت قبل الطلب. فحفظٌ يقع بين
     * القراءتين يُجمَّد نصفُه — أقسامٌ بعده وصفحاتٌ قبله — ثمّ يُكتب
     * `published_revision` رقمًا أقدم، فتقول اللوحة «فيه تغييرات» أبدًا عن
     * تغييرٍ نُشر، أو تقول «منشور» عن نصفٍ لم يُنشر.
     *
     * فالقفل يُؤخذ أوّلًا، ويُقرأ منه كلُّ شيء: الرقمُ واللقطةُ والمراجعة.
     * وما بعده متناسقٌ بالبناء لا بحسن التوقيت.
     */
    public static function publish(Website $website, ?int $userId = null, ?string $note = null): WebsiteVersion
    {
        $version = DB::transaction(function () use ($website, $userId, $note) {
            /*
             * وصفُّ الموقع هو المقفول لا صفُّ النشرة.
             *
             * النشرةُ لا وجود لها بعد، ولا يُقفل ما لم يُخلق. والموقع هو ما
             * يتنازع عليه الطلبان: منه يُقرأ الأقصى، وفيه يُكتب المؤشّر.
             */
            $locked = Website::whereKey($website->getKey())->lockForUpdate()->first();

            if (! $locked) {
                throw new \RuntimeException('الموقع حُذف قبل أن يُنشر');
            }

            $number = ((int) WebsiteVersion::where('website_id', $locked->id)->max('number')) + 1;

            $version = WebsiteVersion::create([
                'website_id' => $locked->id,
                'business_id' => $locked->business_id,
                'number' => $number,
                'payload' => Publication::compile($locked),
                'note' => $note !== null && $note !== '' ? mb_substr($note, 0, 255) : null,
                'created_by' => $userId,
                'published_at' => now(),
            ]);

            $locked->update([
                'published_version_id' => $version->id,
                'published_at' => $version->published_at,
                /*
                 * وما نُشر هو مراجعةُ **الصفّ المقفول** لا مراجعةُ النسخة
                 * التي في يد المنادي — تلك قد تكون حُمّلت قبل حفظٍ وقع.
                 */
                'published_revision' => $locked->draft_revision,
            ]);

            return $version;
        });

        // والنسخة التي في يد المنادي تُوافق ما صار في القاعدة
        $website->refresh();

        WebsitePublished::dispatch($website, $version);

        return $version;
    }

    /**
     * إعادة الموقع إلى نسخةٍ سابقة.
     *
     * والاستعادة لا تنشر: تُكتب اللقطة في المسوّدة، فيراها التاجر ويعاينها
     * ثمّ ينشر إن رضي. واستعادةٌ تنشر بنفسها تجعل ضغطةً واحدة تُبدّل موقعًا
     * يعمل بلا معاينة.
     */
    public static function restore(Website $website, WebsiteVersion $version): void
    {
        if ($version->website_id !== $website->id || $version->business_id !== $website->business_id) {
            return;
        }

        DB::transaction(function () use ($website, $version) {
            /*
             * واللقطةُ تمرّ بالمُرقّي قبل أن تُكتب.
             *
             * نسخةٌ نُشرت قبل نسخةٍ من العقد شكلُها شكلُ يومها. وكتابتُها
             * كما هي تُدخل إلى المسوّدة الحيّة مفاتيحَ ناقصة، فيقع النقص
             * في النشرة التالية أيضًا — والعطبُ يبقى بعد أن تُنسى استعادتُه.
             */
            $payload = Publication::upgrade((array) $version->payload);

            // الحيّ يُمحى ثمّ يُكتب من اللقطة: الدمج يترك أقسامًا لا أصل لها
            WebsiteSection::where('website_id', $website->id)->delete();
            WebsitePage::where('website_id', $website->id)->delete();

            $website->update([
                'name' => $payload['name'] ?: $website->name,
                'goal' => Blueprints::goal($payload['goal'] ?? null),
                'template' => Templates::key($payload['template'] ?? null),
                'theme' => Theme::normalize($payload['theme'] ?? []),
                'seo' => $payload['seo'],
            ]);

            $goal = $website->goal();

            foreach ($payload['globals'] as $slot) {
                if (! Sections::isSlot((string) ($slot['type'] ?? ''))) {
                    continue;
                }

                WebsiteSection::create([
                    'website_id' => $website->id,
                    'business_id' => $website->business_id,
                    'page_id' => null,
                    'slot' => $slot['slot'],
                    'type' => $slot['type'],
                    'position' => 0,
                    'visible' => (bool) ($slot['visible'] ?? true),
                    'data' => Content::clean($slot['type'], (array) ($slot['data'] ?? []), $goal),
                ]);
            }

            foreach ($payload['pages'] as $i => $spec) {
                $page = WebsitePage::create([
                    'website_id' => $website->id,
                    'business_id' => $website->business_id,
                    'key' => $spec['key'] ?? 'custom',
                    'title' => $spec['title'] ?: __('صفحة'),
                    'slug' => WebsitePage::normalizeSlug($spec['slug']),
                    'status' => $spec['status'],
                    'is_home' => $spec['is_home'],
                    'removable' => (bool) ($spec['removable'] ?? true),
                    'position' => $i,
                    'seo' => $spec['seo'] ?? null,
                ]);

                $position = 0;

                foreach ($spec['sections'] as $section) {
                    $type = (string) ($section['type'] ?? '');

                    // وما لا يدخل النشرةَ لا يخرج منها — القاعدة واحدة
                    if (! Publication::carries($type, $goal)) {
                        continue;
                    }

                    WebsiteSection::create([
                        'website_id' => $website->id,
                        'business_id' => $website->business_id,
                        'page_id' => $page->id,
                        'slot' => null,
                        'type' => $type,
                        'position' => ++$position,
                        'visible' => (bool) ($section['visible'] ?? true),
                        'data' => Content::clean($type, (array) ($section['data'] ?? []), $goal),
                    ]);
                }
            }

            // والقائمة تُبنى من الصفحات المستعادة لا تبقى على صفحاتٍ حُذفت
            Nav::sync($website->fresh());

            $website->touchDraft();
        });

        $website->refresh();

        WebsiteRestored::dispatch($website, $version);
    }

    /**
     * اللقطة — والشكلُ في `Publication::compile`.
     *
     * ويبقى هذا الاسم لأنّ المعاينة تناديه ولأنّه يُقرأ في اختبارات قائمة؛
     * وهو سطرٌ واحد لا منطقَ فيه، فلا مصدرين لشكلٍ واحد.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(Website $website): array
    {
        return Publication::compile($website);
    }
}
