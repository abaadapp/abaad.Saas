<?php

namespace App\Support\Store;

use App\Models\Business;
use App\Models\StoreTermsVersion;
use App\Support\MarketingSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * شروطُ المتجر وأحكامُه — يكتبها صاحبُه بلغتين، ويوافق عليها زبونُه قبل أن يطلب.
 *
 * ═══ صاحبُ المتجر وحده يكتبها ═══
 *
 * لا نصَّ افتراضيَّ ولا شروطَ يكتبها النظامُ عنه: هي التزامٌ قانونيٌّ بينه
 * وبين زبونه. والمفتاحُ (`store_terms_on`) مطفأٌ حتّى يفتحه، ولا يُفتح إلّا
 * ونصّاه العربيُّ والإنجليزيُّ مكتوبان — فلا تُشترط موافقةٌ على شروطٍ لا
 * يقرؤها زبونُ إحدى اللغتين.
 *
 * ═══ والنصُّ في نسخه لا في الإعدادات ═══
 *
 * كلُّ حفظٍ يغيّر النصَّ يكتب نسخةً جديدةً برقمها (`StoreTermsVersion`)،
 * ولا تُعدَّل نسخةٌ ولا تُحذف. والصفحةُ تعرض آخرَها، والطلبُ يحمل رقمَ ما
 * وافق عليه زبونُه ولغتَه ووقتَه وبصمةَ النصّ الذي رآه. فـ«على ماذا وافق؟»
 * يُجاب بعد سنةٍ بالنصّ نفسِه، لا بما في الشاشة يومئذٍ.
 *
 * ═══ والموافقةُ يُسأل عنها الخادم ═══
 *
 * الصفحةُ ترسل رقمَ النسخة التي عرضتها وخانةَ الموافقة. ولا يُكتب طلبٌ ولا
 * تُفتح صفحةُ دفعٍ بلا موافقة، أو بموافقةٍ على نسخةٍ قديمة — فخانةٌ تُعطَّل في
 * المتصفّح أو طلبٌ يُرسَل بيده لا يتجاوزها.
 *
 * ولا يُسأل عنها في دفعةٍ قُبضت: سُئل قبل أن تُفتح صفحةُ البنك (`WebCheckout::toCard`)،
 * وموافقتُه في حمولة النيّة. فلو عدّل صاحبُ المتجر شروطَه والزبونُ عند البنك،
 * يُكتب الطلبُ بالنسخة التي وافق عليها — لا يُردّ مالٌ مقبوض.
 */
final class StoreTerms
{
    public const ON = 'store_terms_on';

    /** مفتاحا النصّ في نموذج الشاشة — يُكتبان نسخةً لا إعدادًا */
    public const TEXT = 'store_terms';

    public const TEXT_EN = 'store_terms_en';

    /** حدُّ النصّ بكلّ لغة */
    public const MAX = 20000;

    /** خانةُ الموافقة ورقمُ النسخة في حمولة الإتمام — وموضعُ الخطأ في الصفحة */
    public const FIELD = 'terms';

    public const VERSION = 'terms_version';

    public const ACCEPT = 'terms_accept';

    /** لقطةُ الموافقة في حمولة نيّة الدفع — يكتبها الخادم لا المتصفّح */
    public const STAMP = '_terms';

    /** آخرُ نسخةٍ كُتبت — وهي ما يُعرض ويُوافَق عليه */
    public static function current(int $bid): ?StoreTermsVersion
    {
        return StoreTermsVersion::where('business_id', $bid)->orderByDesc('version')->first();
    }

    /** أتُشترط الموافقة؟ — المفتاحُ مرفوعٌ ونسخةٌ مكتوبةٌ بلغتيها */
    public static function required(int $bid): bool
    {
        if ((MarketingSettings::group($bid, 'website')[self::ON] ?? '0') !== '1') {
            return false;
        }

        $v = self::current($bid);

        return $v !== null && trim($v->body_ar) !== '' && trim($v->body_en) !== '';
    }

    /**
     * ما تعرضه صفحةُ الإتمام بلغتها — أو لا شيء حيث لا تُشترط.
     *
     * @return array{id: int, version: int, text: string}|null
     */
    public static function forPage(int $bid, string $lang): ?array
    {
        if (! self::required($bid)) {
            return null;
        }

        $v = self::current($bid);

        return ['id' => (int) $v->id, 'version' => (int) $v->version, 'text' => $v->body($lang)];
    }

