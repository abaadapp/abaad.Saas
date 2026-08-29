import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Check, Landmark, Pencil, Plus, Star, Trash2 } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { FINANCE_TABS } from '@/Components/SectionTabs';
import ExportMenu from '@/Components/ExportMenu';
import SmartLink from '@/Components/SmartLink';
import StatCard from '@/Components/StatCard';
import Field from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface BankRow {
    id: number;
    label: string;
    bank_name: string | null;
    account_name: string | null;
    iban: string | null;
    opening_balance: number;
    opening_date: string | null;
    active: boolean;
    is_primary: boolean;
    /** الرصيد الافتتاحي وما جرى عليه في الدفتر */
    balance: number;
    /** رمز ورقته في شجرة الحسابات */
    account_code: string | null;
    lines: number;
    matched: number;
}

interface Props {
    accounts: BankRow[];
    summary: { count: number; balance: number };
    today: string;
}

const blank = (today: string) => ({
    label: '',
    bank_name: '',
    account_name: '',
    iban: '',
    opening_balance: '0',
    opening_date: today,
    active: true as boolean,
});

export default function Banks() {
    const { accounts, summary, today, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    // null = مغلق · 0 = إضافة · رقم = تعديل حسابٍ بعينه
    const [editing, setEditing] = useState<number | null>(null);
    const form = useForm(blank(today));

    const open = (row?: BankRow) => {
        form.clearErrors();
        form.setDefaults(
            row
                ? {
                      label: row.label ?? '',
                      bank_name: row.bank_name ?? '',
                      account_name: row.account_name ?? '',
                      iban: row.iban ?? '',
                      opening_balance: String(row.opening_balance ?? 0),
                      opening_date: row.opening_date ?? today,
                      active: row.active,
                  }
                : blank(today),
        );
        form.reset();
        setEditing(row ? row.id : 0);
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const done = { preserveScroll: true, onSuccess: () => setEditing(null) };

        if (editing) form.put(route('admin.finance.banks.update', editing), done);
        else form.post(route('admin.finance.banks.store'), done);
    };

    return (
        <AdminLayout title="الحسابات البنكية">
            <PageHeader
                title="الحسابات البنكية"
                subtitle={t('حسابات النشاط ورصيد كلٍّ منها، وكشفه ومطابقته مع الدفتر')}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        {/*
                         * تقرير الحركة المالية كان مبنيًّا ولا بابَ إليه: ثلاثة
                         * مسارات تصديرٍ لا يقصدها زرٌّ في الواجهة كلّها، وبطاقةُ
                         * «الحركة المالية» في فهرس التقارير تقود إلى هذه الشاشة
                         * — وفيها الأرصدة لا المقبوضات والمدفوعات. فيُوصَل هنا،
                         * وهي وجهةُ البطاقة أصلًا.
                         */}
                        <ExportMenu
                            label="الحركة المالية"
                            xlsx={route('admin.finance.xlsx')}
                            pdf={route('admin.finance.pdf')}
                            csv={route('admin.export.transactions')}
                        />
                        <Button onClick={() => open()}>
                            <Plus />
                            {t('حساب بنكي')}
                        </Button>
                    </div>
                }
            />

            <SectionTabs tabs={FINANCE_TABS} current="admin.finance.index" />

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <StatCard stat={{ label: t('حسابات مفعّلة'), value: number(summary.count), icon: 'landmark', color: 'info' }} index={0} />
                <StatCard stat={{ label: t('مجموع الأرصدة'), value: m(summary.balance), icon: 'wallet', color: 'primary' }} index={1} />
            </div>

            {accounts.length === 0 ? (
                <Card className="p-12 text-center">
                    <Landmark className="mx-auto mb-3 size-8 text-[#d1d5db]" />
                    <p className="font-medium text-[#374151]">{t('لا حساب بنكي بعد')}</p>
                    <p className="mx-auto mt-1 max-w-md text-[13px] text-[#9ca3af]">
                        {t('أضف حساب نشاطك البنكي ليُقرأ رصيده في الدفتر ويُطابَق كشفه مع معاملات النظام.')}
                    </p>
                </Card>
            ) : (
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    {accounts.map((a) => (
                        <Card key={a.id} className={cn('p-5', !a.active && 'opacity-60')}>
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h3 className="truncate font-bold text-[#111]">{a.label}</h3>
                                        {a.is_primary && (
                                            <Badge variant="info">{t('رئيسي')}</Badge>
                                        )}
                                        {!a.active && <Badge variant="neutral">{t('موقوف')}</Badge>}
                                    </div>
                                    {a.account_name && (
                                        <p className="mt-0.5 truncate text-[13px] text-[#6b7280]">{a.account_name}</p>
                                    )}
                                    {a.iban && (
                                        <p dir="ltr" className="mt-1 truncate font-mono text-[12px] text-[#9ca3af]">
                                            {a.iban}
                                        </p>
                                    )}
                                </div>
                                <span className="flex size-10 shrink-0 items-center justify-center rounded-[12px] bg-[#eff6ff] text-[#2563eb]">
                                    <Landmark className="size-5" />
                                </span>
                            </div>

                            <div className="mt-4 flex items-end justify-between gap-3">
                                <div>
                                    <p className="text-[12px] text-[#9ca3af]">{t('الرصيد')}</p>
                                    <p
                                        className={cn(
                                            'text-[20px] font-bold tabular-nums tracking-tight',
                                            a.balance < 0 ? 'text-[#b91c1c]' : 'text-[#111]',
                                        )}
                                    >
                                        {m(a.balance)}
                                    </p>
                                </div>
                                <div className="text-end text-[12px] text-[#9ca3af]">
                                    {a.account_code && <p>{t('الحساب')} {a.account_code}</p>}
                                    {a.lines > 0 && (
                                        <p>
                                            {t('الكشف')} {number(a.matched)}/{number(a.lines)}
                                        </p>
                                    )}
                                </div>
                            </div>

                            <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-[var(--ui-border,#e8e8e8)] pt-4">
                                <Button variant="outline" size="sm" asChild>
                                    <SmartLink
                                        routeName="admin.finance.statement"
                                        href={route('admin.finance.statement', a.id)}
                                    >
                                        {t('كشف الحساب')}
                                    </SmartLink>
                                </Button>
                                <Button variant="ghost" size="sm" onClick={() => open(a)}>
                                    <Pencil />
                                    {t('تعديل')}
                                </Button>
                                {!a.is_primary && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            router.post(route('admin.finance.banks.primary', a.id), {}, { preserveScroll: true })
                                        }
                                    >
                                        <Star />
                                        {t('اجعله الرئيسيّ')}
                                    </Button>
                                )}
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="ms-auto text-[#b91c1c]"
                                    onClick={() => {
                                        if (!confirm(t('حذف الحساب البنكي وكشفه المستورد؟'))) return;
                                        router.delete(route('admin.finance.banks.destroy', a.id), { preserveScroll: true });
                                    }}
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        </Card>
                    ))}
                </div>
            )}

            <Dialog open={editing !== null} onOpenChange={(o) => !o && setEditing(null)}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t(editing ? 'تعديل الحساب البنكي' : 'حساب بنكي جديد')}</DialogTitle>
                    </DialogHeader>

                    <form onSubmit={submit} className="space-y-4 px-5 pb-5">
                        <Field
                            label="الاسم المختصر"
                            hint="ما يميّزه في القوائم — «التحصيل» أوضح من رقم الحساب"
                            error={form.errors.label}
                        >
                            <Input
                                value={form.data.label}
                                onChange={(e) => form.setData('label', e.target.value)}
                                placeholder={t('حساب التحصيل')}
                            />
                        </Field>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="اسم البنك" error={form.errors.bank_name}>
                                <Input
                                    value={form.data.bank_name}
                                    onChange={(e) => form.setData('bank_name', e.target.value)}
                                    placeholder={t('بنك مسقط')}
                                />
                            </Field>
                            <Field label="اسم الحساب" error={form.errors.account_name}>
                                <Input
                                    value={form.data.account_name}
                                    onChange={(e) => form.setData('account_name', e.target.value)}
                                />
                            </Field>
                        </div>

                        <Field label="رقم الآيبان (IBAN)" error={form.errors.iban}>
                            <Input
                                dir="ltr"
                                value={form.data.iban}
                                onChange={(e) => form.setData('iban', e.target.value)}
                                placeholder="OM00 0000 0000 0000 0000 0000"
                            />
                        </Field>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field
                                label="الرصيد الافتتاحي"
                                hint="رصيد ما قبل النظام — لا يدخل الدفتر فلا يُقرأ إيرادًا"
                                error={form.errors.opening_balance}
                            >
                                <Input
                                    type="number"
                                    step="0.001"
                                    dir="ltr"
                                    value={form.data.opening_balance}
                                    onChange={(e) => form.setData('opening_balance', e.target.value)}
                                />
                            </Field>
                            <Field label="تاريخ الرصيد الافتتاحي" error={form.errors.opening_date}>
                                <Input
                                    type="date"
                                    dir="ltr"
                                    value={form.data.opening_date}
                                    onChange={(e) => form.setData('opening_date', e.target.value)}
                                />
                            </Field>
                        </div>

                        {editing !== 0 && (
                            <label className="flex cursor-pointer items-center gap-2 text-sm text-[#374151]">
                                <input
                                    type="checkbox"
                                    className="size-4 accent-[#111]"
                                    checked={form.data.active}
                                    onChange={(e) => form.setData('active', e.target.checked)}
                                />
                                {t('الحساب مفعّل')}
                            </label>
                        )}

                        <div className="flex justify-end gap-2 pt-2">
                            <Button type="button" variant="ghost" onClick={() => setEditing(null)}>
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
