<?php

namespace App\Support\Store;

use App\Models\Business;
use App\Models\Setting;
use App\Models\StoreSite;
use App\Models\WebsiteVersion;
use App\Support\MarketingSettings;
use Illuminate\Support\Facades\DB;

/**
 * نشرُ الواجهة الخاصّة — **بالنسخ**: المسوّدةُ تُكتب فوق الحيّ.
 *
 * ═══ ولمَ بالنسخ لا بتحويل العارض إلى اللقطة ═══
 *
 * العارضُ يقرأ مفاتيحَ الإعدادات مباشرةً منذ كُتب، ويخدم متجرًا **يبيع
 * الآن**. وتحويلُ مصدره إلى لقطةٍ يعني أن يتبدّل ما يُرسَم لزبائنَ يشترون
 * — وأيُّ فرقٍ في مفتاحٍ واحد يظهر صفحةً مكسورة على نطاقٍ حيّ.
 *
 * فبالنسخ: المفاتيحُ الحيّة **هي** النسخةُ المنشورة، والعارضُ لا يتبدّل
 * حرفًا. والزائرُ لا يرى ما يُحرَّر لأنّ التحريرَ يقع في المسوّدة لا فيها.
 * واللقطةُ تُكتب في `website_versions` لتاريخٍ واسترجاعٍ دقيق.
 *
 * ═══ ودورةُ الحياة مأخوذةٌ من `Website\Publisher` لا مُعادةُ الاختراع ═══
 *
 * القفلُ على صفّ الموقع، والرقمُ يُقرأ تحته، والمراجعةُ المنشورة تُكتب من
 * الصفّ المقفول لا من نسخةٍ في يد المنادي. وعلّةُ كلٍّ منها مكتوبةٌ هناك.
 */
final class ThemePublisher
{
    /** نسخةُ عقد لقطة الواجهة — يُرقّى في `upgrade` حين يتبدّل */
    public const SCHEMA = 1;

    /**
     * يفتح نظامَ المسوّدات لمتجرٍ قائم — مرّةً، وبلا أن يتبدّل شيءٌ للزائر.
     *
     * ═══ وهي لحظةُ الترحيل كلُّها ═══
     *
     * تُلتقط حالُه المنشورة الآن كما هي، وتُكتب مسوّدتُه **نسخةً منها**،
     * وتُسجَّل نشرةٌ أولى تصفها. فالمفاتيحُ الحيّة لم تُمسّ، والمسوّدةُ
     * تساوي المنشور، واللوحةُ تقول «الموقع محدّث» — ولا يرى الزبون فرقًا.
     *
     * وتُعاد بلا ضرر: من له صفٌّ يُردّ صفُّه ولا يُكتب فوق مسوّدته.
     */
    public static function enable(Business $business, ?int $userId = null): StoreSite
    {
        $bid = (int) $business->id;

        return DB::transaction(function () use ($bid, $userId) {
            $existing = StoreSite::where('business_id', $bid)->lockForUpdate()->first();

            if ($existing !== null) {
                return $existing;
            }

            $published = StoreContent::live($bid);

            $site = StoreSite::create([
                'business_id' => $bid,
                'draft' => $published,
                'draft_revision' => 1,
                'draft_saved_at' => now(),
                'draft_saved_by' => $userId,
            ]);

            $version = WebsiteVersion::create([
                'website_id' => null,
                'business_id' => $bid,
                'kind' => WebsiteVersion::THEME,
                'number' => 1,
                'payload' => self::compile($bid, $published),
                'note' => 'حالُ المتجر يوم فُتح له النشر',
                'created_by' => $userId,
                'published_at' => now(),
            ]);

            $site->update([
                'published_version_id' => $version->id,
                'published_at' => $version->published_at,
                'published_revision' => 1,
            ]);

            return $site->refresh();
        });
    }

    /**
     * أوقع الترحيلُ نظيفًا؟ — والسؤالُ سؤالان في واحد.
     *
     * ١) أبقيت المفاتيحُ الحيّة كما كانت حرفًا بحرف؟ فلو تبدّل مفتاحٌ
     *    واحدٌ لَتبدّل ما يراه الزبون، والترحيلُ وُعد بأن لا يُرى.
     * ٢) وأتساوي المسوّدةُ المنشورَ؟ فلو افترقت لحظةَ الفتح لَقالت اللوحةُ
     *    «تغييراتٌ غير منشورة» عن تغييرٍ لم يقع، ولنشر التاجرُ شيئًا لا
     *    يعرف ما هو.
     *
     * ويُسأل هنا لا في الأمر: قاعدةٌ تُكتب داخل شرطٍ في `handle` لا
     * يبلغها اختبار، وتُحذف فلا يسقط شيء.
     *
     * @param  array<string, string>  $before  الحيُّ كما كان قبل الفتح
     */
    public static function migratedCleanly(int $businessId, array $before): bool
    {
        return StoreContent::live($businessId) === $before
            && StoreContent::changed($businessId) === [];
    }

    /**
     * يحفظ المسوّدة — ولا ينشرها.
     *
     * @param  array<string, mixed>  $values
     */
    public static function saveDraft(int $businessId, array $values, ?int $userId = null): void
    {
        $clean = StoreContent::only($values);

        if ($clean === []) {
            return;
        }

        DB::transaction(function () use ($businessId, $clean, $userId) {
            $site = StoreSite::where('business_id', $businessId)->lockForUpdate()->first();

            if ($site === null) {
                return;
            }

            $site->update([
                'draft' => array_merge(StoreContent::only((array) $site->draft), $clean),
                'draft_revision' => (int) $site->draft_revision + 1,
                'draft_saved_at' => now(),
                'draft_saved_by' => $userId,
            ]);
        });
    }

