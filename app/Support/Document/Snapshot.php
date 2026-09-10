<?php

namespace App\Support\Document;

use App\Models\Business;
use App\Support\Demo;
use App\Support\Paper;
use Illuminate\Database\Eloquent\Model;

/**
 * ما كانت عليه الورقةُ يومَ صدرت — لا ما صار عليه المتجر بعدها.
 *
 * ═══ ما كان يقع ═══
 *
 * بنودُ الفاتورة ملقوطةٌ منذ البدء (`order_items` تحمل الاسمَ والسعر نسخةً)،
 * لكنّ هويّةَ البائع والعملةَ كانتا تُقرآن حيّتين عند كلّ رسم. فأصدرتُ
 * فاتورةً، ثمّ غيّرتُ اسمَ المتجر ورقمَه الضريبيّ وعملتَه، وأعدتُ رسمَها:
 *
 *   • «12.500 OMR» صارت «12.50 د.إ» — الرقمُ في القاعدة لم يتغيّر، لكنّه
 *     أُعيد وسمُه بعملةٍ أخرى وفقد منزلة.
 *   • والرقمُ الضريبيّ صار رقمًا لم يكن قائمًا يومَ البيع.
 *
 * ═══ وما يُلقَط، وما لا يُلقَط ═══
 *
 * يُلقَط ما **يُقرأ حيًّا** ولا أثرَ له في صفّ الورقة: هويّةُ البائع،
 * ووصفُ العملة، ونسخةُ القالب. ولا يُلقَط ما هو منسوخٌ أصلًا — اسمُ الصنف
 * وسعرُه واسمُ العميل والفرع — فنسخُه ثانيةً مصدرٌ ثانٍ للحقيقة نفسِها.
 *
 * ═══ وصفٌّ بلا لقطةٍ يُقرأ من الحيّ ═══
 *
 * أوراقُ ما قبل هذا الملفّ لا لقطةَ لها. ولا تُختلق لها واحدةٌ بحالٍ لم
 * يكن حالَها: تُقرأ من المتجر كما كانت تُقرأ، ويُقال ذلك صراحةً في التقرير
 * بدل أن يُدَّعى لها تاريخٌ لا تملكه.
 */
final class Snapshot
{
    /** رقمُ شكلِ اللقطة — يتغيّر إن تغيّر ما فيها، فتُقرأ القديمةُ بقواعدها */
    public const VERSION = 1;

    /** العمودُ الذي يحملها */
    public const COLUMN = 'document_snapshot';

    /**
     * حالُ المتجر الآن — تُكتب مرّةً يومَ تصدر الورقة.
     *
     * @return array<string, mixed>
     */
    public static function capture(int $businessId): array
    {
        /*
         * والصفُّ نفسُه لا `Demo::business`.
         *
         * تلك تردّ مصفوفةً مختارةَ الحقول **بلا `address`** — عمدًا، لأنّها
         * تُبنى للوحة لا للورقة. ولقطةٌ تُبنى منها تفقد عنوانَ البائع صامتةً،
         * فتُطبع الفاتورةُ القديمة بلا عنوانٍ بينما الجديدةُ تحمله.
         */
        $business = Business::find($businessId);

        $get = static fn (string $k): string => trim((string) ($business?->{$k} ?? ''));

        return [
            'v' => self::VERSION,
            /*
             * والبائعُ بالمفاتيح التي تقرؤها `Paper::brand` نفسُها.
             *
             * فتُمرَّر اللقطةُ إليها كما يُمرَّر صفُّ المتجر — بلا فرعٍ ثانٍ
             * في الشفرة يقرأ اللقطةَ ويبني منها ما تبنيه هي.
             */
            'seller' => [
                'name' => $get('name'),
                'type' => $get('type'),
                'city' => $get('city'),
                'address' => $get('address'),
                'phone' => $get('phone'),
                'email' => $get('email'),
            ],
            'vat' => Paper::vatNumber($businessId),
            /* ووصفُ العملة كاملًا: رمزٌ ومنازلُ وموضعُ رمز — انظر `Support\Money` */
            'currency' => Demo::currencyFor($businessId),
            /* وأيُّ قالبٍ رسمها — فتُعرف بعد سنةٍ بأيّ نسخةٍ صدرت */
            'template' => Version::CURRENT,
        ];
    }

    /**
     * لقطةُ ورقةٍ — المحفوظةُ إن وُجدت، وإلّا فحالُ المتجر الآن.
     *
     * @return array<string, mixed>
     */
    public static function of(?Model $document, int $businessId): array
    {
        $raw = $document?->getAttribute(self::COLUMN);

        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) && ($raw['v'] ?? null) === self::VERSION
            ? $raw
            : self::capture($businessId);
    }

    /** أللورقة لقطةٌ محفوظة؟ — للتقارير وللاختبار، لا لمنطق الرسم */
    public static function stamped(?Model $document): bool
    {
        $raw = $document?->getAttribute(self::COLUMN);

        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) && ($raw['v'] ?? null) === self::VERSION;
    }

    /**
     * تُختم الورقةُ مرّةً واحدة — ولا يُعاد ختمُ ما خُتم.
     *
     * وإعادةُ الختم تعني أن يُكتب عليها حالُ اليوم بدل حال يومها، وهو
     * العطبُ نفسُه الذي وُجدت لتمنعه.
     */
    public static function stamp(Model $document, int $businessId): void
    {
        if (self::stamped($document)) {
            return;
        }

        $document->setAttribute(self::COLUMN, self::capture($businessId));
    }

    /**
     * بائعُ الورقة — بالشكل الذي تقبله `Paper::brand`.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, string>
     */
    public static function seller(array $snapshot): array
    {
        return is_array($snapshot['seller'] ?? null) ? $snapshot['seller'] : [];
    }

    /** رقمُ البائع الضريبيُّ يومَ صدرت */
    public static function vat(array $snapshot): string
    {
        return trim((string) ($snapshot['vat'] ?? ''));
    }

    /**
     * عملةُ الورقة يومَ صدرت.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public static function currency(array $snapshot): array
    {
        return is_array($snapshot['currency'] ?? null) ? $snapshot['currency'] : [];
    }
}
