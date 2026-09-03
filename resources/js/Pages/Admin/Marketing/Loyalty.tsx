import { useForm, usePage } from '@inertiajs/react';
import { Save, Star } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import StatCard from '@/Components/StatCard';
import SmartLink from '@/Components/SmartLink';
import Toggle from '@/Components/Toggle';
import Field from '@/Components/Field';
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
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface Member {
    id: number;
    name: string;
    phone: string | null;
    points: number;
}

interface Movement {
    id: number;
    customer: string;
    type: string;
    points: number;
    balance_after: number;
    note: string | null;
    at: string | null;
}

interface Props {
    settings: Record<string, string>;
    summary: { members: number; points: number; earned: number; redeemed: number };
    top: Member[];
    recent: Movement[];
}

export default function Loyalty() {
    const { settings, summary, top, recent } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const form = useForm({
        loyalty_enabled: settings.loyalty_enabled === '1',
        loyalty_earn_rate: settings.loyalty_earn_rate ?? '5',
        loyalty_redeem_max_pct: settings.loyalty_redeem_max_pct ?? '50',
        loyalty_redeem_min: settings.loyalty_redeem_min ?? '100',
    });

    // ١٠٠ نقطة = وحدة عملة (POINTS_PER_UNIT في نقطة البيع)
    const rate = parseFloat(form.data.loyalty_earn_rate) || 0;

    return (
        <AdminLayout title="برنامج ولاء">
            <PageHeader
                title="برنامج ولاء"
                subtitle={t('نقاطٌ تُكتسب بالشراء وتُستبدل خصمًا')}
            />

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard stat={{ label: t('أعضاء لهم رصيد'), value: number(summary.members), icon: 'users', color: 'primary' }} index={0} />
                <StatCard stat={{ label: t('النقاط القائمة'), value: number(summary.points), icon: 'star', color: 'info' }} index={1} />
                <StatCard stat={{ label: t('نقاط مُكتسبة'), value: number(summary.earned), icon: 'trending-up', color: 'success' }} index={2} />
                <StatCard stat={{ label: t('نقاط مُستبدلة'), value: number(summary.redeemed), icon: 'trending-down', color: 'secondary' }} index={3} />
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(route('admin.marketing.loyalty.save'), { preserveScroll: true });
                    }}
                >
                    <Card className="p-6">
                        <div className="mb-5">
                            <Toggle
                                label="تفعيل البرنامج"
                                hint="بإطفائه تتوقّف النقاط عن التراكم ولا تُمسّ الأرصدة القائمة."
                                on={form.data.loyalty_enabled}
                                onChange={(v) => form.setData('loyalty_enabled', v)}
                            />
                        </div>

                        <div className="space-y-4">
                            <Field
                                label="نقاط لكل وحدة شراء"
                                hint="١٠٠ نقطة = وحدة عملة واحدة عند الاستبدال — فما فوق ١٠٠ يعيد للزبون فاتورته كاملة"
                                error={form.errors.loyalty_earn_rate}
                            >
                                <Input
                                    type="number"
                                    min="0"
                                    max="99.9"
                                    step="0.1"
                                    dir="ltr"
                                    value={form.data.loyalty_earn_rate}
                                    onChange={(e) => form.setData('loyalty_earn_rate', e.target.value)}
                                />
                            </Field>

                            <Field
                                label="أقصى نسبة استبدال من الفاتورة (%)"
                                hint="سقفٌ يمنع أن تُغطّي النقاط الفاتورة كلّها"
                                error={form.errors.loyalty_redeem_max_pct}
                            >
                                <Input
                                    type="number"
                                    min="0"
                                    max="100"
                                    dir="ltr"
                                    value={form.data.loyalty_redeem_max_pct}
                                    onChange={(e) => form.setData('loyalty_redeem_max_pct', e.target.value)}
                                />
                            </Field>

                            <Field
                                label="الحدّ الأدنى لبدء الاستبدال"
                                hint="بالنقاط — تحته لا يُسمح بالاستبدال"
                                error={form.errors.loyalty_redeem_min}
                            >
                                <Input
                                    type="number"
                                    min="0"
                                    dir="ltr"
                                    value={form.data.loyalty_redeem_min}
                                    onChange={(e) => form.setData('loyalty_redeem_min', e.target.value)}
                                />
                            </Field>
                        </div>

                        {/* مثالٌ محسوب: النِسَب المجرّدة لا تُقرأ حتى تُترجَم إلى فاتورة */}
                        {rate > 0 && (
                            <p className="mt-4 rounded-[12px] bg-[#fafafa] px-4 py-3 text-[13px] text-[#6b7280]">
                                {t('فاتورة بـ١٠ تمنح :points نقطة — أي ما قيمته :value عند الاستبدال.', {
                                    points: number(Math.floor(rate * 10)),
                                    value: (Math.floor(rate * 10) / 100).toFixed(3),
                                })}
                            </p>
                        )}

                        <div className="mt-5 flex justify-end">
                            <Button type="submit" loading={form.processing}>
                                <Save />
                                {t('حفظ')}
                            </Button>
                        </div>
                    </Card>
                </form>

                <div className="space-y-6">
                    <Card className="overflow-hidden">
                        <p className="border-b border-[var(--ui-border,#e8e8e8)] px-4 py-3 text-[13px] font-medium text-[#374151]">
                            {t('أعلى الأرصدة')}
                        </p>
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    <TableHead>{t('العميل')}</TableHead>
                                    <TableHead className="text-end">{t('النقاط')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {top.length === 0 ? (
                                    <TableEmpty colSpan={2}>{t('لا أرصدة بعد')}</TableEmpty>
                                ) : (
                                    top.map((c) => (
                                        <TableRow key={c.id}>
                                            <TableCell>
                                                <SmartLink
                                                    routeName="admin.customers.show"
                                                    href={route('admin.customers.show', c.id)}
                                                    className="font-medium text-[#111] hover:underline"
                                                >
                                                    {c.name}
                                                </SmartLink>
                                                {c.phone && (
                                                    <span className="block text-[12px] text-[#9ca3af]" dir="ltr">
                                                        {c.phone}
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-end">
                                                <span className="inline-flex items-center gap-1 font-semibold tabular-nums text-[#111]">
                                                    <Star className="size-3.5 text-[#d97706]" />
                                                    {number(c.points)}
                                                </span>
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </Card>

                    <Card className="overflow-hidden">
                        <p className="border-b border-[var(--ui-border,#e8e8e8)] px-4 py-3 text-[13px] font-medium text-[#374151]">
                            {t('آخر الحركات')}
                        </p>
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    <TableHead>{t('العميل')}</TableHead>
                                    <TableHead>{t('الحركة')}</TableHead>
                                    <TableHead className="text-end">{t('الرصيد بعدها')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {recent.length === 0 ? (
                                    <TableEmpty colSpan={3}>{t('لا حركات بعد')}</TableEmpty>
                                ) : (
                                    recent.map((m) => (
                                        <TableRow key={m.id}>
                                            <TableCell>
                                                <span>{m.customer}</span>
                                                <span className="block text-[12px] text-[#9ca3af]" dir="ltr">
                                                    {m.at}
                                                </span>
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant={m.points >= 0 ? 'success' : 'warning'}>
                                                    {m.points >= 0 ? '+' : ''}
                                                    {number(m.points)}
                                                </Badge>
                                            </TableCell>
                                            <TableCell
                                                className={cn('text-end tabular-nums text-[#6b7280]')}
                                            >
                                                {number(m.balance_after)}
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </Card>
                </div>
            </div>
        </AdminLayout>
    );
}
