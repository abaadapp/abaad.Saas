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
import { loyaltyPercentOf, loyaltyQuote, loyaltyWorth, type LoyaltyMode } from '@/lib/loyalty';
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
        // والسقفان اختياريّان: فراغٌ محفوظ يبقى فراغًا («بلا حدّ»)، وغيابُ الصفّ افتراضُه
        loyalty_redeem_max_pct: settings.loyalty_redeem_max_pct ?? '50',
        loyalty_redeem_max_amount: settings.loyalty_redeem_max_amount ?? '',
        loyalty_redeem_min: settings.loyalty_redeem_min ?? '100',
        // وما صار للتاجر أن يحدّده — وافتراضُه الصرفُ القديم (`Loyalty::DEFAULTS`)
        loyalty_redeem_enabled: (settings.loyalty_redeem_enabled ?? '1') === '1',
        loyalty_redeem_mode: settings.loyalty_redeem_mode === 'percent' ? 'percent' : 'fixed',
        loyalty_redeem_points: settings.loyalty_redeem_points ?? '100',
        loyalty_redeem_value: settings.loyalty_redeem_value ?? '1',
        loyalty_redeem_percent: settings.loyalty_redeem_percent ?? '10',
        loyalty_redeem_min_invoice: settings.loyalty_redeem_min_invoice ?? '0',
    });

    // وقيمةُ النقاط كما يحدّدها التاجر — والحسابُ من مرآة الخادم (`lib/loyalty.ts`)
    const rate = parseFloat(form.data.loyalty_earn_rate) || 0;
    const byPercent = form.data.loyalty_redeem_mode === 'percent';
    // حقلٌ فارغ = بلا حدّ (`Loyalty::rules`)
    const limit = (v: string, ceiling = Infinity): number | null =>
        v.trim() === '' ? null : Math.min(ceiling, Math.max(0, Number(v) || 0));
    const worthRules = {
        mode: form.data.loyalty_redeem_mode as LoyaltyMode,
        points: Math.max(1, Math.trunc(Number(form.data.loyalty_redeem_points) || 0)),
        value: Math.max(0, Number(form.data.loyalty_redeem_value) || 0),
        percent: Math.min(100, Math.max(0, Number(form.data.loyalty_redeem_percent) || 0)),
    };

    /*
     * ومثالٌ في طريقة النسبة يمرّ بالحساب كلِّه — لا بالنسبة وحدها.
     *
     * فاتورةٌ مؤهّلةٌ بعشرين، والرصيدُ ضعفا ما يُستبدل به: يُرى أثرُ السقف
     * (`max_pct`) والحدّين في الرقم نفسِه الذي سيراه الكاشير.
     */
    const percentExample = byPercent
        ? loyaltyQuote(
              {
                  on: true,
                  redeem: worthRules.percent > 0,
                  min_points: 0,
                  mode: 'percent',
                  points: worthRules.points,
                  value: worthRules.value,
                  percent: worthRules.percent,
                  min_invoice: 0,
                  max_pct: limit(form.data.loyalty_redeem_max_pct, 100),
                  max_amount: limit(form.data.loyalty_redeem_max_amount),
              },
              worthRules.points * 2,
              20,
          )
        : null;

    /*
     * ═══ تحذيرٌ قبل الحفظ — لا منع ═══
     *
     * صاحبُ النشاط يحدّد قواعده بنفسه، فلا يُرفض إعدادٌ آمنٌ حسابيًّا. لكنّ
     * سقفًا بالنسبة فارغًا (أو مئة) يجعل النقاطَ تغطّي الفاتورةَ كلَّها فيصير
     * إجماليُّها صفرًا، ونسبةُ مئةٍ تجعل كلَّ دفعةِ نقاطٍ فاتورةً كاملة، وطريقةُ
     * النسبة بلا حدٍّ بالمبلغ تجعل قيمةَ النقاط تكبر مع الفاتورة بلا سقف. فيُقال
     * ذلك صريحًا وهو يكتب، لا بعد أن يقرأه في فاتورةٍ صدرت.
     */
    const redeemOn = form.data.loyalty_enabled && form.data.loyalty_redeem_enabled;
    const maxPct = limit(form.data.loyalty_redeem_max_pct, 100);
    const wholeInvoice = redeemOn && (maxPct === null || maxPct >= 100);
    const fullPercent = redeemOn && byPercent && worthRules.percent >= 100;
    const noAmountCap = redeemOn && byPercent && limit(form.data.loyalty_redeem_max_amount) === null;

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
                                hint="حدد عدد النقاط التي يحصل عليها العميل مقابل كل 1 ريال يشتري به."
                                error={form.errors.loyalty_earn_rate}
                            >
                                <Input
                                    type="number"
                                    min="0"
                                    step="0.1"
                                    dir="ltr"
                                    value={form.data.loyalty_earn_rate}
                                    onChange={(e) => form.setData('loyalty_earn_rate', e.target.value)}
                                />
                            </Field>

                            <div className="border-t border-[var(--ui-border,#e8e8e8)] pt-4">
                                <Toggle
                                    label="تفعيل الاستبدال"
                                    hint="بإطفائه تبقى النقاط تُكتسب وتتراكم، ولا تُستبدل في الصندوق ولا في فواتير العملاء."
                                    on={form.data.loyalty_redeem_enabled}
                                    onChange={(v) => form.setData('loyalty_redeem_enabled', v)}
                                />
                            </div>
                            {/*
                                ═══ طريقةُ الاستبدال ═══

                                مبلغٌ ثابتٌ لكلّ عددٍ من النقاط (كما كان)، أو نسبةٌ
                                من الفاتورة المؤهّلة. والسقفُ والحدّان تحتهما
                                قيودٌ مستقلّة تُطبَّق على الطريقتين.
                            */}
                            <Field label="طريقة الاستبدال" error={form.errors.loyalty_redeem_mode}>
                                <div role="radiogroup" className="grid grid-cols-2 gap-2" data-testid="redeem-mode">
                                    {(
                                        [
                                            ['fixed', t('خصم بمبلغ ثابت'), t('مثال: 100 نقطة = 1 ريال')],
                                            ['percent', t('خصم بنسبة مئوية'), t('مثال: 100 نقطة = 10% من الفاتورة')],
                                        ] as const
                                    ).map(([mode, label, hint]) => (
                                        <button
                                            key={mode}
                                            type="button"
                                            role="radio"
                                            aria-checked={form.data.loyalty_redeem_mode === mode}
                                            data-testid={`redeem-mode-${mode}`}
                                            onClick={() => form.setData('loyalty_redeem_mode', mode)}
                                            className={cn(
                                                'rounded-[10px] border px-3 py-2 text-start transition',
                                                form.data.loyalty_redeem_mode === mode
                                                    ? 'border-[#db2777] bg-[#fdf2f8] text-[#be185d]'
                                                    : 'border-[var(--ui-border,#e8e8e8)] bg-white text-[#374151]',
                                            )}
                                        >
                                            <span className="block text-[13px] font-semibold">{label}</span>
                                            <span className="block text-[11px] text-[#6b7280]">{hint}</span>
                                        </button>
                                    ))}
                                </div>
                            </Field>
                            <div className="grid grid-cols-2 gap-3">
                                <Field
                                    label="عدد النقاط"
                                    hint="عددٌ صحيح"
                                    error={form.errors.loyalty_redeem_points}
                                >
                                    <Input
                                        type="number"
                                        min="1"
                                        step="1"
                                        dir="ltr"
                                        data-testid="redeem-points"
                                        value={form.data.loyalty_redeem_points}
                                        onChange={(e) => form.setData('loyalty_redeem_points', e.target.value)}
                                    />
                                </Field>
                                {byPercent ? (
                                    <Field
                                        label="تساوي خصمًا بنسبة (%)"
                                        hint="من الفاتورة المؤهّلة بعد الخصومات الأخرى"
                                        error={form.errors.loyalty_redeem_percent}
                                    >
                                        <Input
                                            type="number"
                                            min="0.01"
                                            max="100"
                                            step="0.01"
                                            dir="ltr"
                                            data-testid="redeem-percent"
                                            value={form.data.loyalty_redeem_percent}
                                            onChange={(e) => form.setData('loyalty_redeem_percent', e.target.value)}
                                        />
                                    </Field>
                                ) : (
                                    <Field
                                        label="تساوي خصمًا بقيمة"
                                        hint="بعملة النشاط"
                                        error={form.errors.loyalty_redeem_value}
                                    >
                                        <Input
                                            type="number"
                                            min="0.001"
                                            step="0.001"
                                            dir="ltr"
                                            data-testid="redeem-value"
                                            value={form.data.loyalty_redeem_value}
                                            onChange={(e) => form.setData('loyalty_redeem_value', e.target.value)}
                                        />
                                    </Field>
                                )}
                            </div>
                            <Field
                                label="الحدّ الأدنى لقيمة الفاتورة المؤهّلة"
                                hint="بعملة النشاط — فاتورةٌ دونه لا يُستبدل عليها. صفرٌ يعني كلّ فاتورة."
                                error={form.errors.loyalty_redeem_min_invoice}
                            >
                                <Input
                                    type="number"
                                    min="0"
                                    step="0.001"
                                    dir="ltr"
                                    value={form.data.loyalty_redeem_min_invoice}
                                    onChange={(e) => form.setData('loyalty_redeem_min_invoice', e.target.value)}
                                />
                            </Field>
                            {/*
                                ═══ السقفان اختياريّان — سياسةُ صاحب النشاط ═══

                                فراغُ الحقل «بلا حدّ». ولا يتجاوز الخصمُ ما بقي من
                                الفاتورة بعد الخصومات الأخرى في كلّ حال — حسابٌ لا سقف.
                            */}
                            <div className="grid grid-cols-2 gap-3">
                                <Field
                                    label="أقصى نسبة خصم بالنقاط (%) — اختياري"
                                    hint={
                                        byPercent
                                            ? 'مستقلّ عن نسبة الخصم أعلاه. اتركه فارغًا: بلا حدّ'
                                            : 'من قيمة الفاتورة. اتركه فارغًا: بلا حدّ'
                                    }
                                    error={form.errors.loyalty_redeem_max_pct}
                                >
                                    <Input
                                        type="number"
                                        min="0"
                                        max="100"
                                        step="0.01"
                                        dir="ltr"
                                        placeholder={t('بلا حدّ')}
                                        data-testid="redeem-max-pct"
                                        value={form.data.loyalty_redeem_max_pct}
                                        onChange={(e) => form.setData('loyalty_redeem_max_pct', e.target.value)}
                                    />
                                </Field>
                                <Field
                                    label="أقصى مبلغ خصم بالنقاط — اختياري"
                                    hint="بعملة النشاط لكل فاتورة. اتركه فارغًا: بلا حدّ"
                                    error={form.errors.loyalty_redeem_max_amount}
                                >
                                    <Input
                                        type="number"
                                        min="0.001"
                                        step="0.001"
                                        dir="ltr"
                                        placeholder={t('بلا حدّ')}
                                        data-testid="redeem-max-amount"
                                        value={form.data.loyalty_redeem_max_amount}
                                        onChange={(e) => form.setData('loyalty_redeem_max_amount', e.target.value)}
                                    />
                                </Field>
                            </div>

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
                        {rate > 0 && !byPercent && (
                            <p className="mt-4 rounded-[12px] bg-[#fafafa] px-4 py-3 text-[13px] text-[#6b7280]">
                                {t('فاتورة بـ١٠ تمنح :points نقطة — أي ما قيمته :value عند الاستبدال.', {
                                    points: number(Math.floor(rate * 10)),
                                    value: loyaltyWorth(worthRules, Math.floor(rate * 10)).toFixed(3),
                                })}
                            </p>
                        )}
                        {percentExample && (
                            <p data-testid="redeem-percent-example" className="mt-4 rounded-[12px] bg-[#fafafa] px-4 py-3 text-[13px] text-[#6b7280]">
                                {percentExample.eligible
                                    ? t('فاتورة مؤهّلة بـ٢٠ وعميلٌ رصيده :balance نقطة: يُخصم :discount (:pct٪) مقابل :points نقطة.', {
                                          balance: number(worthRules.points * 2),
                                          discount: percentExample.max_discount.toFixed(3),
                                          pct: loyaltyPercentOf(worthRules, percentExample.max_points),
                                          points: number(percentExample.max_points),
                                      })
                                    : t('لا يُخصم شيء بهذه الأرقام — راجع النسبة وسقف الاستبدال.')}
                                <span className="mt-1 block text-[12px]">
                                    {t('قيمة النقطة تتبع الفاتورة: كلما كبرت الفاتورة كبر خصم النقاط نفسها، وسقف الاستبدال يحدّه.')}
                                </span>
                                {rate > 0 && (
                                    <span className="mt-1 block text-[12px]">
                                        {t('فاتورة بـ١٠ تمنح :points نقطة — أي :pct٪ من فاتورة قادمة.', {
                                            points: number(Math.floor(rate * 10)),
                                            pct: loyaltyPercentOf(worthRules, Math.floor(rate * 10)),
                                        })}
                                    </span>
                                )}
                            </p>
                        )}

                        {(wholeInvoice || fullPercent || noAmountCap) && (
                            <div
                                role="status"
                                data-testid="loyalty-settings-warning"
                                className="mt-4 space-y-1 rounded-[12px] border border-[#fde68a] bg-[#fffbeb] px-4 py-3 text-[13px] leading-6 text-[#92400e]"
                            >
                                {wholeInvoice && (
                                    <p data-testid="loyalty-warning-whole-invoice">
                                        {t('تنبيه: بهذه الحدود قد تغطّي النقاط الفاتورة كلّها فيصير إجماليّها صفرًا — لا يدفع العميل شيئًا ولا يُطلب له رابط دفع. ضع أقصى نسبة خصم أقل من ١٠٠ إن أردت أن يدفع العميل جزءًا دائمًا.')}
                                    </p>
                                )}
                                {fullPercent && (
                                    <p data-testid="loyalty-warning-full-percent">
                                        {t('تنبيه: نسبة ١٠٠٪ تعني أن كل :points نقطة تساوي الفاتورة المؤهّلة كاملة.', {
                                            points: number(worthRules.points),
                                        })}
                                    </p>
                                )}
                                {noAmountCap && (
                                    <p data-testid="loyalty-warning-no-amount-cap">
                                        {t('تنبيه: في طريقة النسبة تكبر قيمة النقاط مع الفاتورة ولا حدّ لها بالمبلغ — النقاط نفسها تخصم من فاتورة بألف ما تخصمه نسبتها من فاتورة بعشرة. ضع أقصى مبلغ خصم إن أردت حدًّا بعملة النشاط.')}
                                    </p>
                                )}
                                <p className="text-[12px]">{t('الإعداد يُحفظ كما اخترته — هذا تنبيه لا منع.')}</p>
                            </div>
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
