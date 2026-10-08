<?php

namespace App\Support;

use App\Models\Addon;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Validation\ValidationException;

/**
 * ملاحظاتُ العميل وتعديلُ أصناف الفاتورة — ميزةٌ يفتحها مديرُ المنصّة لنشاطٍ بعينه.
 *
 * ═══ خمسُ ملاحظاتٍ لا واحدة ═══
 *
 *   ملاحظةُ المنتج       `order_items.note`        يكتبها العميل تحت الصنف
 *   ملاحظاتُ الطلب       `orders.notes`            يكتبها العميل في الإتمام
 *   تعليماتُ التوصيل     `orders.delivery_notes`   موعدُ الموقع أو ما يكتبه المحلّ
 *   ملاحظاتٌ داخليّة     `orders.internal_notes`   للمحلّ وحده، لا تُطبع للعميل
 *   رسالةُ الكرت         `orders.card_message`     ونصُّ بند الكرت (`GiftCardProduct`)
 *
 * وبندُ كرت الهدية يحمل في `order_items.note` رسالتَه لا ملاحظةَ منتج — يُعرف
 * بعلامة صنفه (`GiftCardProduct::paperNote`) ولا يمسّه شيءٌ هنا.
 *
 * ═══ ونصُّ العميل بالإنجليزيّة ═══
 *
 * عنوانُ الحقل بلغة الموقع، والنصُّ نفسُه بالإنجليزيّة: يقرؤه فريقٌ يجهّز
 * بالإنجليزيّة. حروفٌ لاتينيّة وأرقامٌ ومسافاتٌ وعلامات — ولا حرفَ عربيًّا ولا
 * حرفًا من أبجديّةٍ أخرى. والموظّفُ داخل النظام لا يُقيَّد بها.
 */
final class NotesAndEdits
{
    public const PRODUCT_NOTE_MAX = 500;

    public const ORDER_NOTES_MAX = 1000;

    /**
     * ما ليس إنجليزيًّا: حرفٌ غيرُ A-Z، أو شيءٌ من الكتابة العربيّة (حروفُها
     * وأرقامُها وعلاماتُها). والقاعدةُ نفسُها في الصفحة (`ribbon-notes.js`).
     */
    public const NOT_ENGLISH = '/[^\P{L}A-Za-z]|\p{Arabic}/u';

    public static function on(int $businessId): bool
    {
        return (bool) Business::whereKey($businessId)->value('order_notes_and_edits_enabled');
    }

    public static function isEnglish(string $text): bool
    {
        return preg_match(self::NOT_ENGLISH, $text) !== 1;
    }

    public static function englishOnlyMessage(): string
    {
        return __('يرجى كتابة الملاحظة باللغة الإنجليزية فقط.');
    }

    /**
     * ملاحظةُ منتجٍ من الموقع — تُفحص وتُردّ، أو `null`.
     *
     * والميزةُ مغلقة: لا ملاحظةَ لصنفٍ عاديّ، كما كان قبلها.
     */
    public static function productNote(int $businessId, mixed $raw): ?string
    {
        if (! self::on($businessId)) {
            return null;
        }

        $text = is_scalar($raw) ? trim(str_replace("\r\n", "\n", (string) $raw)) : '';

        if ($text === '') {
            return null;
        }

        if (! self::isEnglish($text)) {
            throw ValidationException::withMessages(['items' => self::englishOnlyMessage()]);
        }

        if (mb_strlen($text) > self::PRODUCT_NOTE_MAX) {
            throw ValidationException::withMessages(['items' => __('ملاحظة المنتج أطول من :max حرفًا.', ['max' => self::PRODUCT_NOTE_MAX])]);
        }

        return $text;
    }

    /**
     * قاعدةُ «ملاحظات الطلب» في الإتمام — ولا قاعدةَ حين تُغلق الميزة،
     * فالحقلُ لا يبلغ الطلبَ أصلًا (`validate()` لا تُرجع إلّا ما له قاعدة).
     *
     * @return array<string, array<int, string>>
     */
    public static function checkoutRules(int $businessId): array
    {
        return self::on($businessId)
            ? ['notes' => ['nullable', 'string', 'max:'.self::ORDER_NOTES_MAX, 'not_regex:'.self::NOT_ENGLISH]]
            : [];
    }

    /**
     * ما تحتاجه شاشةُ الطلب لتعرض الأزرار — أو `null` لنشاطٍ لم تُفتح له.
     *
     * والأزرارُ تُخفى لمن لا يملك «order.edit» وحسب؛ الحكمُ في الخادم
     * (`OrderEditController`). والإضافةُ والاستبدالُ في يوم البيع وحده، أمّا
     * الملاحظةُ — نصٌّ لا مال — فلا تُقفل بانتهاء اليوم.
     *
     * @return array{lines: bool, notes: bool, catalog: list<array<string, mixed>>}|null
     */
    public static function screen(int $businessId, bool $mayEdit, bool $sameDay, bool $cancelled = false): ?array
    {
        if (! self::on($businessId)) {
            return null;
        }

        $lines = $mayEdit && $sameDay && ! $cancelled;

        return [
            'lines' => $lines,
            'notes' => $mayEdit && ! $cancelled,
            'catalog' => $lines ? self::catalog($businessId) : [],
        ];
    }

    /**
     * أصنافُ المتجر التي تُضاف إلى فاتورة — بمقاساتها وإضافاتها المسموحة.
     *
     * والأسعارُ للعرض في الحوار وحده: الخادمُ يسعّر من القاعدة
     * (`OrderCorrection::addLine`). ولا كرتَ هدية (رسالتُه من صفحته)، ولا
     * صنفًا موقوفًا.
     *
     * @return list<array<string, mixed>>
     */
    public static function catalog(int $businessId): array
    {
        $variants = ProductVariant::where('business_id', $businessId)
            ->where('active', true)->orderBy('sort_order')->orderBy('id')->get()->groupBy('product_id');
        $addonMap = ProductAddons::map($businessId);
        $allAddons = Addon::where('business_id', $businessId)->orderBy('id')->get();

        return Product::where('business_id', $businessId)
            ->where('active', true)
            ->where(fn ($q) => $q->whereNull('is_gift_card')->orWhere('is_gift_card', false))
            ->orderBy('name')->get()
            ->map(fn ($p) => [
                'id' => (int) $p->id,
                'name' => Demo::ln($p->name, $p->name_en),
                'price' => (float) $p->sellingPrice(),
                'variants' => ($variants[$p->id] ?? collect())->map(fn ($v) => [
                    'id' => (int) $v->id,
                    'name' => Demo::ln($v->name, $v->name_en),
                    'price' => (float) $v->price,
                ])->values()->all(),
                'addons' => ProductAddons::for($p, $allAddons, $addonMap)
                    ->filter(fn ($a) => (bool) $a->active)
                    ->map(fn ($a) => ['id' => (int) $a->id, 'name' => Demo::ln($a->name, $a->name_en), 'price' => (float) $a->price])
                    ->values()->all(),
            ])->values()->all();
    }

    /** @return array<string, string> */
    public static function checkoutMessages(): array
    {
        return [
            'notes.not_regex' => self::englishOnlyMessage(),
            'notes.max' => __('ملاحظات الطلب أطول من :max حرفًا.', ['max' => self::ORDER_NOTES_MAX]),
        ];
    }
}
