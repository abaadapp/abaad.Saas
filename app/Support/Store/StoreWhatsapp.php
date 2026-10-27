<?php

namespace App\Support\Store;

/**
 * رسالةُ واتساب بحسب الصفحة — يُسأل التاجرُ عمّا يقف عليه الزائر.
 *
 * كان الزرُّ العائم يحمل رسالةً واحدةً في كلّ صفحة. فصار يحمل ما يقف عليه
 * الزائر باسمه كما في القاعدة — القسمَ والمجموعةَ والصنفَ وعبارةَ البحث —
 * ورابطَه على النطاق الذي جاء منه: المتجرُ بنطاقه الخاصّ يُسأل برابطه هو.
 * والرسالةُ تُكتب في المحادثة ولا تُرسَل — الزائرُ يرسلها أو يمحوها.
 *
 * ولا يُكتب فيها ما يخصّ الزائر: لا محتوى سلّته، ولا رقمُ طلبه، ولا رابطُ
 * دفعه. فالسلّةُ والإتمامُ والتأكيدُ والدفعُ يسألون بلا رابط. وما بلا اسمٍ
 * (قسمٌ لا يُعرف، مجموعةٌ بلا عنوان) يُسأل بالرسالة العامّة.
 */
final class StoreWhatsapp
{
    /** صفحاتٌ رابطُها أو ما فيها للزائر وحده — تُسأل بلا رابط */
    private const PRIVATE = ['cart', 'checkout', 'done', 'paying'];

    /** وما يُبقى من استعلام الصفحة — ما يعيّن ما عليه الزائر، لا غيرُه */
    private const KEEP = ['cat', 'q', 'view', StoreCollections::QUERY];

    /** مكانُ الخيار في رابط الصنف — يملؤه المتصفّحُ بما اختاره الزائر (`product.blade.php`) */
    public const OPTION = '__OPTION__';

    /**
     * رقمُ «بيانات المتجر» أرقامًا وحدها — لمن شُغّل له الزرّ، وإلّا فراغ.
     *
     * @param  array<string, mixed>  $identity
     */
    public static function number(int $bid, array $identity): string
    {
        $listed = in_array($bid, array_map('intval', (array) config('storefront.ribbon_floating_whatsapp_businesses', [])), true);

        return $listed ? (string) preg_replace('/\D/', '', (string) ($identity['whatsapp'] ?? '')) : '';
    }

    /** رابطُ المحادثة برسالتها — يفتح التطبيقَ على الهاتف وWhatsApp Web على الحاسوب */
    public static function url(string $number, string $text): string
    {
        return 'https://wa.me/'.$number.'?text='.rawurlencode($text);
    }

    /**
     * رسالةُ الصفحة.
     *
     * @param  array<string, string>  $t  نصوصُ الواجهة بلغة الزائر (`RibbonTexts::for`)
     * @param  array{type: string, value: string|int}|null  $here  ما يقف عليه (`RibbonController::here`)
     * @param  array<string, mixed>  $data  بياناتُ الصفحة كما تُرسم
     */
    public static function page(array $t, string $store, string $current, ?array $here, array $data): string
    {
        if (in_array($current, self::PRIVATE, true)) {
            return self::say($t[$current === 'cart' ? 'waAskCart' : 'waFloatText'], $store);
        }

        $q = mb_substr(trim((string) ($data['q'] ?? '')), 0, 100);
        $at = $here['type'] ?? null;

        [$key, $name] = match (true) {
            $current === 'p' => ['waAskProduct', $data['product']['name'] ?? ''],
            $q !== '' => ['waAskSearch', $q],
            $at === 'collection' => ['waAskCollection', $data['collection']['title'] ?? ''],
            $at === 'category' => ['waAskCategory', $data['catName'] ?? ''],
            $at === 'page' => ['waAskPage', $data['customPage']['title'] ?? ''],
            $current === 'shop' => ['waAskShop', null],
            default => ['waAskHome', null],
        };

        $text = match (true) {
            $name === null => self::say($t[$key], $store),
            trim((string) $name) !== '' => self::say($t[$key], $store, trim((string) $name)),
            default => self::say($t['waAskHome'], $store),
        };

        return $text."\n".self::here();
    }

    /** رسالةُ صنفٍ — باسمه ورابطه، وبالخيار الذي اختاره الزائر إن اختار */
    public static function product(array $t, string $name, string $link, string $option = ''): string
    {
        return self::say($t['waAskProduct'], '', $name)
            .($option !== '' ? "\n".$t['size'].': '.$option : '')
            ."\n".$link;
    }

    /** رابطُ صنفٍ على النطاق الذي جاء منه الزائر — و`$base` فراغُ النطاق الخاصّ أو `/s/{slug}` */
    public static function productLink(string $base, int $id): string
    {
        return request()->getSchemeAndHttpHost().$base.'/p/'.$id;
    }

    /** عنوانُ ما عليه الزائر — على النطاق الذي جاء منه، وبما يعيّنه من الاستعلام وحده */
    private static function here(): string
    {
        $keep = array_filter(
            array_intersect_key(request()->query(), array_flip(self::KEEP)),
            fn ($v) => is_string($v) && $v !== '',
        );

        return request()->url().($keep === [] ? '' : '?'.http_build_query($keep, '', '&', PHP_QUERY_RFC3986));
    }

    private static function say(string $text, string $store, string $name = ''): string
    {
        return strtr($text, [':store' => $store, ':name' => $name]);
    }
}
