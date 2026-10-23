import { Award } from 'lucide-react';
import { Card } from '@/Components/ui/card';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';

export interface InvoiceLoyaltyRow {
    id: number;
    type: 'earn' | 'redeem';
    points: number;
    balance_after: number;
    payment: string | null;
    at: string | null;
}

export interface InvoiceLoyaltyData {
    /** البرنامجُ عاملٌ الآن — مفتاحُ المتجر وباقتُه */
    on: boolean;
    rows: InvoiceLoyaltyRow[];
    /** دُفع رابطٌ ولم تُحسم نقاطُه بعد — تُعاد كلَّ عشر دقائق */
    waiting: boolean;
}

/**
 * نقاطُ الولاء على سداد هذه الفاتورة برابط — حركاتُها كما كُتبت.
 *
 * والخادمُ لا يرسلها لفاتورةٍ لا تُكسب عليها نقاط (برنامجٌ متوقّف بلا حركات،
 * أو فاتورةٌ تغطّي طلباتٍ كسبت نقاطَها عند البيع) — فلا يُعِد هذا القسمُ بشيءٍ
 * لن يقع. انظر `InvoiceLoyalty` و`CustomerInvoiceController::loyaltyProps`.
 */
export default function InvoiceLoyaltyPanel({ loyalty }: { loyalty: InvoiceLoyaltyData }) {
    const t = useTranslate();

    return (
        <Card className="p-4" data-testid="invoice-loyalty">
            <h3 className="mb-2 flex items-center gap-1.5 text-[13px] font-bold">
                <Award className="size-4 text-[#b45309]" />
                {t('نقاط الولاء')}
            </h3>

            {loyalty.rows.length === 0 ? (
                <p className="text-[12.5px] text-[#71717a]">
                    {loyalty.waiting
                        ? t('دُفعت الفاتورة — تُحسب النقاط خلال دقائق.')
                        : t('يكسب العميل نقاطه حين يسدّد الفاتورة برابط الدفع.')}
                </p>
            ) : (
                loyalty.rows.map((r) => (
                    <div key={r.id} className="flex justify-between gap-3 border-t border-[var(--ui-border,#e8e8e8)] py-2 text-[13px]" data-testid="invoice-loyalty-row">
                        <span className="min-w-0">
                            <span className={r.points >= 0 ? 'font-semibold text-[#15803d]' : 'font-semibold text-[#b45309]'}>
                                {r.points > 0 ? '+' : ''}
                                {number(r.points)} {t('نقطة')}
                            </span>
                            {r.type === 'redeem' && <span className="ms-1 text-[12px] text-[#71717a]">({t('عكس تحصيلٍ ملغى')})</span>}
                            {r.payment && <span className="ms-1 text-[12px] text-[#71717a]">· {r.payment}</span>}
                        </span>
                        <span className="shrink-0 text-[12px] text-[#71717a]">
                            {t('الرصيد بعدها :n', { n: number(r.balance_after) })}
                        </span>
                    </div>
                ))
            )}

            {!loyalty.on && loyalty.rows.length > 0 && (
                <p className="mt-2 text-[12px] text-[#71717a]">{t('برنامج الولاء متوقّف الآن — لا تُكسب نقاط جديدة.')}</p>
            )}
        </Card>
    );
}
