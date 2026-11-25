import CopyButton from '@/Components/CopyButton';

/** بعد البيع في الصندوق: ينسخ رابطَ البوابة الواحد — ولا يمسّ البيعةَ ولا طباعتَها */
export function PortalSaleLink({ url, className }: { url: string; className?: string }) {
    return (
        <div className={className} data-testid="portal-sale">
            <CopyButton text={url} label="نسخ رابط بوابة العملاء" done="نُسخ رابط بوابة العملاء" size="md" className="w-full rounded-full" />
        </div>
    );
}
