import { useTranslate } from '@/lib/i18n';
import { money as formatMoney } from '@/lib/format';
import { Badge } from '@/Components/ui/badge';
import { Card } from '@/Components/ui/card';
import { cn } from '@/lib/utils';
import type { Currency } from '@/types';
import type { VariantRow } from './LineDetails';

/**
 * صنفُ البوتيك في فاتورة العميل — متى يدخل حسابَه، ومن يتحمّل خصمَه.
 *
 * الحسابُ في الخادم (`BoutiqueInvoiceDues`)؛ وهنا خبرٌ لكاتب الورقة قبل أن يصدرها.
 * والنسبةُ لا تصل إلّا لمن يقرأ مالَ البوتيكات، فتحذيرُ الخصم لا يُرسم لغيره.
 */

export const round3 = (v: number) => Math.round(v * 1000) / 1000;

/** سعرُ بطاقة الصنف أو مقاسه المختار — `null` حين لا يُعرف */
export function listedPrice(product: { price: number; variants?: VariantRow[] } | null, variantId?: string): number | null {
    if (!product) return null;
    const variant = variantId ? product.variants?.find((v) => String(v.id) === variantId) : undefined;

    return variant ? variant.price : product.price;
}

/** كم جاوز خصمُ البند حصّةَ المتجر منه (عمولتَه) — وصفرٌ حين لا يجاوزها أو لا تُعرف النسبة */
export function discountOverShare(gross: number, discount: number, rate: number | null): number {
    if (rate === null || discount <= 0) return 0;

    return Math.max(0, round3(Math.min(discount, gross) - round3((gross * rate) / 100)));
}

export function BoutiqueLineNote({ boutique, rate, gross, discount, price, listed, money }: {
    boutique: string; rate: number | null; gross: number; discount: number; price: number; listed: number | null; money: (v: number) => string;
}) {
    const t = useTranslate();
    const over = discountOverShare(gross, discount, rate);

    return (
        <div className="mt-1 space-y-0.5 px-1 text-[12px] leading-5">
            <p data-testid="line-boutique-note" className="text-[#6b7280]">
                {t('صنف بوتيك «:name»: يدخل حسابه حين تُسدَّد الفاتورة كاملة. وخصم البند على المتجر.', { name: boutique })}
            </p>
            {over > 0 && (
                <p data-testid="line-boutique-discount-over" className="text-[#b91c1c]">
                    {t('الخصم يتجاوز حصة المتجر من هذا البند: يتحمّل المتجر :over زيادة عليها، ومستحق البوتيك لا ينقص.', { over: money(over) })}
                </p>
            )}
            {listed !== null && price > 0 && round3(price) < round3(listed) && (
                <p data-testid="line-boutique-under-list" className="text-[#b45309]">
                    {t('السعر أقل من سعر الصنف (:listed): مستحق البوتيك يُحسب من السعر المكتوب. للتخفيض على حساب المتجر اكتبه خصمًا.', { listed: money(listed) })}
                </p>
            )}
        </div>
    );
}

export interface BoutiqueShare {
    id: number; item_id: number; boutique_id: number; boutique: string; name: string;
    /** صنفٌ منفرد، أو قطعةٌ داخل باقةٍ بثمنها كاملًا — وباقتُها */
    kind?: 'line' | 'piece'; parent?: string | null;
    quantity: number; returned: number; unit_price: number; gross: number; discount: number;
    rate: number; commission: number; net: number; under_list: number | null;
    state: 'awaiting' | 'pending' | 'issued' | 'paid' | 'returned' | 'void';
    sheet: string | null;
}

const STATE: Record<BoutiqueShare['state'], [string, 'info' | 'neutral' | 'warning' | 'success' | 'danger']> = {
    awaiting: ['بانتظار التحصيل', 'info'],
    pending: ['لم تُسوَّ', 'neutral'],
    issued: ['في ورقة لم تُسدَّد', 'warning'],
    paid: ['سُدِّدت', 'success'],
    returned: ['مرتجعة كاملة', 'warning'],
    void: ['ملغاة', 'danger'],
};

/** نصيبُ كلّ بوتيكٍ في هذه الفاتورة — لمن يقرأ مالَ البوتيكات، ولا يُطبع على الورقة */
export function BoutiqueSharesCard({ shares, currency }: { shares: BoutiqueShare[]; currency: Currency }) {
    const t = useTranslate();
    if (shares.length === 0) return null;

    const m = (v: number) => formatMoney(v, currency);
    const live = shares.filter((s) => s.state !== 'void');
    const sum = (k: 'commission' | 'net' | 'discount') => round3(live.reduce((n, s) => n + s[k], 0));
    const afterDiscount = round3(sum('commission') - sum('discount'));

    return (
        <Card className="p-4" data-testid="boutique-shares">
            <h3 className="mb-1 text-[13px] font-bold">{t('حصص البوتيكات في هذه الفاتورة')}</h3>
            <p className="mb-2 text-[12px] leading-5 text-[#6b7280]">
                {t('كما حُفظت يوم الإصدار. تدخل حساب البوتيك حين تُسدَّد الفاتورة كاملة، ولا تُطبع للعميل.')}
            </p>
            {shares.map((s) => (
                <div key={s.id} className="flex flex-wrap items-baseline justify-between gap-2 border-t border-[var(--ui-border,#e8e8e8)] py-2 text-[13px]">
                    <span className="min-w-0">
                        {s.name} <span className="text-[#6b7280]">— {s.boutique}</span>
                        <span className="block text-[12px] text-[#6b7280]">
                            {s.kind === 'piece'
                                ? t('قطعة في «:parent» × :n بثمنها كاملًا', { parent: s.parent ?? '', n: String(s.quantity) })
                                : t('عمولة المتجر :rate% = :commission', { rate: String(s.rate), commission: m(s.commission) })}
                            {s.discount > 0 && <> · {t('خصم البند :amount على المتجر', { amount: m(s.discount) })}</>}
                            {s.returned > 0 && <> · {t('رُدّ منه :n', { n: String(s.returned) })}</>}
                            {s.under_list !== null && <> · <span className="text-[#b45309]">{t('كُتب بأقل من سعر الصنف :listed', { listed: m(s.under_list) })}</span></>}
                        </span>
                    </span>
                    <span className="flex items-center gap-2">
                        <span className="font-medium tabular-nums text-[#6d28d9]">{m(s.net)}</span>
                        <Badge variant={STATE[s.state][1]} className="whitespace-nowrap">{t(STATE[s.state][0])}</Badge>
                        {s.sheet && <span className="whitespace-nowrap text-[11px] text-[#9ca3af]">{s.sheet}</span>}
                    </span>
                </div>
            ))}
            <p className={cn('border-t border-[var(--ui-border,#e8e8e8)] pt-2 text-[13px] tabular-nums', afterDiscount < 0 ? 'font-semibold text-[#b91c1c]' : 'text-[#047857]')} data-testid="boutique-shop-share">
                {t('للبوتيكات :net — وحصة المتجر من بنودها بعد خصمها :shop', { net: m(sum('net')), shop: m(afterDiscount) })}
            </p>
            {afterDiscount < 0 && (
                <p className="mt-1 rounded-md bg-[#fef2f2] px-2 py-1.5 text-[12px] leading-5 text-[#b91c1c]" data-testid="boutique-shares-over">
                    {t('الخصم تجاوز حصة المتجر من بنود البوتيكات: يتحمّل المتجر الفرق، ومستحق البوتيكات لا ينقص.')}
                </p>
            )}
        </Card>
    );
}
