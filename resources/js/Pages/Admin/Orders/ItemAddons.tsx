import { TableCell, TableRow } from '@/Components/ui/table';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';

/**
 * إضافاتُ البند تحته في صفحة الطلب — بقاعدة الورق نفسِها (`ItemAddons`).
 *
 * كانت الصفحةُ تكتب «باقة ورد · 20.000 · 22.000» ولا تسمّي ما في الفرق:
 * الإضافةُ تصل في بيانات الطلب ولا تُرسم. والشريطُ يسمّيها، والفاتورةُ صارت
 * تسمّيها — فهذه لا تبقى وحدها عمياء عمّا بِيع.
 *
 *   - **`priced`**: جُبيت فوق ثمن البند — صفٌّ تابعٌ لكلّ إضافةٍ بكميّتها
 *     وثمنها ومجموعها، ومبلغُ البند ثمنُه وحده (`own_total`). فيجمع عمودُ
 *     «الإجمالي» إلى المجموع الفرعيّ كما هو، ولا يُكتب المالُ مرّتين.
 *   - **`bundled`**: لقطاتٌ لا تطابق ما جُبي — صفٌّ واحدٌ بما جُبي يسمّي ما فيه.
 *   - **`included`**: داخل ثمن البند — تُسمّى تحت اسمه بلا مال.
 *
 * وهذه شاشةُ الموظّف لا ورقةُ الزبون: الإضافةُ المأخوذةُ من رفّ صنفٍ يُسمّى
 * صنفُها ورفُّ بوتيكه (`from`) — بلا تكلفةٍ ولا ما للبوتيك.
 */

export type ItemAddon = {
    name: string;
    qty: number;
    unit?: number;
    total: number;
    from?: { product: string; boutique: string | null } | null;
};

export type AddonsMode = 'none' | 'priced' | 'bundled' | 'included';

export type LineWithAddons = {
    total: number;
    own_total?: number;
    addons?: ItemAddon[];
    addons_mode?: AddonsMode;
    addons_total?: number;
};

/** مبلغُ البند في عموده: ثمنُه وحده إن كُتبت إضافاتُه صفوفًا تحته — وإلّا كما كان */
export function lineAmount(line: LineWithAddons): number {
    return line.addons_mode === 'priced' || line.addons_mode === 'bundled' ? (line.own_total ?? line.total) : line.total;
}

function From({ from }: { from?: ItemAddon['from'] }) {
    const t = useTranslate();
    if (!from) return null;

    return (
        <span className="block text-[11px] text-[#9ca3af]" data-testid="addon-from">
            {from.boutique
                ? t('من رفّ :boutique: :product', { boutique: from.boutique, product: from.product })
                : t('يُخصم من: :product', { product: from.product })}
        </span>
    );
}

/** ما داخل ثمن البند — تحت اسمه، بلا مال */
export function IncludedAddons({ line }: { line: LineWithAddons }) {
    const t = useTranslate();
    if (line.addons_mode !== 'included' || !line.addons?.length) return null;

    return (
        <ul className="mt-1 space-y-0.5">
            {line.addons.map((a, k) => (
                <li key={k} className="text-[12px] font-normal text-[#4b4b4b]" data-testid="addon-included">
                    + <bdi>{a.name}</bdi> × <span dir="ltr">{number(a.qty)}</span>
                    <span className="text-[#6b7280]"> — {t('ضمن السعر')}</span>
                    <From from={a.from} />
                </li>
            ))}
        </ul>
    );
}

/** صفوفُ الإضافات المجبيّة تحت بندها — كلٌّ بماله مرّةً واحدة */
export function AddonRows({ line, money, extraCell }: { line: LineWithAddons; money: (v: number) => string; extraCell: boolean }) {
    const t = useTranslate();
    const rows = line.addons ?? [];
    const sub = 'border-t-0 py-1.5 text-[13px] text-[#4b4b4b]';

    if (line.addons_mode === 'priced') {
        return (
            <>
                {rows.map((a, k) => (
                    <TableRow key={k} className="hover:bg-transparent" data-testid="addon-row">
                        <TableCell className={`${sub} ps-8`}>
                            + <bdi>{a.name}</bdi>
                            <From from={a.from} />
                        </TableCell>
                        <TableCell className={`${sub} text-end tabular-nums`}>{number(a.qty)}</TableCell>
                        <TableCell className={`${sub} text-end tabular-nums`}>{a.unit !== undefined ? money(a.unit) : ''}</TableCell>
                        <TableCell className={`${sub} text-end tabular-nums`}>{money(a.total)}</TableCell>
                        {extraCell && <TableCell className={sub} />}
                    </TableRow>
                ))}
            </>
        );
    }

    if (line.addons_mode === 'bundled') {
        const names = rows.map((a) => (a.qty > 1 ? `${a.name} × ${a.qty}` : a.name)).join('، ');

        return (
            <TableRow className="hover:bg-transparent" data-testid="addon-row">
                <TableCell className={`${sub} ps-8`}>
                    + {t('إضافات')}: <bdi>{names}</bdi>
                </TableCell>
                <TableCell className={sub} />
                <TableCell className={sub} />
                <TableCell className={`${sub} text-end tabular-nums`}>{money(line.addons_total ?? 0)}</TableCell>
                {extraCell && <TableCell className={sub} />}
            </TableRow>
        );
    }

    return null;
}
