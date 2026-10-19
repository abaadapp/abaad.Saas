<?php

namespace App\Support;

use App\Models\Addon;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * ما اختير مع بند فاتورة العميل — يُكتب مرّةً، ويُقرأ في كلّ ورقة.
 *
 * ═══ ما كان ═══
 *
 * بندُ الفاتورة وصفٌ وكميّةٌ وثمن. فباقةٌ «كبيرة» معها شوكولاتتان وكرتٌ
 * ولونُها أحمرُ وأبيض كانت تُكتب سطرًا، والإضافةُ سطرًا آخرَ لا يُعرف لأيّ
 * صنفٍ هي، ولا موضعَ للون ولا للملاحظة — لا في الفاتورة ولا في المعاينة ولا
 * في ورقة الصندوق ولا في سند التسليم.
 *
 * ═══ وما هو ═══
 *
 * **المالُ كما كان، سطورًا مستقلّة:** الإضافةُ المدفوعةُ سطرٌ بثمنها من
 * الكتالوج وكميّتها وضريبةِ صنفها — يُحسب في `CustomerInvoices::compute` كأيّ
 * سطر، ويحمل `parent_item_id` إلى صنفه ليُعرض تحته. فلا يتغيّر حسابٌ ولا
 * يُضاف مالٌ مرّتين.
 *
 * **وما لا مالَ فيه لقطةٌ في `details`:** اسمُ البند بالإنجليزيّة حين يُعرف
 * (`label_en`)، والمقاسُ، والخياراتُ (تسميةٌ وقيمة)، وملاحظةُ الصنف،
 * والإضافاتُ المشمولةُ في ثمنه (`included`) — تُسمّى ولا تُكتب مالًا. وهي ما
 * ثمنُه في الكتالوج صفرٌ وحده: المدفوعةُ لا تُشمل (انظر `expand`).
 *
 * ═══ ومن يقرؤها ═══
 *
 * `rows()` وحدَها ترتّب البنودَ للورق: الصنفُ ثمّ ما يميّزه ثمّ إضافاتُه
 * المدفوعةُ تحته — لفاتورة A4 ومعاينتها، والنسخةِ الداخليّة، وسندِ التسليم،
 * وورقةِ التجهيز. كلٌّ بجمهوره: الداخليُّ من الخيارات لا يبلغ ورقةَ العميل.
 *
 * والفاتورةُ القديمةُ تُقرأ كما كُتبت: لا `details` لها، وبندُ الإضافة فيها
 * يُعرض تحت صنفه **فقط** إن كانا من بند الطلب نفسِه (`order_item_id`) — لا
 * يُخترع لها صاحبٌ بتشابه اسم.
 */
final class InvoiceLineDetails
{
    /** من يقرأ الورقة — الداخليُّ من الخيارات لا يبلغ إلّا الطاولة */
    public const CUSTOMER = 'customer';

    public const STAFF = 'staff';

    /** أقصى ما يُكتب مع البند — قيودُ نموذجٍ لا كتالوج */
    public const MAX_OPTIONS = 10;

    public const MAX_ADDONS = 20;

