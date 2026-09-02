import { useRef, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { ArrowRight, Landmark, RefreshCw, Save, Trash2, Upload, UploadCloud } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { FINANCE_TABS } from '@/Components/SectionTabs';
import Tabs from '@/Components/Tabs';
import SmartLink from '@/Components/SmartLink';
import StatCard from '@/Components/StatCard';
import Field, { Select } from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { money, number } from '@/lib/format';
import { useConfirm } from '@/Components/ConfirmDialog';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface StatementRow {
    id: number;
    reference: string;
    date: string;
    description: string;
    method: string;
    debit: number;
    credit: number;
    balance: number;
}

interface BankLine {
    id: number;
    date: string;
    description: string;
    reference: string;
    amount: number;
    status: string;
    matched: boolean;
    transaction: string | null;
}

interface Account {
    id: number;
    label: string;
    bank_name: string | null;
    account_name: string | null;
    iban: string | null;
    opening_balance: number;
    opening_date: string | null;
}

interface Props {
    account: Account;
    /** حسابات النشاط كلّها — للتنقّل بينها بلا الرجوع إلى القائمة */
    accounts: { id: number; label: string }[];
    /** تاريخ اليوم بتوقيت الخادم — تعبئة تاريخ الرصيد الافتتاحي حين لا يكون محفوظًا */
    today: string;
    statement: { opening: number; rows: StatementRow[]; closing: number };
    lines: BankLine[];
    reconciliation: {
        lines: number;
        matched: number;
        unmatched_bank: number;
        unmatched_system: number;
        bank_total: number;
    };
}

const TABS = [
    { key: 'statement', label: 'كشف الحساب' },
    { key: 'reconcile', label: 'المطابقة مع البنك' },
    { key: 'account', label: 'بيانات الحساب' },
] as const;

type TabKey = (typeof TABS)[number]['key'];

export default function FinanceStatement() {
    const { account, accounts, statement, lines, reconciliation, today, context } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    // نافذةُ التأكيد من النظام لا من المتصفّح — انظر ConfirmDialog
    const [ask, confirmDialog] = useConfirm();
    const currency = context!.currency;
    const m = (v: number) => money(v, currency);

    const [tab, setTab] = useState<TabKey>('statement');
    const [file, setFile] = useState<File | null>(null);
    const fileInput = useRef<HTMLInputElement>(null);

    const accountForm = useForm({
        bank_name: account.bank_name ?? '',
        account_name: account.account_name ?? '',
        iban: account.iban ?? '',
        opening_balance: String(account.opening_balance ?? 0),
        opening_date: account.opening_date ?? today,
    });

    /*
     * كلّ فعلٍ هنا يحمل الحساب الذي يخصّه.
     *
     * بلا هذا كان الاستيراد والمطابقة والحذف تقع كلّها على «الحساب الرئيسيّ»
     * أيًّا كان المفتوح على الشاشة: تفتح كشف حساب المصروفات وترفع ملفّه فيدخل
     * على حساب التحصيل، ولا شيء في الشاشة يقول ذلك.
     */
    const bank = { bank_account_id: account.id };

    const importStatement = (e: React.FormEvent) => {
        e.preventDefault();
        if (!file) return;
        router.post(route('admin.bank.import'), { ...bank, statement: file }, { forceFormData: true });
    };

    return (
        <AdminLayout title="كشف الحساب البنكي">
            <PageHeader
                title="كشف الحساب البنكي"
                subtitle={account.label}
                actions={
                    <>
                        {accounts.length > 1 && (
                            <Select
                                value={String(account.id)}
                                aria-label={t('الحساب البنكي')}
                                className="w-48"
                                onChange={(e) =>
                                    router.get(route('admin.finance.statement', e.target.value))
                                }
                                options={accounts.map((a) => ({ value: a.id, label: a.label }))}
                            />
                        )}
                        <Button variant="outline" asChild>
                            <SmartLink routeName="admin.finance.index" href={route('admin.finance.index')}>
                                <ArrowRight />
                                {t('الحسابات البنكية')}
                            </SmartLink>
                        </Button>
                    </>
                }
            />

            {/* الشريط يُضيء «الحسابات البنكية»: هذه صفحةُ حسابٍ منها لا تبويبٌ سادس */}
            <SectionTabs tabs={FINANCE_TABS} current="admin.finance.index" />

            {/* تبويبات داخل الصفحة: تبدّل جزءًا منها ولا تنقل بين صفحات القسم.
                وشكلها شكل شريط القسم فوقها — يفصل بينهما التباعد لا اختلاف
                الشريط، منذ صار للنظام شكل تبويبٍ واحد. */}
            <Tabs
                tabs={TABS.map((x) => ({ key: x.key, label: x.label }))}
                current={tab}
                onChange={(k) => setTab(k as TabKey)}
            />

            {/* ===== كشف الحساب المحسوب ===== */}
            {tab === 'statement' && (
                <>
                    <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                        {[
                            { label: t('الرصيد الافتتاحي'), value: m(statement.opening), icon: 'wallet', color: 'secondary' },
                            { label: t('عدد الحركات'), value: number(statement.rows.length), icon: 'list', color: 'info' },
                            { label: t('الرصيد الحالي'), value: m(statement.closing), icon: 'landmark', color: 'primary' },
                        ].map((s, i) => (
                            <StatCard key={s.label} stat={s} index={i} />
                        ))}
                    </div>

                    <Card className="overflow-hidden">
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    <TableHead>{t('المرجع')}</TableHead>
                                    <TableHead>{t('التاريخ')}</TableHead>
                                    <TableHead>{t('البيان')}</TableHead>
                                    <TableHead className="text-end">{t('مدين')}</TableHead>
                                    <TableHead className="text-end">{t('دائن')}</TableHead>
                                    <TableHead className="text-end">{t('الرصيد')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {/* سطر الرصيد الافتتاحي — نقطة انطلاق العمود المتراكم */}
                                <TableRow className="bg-[#fafafa] hover:bg-[#fafafa]">
                                    <TableCell colSpan={5} className="text-[#9ca3af]">
                                        {t('رصيد افتتاحي')}
                                        {account.opening_date ? ` — ${account.opening_date}` : ''}
                                    </TableCell>
                                    <TableCell className="text-end font-semibold tabular-nums text-[#111]">
                                        {m(statement.opening)}
                                    </TableCell>
                                </TableRow>

                                {statement.rows.map((r) => (
                                    <TableRow key={r.id}>
                                        <TableCell className="font-mono text-[12px] text-[#4b4b4b]">
                                            {r.reference}
                                        </TableCell>
                                        <TableCell dir="ltr" className="text-[#6b7280]">
                                            {r.date}
                                        </TableCell>
                                        <TableCell>{r.description}</TableCell>
                                        <TableCell className="text-end tabular-nums text-[#b91c1c]">
                                            {r.debit > 0 ? m(r.debit) : '—'}
                                        </TableCell>
                                        <TableCell className="text-end tabular-nums text-[#047857]">
                                            {r.credit > 0 ? m(r.credit) : '—'}
                                        </TableCell>
                                        <TableCell
                                            className={cn(
                                                'text-end font-semibold tabular-nums',
                                                r.balance < 0 ? 'text-[#b91c1c]' : 'text-[#111]',
                                            )}
                                        >
                                            {m(r.balance)}
                                        </TableCell>
                                    </TableRow>
                                ))}

                                {/* الرصيد الختامي — تذييل الجدول */}
                                <TableRow className="bg-[#fafafa] font-semibold hover:bg-[#fafafa]">
                                    <TableCell colSpan={5} className="text-[#111]">
                                        {t('الرصيد الختامي')}
                                    </TableCell>
                                    <TableCell className="text-end tabular-nums text-[#111]">
                                        {m(statement.closing)}
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </Card>
                    <p className="mt-3 text-[12px] text-[#9ca3af]">
                        {t('الرصيد يُحتسب تلقائيًا: الرصيد الافتتاحي + الإيرادات − المصروفات، مرتّبًا بالتاريخ. ولا يدخله إلا ما مرّ بالبنك — البطاقة والتحويل — ولا ما سبق تاريخ الرصيد الافتتاحي لأنه داخلٌ فيه.')}
                    </p>
                </>
            )}

            {/* ===== المطابقة مع كشف البنك ===== */}
            {tab === 'reconcile' && (
                <>
                    <Card className="mb-6 p-6">
                        <h3 className="mb-2 font-bold text-[#111]">{t('استيراد كشف البنك')}</h3>
                        <p className="mb-4 text-[13px] text-[#6b7280]">
                            {t('ارفع ملف الكشف من بنكك ليطابقه النظام تلقائيًا مع معاملاته.')}{' '}
                            {t('يجب أن يحتوي الملف على عمودَي «التاريخ» و«المبلغ» (وأعمدة البيان والمرجع اختيارية).')}{' '}
                            {t('المبلغ الموجب = وارد، والسالب = صادر.')}
                        </p>

                        <form onSubmit={importStatement}>
                            <label className="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-[16px] border-2 border-dashed border-[var(--ui-border,#e8e8e8)] p-6 text-center transition hover:border-[#d1d5db]">
                                <UploadCloud className="size-8 text-[#9ca3af]" />
                                <span className="text-sm text-[#374151]">
                                    {file?.name || t('اختر ملف كشف البنك')}
                                </span>
                                <span className="text-[12px] text-[#9ca3af]">
                                    {t('XLSX · XLS · XLSM · CSV — حتى 10 ميجابايت. يُقبل عمود «المبلغ» أو عمودا «مدين» و«دائن». والاستيراد يضيف ولا يمسح، والمكرّر يُتخطّى.')}
                                </span>
                                <input
                                    ref={fileInput}
                                    type="file"
                                    className="hidden"
                                    accept=".xlsx,.xls,.xlsm,.csv"
                                    onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                                />
                            </label>

                            <div className="mt-4 flex flex-wrap items-center gap-2">
                                <Button type="submit" disabled={!file}>
                                    <Upload />
                                    {t('استيراد ومطابقة')}
                                </Button>

                                {reconciliation.lines > 0 && (
                                    <>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() =>
                                                router.post(route('admin.bank.rematch'), bank, { preserveScroll: true })
                                            }
                                        >
                                            <RefreshCw />
                                            {t('إعادة المطابقة')}
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            className="text-[#b91c1c]"
                                            onClick={async () => {
                                                if (! await ask({ message: 'حذف الكشف المستورد؟', danger: true, action: 'حذف' })) return;
                                                router.delete(route('admin.bank.clear'), { data: bank, preserveScroll: true });
                                            }}
                                        >
                                            <Trash2 />
                                            {t('حذف الكشف')}
                                        </Button>
                                    </>
                                )}
                            </div>
                        </form>
                    </Card>

                    {reconciliation.lines === 0 ? (
                        <Card className="p-12 text-center">
                            <Landmark className="mx-auto mb-3 size-8 text-[#d1d5db]" />
                            <p className="font-medium text-[#374151]">{t('لم يُستورد كشف بنك بعد')}</p>
                            <p className="mx-auto mt-1 max-w-md text-[13px] text-[#9ca3af]">
                                {t('ارفع ملف كشف الحساب من بنكك ليطابقه النظام تلقائيًا مع معاملاته ويُظهر الفروقات.')}
                            </p>
                        </Card>
                    ) : (
                        <>
                            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                {[
                                    { label: t('أسطر الكشف'), value: number(reconciliation.lines), icon: 'list', color: 'secondary' },
                                    { label: t('مطابَقة'), value: number(reconciliation.matched), icon: 'circle-check', color: 'success' },
                                    { label: t('في البنك ولا يقابلها معاملة'), value: number(reconciliation.unmatched_bank), icon: 'alert-triangle', color: 'warning' },
                                    { label: t('في النظام ولا يقابلها سطر بنكي'), value: number(reconciliation.unmatched_system), icon: 'circle-alert', color: 'danger' },
                                ].map((s, i) => (
                                    <StatCard key={s.label} stat={s} index={i} />
                                ))}
                            </div>

                            <Card className="overflow-hidden">
                                <Table>
                                    <TableHeader>
                                        <TableRow className="hover:bg-transparent">
                                            <TableHead>{t('التاريخ')}</TableHead>
                                            <TableHead>{t('البيان')}</TableHead>
                                            <TableHead>{t('المرجع')}</TableHead>
                                            <TableHead className="text-end">{t('المبلغ')}</TableHead>
                                            <TableHead>{t('الحالة')}</TableHead>
                                            <TableHead>{t('المعاملة المطابِقة')}</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {lines.length === 0 ? (
                                            <TableEmpty colSpan={6}>{t('لا توجد أسطر')}</TableEmpty>
                                        ) : (
                                            lines.map((l) => (
                                                <TableRow
                                                    key={l.id}
                                                    className={cn(!l.matched && 'bg-[#fffbeb] hover:bg-[#fef3c7]')}
                                                >
                                                    <TableCell dir="ltr" className="text-[#6b7280]">
                                                        {l.date}
                                                    </TableCell>
                                                    <TableCell>{l.description}</TableCell>
                                                    <TableCell className="font-mono text-[12px] text-[#6b7280]">
                                                        {l.reference}
                                                    </TableCell>
                                                    <TableCell className="text-end whitespace-nowrap">
                                                        <span
                                                            className={cn(
                                                                'font-semibold tabular-nums',
                                                                l.amount >= 0 ? 'text-[#047857]' : 'text-[#b91c1c]',
                                                            )}
                                                        >
                                                            {m(Math.abs(l.amount))}
                                                        </span>{' '}
                                                        <span className="text-[12px] text-[#9ca3af]">
                                                            {t(l.amount >= 0 ? 'وارد' : 'صادر')}
                                                        </span>
                                                    </TableCell>
                                                    <TableCell>
                                                        <Badge variant={l.matched ? 'success' : 'warning'}>
                                                            {t(l.status)}
                                                        </Badge>
                                                    </TableCell>
                                                    <TableCell className="font-mono text-[12px] text-[#6b7280]">
                                                        {l.transaction ?? '—'}
                                                    </TableCell>
                                                </TableRow>
                                            ))
                                        )}
                                    </TableBody>
                                </Table>
                            </Card>
                            <p className="mt-3 text-[12px] text-[#9ca3af]">
                                {t('قاعدة المطابقة: نفس المبلغ (بفارق لا يتجاوز 0.005 ر.ع) وتاريخ ضمن ±3 أيام، وكل معاملة تُستخدم مرة واحدة.')}
                            </p>
                        </>
                    )}
                </>
            )}

            {/* ===== بيانات الحساب ===== */}
            {tab === 'account' && (
                <Card className="max-w-2xl p-6">
                    <h3 className="mb-5 font-bold text-[#111]">{t('بيانات حساب الشركة البنكي')}</h3>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            accountForm.put(route('admin.finance.banks.update', account.id), { preserveScroll: true });
                        }}
                        className="space-y-4"
                    >
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="اسم البنك" error={accountForm.errors.bank_name}>
                                <Input
                                    value={accountForm.data.bank_name}
                                    onChange={(e) => accountForm.setData('bank_name', e.target.value)}
                                    placeholder={t('بنك مسقط')}
                                />
                            </Field>
                            <Field label="اسم الحساب" error={accountForm.errors.account_name}>
                                <Input
                                    value={accountForm.data.account_name}
                                    onChange={(e) => accountForm.setData('account_name', e.target.value)}
                                    placeholder={t('اسم الشركة')}
                                />
                            </Field>
                        </div>

                        <Field label="رقم الآيبان (IBAN)" error={accountForm.errors.iban}>
                            <Input
                                dir="ltr"
                                value={accountForm.data.iban}
                                onChange={(e) => accountForm.setData('iban', e.target.value)}
                                placeholder="OM00 0000 0000 0000 0000 0000"
                            />
                        </Field>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="الرصيد الافتتاحي" error={accountForm.errors.opening_balance}>
                                <Input
                                    type="number"
                                    step="0.001"
                                    dir="ltr"
                                    value={accountForm.data.opening_balance}
                                    onChange={(e) => accountForm.setData('opening_balance', e.target.value)}
                                />
                            </Field>
                            <Field label="تاريخ الرصيد الافتتاحي" error={accountForm.errors.opening_date}>
                                <Input
                                    type="date"
                                    dir="ltr"
                                    value={accountForm.data.opening_date}
                                    onChange={(e) => accountForm.setData('opening_date', e.target.value)}
                                />
                            </Field>
                        </div>

                        <div className="flex justify-end">
                            <Button type="submit" loading={accountForm.processing}>
                                <Save />
                                {t('حفظ البيانات')}
                            </Button>
                        </div>
                    </form>
                </Card>
            )}

            {confirmDialog}
        </AdminLayout>
    );
}
