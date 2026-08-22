import { useEffect, useState } from 'react';
import { Printer, X } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export interface ReportPayload {
    title: string;
    columns: string[];
    rows: string[][];
    summary: string;
}

/**
 * عارض التقارير التي لا صفحة لها.
 *
 * ثلاثةٌ من تقارير الفهرس ليست أقسامًا في اللوحة بل قراءاتٌ عليها: وسائل
 * الدفع، وأداء الموظفين، والعملاء الأكثر إنفاقًا. لا يُصنع لكلٍّ منها صفحةٌ
 * ومسارٌ وتبويب — الخادم يعرفها أصلًا أعمدةً وصفوفًا (ReportDataController)،
 * فتُعرض هنا كما هي وتُطبع.
 *
 * ويُطلب التقرير عند الفتح لا عند تحميل الصفحة: ثلاثة طلباتٍ لبطاقاتٍ قد لا
 * تُفتح واحدةٌ منها تُبطئ الفهرس بلا مقابل.
 */
export default function ReportViewer({ dataKey, onClose }: { dataKey: string | null; onClose: () => void }) {
    const t = useTranslate();
    const [report, setReport] = useState<ReportPayload | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (!dataKey) return;

        let alive = true;
        setReport(null);
        setFailed(false);

        fetch(route('admin.reports.data', dataKey), { headers: { Accept: 'application/json' } })
            .then((res) => (res.ok ? res.json() : Promise.reject()))
            .then((payload: ReportPayload) => alive && setReport(payload))
            .catch(() => alive && setFailed(true));

        return () => {
            alive = false;
        };
    }, [dataKey]);

    return (
        <Dialog open={dataKey !== null} onOpenChange={(open) => !open && onClose()}>
            {/* يتّسع للجدول ويطول بطوله، وسقفُ الارتفاع يُبقي الترويسة ظاهرة.
                و`printable-report` تكشفه للطابعة: قاعدة الطباعة العامة تُخفي
                الصفحة كلّها إلا الإيصال الحراري (انظر app.css). */}
            <DialogContent
                hideClose
                className={cn(
                    'printable-report max-h-[85vh] w-[calc(100%-2rem)] max-w-3xl overflow-hidden p-0',
                    /* على الورق: النافذة تفقد توسيطها وسقفها وتصير صفحة.
                       أدواتٍ لا قواعدَ في app.css — انظر التعليق هناك. */
                    'print:absolute print:inset-x-0 print:top-0 print:max-h-none print:w-full print:max-w-none',
                    'print:translate-x-0 print:translate-y-0 print:overflow-visible',
                    'print:rounded-none print:border-0 print:shadow-none',
                )}
            >
                <DialogHeader className="flex-row items-start justify-between gap-3 border-b border-[var(--ui-border,#e8e8e8)] p-5">
                    <div className="min-w-0">
                        <DialogTitle>{report?.title ?? t('التقرير')}</DialogTitle>
                        {report && <p className="mt-1 text-[13px] text-[#6b7280]">{report.summary}</p>}
                    </div>
                    <DialogClose className="no-print -me-1 -mt-1 rounded-[8px] p-1.5 text-[#6b7280] transition-colors hover:bg-[#f2f2f0] hover:text-[#111] focus:outline-none">
                        <X className="size-4" />
                        <span className="sr-only">{t('إغلاق')}</span>
                    </DialogClose>
                </DialogHeader>

                {/* الورق لا يُمرَّر: بلا فكّ السقف يُطبع ما ظهر من الجدول وحده
                    ويسقط باقي الصفوف بلا أثر — نقصٌ لا يُرى في الورقة */}
                <div className="max-h-[60vh] overflow-auto print:max-h-none print:overflow-visible">
                    {failed ? (
                        <p className="p-8 text-center text-[13px] text-[#9ca3af]">
                            {t('تعذّر تحميل التقرير')}
                        </p>
                    ) : !report ? (
                        <p className="p-8 text-center text-[13px] text-[#9ca3af]">{t('جارٍ التحميل…')}</p>
                    ) : report.rows.length === 0 ? (
                        <p className="p-8 text-center text-[13px] text-[#9ca3af]">
                            {t('لا توجد بيانات في هذا التقرير بعد')}
                        </p>
                    ) : (
                        <table className="w-full border-collapse">
                            <thead>
                                <tr>
                                    {report.columns.map((c) => (
                                        <th
                                            key={c}
                                            /* اللصق بأعلى الشاشة لا بأعلى كل ورقة */
                                            className="sticky top-0 z-[1] whitespace-nowrap border-b border-[var(--ui-border,#e8e8e8)] bg-[#fbfbfa] px-5 py-3 text-start text-[12px] font-semibold text-[#6b7280] print:static"
                                        >
                                            {c}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {report.rows.map((row, i) => (
                                    <tr key={i} className="transition-colors hover:bg-[#fafafa]">
                                        {row.map((cell, j) => (
                                            <td
                                                key={j}
                                                className="border-b border-[#f2f2f0] px-5 py-3 text-[13px] tabular-nums text-[#111]"
                                            >
                                                {cell}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>

                <div className="no-print flex items-center justify-end gap-2 border-t border-[var(--ui-border,#e8e8e8)] p-4">
                    <Button variant="outline" onClick={() => window.print()} disabled={!report}>
                        <Printer />
                        {t('طباعة')}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
