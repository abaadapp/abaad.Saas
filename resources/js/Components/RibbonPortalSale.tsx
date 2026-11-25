import { useState } from 'react';
import { ShieldCheck } from 'lucide-react';
import CopyButton from '@/Components/CopyButton';
import { DeviceApproval } from '@/Components/RibbonPortalDevice';
import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';
import type { SalePortal } from '@/lib/ribbon-portal';
import { cn } from '@/lib/utils';

/** بعد البيع في الصندوق: ينسخ الرابطَ الواحد ويعتمد جهازَ عميل البيعة — ولا يمسّ البيعةَ ولا طباعتَها */
export function PortalSale({ portal, className }: { portal: SalePortal; className?: string }) {
    const t = useTranslate();
    const [open, setOpen] = useState(false);
    const [done, setDone] = useState(false);

    return (
        <div className={cn('space-y-3', className)} data-testid="portal-sale">
            <div className="grid grid-cols-2 gap-2">
                <CopyButton text={portal.url} label="نسخ رابط البوابة" size="md" className="w-full rounded-full" />
                <Button variant="outline" className="w-full rounded-full" disabled={done} aria-expanded={open} onClick={() => setOpen(! open)} data-testid="portal-sale-device">
                    <ShieldCheck />
                    {t(done ? 'اعتُمد الجهاز' : 'اعتماد جهاز العميل')}
                </Button>
            </div>
            {open && !done && <DeviceApproval url={portal.device} phone={portal.phone} onDone={() => setDone(true)} className="rounded-2xl border border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] p-3 text-start" />}
        </div>
    );
}
