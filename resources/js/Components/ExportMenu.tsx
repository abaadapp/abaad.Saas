import { useState } from 'react';
import { Download, FileDown, FileSpreadsheet, FileText, Loader2, Upload } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { downloadFile, useTenantLink, withFilters } from '@/lib/exportLink';
import { useTranslate } from '@/lib/i18n';
import { useMay } from '@/lib/may';
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
    /**
     * فعلُ التصدير الذي يحرسه الخادمُ لهذا الملفّ — `SectionActions` (مثل `customer.export`).
     *
     * من يفتح القسمَ ولا يملك تصديرَه لا يرى القائمة: الضغطُ كان سيُردّ بـ٤٠٣.
     */
    action?: string;
}

/**
 * قائمة تصدير موحّدة بثلاث صيغ — بديل partials/export-menu.
 *
 * روابط تنزيل حقيقية لا روابط Inertia: الاستجابة ملف لا صفحة،
 * وزيارتها عبر <Link> تُفشل التنزيل.
 */
export default function ExportMenu({ xlsx, pdf, csv, importXlsx, label = 'تصدير', feature, action }: Props) {
    const t = useTranslate();
    const may = useMay();
    /*
     * «جاري التصدير…» حتى يبدأ الملفّ — ولا نقرةَ ثانية تُنزّله مرّتين.
     * والخطأُ يُقال تحت الزرّ ولا يستبدل الصفحة (`downloadFile`).
     */
    const [busy, setBusy] = useState(false);
    const [failed, setFailed] = useState<'failed' | 'stale' | null>(null);
    /*
     * وكلُّ رابطٍ يحمل نشاطَ هذه الصفحة — هنا مرّةً لكلّ شاشةٍ تستعمل القائمة.
     *
     * تبويبٌ بقي على النشاط A بعد أن تبدّلت الجلسةُ إلى B في تبويبٍ آخر لا يُخرج
     * ملفَّ B تحت شاشةٍ تقول A: الخادمُ يقارن ويقف (`TenantExportGuard`).
     */
    const tenant = useTenantLink();
    const link = (url: string) => withFilters(tenant(url));

    const start = (url: string) => (e: { preventDefault: () => void }) => {
        e.preventDefault();
        if (busy) return;
        setBusy(true);
        setFailed(null);
        void downloadFile(link(url)).then((result) => {
            setBusy(false);
            setFailed(result === 'failed' || result === 'stale' ? result : null);
        });
    };
    /*
     * والزرّ يُخفى لا يُعطَّل: زرٌّ يُضغط فيردّ بـ403 يجعل صاحبه يظنّ العطب في
     * النظام. ولوحة المنصّة لا تتأثّر — لا باقة لصاحبها، فكلّ شيء مفتوح.
     */
    const licensed = usePlanFeature(feature ?? '');

    if ((!xlsx && !pdf && !csv && !importXlsx) || !licensed) return null;

    if (action && ! may(action)) {
        return null;
    }

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
                        <a href={link(xlsx)} onClick={start(xlsx)}>
                            <FileSpreadsheet className="text-[#9ca3af]" />
                            {t('تصدير كملف إكسل')}
                        </a>
                    </DropdownMenuItem>
                )}
                {pdf && (
                    <DropdownMenuItem asChild>
                        <a href={link(pdf)} target="_blank" rel="noreferrer">
                            <FileText className="text-[#9ca3af]" />
                            {t('تصدير كملف PDF')}
                        </a>
                    </DropdownMenuItem>
                )}
                {csv && (
                    <DropdownMenuItem asChild>
                        <a href={link(csv)} onClick={start(csv)}>
                            <FileDown className="text-[#9ca3af]" />
                            {t('تصدير كملف CSV')}
                        </a>
                    </DropdownMenuItem>
                )}
                {importXlsx && (
                    <DropdownMenuItem asChild>
                        <a href={link(importXlsx)} onClick={start(importXlsx)}>
                            <Upload className="text-[#9ca3af]" />
                            {t('تصدير للاستيراد (Excel)')}
                        </a>
                    </DropdownMenuItem>
                )}
            </DropdownMenuContent>
            {failed && (
                <span role="alert" className="text-[12px] text-[#b91c1c]" data-export-failure={failed}>
                    {t(
                        failed === 'stale'
                            ? 'تغيّرت جلسة النشاط منذ فتح هذه الصفحة. حدّث الصفحة ثم أعد التصدير.'
                            : 'تعذّر التصدير — حاول مرّة أخرى',
                    )}
                </span>
            )}
        </DropdownMenu>
    );
}
