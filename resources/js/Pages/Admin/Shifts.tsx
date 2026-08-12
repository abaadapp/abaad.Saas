import { useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, Lock, Printer } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { FINANCE_TABS } from '@/Components/SectionTabs';
import DataTable, { type Column } from '@/Components/DataTable';
import StatCard from '@/Components/StatCard';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import Field from '@/Components/Field';
import { Input } from '@/Components/ui/input';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface Row {
    id: number;
    branch: string;
    opened_at: string | null;
    closed_at: string | null;
    opened_by: string;
    closed_by: string;
    opening: number;
    cash: number;
    expected: number;
    counted: number | null;
    difference: number | null;
    note: string | null;
    status: string;
    /** counted | auto | admin — و null للمفتوحة */
    closedKind: string | null;
    /** فُتحت منذ أطول ممّا يحتمله يوم عمل */
    stale: boolean;
}

export default function AdminShifts() {
    const { shifts, maxHours, context } =
        usePage<PageProps<{ shifts: Row[]; openShiftId: number | null; maxHours: number }>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);
    const [closing, setClosing] = useState<Row | null>(null);
    const form = useForm({ note: '' });

    /*
     * «مُقفلة» غير «مُقفلة بعدّ».
     *
     * كان الفرق الفارغ يعني «مفتوحة» وحدها، فصار يعني أيضًا «أُقفلت ولم
     * يعدّها أحد». وخلطُهما في الإحصاء يجعل ورديةً بلا عدٍّ تُحسب كأنها
     * طابقت — وهو ما بُني هذا كلّه ليمنعه.
     */
    const counted = shifts.filter((s) => s.closedKind === 'counted');
    const uncounted = shifts.filter((s) => s.closedKind !== null && s.closedKind !== 'counted');
    const stale = shifts.filter((s) => s.stale);
    const short = counted.filter((s) => (s.difference ?? 0) < 0);
    const totalGap = counted.reduce((sum, s) => sum + (s.difference ?? 0), 0);

    const submitClose = (e: React.FormEvent) => {
        e.preventDefault();
        if (!closing) return;
        form.post(route('admin.shifts.close', closing.id), {
            preserveScroll: true,
            onSuccess: () => { form.reset(); setClosing(null); },
        });
    };

    const columns: Column<Row>[] = [
        { key: 'opened_at', header: 'فُتحت', sortable: true, value: (s) => s.opened_at ?? '', cell: (s) => s.opened_at ?? '—' },
        { key: 'branch', header: 'الفرع', cell: (s) => s.branch },
        { key: 'opened_by', header: 'فتحها', cell: (s) => s.opened_by },
        { key: 'closed_by', header: 'أقفلها', cell: (s) => s.closed_by },
        { key: 'opening', header: 'الابتدائي', align: 'end', cell: (s) => <span className="tabular-nums">{m(s.opening)}</span> },
        { key: 'cash', header: 'مبيعات نقدية', align: 'end', cell: (s) => <span className="tabular-nums">{m(s.cash)}</span> },
        { key: 'expected', header: 'المتوقّع', align: 'end', cell: (s) => <span className="tabular-nums">{m(s.expected)}</span> },
        {
            key: 'counted',
            header: 'المعدود',
            align: 'end',
            cell: (s) => (s.counted === null ? '—' : <span className="tabular-nums">{m(s.counted)}</span>),
        },
        {
            key: 'difference',
            header: 'الفرق',
            align: 'end',
            sortable: true,
            value: (s) => s.difference ?? 0,
            cell: (s) =>
                s.closedKind === null ? (
                    <Badge variant={s.stale ? 'danger' : 'info'}>{s.stale ? t('منسيّة') : t('مفتوحة')}</Badge>
                ) : s.difference === null ? (
                    // فرقٌ مجهول لا صفر: لا أحد عدّ الدرج، فلا يُقال إنه طابق
                    <Badge variant="warning">{t('بلا عدّ')}</Badge>
                ) : (
                    <span
                        className={cn(
                            'tabular-nums font-medium',
                            s.difference < 0 ? 'text-[#b91c1c]' : s.difference > 0 ? 'text-[#d97706]' : 'text-[#047857]',
                        )}
                    >
                        {s.difference > 0 ? '+' : ''}
                        {m(s.difference)}
                    </span>
                ),
        },
        { key: 'note', header: 'ملاحظة', cell: (s) => s.note || '—' },
        {
            key: 'print',
            header: '',
            align: 'end',
            /*
             * تقرير الإقفال (Z) — للمقفلة وحدها.
             *
             * الوردية المفتوحة أرقامها تتغيّر مع كل بيعة، وورقةٌ تُطبع منها
             * تُوقَّع على رقمٍ يكذّبه الصندوق بعد دقيقة.
             */
            cell: (s) =>
                s.closedKind === null ? (
                    <Button variant="outline" size="sm" onClick={() => setClosing(s)}>
                        <Lock className="size-4" />
                        {t('إقفال بلا عدّ')}
                    </Button>
                ) : (
                    <Button variant="outline" size="sm" asChild>
                        <a href={route('admin.shifts.pdf', s.id)} target="_blank" rel="noreferrer">
                            <Printer className="size-4" />
                            {t('تقرير الإقفال')}
                        </a>
                    </Button>
                ),
        },
    ];

    return (
        <AdminLayout title="الورديات">
            <PageHeader
                title="الورديات"
                subtitle={t('حصيلة كل درج: ما توقّعه النظام، وما وُجد فيه فعلًا')}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('admin.dashboard') },
                    { label: 'المالية', href: route('admin.finance.index') },
                    { label: 'الورديات' },
                ]}
            />

            <SectionTabs tabs={FINANCE_TABS} current="admin.shifts.index" variant="segmented" />

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <StatCard stat={{ label: 'ورديات عُدّت', value: number(counted.length), icon: 'clipboard-list', color: 'primary' }} />
                <StatCard
                    stat={{
                        label: 'ورديات بنقص',
                        value: number(short.length),
                        icon: 'alert-triangle',
                        color: short.length ? 'danger' : 'success',
                    }}
                />
                {/* المجموع لا المتوسّط: نقصٌ وزيادةٌ يتعادلان في المتوسّط فيبدو الصندوق سليمًا */}
                <StatCard
                    stat={{
                        label: 'صافي الفروق',
                        value: m(totalGap),
                        icon: 'wallet',
                        color: totalGap < 0 ? 'danger' : 'success',
                    }}
                />
            </div>

            {/* الوردية المنسيّة تبتلع مبيعات اليوم التالي — فتُقال أوّل الصفحة */}
            {stale.length > 0 && (
                <div className="mb-4 flex items-start gap-2 rounded-[10px] bg-[#fef2f2] px-3 py-2.5 text-[13px] text-[#b91c1c]">
                    <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                    <span>
                        {t(':n وردية مفتوحة منذ أكثر من :h ساعة — أقفلها قبل أن تُنسب إليها مبيعات يومٍ آخر.', {
                            n: number(stale.length),
                            h: number(maxHours),
                        })}
                    </span>
                </div>
            )}

            {uncounted.length > 0 && (
                <p className="mb-4 text-[12px] text-[#9ca3af]">
                    {t(':n وردية أُقفلت بلا عدّ — فرقُها مجهول ولا يدخل صافي الفروق أعلاه.', {
                        n: number(uncounted.length),
                    })}
                </p>
            )}

            <Card className="overflow-hidden">
                <DataTable
                    rows={shifts}
                    columns={columns}
                    rowKey={(s) => s.id}
                    searchPlaceholder="ابحث بالفرع أو الموظف…"
                    searchable={(s) => `${s.branch} ${s.opened_by} ${s.closed_by} ${s.note ?? ''}`}
                    empty="لا ورديات بعد"
                />
            </Card>

            <Dialog open={closing !== null} onOpenChange={(o) => !o && setClosing(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('إقفال الوردية بلا عدّ')}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitClose} className="space-y-4">
                        <p className="text-[13px] leading-relaxed text-[#6b7280]">
                            {t('الدرج لا يُعدّ بأثرٍ رجعيّ. ستُقفل الوردية ويبقى فرقُها مجهولًا — ولن يُحسب لها نقصٌ ولا زيادة.')}
                        </p>

                        <Field label="السبب" required error={form.errors.note}>
                            <Input
                                value={form.data.note}
                                onChange={(e) => form.setData('note', e.target.value)}
                                placeholder={t('مثال: نسي الكاشير الإقفال وغادر')}
                                required
                            />
                        </Field>

                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="outline" onClick={() => setClosing(null)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" loading={form.processing}>
                                <Lock />
                                {t('إقفال بلا عدّ')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
