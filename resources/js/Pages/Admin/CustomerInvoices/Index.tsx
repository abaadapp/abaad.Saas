import { useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { Plus, Search, Trash2 } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import StatCard from '@/Components/StatCard';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Row {
    id: number;
    number: string;
    customer: string;
    status: string;
    state: string;
    issued_at: string | null;
    due_at: string | null;
    total: number;
    paid: number;
    outstanding: number;
    days_overdue: number;
    po_number: string | null;
}

interface Props {
    invoices: Row[];
    customers: { id: number; name: string }[];
    filters: Record<string, string | undefined>;
    totals: { total: number; overdue: number; due_soon: number; credit: number; invoices: number; customers: number };
}

/** حالُ السداد لونًا — والمتأخّرةُ وحدها حمراء */
const tone = (state: string) =>
    state === 'متأخرة' ? 'bg-[#fef2f2] text-[#b91c1c]'
    : state === 'مدفوعة' ? 'bg-[#ecfdf5] text-[#047857]'
    : state === 'ملغاة' ? 'bg-[#f4f4f5] text-[#71717a]'
    : 'bg-[#eff6ff] text-[#1d4ed8]';

/**
 * فواتيرُ العملاء.
 *
 * والفاتورةُ ليست الطلب: الطلبُ تنفيذٌ ومخزون، وهذه التزامٌ ماليٌّ له تاريخُ
 * استحقاقٍ ويُسدَّد على دفعات.
 */
export default function CustomerInvoicesIndex({ invoices, customers, filters, totals }: Props) {
    const { context } = usePage<PageProps>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);
    const [q, setQ] = useState(filters.q ?? '');
    const [creating, setCreating] = useState(false);

    const search = (extra: Record<string, string> = {}) =>
        router.get('/admin/customer-invoices', { ...filters, q, ...extra }, { preserveState: true });

    return (
        <AdminLayout title={t('فواتير العملاء')}>
            <PageHeader
                title={t('فواتير العملاء')}
                subtitle={t('الفواتير الصادرة على الشركات والجهات، وما بقي منها.')}
                actions={
                    <Button onClick={() => setCreating((v) => !v)}>
                        <Plus />
                        {t('إنشاء فاتورة')}
                    </Button>
                }
            />

            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard stat={{ label: t('إجمالي الذمم'), value: m(totals.total), icon: 'wallet', color: 'primary' }} index={0} />
                <StatCard stat={{ label: t('المتأخر'), value: m(totals.overdue), icon: 'alert-triangle', color: 'danger' }} index={1} />
                <StatCard stat={{ label: t('يستحق خلال ٧ أيام'), value: m(totals.due_soon), icon: 'calendar', color: 'warning' }} index={2} />
                <StatCard stat={{ label: t('رصيد دائن للعملاء'), value: m(totals.credit), icon: 'coins', color: 'success' }} index={3} />
            </div>

            {creating && <CreateForm customers={customers} onDone={() => setCreating(false)} />}

            <Card className="mb-4 flex flex-wrap items-center gap-2 p-3">
                <Input
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                    onKeyDown={(e) => e.key === 'Enter' && search()}
                    placeholder={t('رقم الفاتورة أو أمر الشراء أو العميل')}
                    className="max-w-xs"
                />
                <Button variant="outline" onClick={() => search()}>
                    <Search />
                    {t('بحث')}
                </Button>
                <Button
                    variant={filters.overdue ? 'primary' : 'outline'}
                    onClick={() => search({ overdue: filters.overdue ? '' : '1' })}
                >
                    {t('المتأخرة فقط')}
                </Button>
            </Card>

            <Card className="overflow-x-auto p-0">
                <table className="w-full text-[13px]">
                    <thead className="bg-[#fafafa] text-[12px] text-[#71717a]">
                        <tr>
                            <th className="p-3 text-start">{t('الرقم')}</th>
                            <th className="p-3 text-start">{t('العميل')}</th>
                            <th className="p-3 text-start">{t('الاستحقاق')}</th>
                            <th className="p-3 text-start">{t('الإجمالي')}</th>
                            <th className="p-3 text-start">{t('الباقي')}</th>
                            <th className="p-3 text-start">{t('الحالة')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {invoices.map((i) => (
                            <tr key={i.id} className="border-t border-[var(--ui-border,#e8e8e8)]">
                                <td className="p-3">
                                    <Link href={`/admin/customer-invoices/${i.id}`} className="font-medium text-[#1d4ed8]">
                                        {i.number}
                                    </Link>
                                    {i.po_number && <div className="text-[11px] text-[#9ca3af]">{i.po_number}</div>}
                                </td>
                                <td className="p-3">{i.customer}</td>
                                <td className="p-3" dir="ltr">
                                    {i.due_at ?? '—'}
                                    {i.days_overdue > 0 && (
                                        <span className="ms-1 text-[11px] text-[#b91c1c]">
                                            {t('+:n يوم', { n: i.days_overdue })}
                                        </span>
                                    )}
                                </td>
                                <td className="p-3">{m(i.total)}</td>
                                <td className="p-3 font-medium">{m(i.outstanding)}</td>
                                <td className="p-3">
                                    <span className={'rounded-full px-2 py-1 text-[11px] ' + tone(i.state)}>{i.state}</span>
                                </td>
                            </tr>
                        ))}
                        {invoices.length === 0 && (
                            <tr>
                                <td colSpan={6} className="p-8 text-center text-[#9ca3af]">
                                    {t('لا فواتير بعد')}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </Card>
        </AdminLayout>
    );
}

interface Line {
    product_id: string;
    description: string;
    quantity: string;
    unit_price: string;
    discount: string;
}

const EMPTY: Line = { product_id: '', description: '', quantity: '1', unit_price: '0', discount: '0' };

/**
 * نموذجُ الإنشاء.
 *
 * وبياناتُ الجهة مطويّةٌ خلف زرّ: نصفُ الفواتير لأفرادٍ لا أمرَ شراء لهم،
 * ونموذجٌ بأربعةَ عشرَ حقلًا يجعل الأسهلَ أن يُملأ بأيّ شيء.
 */
function CreateForm({ customers, onDone }: { customers: { id: number; name: string }[]; onDone: () => void }) {
    const t = useTranslate();
    const [extra, setExtra] = useState(false);
    const [lines, setLines] = useState<Line[]>([{ ...EMPTY }]);

    const form = useForm({
        customer_id: '',
        issued_at: '',
        due_at: '',
        po_number: '',
        contract_number: '',
        external_reference: '',
        department: '',
        cost_center: '',
        attention_to: '',
        notes: '',
        issue: false,
        items: [] as Line[],
    });

    const setLine = (i: number, key: keyof Line, value: string) =>
        setLines((prev) => prev.map((l, idx) => (idx === i ? { ...l, [key]: value } : l)));

    /* والإجماليُّ هنا عرضٌ لا مصدر: الخادم يعيد حسابه من البنود */
    const preview = lines.reduce(
        (sum, l) => sum + Math.max(0, Number(l.quantity || 0) * Number(l.unit_price || 0) - Number(l.discount || 0)),
        0,
    );

    const submit = (issue: boolean) => {
        form.transform((data) => ({ ...data, issue, items: lines }));
        form.post('/admin/customer-invoices', { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <Card className="mb-4 space-y-4 p-4">
            <div className="grid gap-3 lg:grid-cols-3">
                <label className="text-[13px]">
                    {t('العميل')}
                    <select
                        className="mt-1 w-full rounded-[8px] border border-[var(--ui-border,#e8e8e8)] p-2"
                        value={form.data.customer_id}
                        onChange={(e) => form.setData('customer_id', e.target.value)}
                    >
                        <option value="">{t('اختر العميل')}</option>
                        {customers.map((c) => (
                            <option key={c.id} value={c.id}>{c.name}</option>
                        ))}
                    </select>
                    {form.errors.customer_id && <p className="text-[12px] text-[#b91c1c]">{form.errors.customer_id}</p>}
                </label>
                <label className="text-[13px]">
                    {t('تاريخ الفاتورة')}
                    <Input type="date" value={form.data.issued_at} onChange={(e) => form.setData('issued_at', e.target.value)} />
                </label>
                <label className="text-[13px]">
                    {t('تاريخ الاستحقاق')}
                    <Input type="date" value={form.data.due_at} onChange={(e) => form.setData('due_at', e.target.value)} />
                </label>
            </div>

            <button type="button" className="text-[12px] text-[#1d4ed8]" onClick={() => setExtra((v) => !v)}>
                {extra ? t('إخفاء بيانات الجهة') : t('بيانات إضافية للجهة')}
            </button>

            {extra && (
                <div className="grid gap-3 lg:grid-cols-3">
                    {([
                        ['po_number', 'رقم أمر الشراء'],
                        ['contract_number', 'رقم العقد'],
                        ['external_reference', 'المرجع'],
                        ['department', 'القسم'],
                        ['cost_center', 'مركز التكلفة'],
                        ['attention_to', 'عناية'],
                    ] as const).map(([key, label]) => (
                        <label key={key} className="text-[13px]">
                            {t(label)}
                            <Input
                                value={form.data[key] as string}
                                onChange={(e) => form.setData(key, e.target.value)}
                            />
                        </label>
                    ))}
                </div>
            )}

            <div className="space-y-2">
                {lines.map((line, i) => (
                    <div key={i} className="grid gap-2 lg:grid-cols-[1fr_100px_120px_120px_40px]">
                        <Input
                            value={line.description}
                            onChange={(e) => setLine(i, 'description', e.target.value)}
                            placeholder={t('البيان — مثال: توريد وتنسيق زهور لفعالية رسمية')}
                        />
                        <Input value={line.quantity} onChange={(e) => setLine(i, 'quantity', e.target.value)} placeholder={t('الكمية')} />
                        <Input value={line.unit_price} onChange={(e) => setLine(i, 'unit_price', e.target.value)} placeholder={t('السعر')} />
                        <Input value={line.discount} onChange={(e) => setLine(i, 'discount', e.target.value)} placeholder={t('الخصم')} />
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setLines((p) => (p.length > 1 ? p.filter((_, idx) => idx !== i) : p))}
                        >
                            <Trash2 />
                        </Button>
                    </div>
                ))}
                <Button type="button" variant="outline" onClick={() => setLines((p) => [...p, { ...EMPTY }])}>
                    <Plus />
                    {t('إضافة بند')}
                </Button>
                {form.errors.items && <p className="text-[12px] text-[#b91c1c]">{form.errors.items}</p>}
            </div>

            <div className="flex flex-wrap items-center gap-2 border-t border-[var(--ui-border,#e8e8e8)] pt-3">
                <span className="text-[13px] text-[#71717a]">
                    {t('المجموع قبل الضريبة')}: {preview.toFixed(3)}
                </span>
                <div className="ms-auto flex gap-2">
                    <Button variant="outline" disabled={form.processing} onClick={() => submit(false)}>
                        {t('حفظ كمسودة')}
                    </Button>
                    <Button disabled={form.processing} onClick={() => submit(true)}>
                        {t('إنشاء وإصدار')}
                    </Button>
                </div>
            </div>
        </Card>
    );
}
