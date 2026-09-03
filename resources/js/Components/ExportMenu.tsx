import { Download, FileDown, FileSpreadsheet, FileText } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { withFilters } from '@/lib/exportLink';
import { useTranslate } from '@/lib/i18n';
import { usePlanFeature } from '@/lib/plan';

interface Props {
    /** أي منها اختياري — يظهر البند فقط إذا مُرِّر رابطه */
    xlsx?: string;
    pdf?: string;
    csv?: string;
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
export default function ExportMenu({ xlsx, pdf, csv, label = 'تصدير', feature }: Props) {
    const t = useTranslate();
    /*
     * والزرّ يُخفى لا يُعطَّل: زرٌّ يُضغط فيردّ بـ403 يجعل صاحبه يظنّ العطب في
     * النظام. ولوحة المنصّة لا تتأثّر — لا باقة لصاحبها، فكلّ شيء مفتوح.
     */
    const licensed = usePlanFeature(feature ?? '');

    if ((!xlsx && !pdf && !csv) || !licensed) return null;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline">
                    <Download />
                    {t(label)}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-56">
                {xlsx && (
                    <DropdownMenuItem asChild>
                        <a href={withFilters(xlsx)}>
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
                        <a href={withFilters(csv)}>
                            <FileDown className="text-[#9ca3af]" />
                            {t('تصدير كملف CSV')}
                        </a>
                    </DropdownMenuItem>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
