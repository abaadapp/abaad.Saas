import CopyButton from '@/Components/CopyButton';
import type { SalePortal } from '@/lib/ribbon-portal';

/** بعد البيع في الصندوق: ينسخ الرابطَ الواحد — ولا يمسّ البيعةَ ولا طباعتَها */
export function PortalSale({ portal, className }: { portal: SalePortal; className?: string }) {
    return (
        <div className={className} data-testid="portal-sale">
            <CopyButton text={portal.url} label="نسخ رابط البوابة" size="md" className="w-full rounded-full" />
        </div>
    );
}
