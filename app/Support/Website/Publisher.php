<?php

namespace App\Support\Website;

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
 * واللقطة كاملةٌ لا فروق: الصفحات وأقسامها ومحتواها والقالب والألوان والسيو
 * في مستندٍ واحد. فالاستعادة كتابةٌ لا تجميع، وقارئ الموقع يقرأ مستندًا
 * واحدًا لا يحتاج معه استعلامًا في جداولنا.
 *
 * ولا يُقرأ من هذا الملفّ محتوى المنتجات: القسم يحمل «اعرض ثمانية من أحدث
 * المنتجات» لا المنتجاتِ نفسها. ولو جُمّدت المنتجات في اللقطة لبقي سعرُ
 * الأمس في الموقع بعد أن غيّره التاجر اليوم — والكتالوج مصدرُه واحدٌ، هو
 * جدول المنتجات.
 */
class Publisher
{
    /**
     * نشرةٌ جديدة — لقطةٌ ورقمٌ ووقت.
     *
     * والقديمة تبقى: «استعادة النسخة» لا تعمل إن كانت النشرةُ تمحو سابقتها.
     */
    public static function publish(Website $website, ?int $userId = null, ?string $note = null): WebsiteVersion
    {
        return DB::transaction(function () use ($website, $userId, $note) {
            $version = WebsiteVersion::create([
                'website_id' => $website->id,
                'business_id' => $website->business_id,
                'number' => WebsiteVersion::nextNumber($website->id),
                'payload' => self::snapshot($website),
                'note' => $note ? mb_substr($note, 0, 255) : null,
                'created_by' => $userId,
                'published_at' => now(),
            ]);

            $website->update([
                'published_version_id' => $version->id,
                'published_at' => $version->published_at,
                // ما نُشر هو مراجعةُ المسوّدة الآن — فتتساويان حتى أوّل تعديل
                'published_revision' => $website->draft_revision,
            ]);

            return $version;
        });
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
            $payload = $version->payload;

            // الحيّ يُمحى ثمّ يُكتب من اللقطة: الدمج يترك أقسامًا لا أصل لها
            WebsiteSection::where('website_id', $website->id)->delete();
            WebsitePage::where('website_id', $website->id)->delete();

            $website->update([
                'name' => $payload['name'] ?? $website->name,
                'goal' => Blueprints::goal($payload['goal'] ?? null),
                'template' => Templates::key($payload['template'] ?? null),
                'theme' => Theme::normalize($payload['theme'] ?? []),
                'seo' => $payload['seo'] ?? $website->seo,
            ]);

            foreach ($payload['globals'] ?? [] as $slot) {
                WebsiteSection::create([
                    'website_id' => $website->id,
                    'business_id' => $website->business_id,
                    'page_id' => null,
                    'slot' => $slot['slot'],
                    'type' => $slot['type'],
                    'position' => 0,
                    'visible' => (bool) ($slot['visible'] ?? true),
                    'data' => Content::clean($slot['type'], $slot['data'] ?? [], $website->goal),
                ]);
            }

            foreach ($payload['pages'] ?? [] as $i => $spec) {
                $page = WebsitePage::create([
                    'website_id' => $website->id,
                    'business_id' => $website->business_id,
                    'key' => $spec['key'] ?? 'custom',
                    'title' => $spec['title'] ?? __('صفحة'),
                    'slug' => WebsitePage::normalizeSlug($spec['slug'] ?? '/'),
                    'status' => $spec['status'] ?? WebsitePage::PUBLISHED,
                    'is_home' => (bool) ($spec['is_home'] ?? false),
                    'removable' => (bool) ($spec['removable'] ?? true),
                    'position' => $i,
                    'seo' => $spec['seo'] ?? null,
                ]);

                foreach ($spec['sections'] ?? [] as $j => $section) {
                    if (! Sections::exists($section['type'] ?? '')) {
                        continue;
                    }

                    WebsiteSection::create([
                        'website_id' => $website->id,
                        'business_id' => $website->business_id,
                        'page_id' => $page->id,
                        'slot' => null,
                        'type' => $section['type'],
                        'position' => $j,
                        'visible' => (bool) ($section['visible'] ?? true),
                        'data' => Content::clean($section['type'], $section['data'] ?? [], $website->goal),
                    ]);
                }
            }

            $website->touchDraft();
        });
    }

    /**
     * اللقطة — الموقع كلُّه في مصفوفةٍ واحدة.
     *
     * وهي أيضًا ما يقرؤه العارض الخارجيّ: صيغةٌ واحدة للنشر وللمعاينة
     * وللاستعادة. وصيغتان لشيءٍ واحد تفترقان عند أوّل حقلٍ يُضاف.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(Website $website): array
    {
        $website->loadMissing(['pages.sections', 'sections']);

        return [
            'version' => 1,
            'name' => $website->name,
            'goal' => $website->goal,
            'template' => $website->template,
            'theme' => $website->theme,
            'tokens' => $website->tokens(),
            'seo' => $website->seo,
            'maintenance' => $website->maintenance,
            'maintenance_message' => $website->maintenance_message,
            'globals' => $website->sections->whereNotNull('slot')->sortBy('slot')->map(fn ($s) => [
                'slot' => $s->slot,
                'type' => $s->type,
                'visible' => $s->visible,
                'data' => $s->data,
            ])->values()->all(),
            'pages' => $website->pages->map(fn ($page) => [
                'key' => $page->key,
                'title' => $page->title,
                'slug' => $page->slug,
                'status' => $page->status,
                'is_home' => $page->is_home,
                'removable' => $page->removable,
                'seo' => $page->seo,
                'sections' => $page->sections->map(fn ($s) => [
                    'type' => $s->type,
                    'visible' => $s->visible,
                    // مصدرُ محتواه إن كان يقرأ من النظام — يقرؤه العارض ليصله
                    'source' => Sections::source($s->type),
                    'data' => $s->data,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
