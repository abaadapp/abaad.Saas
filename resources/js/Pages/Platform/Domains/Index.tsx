import { type FormEvent, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, Globe, XCircle } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import Field from '@/Components/Field';
import Tabs from '@/Components/Tabs';
import DataTable, { type Column } from '@/Components/DataTable';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Textarea } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface DomainRequestRow {
    id: number;
    business: string;
    business_id: number;
    domain: string;
    note: string | null;
    status: string;
    at: string | null;
    handled_at: string | null;
}

interface Props {
    requests: DomainRequestRow[];
    filters: { status: string | null };
    statuses: string[];
    pending: number;
}

/**
 * طلبات النطاقات — الطرف الثاني لزرٍّ في لوحة التاجر.
 *
 * لا مسجّل نطاقاتٍ موصولٌ بالنظام ولا بوّابة دفعٍ لها، فالشراء عملُ إنسان.
 * وبدون هذه الشاشة يكون زرّ «اطلب من أبعاد تجهيز نطاق» مقبضًا لا يُمسك:
 * يضغطه التاجر فيُقال له «وصلنا طلبك» ولا يصل إلى أحد.
 */
export default function DomainRequests() {
    const { requests, filters, statuses, pending } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    /** الطلب الذي يُغلق الآن، ومعه الحالة التي يُغلق بها */
    const [closing, setClosing] = useState<{ row: DomainRequestRow; status: string } | null>(null);

    const form = useForm({ status: '', note: '' });

    const open = (row: DomainRequestRow, status: string) => {
        form.setData({ status, note: '' });
        setClosing({ row, status });
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!closing) return;
        form.post(route('super-admin.domains.status', closing.row.id), {
            preserveScroll: true,
            onSuccess: () => setClosing(null),
        });
    };

    const columns: Column<DomainRequestRow>[] = [
        {
            key: 'domain',
            header: 'النطاق المطلوب',
            value: (r) => r.domain,
            cell: (r) => (
                <span className="font-mono text-[13px] text-[#111]" dir="ltr">
                    {r.domain}
                </span>
            ),
        },
        { key: 'business', header: 'المتجر', value: (r) => r.business },
        {
            key: 'note',
            header: 'الملاحظة',
            cell: (r) => <span className="text-[13px] text-[#6b7280]">{r.note || '—'}</span>,
        },
        { key: 'at', header: 'التاريخ', cell: (r) => r.at ?? '—' },
        { key: 'status', header: 'الحالة', cell: (r) => <Badge status={r.status} /> },
        {
            key: 'actions',
            header: '',
            align: 'end',
            cell: (r) =>
                r.status === 'معلّق' ? (
                    <div className="flex items-center justify-end gap-2">
                        <Button size="sm" variant="success" onClick={() => open(r, 'مكتمل')}>
                            <CheckCircle2 />
                            {t('تمّ التجهيز')}
                        </Button>
                        <Button size="sm" variant="outline" onClick={() => open(r, 'مرفوض')}>
                            <XCircle />
                            {t('رفض')}
                        </Button>
                    </div>
                ) : (
                    <span className="text-[12px] text-[#9ca3af]">{r.handled_at ?? '—'}</span>
                ),
        },
    ];

    return (
        <PlatformLayout title="طلبات النطاقات">
            <PageHeader
                title="طلبات النطاقات"
                subtitle="متاجر طلبت أن نشتري لها نطاقًا ونجهّزه — الشراء والضبط يدويّان."
            />

            {/* العدد المعلّق في الترويسة: هو السبب الوحيد لفتح هذه الشاشة */}
            <Card className="mb-6 flex items-center gap-3 p-5">
                <span className="flex size-11 shrink-0 items-center justify-center rounded-[12px] bg-[#f5f5f4] text-[#111]">
                    <Globe className="size-5" />
                </span>
                <div>
                    <p className="text-[13px] text-[#6b7280]">{t('طلبات تنتظر')}</p>
                    <p className="text-[20px] font-bold text-[#111]">{pending}</p>
                </div>
            </Card>

            <Tabs
                current={filters.status ?? 'all'}
                onChange={(key) =>
                    router.get(
                        route('super-admin.domains.index'),
                        key === 'all' ? {} : { status: key },
                        { preserveScroll: true, preserveState: true },
                    )
                }
                tabs={[{ key: 'all', label: 'الكل' }, ...statuses.map((s) => ({ key: s, label: s }))]}
            />

            <div className="mt-6">
                <DataTable
                    columns={columns}
                    rows={requests}
                    rowKey={(r) => r.id}
                    searchable={(r) => `${r.domain} ${r.business}`}
                    searchPlaceholder="ابحث بالنطاق أو المتجر…"
                    empty="لا طلبات نطاقات."
                />
            </div>

            <Dialog open={!!closing} onOpenChange={(o) => !o && setClosing(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {closing?.status === 'مكتمل' ? t('تأكيد التجهيز') : t('رفض الطلب')}
                        </DialogTitle>
                    </DialogHeader>

                    <form onSubmit={submit}>
                        <p className="mb-4 text-[13px] text-[#6b7280]">
                            <span className="font-mono text-[#111]" dir="ltr">
                                {closing?.row.domain}
                            </span>{' '}
                            — {closing?.row.business}
                        </p>

                        {/*
                            الردّ يقرؤه التاجر في لوحته لا في بريدٍ لا يصل.
                            و«مرفوض» بلا سببٍ تجعل صاحبها يعيد الطلب نفسه،
                            فيُرفض ثانيةً بالصمت نفسه.
                        */}
                        <Field
                            label={closing?.status === 'مكتمل' ? 'ملاحظة للتاجر (اختياري)' : 'سبب الرفض'}
                            hint="يظهر للتاجر في شاشة إعدادات الدومين عنده."
                            error={form.errors.note}
                            required={closing?.status === 'مرفوض'}
                        >
                            <Textarea
                                rows={3}
                                value={form.data.note}
                                onChange={(e) => form.setData('note', e.target.value)}
                            />
                        </Field>

                        <div className="mt-6 flex justify-end gap-2">
                            <Button type="button" variant="ghost" onClick={() => setClosing(null)}>
                                {t('إلغاء')}
                            </Button>
                            <Button
                                type="submit"
                                loading={form.processing}
                                variant={closing?.status === 'مكتمل' ? 'success' : 'danger'}
                            >
                                {closing?.status === 'مكتمل' ? t('تمّ التجهيز') : t('رفض')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </PlatformLayout>
    );
}
