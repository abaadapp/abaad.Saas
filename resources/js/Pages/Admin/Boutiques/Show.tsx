import { router, useForm, usePage } from '@inertiajs/react';
import { ArrowRight, FileCheck2, Receipt } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Select } from '@/Components/Field';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface Line {
    product_id: number | null;
    name: string;
    variant: string | null;
    rate: number;
    quantity: number;
    gross: number;
    commission: number;
    net: number;
}

interface Props {
    boutique: {
        id: number; name: string; name_en: string | null; phone: string | null;
        contact_person: string | null; rate: number; active: boolean; notes: string | null;
    };
    statement: {
        /** أتُصدَر على شهرٍ ما زال يبيع؟ — خبرٌ من الخادم لا حسبةٌ في المتصفّح */
        partial: boolean;
        period: string; from: string; to: string; lines: Line[];
        quantity: number; gross: number; commission: number; net: number; lines_count: number;
    };
    period: string;
    periods: { value: string; label: string }[];
    /** آخرُ ورقةٍ صدرت في هذا الشهر — وقد تكون واحدةً من عدّة */
    settlement: {
        id: number; number: string; net: number; gross: number; commission: number;
        issued_at: string | null; paid: boolean; expense_reference: string | null;
    } | null;
    history: { id: number; number: string; period: string; gross: number; commission: number; net: number; paid: boolean }[];
}

/**
 * كشفُ حساب بوتيكٍ عن شهر — ما بِيع، وما أخذه المحلّ، وما بقي له.
 *
 * وترتيبُ الشاشة ترتيبُ السؤال: الأرقامُ الثلاثة أوّلًا (فهي ما يُفتح
 * الكشفُ لأجله)، ثمّ ما بُني منه صنفًا صنفًا، ثمّ ما صدر من قبل.
 */
