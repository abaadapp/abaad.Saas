import type { ReactNode } from 'react';
import { FilePlus2 } from 'lucide-react';
import SmartLink from '@/Components/SmartLink';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { STATE_VARIANT, type DocumentRow } from '@/lib/platformDocuments';

/** مستنداتُ الشركة كما يرسلها `DocumentController::forCompany` */
export interface BusinessDocumentsData {
    rows: DocumentRow[];
    count: number;
    new_url: string;
    all_url: string;
}

/**
 * بطاقةُ مستندات الشركة — ما صدر لها من قسم المستندات، ومنها مستندٌ جديد والشركةُ مختارة.
 *
 * المستندُ يُربط بالشركة ساعةَ يُصدر لها، فلا يُبحث عنه بين مستندات العملاء كلّهم.
 * وفي صفحة التعديل (`newTab`) تُفتح الروابطُ في تبويبٍ جديد: لا تضيع تعديلاتٌ لم تُحفظ.
 */
export function BusinessDocuments({ documents, newTab = false }: { documents: BusinessDocumentsData; newTab?: boolean }) {
    const t = useTranslate();
    const link = (href: string, routeName: string, children: ReactNode, props: { className?: string; 'data-testid'?: string; 'aria-label'?: string } = {}) =>
        newTab ? (
            <a href={href} target="_blank" rel="noopener noreferrer" {...props}>
                {children}
            </a>
        ) : (
            <SmartLink routeName={routeName} href={href} {...props}>
                {children}
            </SmartLink>
        );

    return (
        <Card className="mt-6 overflow-hidden" data-testid="business-documents">
            <div className="flex flex-wrap items-center justify-between gap-2 px-5 pt-5">
                <div>
                    <h2 className="text-[15px] font-semibold text-[#111]">{t('مستندات الشركة')}</h2>
                    <p className="text-sm text-[#6b7280]">
                        {t('ما صدر لهذه الشركة من قسم المستندات — :n مستند', { n: number(documents.count) })}
                        {newTab && ` · ${t('تُفتح في تبويب جديد فلا تضيع تعديلاتك غير المحفوظة')}`}
                    </p>
                </div>
                <div className="flex gap-2">
                    <Button variant="outline" size="sm" asChild>
                        {link(documents.all_url, 'super-admin.documents.index', t('عرض كل المستندات'), { 'data-testid': 'business-all-documents' })}
                    </Button>
                    <Button size="sm" asChild>
                        {link(
                            documents.new_url,
                            'super-admin.documents.index',
                            <>
                                <FilePlus2 />
                                {t('مستند جديد')}
                            </>,
                            { 'data-testid': 'business-new-document' },
                        )}
                    </Button>
                </div>
            </div>
            <Table className="mt-3">
                <TableHeader>
                    <TableRow className="hover:bg-transparent">
                        {['المستند', 'رقم المستند', 'أُصدر', 'الحالة', 'وُقِّع', ''].map((h) => (
                            <TableHead key={h}>{h ? t(h) : ''}</TableHead>
                        ))}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {documents.rows.length === 0 ? (
                        <TableEmpty colSpan={6}>{t('لا مستندات لهذه الشركة بعد')}</TableEmpty>
                    ) : (
                        documents.rows.map((d) => (
                            <TableRow key={d.id} data-testid={`business-document-${d.id}`}>
                                <TableCell>
                                    <div className="truncate font-medium text-[#111]">{d.title}</div>
                                    <div className="truncate text-[12px] text-[#6b7280]">{d.plan_name || d.service || '—'}</div>
                                </TableCell>
                                <TableCell dir="ltr" className="text-end font-mono text-[12px] text-[#4b4b4b]">
                                    {d.number}
                                </TableCell>
                                <TableCell dir="ltr" className="text-end text-[#6b7280]">
                                    {d.created_at}
                                </TableCell>
                                <TableCell>
                                    <Badge variant={STATE_VARIANT[d.state]}>{t(d.state_label)}</Badge>
                                    {d.state === 'sent' && d.shared && <div className="mt-0.5 text-[11.5px] text-[#6b7280]">{t(d.shared)}</div>}
                                </TableCell>
                                <TableCell dir="ltr" className="text-end text-[#6b7280]">
                                    {d.state === 'signed' ? d.signed_at : '—'}
                                </TableCell>
                                <TableCell>
                                    <div className="flex justify-end gap-3 text-[12.5px]">
                                        {link(d.url, 'super-admin.documents.show', t('التفاصيل'), { className: 'underline', 'aria-label': t('تفاصيل :n', { n: d.number }) })}
                                        <a href={d.pdf_url} target="_blank" rel="noopener noreferrer" className="underline" aria-label={t('PDF :n', { n: d.number })}>
                                            PDF
                                        </a>
                                    </div>
                                </TableCell>
                            </TableRow>
                        ))
                    )}
                </TableBody>
            </Table>
        </Card>
    );
}
