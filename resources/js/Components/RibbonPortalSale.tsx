import { useState } from 'react';
import { MessageCircle, Share2 } from 'lucide-react';
import { toast } from 'sonner';
import CopyButton from '@/Components/CopyButton';
import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';
import { askPortal, type SharedLink } from '@/lib/ribbon-portal';
import { cn } from '@/lib/utils';

/** بعد البيع في الصندوق — ولا يمسّ البيعةَ ولا طباعتَها */
export function PortalSaleShare({ url, className }: { url: string; className?: string }) {
    const t = useTranslate();
    const [busy, setBusy] = useState(false);
    const [shared, setShared] = useState<SharedLink | null>(null);
    const failed = t('تعذّر ذلك الآن. تحقّق من الاتصال وحاول من جديد.');

    const run = async () => {
        setBusy(true);
        const { data, error } = await askPortal<SharedLink>(url, 'POST', failed);
        setBusy(false);
        // البيعةُ تمّت: خطأُ المشاركة يُقال ولا يوقف شيئًا
        if (error || !data) return void toast.warning(error ?? failed);
        setShared(data);
    };

    return shared ? (
        <div className={cn('grid grid-cols-2 gap-2', className)} data-testid="portal-sale-shared">
            <CopyButton text={shared.url} label={t('نسخ رابط العميل')} size="md" className="w-full rounded-full" />
            <Button variant="success" className="rounded-full" asChild>
                <a href={shared.whatsapp} target="_blank" rel="noreferrer"><MessageCircle />{t('واتساب')}</a>
            </Button>
        </div>
    ) : (
        <Button variant="outline" className={className} loading={busy} onClick={() => void run()} data-testid="portal-sale">
            <Share2 />
            {t('مشاركة رابط مشترياتي')}
        </Button>
    );
}