export default function BoutiqueShow() {
    const { boutique, statement, period, periods, settlement, history, context } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const currency = context!.currency;

    /*
     * ونموذجُ الإصدار يحمل الشهرَ وحده.
     *
     * والخطأُ يُقرأ من مشترك الصفحة لا من النموذج: الخادمُ يردّ
     * `withErrors(['settlement' => …])` وهو مفتاحٌ ليس في حمولة النموذج،
     * فلا تراه `form.errors` المُقيَّدة بحقوله.
     */
    const form = useForm({ period });
    const { errors } = usePage<PageProps<Props>>().props;

    const go = (value: string) =>
        router.get(route('admin.boutiques.show', boutique.id), { period: value }, {
            preserveState: true, preserveScroll: true, replace: true,
        });

    const issue = () => {
        form.setData('period', period);
        form.post(route('admin.boutiques.settle', boutique.id), { preserveScroll: true });
    };

    const boxes = [
        { key: 'gross', label: 'إجمالي المبيعات', value: statement.gross, tone: 'plain' },
        { key: 'commission', label: 'نسبة المتجر', value: statement.commission, tone: 'ours' },
        { key: 'net', label: 'المستحق للبوتيك', value: statement.net, tone: 'theirs' },
    ] as const;

    return (
        <AdminLayout title={boutique.name}>
            <PageHeader
                title={boutique.name}
                subtitle={t('نسبة المتجر :rate% — وكشف حسابه شهرًا بشهر', { rate: String(boutique.rate) })}
                actions={
                    <Button variant="ghost" asChild>
                        <SmartLink routeName="admin.boutiques.index" href={route('admin.boutiques.index')}>
                            <ArrowRight />
                            {t('البوتيكات')}
                        </SmartLink>
                    </Button>
                }
            />

            {/* الشهرُ أوّلَ الشاشة: هو ما يُبدَّل أكثرَ من كلّ شيءٍ فيها */}
            <div className="mb-5 flex flex-wrap items-center gap-3">
                <span className="text-[13px] text-[#6b7280]">{t('الشهر')}</span>
                <Select
                    value={period}
                    onChange={(e) => go(e.target.value)}
                    options={periods}
                    aria-label={t('الشهر')}
                    className="w-56"
                />
                {!boutique.active && <Badge variant="neutral">{t('غير نشط')}</Badge>}
            </div>

            <div className="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
                {boxes.map((b) => (
                    <Card key={b.key} className="p-4">
                        <p className="text-[12px] text-[#6b7280]">{t(b.label)}</p>
                        <p
                            className={cn(
                                'mt-1 text-[26px] font-bold tabular-nums',
                                b.tone === 'ours' && 'text-[#047857]',
                                b.tone === 'theirs' && 'text-[#6d28d9]',
                                b.tone === 'plain' && 'text-[#111]',
                            )}
                        >
                            {money(b.value, currency)}
                        </p>
                    </Card>
                ))}
            </div>

            {/*
                التسوية — ما صدر وما يُصدَر، لا أحدُهما.

                كان الزرُّ يختفي متى وُجدت ورقةٌ لهذا الشهر، وكان صحيحًا
                يومَ كانت الورقةُ تأخذ الشهرَ كلَّه. وصارت تأخذ ما لم
                يُؤخَذ — فاختفاؤه يعني أنّ ما بِيع بعدها لا يجد بابًا
                يُصدَر منه، وهو العطبُ نفسُه الذي فُتح الشهرُ الجاري لأجله.
            */}
            <Card className="mb-5 flex flex-col gap-3 p-4">
                {settlement && (
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex min-w-0 items-center gap-2.5">
                            <FileCheck2 className="size-5 shrink-0 text-[#047857]" />
                            <div className="min-w-0">
                                <p className="text-[13px] font-semibold text-[#111]">
                                    {t('صدرت التسوية')} {settlement.number}
                                </p>
                                <p className="text-[12px] text-[#6b7280]">
                                    {settlement.issued_at} — {money(settlement.net, currency)}
                                </p>
                            </div>
                        </div>
                        {settlement.paid ? (
                            <Badge variant="success">{t('سُدِّدت')}</Badge>
                        ) : (
                            <SmartLink
                                routeName="admin.finance.dues"
                                href={route('admin.finance.dues')}
                                className="text-[13px] font-medium text-[#1d4ed8] underline"
                            >
                                {t('تُسدَّد من المبالغ المستحقة')}
                            </SmartLink>
                        )}
                    </div>
                )}

                {(statement.lines_count > 0 || ! settlement) && (
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex min-w-0 items-center gap-2.5">
                            <Receipt className="size-5 shrink-0 text-[#6b7280]" />
                            {/*
                                والشهرُ الجاري يُسوَّى — وتُقال له جزئيّتُها.

                                الورقةُ تأخذ ما بِيع ولا تُغلق الشهر: ما
                                يُباع بعدها تحمله التي تليها. لكنّ من
                                يُصدرها في العاشر يظنّها ختامَ الشهر إن
                                لم يُقَل له، فيُقال قبل الضغط لا بعده.
                            */}
                            <p className="text-[13px] leading-6 text-[#6b7280]">
                                {statement.lines_count === 0
                                    ? t('لا بيعَ ينتظر ورقةً في هذا الشهر — لا تسوية بلا بيع.')
                                    : statement.partial
                                      ? t('الشهرُ ما زال يبيع — هذه ورقةٌ بما بِيع حتى الآن، وما بعده تحمله ورقةٌ تليها.')
                                      : t('إصدار التسوية يُجمّد أرقام الشهر ويكتب المستحق في «المبالغ المستحقة».')}
                            </p>
                        </div>
                        <Button onClick={issue} disabled={statement.lines_count === 0 || form.processing}>
                            {t('أصدر التسوية')}
                        </Button>
                    </div>
                )}
                {errors?.settlement && (
                    <p className="w-full text-[12px] text-[#b91c1c]">{errors.settlement}</p>
                )}
            </Card>

            {/* ما بُني منه الرقم — صنفًا صنفًا */}
            <Card className="mb-5 overflow-hidden">
                <div className="border-b border-[var(--ui-border,#e8e8e8)] px-4 py-3 text-sm font-semibold text-[#111]">
                    {t('المنتجات المباعة')}
                </div>
                {statement.lines.length === 0 ? (
                    <p className="px-4 py-10 text-center text-[13px] text-[#9ca3af]">
                        {t('لا مبيعات في هذا الشهر')}
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-[#fafafa] text-[12px] text-[#6b7280]">
                                <tr>
                                    <th className="px-4 py-2 text-start font-medium">{t('الصنف')}</th>
                                    <th className="px-4 py-2 text-end font-medium">{t('الكمية')}</th>
                                    <th className="px-4 py-2 text-end font-medium">{t('المبيعات')}</th>
                                    <th className="px-4 py-2 text-end font-medium">{t('النسبة')}</th>
                                    <th className="px-4 py-2 text-end font-medium">{t('نسبة المتجر')}</th>
                                    <th className="px-4 py-2 text-end font-medium">{t('الصافي')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {statement.lines.map((l, i) => (
                                    <tr key={i} className="border-t border-[var(--ui-border,#e8e8e8)]">
                                        <td className="px-4 py-2.5">
                                            <span className="text-[#111]">{l.name}</span>
                                            {l.variant && (
                                                <span className="text-[12px] text-[#9ca3af]"> — {l.variant}</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-2.5 text-end tabular-nums">{number(l.quantity)}</td>
                                        <td className="px-4 py-2.5 text-end tabular-nums">{money(l.gross, currency)}</td>
                                        <td className="px-4 py-2.5 text-end tabular-nums text-[#6b7280]">
                                            {number(l.rate)}%
                                        </td>
                                        <td className="px-4 py-2.5 text-end tabular-nums text-[#047857]">
                                            {money(l.commission, currency)}
                                        </td>
                                        <td className="px-4 py-2.5 text-end font-medium tabular-nums text-[#6d28d9]">
                                            {money(l.net, currency)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot>
                                <tr className="border-t-2 border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] font-semibold">
                                    <td className="px-4 py-3">{t('الإجمالي')}</td>
                                    <td className="px-4 py-3 text-end tabular-nums">{number(statement.quantity)}</td>
                                    <td className="px-4 py-3 text-end tabular-nums">{money(statement.gross, currency)}</td>
                                    <td className="px-4 py-3" />
                                    <td className="px-4 py-3 text-end tabular-nums text-[#047857]">
                                        {money(statement.commission, currency)}
                                    </td>
                                    <td className="px-4 py-3 text-end tabular-nums text-[#6d28d9]">
                                        {money(statement.net, currency)}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                )}
            </Card>

            {/*
                وما صدر من قبل — فيُقرأ تاريخُه بلا تنقّلٍ بين الشهور.
                ويُخفى حين لا شيء: جدولٌ فارغٌ يشغل موضعًا ولا يقول شيئًا.
            */}
            {history.length > 0 && (
                <Card className="overflow-hidden">
                    <div className="border-b border-[var(--ui-border,#e8e8e8)] px-4 py-3 text-sm font-semibold text-[#111]">
                        {t('التسويات السابقة')}
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-[#fafafa] text-[12px] text-[#6b7280]">
                                <tr>
                                    <th className="px-4 py-2 text-start font-medium">{t('الشهر')}</th>
                                    <th className="px-4 py-2 text-start font-medium">{t('الرقم')}</th>
                                    <th className="px-4 py-2 text-end font-medium">{t('المبيعات')}</th>
                                    <th className="px-4 py-2 text-end font-medium">{t('نسبة المتجر')}</th>
                                    <th className="px-4 py-2 text-end font-medium">{t('الصافي')}</th>
                                    <th className="px-4 py-2 text-end font-medium">{t('الحالة')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {history.map((h) => (
                                    <tr key={h.id} className="border-t border-[var(--ui-border,#e8e8e8)]">
                                        <td className="px-4 py-2.5 tabular-nums">{h.period}</td>
                                        <td className="px-4 py-2.5 text-[#6b7280]">{h.number}</td>
                                        <td className="px-4 py-2.5 text-end tabular-nums">{money(h.gross, currency)}</td>
                                        <td className="px-4 py-2.5 text-end tabular-nums text-[#047857]">
                                            {money(h.commission, currency)}
                                        </td>
                                        <td className="px-4 py-2.5 text-end tabular-nums text-[#6d28d9]">
                                            {money(h.net, currency)}
                                        </td>
                                        <td className="px-4 py-2.5 text-end">
                                            <Badge variant={h.paid ? 'success' : 'warning'}>
                                                {t(h.paid ? 'سُدِّدت' : 'لم تُسدَّد')}
                                            </Badge>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>
            )}
        </AdminLayout>
    );
}
