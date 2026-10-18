<?php

namespace App\Support\Store;

use App\Support\DeliveryPaper;
use App\Support\Digits;
use App\Support\NameTransliterator;

/**
 * «لا تذكر اسمي» ورسالةُ البطاقة — أتقول الرسالةُ ما طُلب إخفاؤه؟
 *
 * ═══ ما يقع ═══
 *
 * زبونٌ يُهدي ويختار «لا تذكر اسمي للمستلم»، ثمّ يكتب على البطاقة «مع حبّي،
 * أحمد» أو رقمَه. فالسندُ والكرتُ يُخفيان اسمَ المُهدي — والرسالةُ تقوله.
 *
 * ═══ وما لا يُفعل ═══
 *
 * لا تُعدَّل الرسالةُ ولا يُحذف منها حرف: هي كلامُ صاحبها إلى من يحبّ، وقد
 * يريد أن يوقّعها. فيُسأل قبل أن يُنشأ الطلب أو تُفتح دفعة: «عدّلها أو
 * أرسلها كما هي» — وجوابُه يُحترم.
 *
 * ═══ والإقرارُ رمزٌ لا علم ═══
 *
 * «أرسلها كما هي» يُعطي رمزًا يربطه الخادمُ بما أُقرّ عليه: نصِّ البطاقة
 * واسمِ المشتري وهاتفِه واسمِ المستلِم (`token`). فإن تبدّل شيءٌ منها بعد
 * الإقرار لم يطابق الرمزُ، ويُسأل ثانيةً — لا يمرّ إقرارٌ قديمٌ على رسالةٍ
 * لم يرها صاحبُها. ولا عمودَ له في الطلب: يُقرأ في الحمولة ويمضي معها إلى
 * نيّة الدفع كما هي (`Paymob::open` تحفظ الحمولةَ كلَّها).
 *
 * ═══ ولمن ═══
 *
 * لمتجرٍ فُتح له طرحُ سند التسليم الحراريّ (`DeliveryPaper::thermalOn`) —
 * المفتاحُ نفسُه — وفيه الإهداء (`GiftOrders::on`). وسائرُ المتاجر لا يتبدّل
 * فيها شيء.
 *
 * ═══ والكشفُ ظنٌّ لا يقين ═══
 *
 * كلماتٌ كاملةٌ لا أجزاءُ كلمات: «Ali» لا تُطابق «Alia». والاسمُ بعد توحيد
 * الهمزات والتاء المربوطة والتشكيل وحالة الحروف، وبصورته العربيّة من قاموس
 * الأسماء (`NameTransliterator::toArabic`) — فـ«Ahmed» تُطابق «أحمد». وما
 * يحمله اسمُ المستلِم لا يُعدّ: «عيد ميلاد سعيد يا سارة» لمستلِمةٍ اسمُها
 * سارة كلامٌ إليها لا توقيع. والهاتفُ بخاناته الثماني الأخيرة، بأيّ أرقامٍ
 * وفواصل كُتب. ولا يُستدعى شيءٌ من خارج النظام.
 */
final class CardIdentity
{
    /** حقلُ الإقرار في حمولة الإتمام */
    public const FIELD = 'card_identity_ack';

    /** نصُّ البطاقة من خانة الإتمام (`card`) */
    public const FROM_CARD = 'card';

    /** ونصُّها من بنود «كرت هدية» في السلّة (`GiftCardProduct`) */
    public const FROM_ITEMS = 'items';

    /** أدواتٌ في الأسماء لا تدلّ وحدها على أحد */
    private const PARTICLES = ['al', 'el', 'bin', 'ben', 'bint', 'ibn', 'abu', 'abo', 'umm', 'om', 'abd', 'abdul', 'بن', 'بنت', 'ابن', 'ابو', 'ام', 'عبد'];

    /** أيُسأل في هذا المتجر؟ — مفتاحُ الطرح، والإهداءُ مفتوح */
    public static function on(int $businessId): bool
    {
        return DeliveryPaper::thermalOn($businessId) && GiftOrders::on($businessId);
    }

    /** @return array<string, array<int, string>> */
    public static function rules(int $businessId): array
    {
        return self::on($businessId) ? [self::FIELD => ['nullable', 'string', 'max:64']] : [];
    }

