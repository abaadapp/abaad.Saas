import { useState } from 'react';
import { toast } from 'sonner';
import { Download, Loader2 } from 'lucide-react';

import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';
import { savePdf } from '@/lib/pdfDownload';

/**
 * زرُّ «تحميل» لورقة المستند — يحفظ PDF أو يقول لماذا لم يحفظ.
 *
 * والضغطةُ الثانية أثناء التنزيل لا تُرسل طلبًا ثانيًا: الزرُّ يُعطَّل حتى يعود
 * الأوّل. انظر `lib/pdfDownload`.
 */
export default function PdfDownloadButton({ url, filename }: { url: string; filename: string }) {
    const t = useTranslate();
    const [pending, setPending] = useState(false);

    async function save() {
        if (pending) return;
        setPending(true);
        try {
            const result = await savePdf(url, filename);
            if (!result.ok) {
                toast.error(
                    result.reason === 'session'
                        ? t('انتهت الجلسة — سجّل الدخول من جديد')
                        : t('تعذّر تحميل الملف — لم يُحفظ شيء. حاول مرة أخرى'),
                );
            }
        } finally {
            setPending(false);
        }
    }

    return (
        <Button variant="outline" size="sm" onClick={save} disabled={pending} aria-busy={pending}>
            {pending ? <Loader2 className="animate-spin" /> : <Download />}
            <span className="max-sm:sr-only">{t('تحميل')}</span>
        </Button>
    );
}