    /**
     * قواعدُ ما يُرسَل مع كلّ بند — للحفظ وللمعاينة.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.note' => ['nullable', 'string', 'max:500'],
            'items.*.options' => ['nullable', 'array', 'max:'.self::MAX_OPTIONS],
            'items.*.options.*.label' => ['nullable', 'string', 'max:60'],
            'items.*.options.*.value' => ['nullable', 'string', 'max:200'],
            'items.*.addons' => ['nullable', 'array', 'max:'.self::MAX_ADDONS],
            'items.*.addons.*.addon_id' => ['required', 'integer'],
            'items.*.addons.*.quantity' => ['nullable', 'numeric', 'gt:0', 'max:9999'],
            'items.*.addons.*.included' => ['nullable', 'boolean'],
        ];
    }

    /**
     * بنودُ النموذج سطورًا تُحسب — والمالُ والأسماءُ من القاعدة لا ممّا أُرسل.
     *
     *   - الإضافةُ المستقلّةُ كما كانت (`CustomerInvoices::addonsFromCatalog`).
     *   - المقاسُ من مقاسات الصنف نفسِه، والإضافةُ ممّا يُباع معه
     *     (`ProductAddons::for`) — كما في الصندوق والموقع.
     *   - الإضافةُ المدفوعةُ سطرٌ بثمنها من الكتالوج، تحت صنفها (`parent`).
     *   - والمشمولةُ في الثمن تُسمّى في لقطة الصنف ولا تُكتب مالًا.
     *
     * والحفظُ (`$strict`) يردّ ما لا يجوز بخطأٍ على «البنود»؛ والمعاينةُ تُسقطه
     * وترسم الباقي — ورقةٌ تُرسم وهي تُكتب لا تُردّ.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    public static function expand(int $businessId, array $items, bool $strict = true): array
    {
        $items = CustomerInvoices::addonsFromCatalog($businessId, $items, $strict);

        $productIds = collect($items)->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $variantIds = collect($items)->pluck('variant_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $addonIds = collect($items)->flatMap(fn ($l) => array_merge(
            [(int) ($l['addon_id'] ?? 0)],
            array_map(fn ($a) => (int) ($a['addon_id'] ?? 0), (array) ($l['addons'] ?? [])),
        ))->filter()->unique()->values();

        $products = $productIds->isEmpty() ? collect() : Product::where('business_id', $businessId)
            ->whereIn('id', $productIds)->get(['id', 'business_id', 'name', 'name_en'])->keyBy('id');
        $variants = $variantIds->isEmpty() ? collect() : ProductVariant::where('business_id', $businessId)
            ->whereIn('id', $variantIds)->get(['id', 'product_id', 'name', 'name_en'])->keyBy('id');
        $addons = $addonIds->isEmpty() ? collect() : Addon::where('business_id', $businessId)
            ->whereIn('id', $addonIds)->get()->keyBy('id');

        $attached = collect($items)->contains(fn ($l) => ! empty($l['addons']));
        $catalog = $attached ? Addon::where('business_id', $businessId)->orderBy('id')->get() : collect();
        $map = $attached ? ProductAddons::map($businessId) : [];

        $out = [];

        foreach (array_values($items) as $n => $line) {
            $group = 'l'.$n;
            $product = ! empty($line['product_id']) ? $products->get((int) $line['product_id']) : null;
            $details = [];

            /* ═══ المقاسُ — من مقاسات هذا الصنف وحده ═══ */
            $variant = null;
            if (! empty($line['variant_id'])) {
                $variant = $variants->get((int) $line['variant_id']);

                if (! $variant || ! $product || (int) $variant->product_id !== (int) $product->id) {
                    self::refuse($strict, __('هذا المقاس ليس من مقاسات هذا الصنف.'));
                    $variant = null;
                }
            }

            if ($variant) {
                $details['variant'] = ['id' => (int) $variant->id, 'name' => (string) $variant->name, 'name_en' => $variant->name_en ?: null];
            }

            /*
             * ═══ واسمُ البند بلغتيه — حين يكون اسمَ الكتالوج لا نصًّا كُتب بيد ═══
             *
             * وصفٌ كتبه التاجرُ بيده («باقة حفل التخرّج») يُطبع كما كتبه في
             * اللغتين: لا تُخترع له ترجمة. وما كان اسمَ الصنف (ومقاسه) يُحفظ
             * معه اسمُه الإنجليزيّ من الكتالوج — لقطةً ساعةَ الكتابة.
             */
            if ($product) {
                $auto = trim((string) $product->name).($variant ? ' — '.$variant->name : '');
                $written = trim((string) ($line['description'] ?? ''));

                if ($written === '' || $written === trim((string) $product->name) || $written === $auto) {
                    $line['description'] = $auto;
                    $en = self::english($product->name, $product->name_en, $variant?->name, $variant?->name_en);
                    if ($en !== null) {
                        $details['label_en'] = $en;
                    }
                }
            } elseif (! empty($line['addon_id']) && ($a = $addons->get((int) $line['addon_id'])) && filled($a->name_en)) {
                $details['label_en'] = (string) $a->name_en;
            }