    /** هديّةٌ أخفى صاحبُها اسمَه، في متجرٍ يُسأل فيه؟ */
    public static function applies(int $businessId, array $form): bool
    {
        return self::on($businessId)
            && GiftOrders::wanted($businessId, $form)
            && filter_var($form['hide_sender'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * النصُّ الذي سيقرؤه المستلِم، ومن أين جاء.
     *
     * من بنود الكرت حيث الكرتُ صنفٌ من الرفّ — المصدرُ نفسُه الذي يُكتب منه
     * `orders.card_message` (`GiftCardProduct::orderMessage`) — وإلّا من خانة
     * الإتمام. وملفُّ الكرت لا يُقرأ: لا يُدّعى ما لا يُرى.
     *
     * @param  list<array<string, mixed>>  $lines  أسطرُ `WebCheckout::quote`
     * @return array{0: string, 1: string}
     */
    public static function message(int $businessId, array $form, array $lines): array
    {
        if (GiftCardProduct::on($businessId)) {
            return [(string) GiftCardProduct::orderMessage($lines), self::FROM_ITEMS];
        }

        return [trim((string) ($form['card'] ?? '')), self::FROM_CARD];
    }

    /**
     * يُسأل الزبون قبل أن يُنشأ طلبُه أو تُفتح دفعتُه — أو يمضي.
     *
     * @param  list<array<string, mixed>>  $lines
     *
     * @throws CardIdentityUnconfirmed
     */
    public static function check(int $businessId, array $form, array $lines): void
    {
        if (! self::applies($businessId, $form)) {
            return;
        }

        [$text, $from] = self::message($businessId, $form, $lines);
        $name = (string) ($form['name'] ?? '');
        $phone = (string) ($form['phone'] ?? '');
        $recipient = (string) ($form['recipient_name'] ?? '');

        if (! self::reveals($text, $name, $phone, $recipient)) {
            return;
        }

        $token = self::token($businessId, $text, $name, $phone, $recipient);

        if (hash_equals($token, (string) ($form[self::FIELD] ?? ''))) {
            return;
        }

        throw CardIdentityUnconfirmed::offer($token, $from);
    }

    /**
     * رمزُ الإقرار — مربوطٌ بالنصّ والمشتري والمستلِم، لا يُخمَّن ولا يُنقل.
     *
     * توقيعٌ بمفتاح التطبيق: لا يصنعه المتصفّح، ولا يحمل شيئًا من الرسالة
     * يُقرأ منه.
     */
    public static function token(int $businessId, string $text, string $name, string $phone, string $recipient): string
    {
        $bound = implode("\x1f", [$businessId, $text, trim($name), trim($phone), trim($recipient)]);

        return substr(hash_hmac('sha256', $bound, (string) config('app.key')), 0, 40);
    }

    /** أتبدو الرسالةُ حاملةً اسمَ المشتري أو رقمَه؟ */
    public static function reveals(string $message, string $name, string $phone, string $recipient = ''): bool
    {
        if (trim($message) === '') {
            return false;
        }

        return self::hasPhone($message, $phone) || self::hasName($message, $name, $recipient);
    }

    private static function hasName(string $message, string $name, string $recipient): bool
    {
        $words = self::words($message);
        $mine = self::words($name);

        if ($words === [] || $mine === []) {
            return false;
        }

        // الاسمُ كاملًا متتابعًا — ولو شارك المستلِمَ بعضَه
        if (count($mine) >= 2 && self::inSequence($words, $mine)) {
            return true;
        }

        // أو كلمةٌ منه تخصّه: ليست أداة، ولا أقلَّ من ثلاثة أحرف، ولا في اسم المستلِم
        $theirs = array_merge([], ...self::words($recipient));

        foreach ($mine as $forms) {
            $forms = array_values(array_filter(
                $forms,
                fn (string $f) => mb_strlen($f) >= 3 && ! in_array($f, self::PARTICLES, true),
            ));

            if ($forms === [] || array_intersect($forms, $theirs) !== []) {
                continue;
            }

            foreach ($words as $word) {
                if (array_intersect($forms, $word) !== []) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param list<list<string>> $words @param list<list<string>> $name */
    private static function inSequence(array $words, array $name): bool
    {
        $n = count($name);

        for ($i = 0; $i + $n <= count($words); $i++) {
            $all = true;
            for ($k = 0; $k < $n; $k++) {
                if (array_intersect($words[$i + $k], $name[$k]) === []) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                return true;
            }
        }

        return false;
    }

    /**
     * كلماتُ النصّ — لكلّ كلمةٍ صورُها التي تُطابَق بها.
     *
     * الكلمةُ موحَّدةً، وبلا «ال»/«al» في أوّلها، وبصورتها العربيّة إن عرفها
     * قاموسُ الأسماء.
     *
     * @return list<list<string>>
     */
    private static function words(string $text): array
    {
        $out = [];

        foreach (preg_split('/[^\p{L}\p{N}]+/u', self::normalize($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $forms = [$word];

            if (NameTransliterator::isLatin($word) && ($ar = NameTransliterator::toArabic($word)) !== null) {
                $forms[] = self::normalize($ar);
            }

            foreach ($forms as $f) {
                foreach (['al', 'el', 'ال'] as $article) {
                    if (str_starts_with($f, $article) && mb_strlen($f) - mb_strlen($article) >= 3) {
                        $forms[] = mb_substr($f, mb_strlen($article));
                    }
                }
            }

            $out[] = array_values(array_unique($forms));
        }

        return $out;
    }

    /** حالةُ الحروف، والتشكيلُ والتطويل، والهمزاتُ والتاءُ المربوطة، وحروفٌ لاتينيّةٌ منبورة */
    private static function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}\x{200C}\x{200D}]/u', '', $text);

        return strtr($text, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ئ' => 'ي', 'ؤ' => 'و', 'ة' => 'ه',
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
        ]);
    }

    /** هاتفُ المشتري في النصّ — خاناتُه الثماني الأخيرة في سلسلةِ أرقامٍ واحدة */
    private static function hasPhone(string $message, string $phone): bool
    {
        $digits = (string) preg_replace('/\D+/', '', (string) Digits::western($phone));

        if (strlen($digits) < 7) {
            return false;
        }

        $key = substr($digits, -8);
        $digit = '[0-9\x{0660}-\x{0669}\x{06F0}-\x{06F9}]';
        preg_match_all('/'.$digit.'(?:[\s\-().\/]*'.$digit.'){6,}/u', $message, $runs);

        foreach ($runs[0] as $run) {
            if (str_contains((string) preg_replace('/\D+/', '', (string) Digits::western($run)), $key)) {
                return true;
            }
        }

        return false;
    }
}
