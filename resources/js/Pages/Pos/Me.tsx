import { Link, usePage } from '@inertiajs/react';
import { BadgeCheck, CalendarDays, KeyRound, Mail, Phone, Receipt, Store, Wallet } from 'lucide-react';
import PosLayout from '@/Layouts/PosLayout';
import { Avatar, AvatarFallback, AvatarImage } from '@/Components/ui/avatar';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { initials, money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Payslip {
    id: number;
    period: string;
    basic: number;
    allowances: number;
    overtime: number;
    deductions: number;
    net: number;
    paid: boolean;
    paidAt: string | null;
    status: string | null;
}

interface ShiftRow {
    id: number;
    openedAt: string | null;
    closedAt: string | null;
    status: string;
    sales: number;
}

interface Props {
    me: {
        name: string;
        email: string | null;
        phone: string | null;
        avatar: string | null;
        jobTitle: string;
        roleLabel: string;
        branch: string | null;
        status: string;
        joined: string | null;
    };
    salary: { basic: number; allowances: number; monthly: number };
    payslips: Payslip[];
    sales: { todayTotal: number; todayCount: number; monthTotal: number; monthCount: number; allCount: number };
    shifts: ShiftRow[];
}

/**
 * «حسابي» — صفحة الموظّف عن نفسه.
 *
 * وكلُّ رقمٍ فيها رقمُ صاحبها: راتبه ومسيراتُه ومبيعاتُه وورديّاتُه. لا شيء
 * عن زميلٍ ولا عن المحلّ — والحصر في الخادم لا هنا (انظر MeController).
 */
export default function Me() {
    const { me, salary, payslips, sales, shifts, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    return (
        <PosLayout title={t('حسابي')}>
            <div className="mx-auto max-w-4xl space-y-5 p-4">
                {/* ------------------------------ بياناتي ------------------------------ */}
                <Card>
                    <CardContent className="flex flex-wrap items-center gap-4 pt-6">
                        <Avatar className="size-16">
                            {me.avatar && <AvatarImage src={me.avatar} alt="" />}
                            <AvatarFallback>{initials(me.name)}</AvatarFallback>
                        </Avatar>
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="text-lg font-bold text-gray-900">{me.name}</h1>
                                <Badge status={me.status}>{t(me.status)}</Badge>
                            </div>
                            <p className="mt-0.5 text-sm text-gray-500">
                                {me.jobTitle}
                                {me.jobTitle !== me.roleLabel ? ` · ${me.roleLabel}` : ''}
                            </p>
                            <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[13px] text-gray-500">
                                {me.branch && (
                                    <span className="flex items-center gap-1.5">
                                        <Store className="size-3.5 text-gray-400" />
                                        {me.branch}
                                    </span>
                                )}
                                {me.phone && (
                                    <span className="flex items-center gap-1.5">
                                        <Phone className="size-3.5 text-gray-400" />
                                        {me.phone}
                                    </span>
                                )}
                                {me.email && (
                                    <span className="flex items-center gap-1.5">
                                        <Mail className="size-3.5 text-gray-400" />
                                        {me.email}
                                    </span>
                                )}
                                {me.joined && (
                                    <span className="flex items-center gap-1.5">
                                        <CalendarDays className="size-3.5 text-gray-400" />
                                        {t('التحاقه')}: {me.joined}
                                    </span>
                                )}
                            </div>
                        </div>
                        {/* تغيير كلمة المرور بابٌ يخصّه — مفتوحٌ لكل الأدوار */}
                        <Button variant="outline" size="sm" className="gap-1.5" asChild>
                            <Link href={route('profile.edit')}>
                                <KeyRound className="size-4" />
                                {t('بياناتي وكلمة المرور')}
                            </Link>
                        </Button>
                    </CardContent>
                </Card>

                {/* ------------------------------ راتبي ------------------------------ */}
                <div className="grid gap-4 sm:grid-cols-3">
                    <Stat icon={<Wallet className="size-4" />} label={t('الراتب الأساسي')} value={m(salary.basic)} />
                    <Stat icon={<Wallet className="size-4" />} label={t('البدلات')} value={m(salary.allowances)} />
                    <Stat
                        icon={<BadgeCheck className="size-4" />}
                        label={t('الإجمالي الشهري')}
                        value={m(salary.monthly)}
                        strong
                    />
                </div>

                {/* ------------------------------ مبيعاتي ------------------------------ */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Receipt className="size-4 text-gray-400" />
                            {t('مبيعاتي')}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4 sm:grid-cols-3">
                        <Stat
                            label={t('اليوم')}
                            value={m(sales.todayTotal)}
                            hint={`${sales.todayCount} ${t('طلب')}`}
                        />
                        <Stat
                            label={t('هذا الشهر')}
                            value={m(sales.monthTotal)}
                            hint={`${sales.monthCount} ${t('طلب')}`}
                        />
                        <Stat label={t('إجمالي الطلبات')} value={String(sales.allCount)} />
                    </CardContent>
                </Card>

                {/* ---------------------------- مسيرات الراتب ---------------------------- */}
                <Card>
                    <CardHeader>
                        <CardTitle>{t('مسيرات راتبي')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('الشهر')}</TableHead>
                                    <TableHead>{t('الأساسي')}</TableHead>
                                    <TableHead>{t('البدلات')}</TableHead>
                                    <TableHead>{t('إضافي')}</TableHead>
                                    <TableHead>{t('خصومات')}</TableHead>
                                    <TableHead>{t('الصافي')}</TableHead>
                                    <TableHead>{t('الحالة')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {payslips.length === 0 ? (
                                    <TableEmpty colSpan={7}>
                                        {t('لا توجد مسيرات معتمدة بعد.')}
                                    </TableEmpty>
                                ) : (
                                    payslips.map((p) => (
                                        <TableRow key={p.id}>
                                            <TableCell className="font-medium text-gray-900">{p.period}</TableCell>
                                            <TableCell>{m(p.basic)}</TableCell>
                                            <TableCell>{m(p.allowances)}</TableCell>
                                            <TableCell>{m(p.overtime)}</TableCell>
                                            <TableCell>{m(p.deductions)}</TableCell>
                                            <TableCell className="font-semibold text-gray-900">{m(p.net)}</TableCell>
                                            <TableCell>
                                                {p.paid ? (
                                                    <span className="text-emerald-600">
                                                        {t('مصروف')}
                                                        {p.paidAt ? ` · ${p.paidAt}` : ''}
                                                    </span>
                                                ) : (
                                                    <span className="text-gray-400">{t('لم يُصرف بعد')}</span>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {/* ------------------------------ ورديّاتي ------------------------------ */}
                <Card>
                    <CardHeader>
                        <CardTitle>{t('ورديّاتي')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('الفتح')}</TableHead>
                                    <TableHead>{t('الإقفال')}</TableHead>
                                    <TableHead>{t('مبيعات الوردية')}</TableHead>
                                    <TableHead>{t('الحالة')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {shifts.length === 0 ? (
                                    <TableEmpty colSpan={4}>{t('لا ورديّات بعد.')}</TableEmpty>
                                ) : (
                                    shifts.map((s) => (
                                        <TableRow key={s.id}>
                                            <TableCell className="text-gray-900">{s.openedAt ?? '—'}</TableCell>
                                            <TableCell>{s.closedAt ?? '—'}</TableCell>
                                            <TableCell>{m(s.sales)}</TableCell>
                                            <TableCell>
                                                <Badge status={s.status}>{t(s.status)}</Badge>
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </PosLayout>
    );
}

function Stat({
    icon,
    label,
    value,
    hint,
    strong,
}: {
    icon?: React.ReactNode;
    label: string;
    value: string;
    hint?: string;
    strong?: boolean;
}) {
    return (
        <div className="rounded-xl border border-gray-200 bg-white p-4">
            <div className="flex items-center gap-1.5 text-[13px] text-gray-500">
                {icon && <span className="text-gray-400">{icon}</span>}
                {label}
            </div>
            <div className={strong ? 'mt-1 text-xl font-bold text-gray-900' : 'mt-1 text-lg font-semibold text-gray-900'}>
                {value}
            </div>
            {hint && <div className="mt-0.5 text-[12px] text-gray-400">{hint}</div>}
        </div>
    );
}