            /* ═══ وملاحظةُ الصنف وخياراتُه — نصٌّ كتبه صاحبُه، يُطبع كما كُتب ═══ */
            $note = trim((string) ($line['note'] ?? ''));
            if ($note !== '') {
                $details['note'] = $note;
            }

            $options = self::options((array) ($line['options'] ?? []));
            if ($options !== []) {
                $details['options'] = $options;
            }

            /* ═══ والإضافاتُ مع الصنف: ممّا يُباع معه، والمدفوعةُ سطورٌ تحته ═══ */
            $children = [];
            $included = [];
            $allowed = null;

            foreach ((array) ($line['addons'] ?? []) as $pick) {
                $addon = $addons->get((int) ($pick['addon_id'] ?? 0));

                if (! $product) {
                    self::refuse($strict, __('الإضافةُ مع صنفٍ من الكتالوج — أو أضفها بندًا مستقلًّا.'));

                    continue;
                }

                $allowed ??= ProductAddons::for($product, $catalog, $map)->pluck('id')->map(fn ($i) => (int) $i)->all();

                if (! $addon || ! in_array((int) $addon->id, $allowed, true)) {
                    self::refuse($strict, __('هذه الإضافة لا تُباع مع هذا الصنف.'));

                    continue;
                }

                $qty = round((float) ($pick['quantity'] ?? 1) ?: 1.0, 3);

                /*
                 * ═══ و«ضمن السعر» لما ثمنُه في الكتالوج صفرٌ وحده ═══
                 *
                 * لا قاعدةَ في الكتالوج تقول إنّ صنفًا يشمل إضافةً بثمن، ولا فعلَ
                 * يأذن به. فلو قُبل العلَمُ على مدفوعةٍ لأعطاها كاتبُ الفاتورة
                 * مجّانًا بلا خصمٍ يظهر في الورقة ولا في التقارير — ونقص الإيرادُ
                 * وضريبتُه بلا أثر. وهو عينُ ما يمنعه `addonsFromCatalog`: «الخصمُ
                 * تخفيضٌ ظاهرٌ في الورقة لا ثمنٌ مزوَّر». فمن أراد إعطاءها أضافها
                 * بثمنها وكتب على الصنف خصمًا ظاهرًا.
                 */
                if (! empty($pick['included']) && round((float) $addon->price, 3) > 0) {
                    self::refuse($strict, __('إضافةٌ بثمنٍ لا تُشمل في سعر الصنف — أضفها بثمنها، أو اكتب على الصنف خصمًا ظاهرًا.'));

                    continue;
                }

                if (! empty($pick['included'])) {
                    $included[] = ['addon_id' => (int) $addon->id, 'name' => (string) $addon->name, 'name_en' => $addon->name_en ?: null, 'quantity' => $qty];

                    continue;
                }

                $children[] = array_filter([
                    'product_id' => null,
                    'addon_id' => (int) $addon->id,
                    'description' => (string) $addon->name,
                    'quantity' => $qty,
                    'unit_price' => round((float) $addon->price, 3),
                    'discount' => 0.0,
                    // والإضافةُ بضريبة صنفها — كما في الطلب (`fromOrder`)
                    'tax_rate' => $line['tax_rate'] ?? null,
                    'details' => filled($addon->name_en) ? ['label_en' => (string) $addon->name_en] : null,
                    'parent' => $group,
                ], fn ($v) => $v !== null);
            }

            if ($included !== []) {
                $details['included'] = $included;
            }

            unset($line['variant_id'], $line['note'], $line['options'], $line['addons']);

