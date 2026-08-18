import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { AlertTriangle, Check, Info, ShieldCheck, X } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface Row {
    name: string | null;
    phone: string | null;
    email: string | null;
    contact: string | null;
    notes: string | null;
    status: 'new' | 'update' | 'invalid' | 'dup_file';
    note?: string | null;
}

interface Props {
    rows: Row[];
    counts: { total: number; new: number; update: number; skip: number };
    /** حقولٌ لا يذكرها الملفّ — تبقى كما هي في المورّدين المحدَّثين */
    untouched: string[];
    file: string;
}

/** لون الصف وشارته حسب ما سيحدث له عند التأكيد */
const STATUS: Record<Row['status'], { row: string; variant: 'success' | 'info' | 'danger' | 'warning'; label: string }> = {
    new: { row: '', variant: 'success', label: 'سيُضاف' },
    update: { row: 'bg-[#eff6ff]/50', variant: 'info', label: 'سيُحدَّث' },
    invalid: { row: 'bg-[#fef2f2]/50', variant: 'danger', label: 'غير صالح' },
    dup_file: { row: 'bg-[#fafafa]', variant: 'warning', label: 'مكرر' },
};

/**
 * معاينة استيراد المورّدين — تُقرأ قبل الكتابة لا بعدها.
 *
 * ملفٌ يُكتب فور رفعه لا سبيل لمراجعته: يكتشف التاجر أن عمودًا أُسيء فهمه وقد
 * صار في قاعدته. فهنا يُقال كم سيُضاف وكم سيُحدَّث وكم سيُتجاهل ولماذا.
 */
export default function SupplierImportPreview() {
    const { rows, counts, untouched, file } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const [confirming, setConfirming] = useState(false);

    const applyCount = counts.new + counts.update;

    const summary = [
        { label: 'إجمالي الصفوف', value: counts.total },
        { label: 'سيُضاف (جديد)', value: counts.new },
        { label: 'سيُحدَّث (موجود)', value: counts.update },
        { label: 'يُتجاهل', value: counts.skip },
    ];

    return (
        <AdminLayout title="معاينة استيراد الموردين">
            <PageHeader
                title="معاينة استيراد الموردين"
                subtitle={t('راجع البيانات المستوردة من الملف قبل حفظها نهائيًا')}
                actions={
                    <>
                        {/* المسار POST — router.get يردّ 405 فيبقى الملف معلّقًا في الجلسة */}
                        <Button variant="outline" onClick={() => router.post(route('admin.suppliers.import.cancel'))}>
                            <X />
                            {t('إلغاء')}
                        </Button>
                        <Button disabled={applyCount === 0} onClick={() => setConfirming(true)}>
                            <Check />
                            {t('تأكيد الاستيراد')} ({number(applyCount)})
                        </Button>
                    </>
                }
            />

            <div className="mb-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                {summary.map((s) => (
                    <Card key={s.label} className="p-4 text-center">
                        <p className="text-[22px] font-bold tabular-nums text-[#111]">{number(s.value)}</p>
                        <p className="mt-0.5 text-[12px] text-[#9ca3af]">{t(s.label)}</p>
                    </Card>
                ))}
            </div>

            {/* الغائب لا يُقرأ فراغًا: قائمة أسماءٍ وأرقام لا تمحو بريدًا ولا ملاحظة */}
            {untouched.length > 0 && (
                <div className="mb-4 flex items-start gap-2 rounded-[10px] bg-[#f0fdf4] px-3 py-2.5 text-[13px] text-[#166534]">
                    <ShieldCheck className="mt-0.5 size-4 shrink-0" />
                    <span>
                        {t('الملفّ لا يذكر:')} <b>{untouched.join('، ')}</b>{' — '}
                        {t('فتبقى كما هي في المورّدين الموجودين، ولا تُمسّ.')}
                    </span>
                </div>
            )}

            <div className="mb-4 flex flex-wrap items-center gap-2 text-[12px]">
                <span className="inline-flex items-center gap-2 rounded-[8px] bg-[#eff6ff] px-3 py-1.5 text-[#2563eb]">
                    <Info className="size-4" />
                    {t('المورّد الموجود يُحدَّث بدل تكراره — يُطابَق بالهاتف، وبالاسم إن لم يكن له هاتف.')}
                </span>
                <span className="text-[#9ca3af]">{file}</span>
            </div>

            <Card className="overflow-hidden">
                <Table>
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            {['#', 'الاسم', 'الهاتف', 'البريد', 'مسؤول التواصل', 'ملاحظات', 'الحالة'].map((h) => (
                                <TableHead key={h}>{h === '#' ? h : t(h)}</TableHead>
                            ))}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((r, i) => {
                            const s = STATUS[r.status];

                            return (
                                <TableRow key={i} className={cn(s.row)}>
                                    <TableCell className="text-[12px] text-[#9ca3af]">{i + 1}</TableCell>
                                    <TableCell className="font-medium text-[#111]">{r.name || '—'}</TableCell>
                                    <TableCell dir="ltr" className="text-[#4b4b4b]">{r.phone || '—'}</TableCell>
                                    <TableCell dir="ltr" className="text-[#6b7280]">{r.email || '—'}</TableCell>
                                    <TableCell className="text-[#4b4b4b]">{r.contact || '—'}</TableCell>
                                    <TableCell className="max-w-56 truncate text-[#6b7280]">{r.notes || '—'}</TableCell>
                                    <TableCell>
                                        <Badge variant={s.variant}>{t(s.label)}</Badge>
                                        {(r.status === 'invalid' || r.status === 'dup_file') && r.note && (
                                            <span className="mt-0.5 block text-[11px] text-[#9ca3af]">{r.note}</span>
                                        )}
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
            </Card>

            {applyCount === 0 && (
                <div className="mt-5 flex items-center gap-2 rounded-[12px] bg-[#fffbeb] px-4 py-3 text-sm text-[#d97706]">
                    <AlertTriangle className="size-5" />
                    {t('لا يوجد أي صف صالح للإضافة أو التحديث. راجع الملف وأعد المحاولة.')}
                </div>
            )}

            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>{t('تأكيد الاستيراد')}</DialogTitle>
                    </DialogHeader>
                    <div className="px-5 pb-5">
                        <p className="text-sm text-[#4b4b4b]">
                            {t('سيُضاف')} {number(counts.new)} {t('ويُحدَّث')} {number(counts.update)}.
                        </p>
                        <div className="mt-5 flex justify-end gap-2">
                            <Button variant="outline" onClick={() => setConfirming(false)}>
                                {t('إلغاء')}
                            </Button>
                            <Button
                                onClick={() =>
                                    router.post(
                                        route('admin.suppliers.import.confirm'),
                                        {},
                                        { onFinish: () => setConfirming(false) },
                                    )
                                }
                            >
                                {t('تأكيد')}
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
