import { useMemo, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Check, Lock, Pencil, Plus, Power, Trash2 } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { FINANCE_TABS } from '@/Components/SectionTabs';
import Field, { Select } from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface AccountRow {
    id: number;
    parent_id: number | null;
    code: string;
    name: string;
    type: string;
    normal_side: 'debit' | 'credit';
    active: boolean;
    /** يقصده الترحيل التلقائي بمفتاحه — لا يُحذف ولا يُغلق */
    system: boolean;
    is_parent: boolean;
    has_lines: boolean;
    balance: number;
}

interface Props {
    accounts: AccountRow[];
    trial: {
        total_debit: number;
        total_credit: number;
        balanced: boolean;
    };
    types: string[];
}

/** ألوان الأنواع الخمسة — تُقرأ الشجرة بلمحة بدل قراءة كل سطر */
const TYPE_TONE: Record<string, string> = {
    أصل: 'info',
    خصم: 'warning',
    'حقوق ملكية': 'neutral',
    إيراد: 'success',
    مصروف: 'danger',
};

export default function Chart() {
    const { accounts, trial, types, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const [editing, setEditing] = useState<AccountRow | null>(null);
    const [adding, setAdding] = useState(false);

    /*
     * الترتيب شجريّ لا أبجديّ.
     *
     * الفرز بالرمز وحده يصفّ الأبناء تحت آبائهم ما دامت الرموز متداخلة
     * (1100 تحت 1)، لكنّ حسابًا يضيفه التاجر برمزٍ من عنده (مثل «9») يقع في
     * آخر القائمة بعيدًا عن أبيه. فالبناء من العلاقة نفسها.
     */
    const tree = useMemo(() => {
        const byParent = new Map<number | null, AccountRow[]>();
        accounts.forEach((a) => {
            const list = byParent.get(a.parent_id) ?? [];
            list.push(a);
            byParent.set(a.parent_id, list);
        });
        byParent.forEach((list) => list.sort((x, y) => x.code.localeCompare(y.code, 'en', { numeric: true })));

        const out: { row: AccountRow; depth: number }[] = [];
        const walk = (parent: number | null, depth: number) => {
            (byParent.get(parent) ?? []).forEach((row) => {
                out.push({ row, depth });
                walk(row.id, depth + 1);
            });
        };
        walk(null, 0);

        // حسابٌ يتيم (أبوه محذوف) لا يسقط من الشاشة: يُلحق في آخرها
        const seen = new Set(out.map((o) => o.row.id));
        accounts.filter((a) => !seen.has(a.id)).forEach((row) => out.push({ row, depth: 0 }));

        return out;
    }, [accounts]);

    const parents = accounts.map((a) => ({ value: a.id, label: `${a.code} — ${a.name}` }));

    const form = useForm({
        parent_id: '',
        code: '',
        name: '',
        type: 'أصل',
        normal_side: 'debit',
        notes: '',
    });

    const openAdd = () => {
        form.clearErrors();
        form.setDefaults({ parent_id: '', code: '', name: '', type: 'أصل', normal_side: 'debit', notes: '' });
        form.reset();
        setAdding(true);
    };

    const openEdit = (row: AccountRow) => {
        form.clearErrors();
        form.setDefaults({
            parent_id: row.parent_id ? String(row.parent_id) : '',
            code: row.code,
            name: row.name,
            type: row.type,
            normal_side: row.normal_side,
            notes: '',
        });
        form.reset();
        setEditing(row);
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const done = {
            preserveScroll: true,
            onSuccess: () => {
                setAdding(false);
                setEditing(null);
            },
        };

        if (editing) form.put(route('admin.finance.chart.update', editing.id), done);
        else form.post(route('admin.finance.chart.store'), done);
    };

    const open = adding || editing !== null;
    // النوع والطبيعة يُقفلان بعد أوّل قيد: قلبُهما يقلب إشارة التاريخ كلّه
    const locked = !!editing?.has_lines;

    return (
        <AdminLayout title="شجرة الحسابات">
            <PageHeader
                title="شجرة الحسابات"
                subtitle={t('الهيكل الذي تُقرأ عليه كلّ أرقام النشاط')}
                actions={
                    <Button onClick={openAdd}>
                        <Plus />
                        {t('حساب جديد')}
                    </Button>
                }
            />

            <SectionTabs tabs={FINANCE_TABS} current="admin.finance.chart" />

            <Card className="overflow-hidden">
                <Table>
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead>{t('الرمز')}</TableHead>
                            <TableHead>{t('الحساب')}</TableHead>
                            <TableHead>{t('النوع')}</TableHead>
                            <TableHead>{t('الطبيعة')}</TableHead>
                            <TableHead className="text-end">{t('الرصيد')}</TableHead>
                            <TableHead className="text-end">{t('إجراءات')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {tree.map(({ row, depth }) => (
                            <TableRow key={row.id} className={cn(!row.active && 'opacity-50')}>
                                <TableCell className="font-mono text-[12px] text-[#6b7280]">{row.code}</TableCell>
                                <TableCell>
                                    <span
                                        className="inline-flex items-center gap-1.5"
                                        // الإزاحة تصنع الشجرة — والاتجاه يتبع اللغة فتُقلب مع RTL
                                        style={{ paddingInlineStart: `${depth * 18}px` }}
                                    >
                                        <span className={cn(depth === 0 ? 'font-bold text-[#111]' : 'text-[#374151]')}>
                                            {row.name}
                                        </span>
                                        {row.system && (
                                            <Lock className="size-3 text-[#9ca3af]" aria-label={t('حساب نظامي')} />
                                        )}
                                        {!row.active && <Badge variant="neutral">{t('مغلق')}</Badge>}
                                    </span>
                                </TableCell>
                                <TableCell>
                                    <Badge variant={(TYPE_TONE[row.type] ?? 'neutral') as never}>{t(row.type)}</Badge>
                                </TableCell>
                                <TableCell className="text-[13px] text-[#6b7280]">
                                    {t(row.normal_side === 'debit' ? 'مدين' : 'دائن')}
                                </TableCell>
                                <TableCell
                                    className={cn(
                                        'text-end font-semibold tabular-nums',
                                        row.balance < 0 ? 'text-[#b91c1c]' : 'text-[#111]',
                                    )}
                                >
                                    {row.balance === 0 ? '—' : m(row.balance)}
                                </TableCell>
                                <TableCell className="text-end">
                                    <div className="flex items-center justify-end gap-1">
                                        <Button variant="ghost" size="sm" onClick={() => openEdit(row)}>
                                            <Pencil />
                                        </Button>
                                        {!row.system && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    router.post(
                                                        route('admin.finance.chart.toggle', row.id),
                                                        {},
                                                        { preserveScroll: true },
                                                    )
                                                }
                                                aria-label={t(row.active ? 'إغلاق الحساب' : 'فتح الحساب')}
                                            >
                                                <Power />
                                            </Button>
                                        )}
                                        {!row.system && !row.has_lines && !row.is_parent && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="text-[#b91c1c]"
                                                onClick={() => {
                                                    if (!confirm(t('حذف الحساب؟'))) return;
                                                    router.delete(route('admin.finance.chart.destroy', row.id), {
                                                        preserveScroll: true,
                                                    });
                                                }}
                                            >
                                                <Trash2 />
                                            </Button>
                                        )}
                                    </div>
                                </TableCell>
                            </TableRow>
                        ))}

                        {/*
                            ميزان المراجعة في تذييل الشجرة لا في صفحةٍ أخرى.
                            مجموعان لا يتطابقان يعنيان خللًا في الدفتر نفسه، وهو
                            ما يجب أن يُرى فور فتح الشاشة لا أن يُبحث عنه.
                        */}
                        <TableRow className="bg-[#fafafa] font-semibold hover:bg-[#fafafa]">
                            <TableCell colSpan={4} className="text-[#111]">
                                {t('ميزان المراجعة')}
                                <span className="ms-2 font-normal text-[12px] text-[#6b7280]">
                                    {t('مدين')} {m(trial.total_debit)} · {t('دائن')} {m(trial.total_credit)}
                                </span>
                            </TableCell>
                            <TableCell colSpan={2} className="text-end">
                                <Badge variant={trial.balanced ? 'success' : 'danger'}>
                                    {t(trial.balanced ? 'متوازن' : 'غير متوازن')}
                                </Badge>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </Card>

            <Dialog
                open={open}
                onOpenChange={(o) => {
                    if (!o) {
                        setAdding(false);
                        setEditing(null);
                    }
                }}
            >
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t(editing ? 'تعديل الحساب' : 'حساب جديد')}</DialogTitle>
                    </DialogHeader>

                    <form onSubmit={submit} className="space-y-4 px-5 pb-5">
                        <Field label="الحساب الأب" hint="اتركه فارغًا لحسابٍ رئيسي" error={form.errors.parent_id}>
                            <Select
                                placeholder="بلا أب — حساب رئيسي"
                                value={form.data.parent_id}
                                onChange={(e) => form.setData('parent_id', e.target.value)}
                                options={parents.filter((p) => p.value !== editing?.id)}
                            />
                        </Field>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <Field label="الرمز" required error={form.errors.code}>
                                <Input
                                    dir="ltr"
                                    value={form.data.code}
                                    onChange={(e) => form.setData('code', e.target.value)}
                                    placeholder="1250"
                                />
                            </Field>
                            <Field label="الاسم" required error={form.errors.name} className="sm:col-span-2">
                                <Input
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                />
                            </Field>
                        </div>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="النوع" required error={form.errors.type}>
                                <Select
                                    value={form.data.type}
                                    disabled={locked}
                                    onChange={(e) => {
                                        const next = e.target.value;
                                        form.setData((d) => ({
                                            ...d,
                                            type: next,
                                            // الطبيعة تتبع النوع ما لم تُقلب عمدًا بعدها
                                            normal_side: ['أصل', 'مصروف'].includes(next) ? 'debit' : 'credit',
                                        }));
                                    }}
                                    options={types.map((x) => ({ value: x, label: x }))}
                                />
                            </Field>
                            <Field
                                label="الطبيعة"
                                hint="تُقلب للحسابات المقابلة: مجمّع الإهلاك أصلٌ دائن"
                                error={form.errors.normal_side}
                            >
                                <Select
                                    value={form.data.normal_side}
                                    disabled={locked}
                                    onChange={(e) => form.setData('normal_side', e.target.value)}
                                    options={[
                                        { value: 'debit', label: 'مدين' },
                                        { value: 'credit', label: 'دائن' },
                                    ]}
                                />
                            </Field>
                        </div>

                        {locked && (
                            <p className="text-[12px] text-[#9ca3af]">
                                {t('على الحساب قيودٌ مرحَّلة، فلا يُبدَّل نوعه ولا طبيعته: تبديلهما يقلب إشارة رصيده التاريخيّ كلّه.')}
                            </p>
                        )}

                        <div className="flex justify-end gap-2 pt-2">
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => {
                                    setAdding(false);
                                    setEditing(null);
                                }}
                            >
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" loading={form.processing}>
                                <Check />
                                {t('حفظ')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
