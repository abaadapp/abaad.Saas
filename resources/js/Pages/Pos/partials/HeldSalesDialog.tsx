import { useState } from 'react';
import { RotateCw } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import type { OutboxEntry } from '@/hooks/usePosCart';
import { useTranslate } from '@/lib/i18n';

/**
 * ‏بيعاتٌ اكتملت عند الصندوق ثمّ ردّها الخادمُ حين عاد الاتّصال.
 *
 * كانت تُحذف من الطابور برسالةٍ تختفي بعد ثوانٍ: نقدٌ في الدرج بلا فاتورة،
 * ومخزونٌ لم يُخصم، ولا أثرَ يُراجَع. فتبقى هنا بسبب ردّها وبما فيها، ولا
 * تُرسل وحدَها (السببُ لا يزول بالانتظار) — تُعاد باليد بمعرّفها نفسِه بعد
 * أن يُصلَح السبب، فإن كان الخادمُ كتبها ردّ فاتورتَها ولم يكتبها ثانيةً.
 */
export default function HeldSalesDialog({
    open,
    onOpenChange,
    sales,
    onRetry,
    money,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    sales: OutboxEntry[];
    onRetry: (uuid: string) => Promise<void>;
    money: (v: number) => string;
}) {
    const t = useTranslate();
    const [busy, setBusy] = useState<string | null>(null);

    const retry = async (uuid: string) => {
        setBusy(uuid);
        try {
            await onRetry(uuid);
        } finally {
            setBusy(null);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg" data-testid="held-sales">
                <DialogHeader>
                    <DialogTitle>{t('طلبات معلّقة رُفضت')}</DialogTitle>
                    <DialogDescription>
                        {t('اكتملت على هذا الجهاز ورفضها الخادم عند الإرسال. باقية هنا ولن تُحذف — أصلح السبب ثمّ أعد إرسالها.')}
                    </DialogDescription>
                </DialogHeader>

                <ul className="max-h-[60vh] space-y-2 overflow-y-auto px-5 pb-5">
                    {sales.map((sale) => {
                        const items = Array.isArray(sale.payload.items) ? (sale.payload.items as { name?: string; qty?: number }[]) : [];
                        const total = typeof sale.payload.expected_total === 'number' ? sale.payload.expected_total : null;
                        const customer = typeof sale.payload.customer === 'string' ? sale.payload.customer : '';

                        return (
                            <li key={sale.uuid} className="rounded-xl border border-[#fecaca] bg-[#fef2f2] p-3 text-sm" data-testid="held-sale">
                                <div className="flex items-center justify-between gap-2">
                                    <span className="font-bold text-gray-900" dir="ltr">
                                        {new Date(sale.at).toLocaleString()}
                                    </span>
                                    {total !== null && <span className="font-bold text-gray-900">{money(total)}</span>}
                                </div>
                                <p className="mt-1 truncate text-gray-700">
                                    {customer && `${customer} · `}
                                    {items.map((i) => `${i.name ?? ''}${(i.qty ?? 1) > 1 ? ` ×${i.qty}` : ''}`).join('، ')}
                                </p>
                                <p className="mt-1 font-medium text-[#b91c1c]">{sale.held?.error || t('تعذّر إتمام البيع')}</p>
                                <div className="mt-2 flex justify-end">
                                    <Button size="sm" variant="outline" disabled={busy !== null} onClick={() => void retry(sale.uuid)}>
                                        <RotateCw className={busy === sale.uuid ? 'animate-spin' : undefined} />
                                        {t('أعد الإرسال')}
                                    </Button>
                                </div>
                            </li>
                        );
                    })}
                </ul>
            </DialogContent>
        </Dialog>
    );
}
