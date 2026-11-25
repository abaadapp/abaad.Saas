import { useState } from 'react';
import { Check, Link2 } from 'lucide-react';
import { toast } from 'sonner';
import CopyButton from '@/Components/CopyButton';
import { Button } from '@/Components/ui/button';
import { useCopy } from '@/lib/copy';
import { useTranslate } from '@/lib/i18n';
import { askPortal, linkOf, type SaleLink } from '@/lib/ribbon-portal';
import { cn } from '@/lib/utils';

/** بعد البيع في الصندوق: ينسخ رابطَ العميل — ولا يمسّ البيعةَ ولا طباعتَها */
export function PortalSaleLink({ url, className }: { url: string; className?: string }) {
    const t = useTranslate();
    const { copyLater, copied } = useCopy();
    const [busy, setBusy] = useState(false);
    /** جهازٌ يمنع الحافظة: يُعرض الرابطُ ليُنسخ بيده */
    const [shown, setShown] = useState<string | null>(null);
    const failed = t('تعذّر ذلك الآن. تحقّق من الاتصال وحاول من جديد.');

    const run = async () => {
        setBusy(true);
        const asked = askPortal<SaleLink>(url, 'POST', failed);
        const done = await copyLater(linkOf(asked));
        const { data, error } = await asked;
        setBusy(false);
        // البيعةُ تمّت: خطأُ النسخ يُقال ولا يوقف شيئًا
        if (error || !data) return void toast.warning(error ?? failed);
        setShown(done ? null : data.url);
    };

    return (
        <div className={cn('space-y-2', className)}>
            <Button variant="outline" className="w-full rounded-full" loading={busy} onClick={() => void run()} data-testid="portal-sale">
                {copied ? <Check className="text-[#047857]" /> : <Link2 />}
                {copied ? t('نُسخ رابط بوابة العميل') : t('نسخ رابط بوابة العميل')}
            </Button>
            {shown && (
                <div className="flex items-center gap-2 rounded-xl border border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] p-2" data-testid="portal-sale-link">
                    <span dir="ltr" className="min-w-0 flex-1 select-all break-all text-start text-[12px] text-[#374151]">{shown}</span>
                    <CopyButton text={shown} label={t('نسخ')} />
                </div>
            )}
        </div>
    );
}