    /** النصُّ كما يُحفظ ويُبصَم: أطرافٌ مقصوصة وأسطرٌ بلا `\r` */
    public static function clean(?string $text): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", (string) $text));
    }

    public static function hash(string $text): string
    {
        return hash('sha256', self::clean($text));
    }

    /**
     * يكتب نسخةً جديدةً إن تغيّر النصّ — ويردّ النسخةَ القائمةَ بعد الحفظ.
     *
     * ونصٌّ لم يتغيّر لا يكتب نسخة: حفظُ الشاشة لسببٍ آخر لا يرفع رقمَ
     * الإصدار، فلا يُطلب من الزبائن أن يوافقوا من جديد على ما وافقوا عليه.
     *
     * والرقمُ يُحسب وصفُّ المتجر مقفل: حفظان في اللحظة نفسِها لا يأخذان رقمًا واحدًا.
     */
    public static function revise(int $bid, ?string $ar, ?string $en, ?int $userId): ?StoreTermsVersion
    {
        $ar = self::clean($ar);
        $en = self::clean($en);

        return DB::transaction(function () use ($bid, $ar, $en, $userId) {
            Business::whereKey($bid)->lockForUpdate()->first();
            $current = self::current($bid);

            if ($current !== null && $current->body_ar === $ar && $current->body_en === $en) {
                return $current;
            }

            if ($current === null && $ar === '' && $en === '') {
                return null;
            }

            return StoreTermsVersion::create([
                'business_id' => $bid,
                'version' => ((int) ($current?->version ?? 0)) + 1,
                'body_ar' => $ar,
                'body_en' => $en,
                'hash_ar' => self::hash($ar),
                'hash_en' => self::hash($en),
                'created_by' => $userId,
            ]);
        });
    }

    /* ═══════════ الإتمام ═══════════ */

    /**
     * أوافق الزبونُ على الشروط القائمة؟ — وإلّا يُردّ قبل أن يُكتب شيء.
     *
     * @param  array<string, mixed>  $payload  حمولةُ المتصفّح كما وصلت
     *
     * @throws ValidationException|TermsChanged
     */
    public static function check(int $bid, array $payload, string $lang): void
    {
        if (! self::required($bid)) {
            return;
        }

        /*
         * «نعم» صريحةٌ وحدها: `true` كما ترسلها الخانة، أو `1`. ولا يُقرأ «yes»
         * ولا «on» ولا نصٌّ آخر موافقةً بظنّ — `FILTER_VALIDATE_BOOL` تقبلها كلَّها.
         */
        $accepted = in_array($payload[self::ACCEPT] ?? null, [true, 1, '1', 'true'], true);

        if (! $accepted) {
            throw ValidationException::withMessages([
                self::FIELD => __('يجب الموافقة على الشروط والأحكام قبل إتمام الطلب.'),
            ]);
        }

        $current = self::current($bid);

        if ((string) ($payload[self::VERSION] ?? '') !== (string) $current->id) {
            throw TermsChanged::with(['id' => (int) $current->id, 'version' => (int) $current->version, 'text' => $current->body($lang)]);
        }
    }

    /**
     * لقطةُ موافقةٍ فُحصت للتوّ — تمضي مع نيّة الدفع وتعود إلى الطلب.
     *
     * @return array{id: int, at: string, lang: string}|null
     */
    public static function stamp(int $bid, string $lang): ?array
    {
        if (! self::required($bid)) {
            return null;
        }

        return ['id' => (int) self::current($bid)->id, 'at' => now()->toIso8601String(), 'lang' => $lang === 'en' ? 'en' : 'ar'];
    }

    /**
     * أعمدةُ الموافقة على الطلب — من لقطةٍ فُحصت لا من المتصفّح.
     *
     * ونسخةٌ لا تُعرف لهذا المتجر لا تُكتب: الطلبُ يُكتب بلا موافقةٍ تُدّعى
     * لا بموافقةٍ على نصّ متجرٍ آخر.
     *
     * @param  array{id?: mixed, at?: mixed, lang?: mixed}|null  $stamp
     * @return array{terms_version_id: ?int, terms_accepted_at: ?string, terms_lang: ?string, terms_hash: ?string}
     */
    public static function columns(int $bid, ?array $stamp): array
    {
        $none = ['terms_version_id' => null, 'terms_accepted_at' => null, 'terms_lang' => null, 'terms_hash' => null];
        $version = is_array($stamp) && is_numeric($stamp['id'] ?? null)
            ? StoreTermsVersion::where('business_id', $bid)->find((int) $stamp['id'])
            : null;

        if ($version === null) {
            return $none;
        }

        $lang = ($stamp['lang'] ?? 'ar') === 'en' ? 'en' : 'ar';

        try {
            $at = Carbon::parse((string) ($stamp['at'] ?? ''))->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            $at = now();
        }

        return [
            'terms_version_id' => (int) $version->id,
            'terms_accepted_at' => $at->toDateTimeString(),
            'terms_lang' => $lang,
            'terms_hash' => $version->hashFor($lang),
        ];
    }
}
