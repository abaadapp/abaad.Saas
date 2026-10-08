import { useState } from 'react';
import { Download, FileDown, FileSpreadsheet, FileText, Loader2, Upload } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { downloadFile, withFilters } from '@/lib/exportLink';
import { useTranslate } from '@/lib/i18n';
import { usePlanFeature } from '@/lib/plan';

interface Props {
    /** أي منها اختياري — يظهر البند فقط إذا مُرِّر رابطه */
    xlsx?: string;
    pdf?: string;
    csv?: string;
    /**
     * ملفُّ الاستيراد — أعمدةُ الاستيراد بلا ترويسة، ليُعاد رفعُه كما خرج.
     *
     * بندٌ مستقلٌّ باسمه لا «Excel» ثانٍ: من أراد تقريرًا يقرؤه أخذ الأوّل،
     * ومن أراد تعديل الكتالوج في Excel ثمّ رفعه أخذ هذا.
     */
    importXlsx?: string;
    label?: string;
    /**
     * قدرةُ الباقة التي يفتحها هذا التصدير — تُمرَّر حيث يكون التصدير مُباعًا.
     *
     * وتُترك فارغةً حيث لا يكون: تصديرُ قائمة العملاء أو المنتجات ليس تقريرًا،
     * وقفلُه على «التقارير المتقدّمة» يسحب من التاجر ما لم يُوعَد بسحبه.
     */
    feature?: string;
}

/**
 * قائمة تصدير موحّدة بثلاث صيغ — بديل partials/export-menu.
 *
 * روابط تنزيل حقيقية لا روابط Inertia: الاستجابة ملف لا صفحة،
 * وزيارتها عبر <Link> تُفشل التنزيل.
 */
export default function ExportMenu({ xlsx, pdf, csv, importXlsx, label = 'تصدير', feature }: Props) {
    const t = useTranslate();
    /*
     * «جاري التصدير…» حتى يبدأ الملفّ — ولا نقرةَ ثانية تُنزّله مرّتين.
     * والخطأُ يُقال تحت الزرّ ولا يستبدل الصفحة (`downloadFile`).
     */
    const [busy, setBusy] = useState(false);
    const [failed, setFailed] = useState(false);

    const start = (url: string) => (e: { preventDefault: () => void }) => {
        e.preventDefault();
        if (busy) return;
        setBusy(true);
        setFailed(false);
        void downloadFile(withFilters(url)).then((result) => {
            setBusy(false);
            setFailed(result === 'failed');
        });
    };
    /*
     * والزرّ يُخفى لا يُعطَّل: زرٌّ يُضغط فيردّ بـ403 يجعل صاحبه يظنّ العطب في
     * النظام. ولوحة المنصّة لا تتأثّر — لا باقة لصاحبها، فكلّ شيء مفتوح.
     */
    const licensed = usePlanFeature(feature ?? '');

    if ((!xlsx && !pdf && !csv && !importXlsx) || !licensed) return null;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline" disabled={busy} aria-busy={busy} data-testid="export-menu">
                    {busy ? <Loader2 className="animate-spin" /> : <Download />}
                    {t(busy ? 'جاري التصدير…' : label)}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-56">
                {xlsx && (
                    <DropdownMenuItem asChild>
                        <a href={withFilters(xlsx)} onClick={start(xlsx)}>
                            <FileSpreadsheet className="text-[#9ca3af]" />
                            {t('تصدير كملف إكسل')}
                        </a>
                    </DropdownMenuItem>
                )}
                {pdf && (
                    <DropdownMenuItem asChild>
                        <a href={withFilters(pdf)} target="_blank" rel="noreferrer">
                            <FileText className="text-[#9ca3af]" />
                            {t('تصدير كملف PDF')}
                        </a>
                    </DropdownMenuItem>
                )}
                {csv && (
                    <DropdownMenuItem asChild>
                        <a href={withFilters(csv)} onClick={start(csv)}>
                            <FileDown className="text-[#9ca3af]" />
                            {t('تصدير كملف CSV')}
                        </a>
                    </DropdownMenuItem>
                )}
                {importXlsx && (
                    <DropdownMenuItem asChild>
                        <a href={withFilters(importXlsx)} onClick={start(importXlsx)}>
                            <Upload className="text-[#9ca3af]" />
                            {t('تصدير للاستيراد (Excel)')}
                        </a>
                    </DropdownMenuItem>
                )}
            </DropdownMenuContent>
            {failed && (
                <span role="alert" className="text-[12px] text-[#b91c1c]">
                    {t('تعذّر التصدير — حاول مرّة أخرى')}
                </span>
            )}
        </DropdownMenu>
    );
}