    /**
     * ينشر المسوّدة: لقطةٌ تُكتب، ثمّ تُنسخ فوق المفاتيح الحيّة.
     *
     * ═══ ومنعُ الضغط مرّتين ومنعُ التزامن ═══
     *
     * القفلُ يُؤخذ أوّلًا، ثمّ تُقرأ المراجعةُ تحته. فضغطتان متقاربتان
     * تدخلان بالترتيب لا معًا، والثانيةُ ترى `draft_revision` مساويًا
     * للمنشور فتردّ `null` بلا أن تكتب نشرةً فارغةً برقمٍ جديد.
     *
     * و`$expected` لمن فتح الشاشةَ ثمّ نشر بعد أن بدّل زميلُه المسوّدة:
     * يُقال له إنّ ما ينشره ليس ما رآه — ولا يُنشر على ظنّه.
     *
     * @return WebsiteVersion|null  لا شيءَ حين لا شيءَ يُنشر
     */
    public static function publish(Business $business, ?int $userId = null, ?string $note = null, ?int $expected = null): ?WebsiteVersion
    {
        $bid = (int) $business->id;

        $version = DB::transaction(function () use ($bid, $userId, $note, $expected) {
            $site = StoreSite::where('business_id', $bid)->lockForUpdate()->first();

            if ($site === null) {
                return null;
            }

            // مَن نشر ما لم يره يُردّ — انظر رأسَ الدالّة
            if ($expected !== null && $expected !== (int) $site->draft_revision) {
                throw new StaleDraft;
            }

            $draft = StoreContent::only((array) $site->draft);
            $live = StoreContent::live($bid);

            if ($draft === $live) {
                return null;
            }

            $number = WebsiteVersion::nextThemeNumber($bid);

            $version = WebsiteVersion::create([
                'website_id' => null,
                'business_id' => $bid,
                'kind' => WebsiteVersion::THEME,
                'number' => $number,
                'payload' => self::compile($bid, $draft),
                'note' => $note !== null && $note !== '' ? mb_substr($note, 0, 255) : null,
                'created_by' => $userId,
                'published_at' => now(),
            ]);

            /*
             * والنسخُ هو النشر — وهنا وحدَه يتبدّل ما يراه الزائر.
             *
             * ويُكتب بالنموذج لا بـ`DB::table`: الكتابةُ بالنموذج تُبطل
             * ذاكرةَ الإعدادات (`Setting::booted`)، وبدونها يُخدَم هذا
             * الطلبُ نفسُه من قيمٍ قبل النشر.
             */
            foreach ($draft as $key => $value) {
                Setting::updateOrCreate(
                    ['business_id' => $bid, 'key' => $key],
                    ['value' => $value],
                );
            }

            $site->update([
                'published_version_id' => $version->id,
                'published_at' => $version->published_at,
                'published_revision' => (int) $site->draft_revision,
            ]);

            return $version;
        });

        MarketingSettings::forget($bid);

        return $version;
    }

    /**
     * يُعيد نشرةً قديمة إلى **المسوّدة** — لا إلى الموقع.
     *
     * وهي قاعدةُ `Website\Publisher::restore` نفسُها: الاسترجاعُ يُري
     * صاحبَه ما كان ليراجعه، ثمّ ينشره بيده إن رضي. ولو كُتب على الحيّ
     * رأسًا لَتبدّل موقعٌ يعمل بضغطةٍ في شاشة تاريخ.
     *
     * والقالبُ لا يُستعاد — انظر `StoreContent::READ_ONLY`.
     */
    public static function restore(Business $business, WebsiteVersion $version, ?int $userId = null): bool
    {
        $bid = (int) $business->id;

        if ((int) $version->business_id !== $bid || $version->kind !== WebsiteVersion::THEME) {
            return false;
        }

        $payload = self::upgrade((array) $version->payload);

        self::saveDraft($bid, (array) ($payload['content'] ?? []), $userId);

        return true;
    }

    /**
     * المستندُ الذي يُكتب في `payload`.
     *
     * ويُكتب فيه القالبُ والاسمُ ليُقرآ عند المراجعة — لا ليُكتبا عند
     * الاستعادة. ولا يدخله سعرٌ ولا مخزونٌ ولا صنف: تلك حالُ النشاط الآن.
     *
     * @param  array<string, string>  $content
     * @return array<string, mixed>
     */
    private static function compile(int $businessId, array $content): array
    {
        $business = Business::find($businessId);

        return [
            'schema_version' => self::SCHEMA,
            'engine' => 'theme',
            'theme' => $business?->storefrontTheme(),
            'name' => (string) ($business?->name ?? ''),
            'content' => StoreContent::only($content),
        ];
    }

    /**
     * كلُّ مستندٍ يُقرأ يمرّ به — فنسخةُ الأمس تخرج بشكل اليوم.
     *
     * ولا يفعل شيئًا بعد إذ النسخةُ واحدة؛ وهو موضعُه حين تصير اثنتين،
     * مكتوبًا قبل أن يُحتاج إليه لا بعد أن تُنشر آلافُ اللقطات.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function upgrade(array $payload): array
    {
        $payload['schema_version'] = (int) ($payload['schema_version'] ?? 1);
        $payload['content'] = StoreContent::only((array) ($payload['content'] ?? []));

        return $payload;
    }
}