            $out[] = $line + ['details' => $details !== [] ? $details : null, 'group' => $group];
            array_push($out, ...$children);
        }

        return $out;
    }

    /**
     * سطرُ بندِ طلبٍ في الفاتورة — ما يميّزه من لقطة البيع، بلا مال.
     *
     * ملاحظةُ البند وخياراتُ الطلب المخصَّص (بداخليّها معلَّمًا). والمقاسُ
     * والمشمولُ في الثمن في الوصف أصلًا (`CustomerInvoices::orderItemLines`)،
     * فلا يُكتبان مرّتين. ولا اسمَ إنجليزيًّا: بندُ الطلب لا يحفظه، ولا يُقرأ
     * من كتالوج اليوم.
     *
     * @return array<string, mixed>|null
     */
    public static function fromOrderItem(OrderItem $item): ?array
    {
        $details = [];

        $note = trim((string) $item->note);
        if ($note !== '') {
            $details['note'] = $note;
        }

        if ($item->isCustom()) {
            $fields = CustomArrangement::view($item->custom_details)['fields'];
            $options = array_values(array_filter(array_map(fn ($f) => [
                'label' => (string) $f['label'],
                'value' => implode(' + ', array_filter($f['values'], fn ($v) => trim((string) $v) !== '')),
                'internal' => (bool) $f['internal'],
            ], $fields), fn ($o) => $o['value'] !== ''));

            if ($options !== []) {
                $details['options'] = $options;
            }
        }

        return $details !== [] ? $details : null;
    }

    /** لقطةُ إضافة الطلب: اسمُها الإنجليزيّ كما حُفظ ساعةَ البيع */
    public static function fromOrderAddon(OrderItemAddon $addon): ?array
    {
        return filled($addon->name_en) ? ['label_en' => (string) $addon->name_en] : null;
    }

    /**
     * البنودُ مرتّبةً للورق — الصنفُ، ثمّ ما يميّزه، ثمّ إضافاتُه المدفوعة.
     *
     * @param  iterable<CustomerInvoiceItem>  $items
     * @return list<array{item: CustomerInvoiceItem, child: bool, label: string, subs: list<array{label: ?string, text: string, internal: bool}>, included: list<array{name: string, qty: string}>}>
     */
    public static function rows(iterable $items, string $audience = self::CUSTOMER): array
    {
        $list = collect($items)->values();
        $parents = self::parents($list);
        $kids = [];

        foreach ($parents as $child => $parent) {
            $kids[$parent][] = $child;
        }

        $rows = [];

        foreach ($list as $i => $line) {
            if (isset($parents[$i])) {
                continue;
            }

            $rows[] = self::row($line, false, $audience);

            foreach ($kids[$i] ?? [] as $j) {
                $rows[] = self::row($list[$j], true, $audience);
            }
        }

        return $rows;
    }

    /**
     * بنودُ الفاتورة لورقة التجهيز — بلا مالٍ بحال.
     *
     * الصنفُ وكميّتُه، وتحته إضافاتُه (المدفوعةُ والمشمولةُ معًا، أسماءً
     * وكميّات)، وخياراتُه كلُّها (والداخليُّ معلَّم)، وملاحظتُه. ولا علامةَ
     * محفوظة: مهمّةُ الفاتورة تُؤشَّر بقلمٍ على الورق.
     *
     * @return list<array<string, mixed>>
     */
    public static function prepLines(CustomerInvoice $invoice): array
    {
        $out = [];

        foreach (self::rows($invoice->items, self::STAFF) as $row) {
            $line = $row['item'];

            if ($row['child']) {
                $k = array_key_last($out);
                if ($k !== null) {
                    $out[$k]['addons'][] = ['key' => 'line:'.$line->id, 'name' => $row['label'], 'qty' => DocumentPaper::qty($line->quantity), 'checked' => null];

                    continue;
                }
            }

            $details = (array) ($line->details ?? []);

            $out[] = [
                'key' => 'line:'.$line->id,
                'name' => ($line->addon_id !== null && $line->product_id === null ? '+ ' : '').$row['label'],
                'variant' => null,
                'qty' => DocumentPaper::qty($line->quantity),
                'note' => $details['note'] ?? null,
                'note_label' => __('ملاحظة المنتج'),
                'checked' => null,
                'addons' => array_map(fn ($a) => ['key' => null, 'name' => $a['name'], 'qty' => $a['qty'], 'checked' => null], $row['included']),
                'components' => [],
                'options' => array_map(fn ($o) => ['label' => (string) $o['label'], 'value' => (string) $o['value'], 'internal' => (bool) ($o['internal'] ?? false)], (array) ($details['options'] ?? [])),
            ];
        }

        return $out;
    }

    /* ═══════════════════════ أجزاء ═══════════════════════ */

    /**
     * لأيّ سطرٍ يتبع كلُّ سطر — بمفتاحه، أو بمجموعته قبل الحفظ، أو ببند الطلب.
     *
     * والثالثُ للفواتير المولودة من الطلبات قبل هذا الربط: أوّلُ سطرٍ لبند
     * الطلب صنفُه، وما بعده إضافاتُه (`orderItemLines` تكتبها كذلك). علاقةٌ
     * محفوظةٌ لا تخمينٌ من اسم.
     *
     * @param  Collection<int, CustomerInvoiceItem>  $list
     * @return array<int, int> موضعُ التابع ← موضعُ صنفه
     */
    private static function parents(Collection $list): array
    {
        $byId = [];
        $byGroup = [];
        $firstOfOrderItem = [];

        foreach ($list as $i => $line) {
            if ($line->id) {
                $byId[(int) $line->id] = $i;
            }
            if (filled($line->getAttribute('group'))) {
                $byGroup[(string) $line->getAttribute('group')] = $i;
            }
        }

        $parents = [];

        foreach ($list as $i => $line) {
            $p = null;

            if ($line->parent_item_id && isset($byId[(int) $line->parent_item_id])) {
                $p = $byId[(int) $line->parent_item_id];
            } elseif (filled($line->getAttribute('parent')) && isset($byGroup[(string) $line->getAttribute('parent')])) {
                $p = $byGroup[(string) $line->getAttribute('parent')];
            } elseif ($line->order_item_id && $line->parent_item_id === null) {
                $first = $firstOfOrderItem[(int) $line->order_item_id] ?? null;

                if ($first === null) {
                    $firstOfOrderItem[(int) $line->order_item_id] = $i;
                } elseif ($line->product_id === null) {
                    $p = $first;
                }
            }

            if ($p !== null && $p !== $i && ! isset($parents[$p])) {
                $parents[$i] = $p;
            }
        }

        return $parents;
    }

    /** سطرٌ للورق — اسمُه بلغة الورقة، وما يميّزه بجمهوره */
    private static function row(CustomerInvoiceItem $line, bool $child, string $audience): array
    {
        $details = (array) ($line->details ?? []);
        $locale = app()->getLocale();
        $subs = [];

        if (filled($details['note'] ?? null)) {
            $subs[] = ['label' => __('ملاحظة المنتج'), 'text' => (string) $details['note'], 'internal' => false];
        }

        foreach ((array) ($details['options'] ?? []) as $o) {
            $internal = (bool) ($o['internal'] ?? false);

            if ($internal && $audience !== self::STAFF) {
                continue;
            }

            $subs[] = ['label' => (string) $o['label'], 'text' => (string) $o['value'], 'internal' => $internal];
        }

        return [
            'item' => $line,
            'child' => $child,
            'label' => ProductName::display((string) $line->description, $details['label_en'] ?? null, $locale),
            'subs' => $subs,
            'included' => array_map(fn ($a) => [
                'name' => ProductName::display($a['name'] ?? '', $a['name_en'] ?? null, $locale),
                'qty' => DocumentPaper::qty($a['quantity'] ?? 1),
            ], (array) ($details['included'] ?? [])),
        ];
    }

    /**
     * الخياراتُ كما كُتبت — تسميةٌ وقيمة، وما فرغ يسقط.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array{label: string, value: string, internal: bool}>
     */
    private static function options(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            $value = trim((string) ($row['value'] ?? ''));

            if ($value === '') {
                continue;
            }

            $out[] = ['label' => $label !== '' ? $label : __('خيار'), 'value' => $value, 'internal' => false];
        }

        return array_slice($out, 0, self::MAX_OPTIONS);
    }

    /** اسمُ البند بالإنجليزيّة — إن عُرف منه شيءٌ بالإنجليزيّة، وإلّا لا شيء */
    private static function english(?string $name, ?string $nameEn, ?string $variant, ?string $variantEn): ?string
    {
        if (blank($nameEn) && blank($variantEn)) {
            return null;
        }

        $label = trim((string) ($nameEn ?: $name));

        return $variant !== null ? $label.' — '.trim((string) ($variantEn ?: $variant)) : $label;
    }

    private static function refuse(bool $strict, string $message): void
    {
        if ($strict) {
            throw new RuntimeException($message);
        }
    }
}
