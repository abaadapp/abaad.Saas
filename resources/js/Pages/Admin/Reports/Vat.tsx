import { Link, usePage } from '@inertiajs/react';
import ReportScreen from '@/Components/ReportScreen';
import { type ReportRange } from '@/Components/RangeTabs';
import { Card } from '@/Components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface Row {
    id: string;
    month: string;
    taxable: number;
    output: number;
    delivery: number;
    purchases: number;
    input: number;
    due: number;
}

interface Props {
    rows: Row[];
    summary: {
        taxable: number;
        output: number;
        purchases: number;
        input: number;
        delivery: number;
        due: number;
        rate: number;
        number: string;
    };
    filters: Record<string, string | null>;
    truncated: { shown: number; total: number } | null;
    range: ReportRange;
    rangeLabel: string;
}

/**
 * رقمُ التسجيل الضريبيّ — يُقرأ هنا ويُدخَل هناك.
 *
 * وإقرارٌ بلا رقمِ تسجيلٍ لا يُقدَّم. فالنقص يُقال في موضعه، ويُفتح منه
 * البابُ الذي يُصلحه — لا يُقال «غير مُدخَل» ويُترك صاحبُه يبحث عن أيّ
 * شاشةٍ فيها الحقل بين أربعةَ عشرَ قسمًا في الإعدادات.
 *
 * والمعامل `section=finance` يفتح قسم المالية بعينه: `tabFromUrl` يقدّمه
 * على المرساة، فيصل الرجلُ إلى الحقل لا إلى لوحة الأقسام.
 */
function TrnValue({ number, canEdit }: { number: string; canEdit: boolean }) {
    const t = useTranslate();
    const to = route('admin.settings.index', { section: 'finance' });

    if (number) {
        return canEdit ? (
            <Link href={to} dir="ltr" className="font-medium text-[#111] underline decoration-dotted underline-offset-4">
                {number}
            </Link>
        ) : (
            <span dir="ltr" className="font-medium text-[#111]">{number}</span>
        );
    }

    return canEdit ? (
        <Link href={to} className="font-medium text-[#b45309] underline decoration-dotted underline-offset-4">
            {t('غير مُدخَل — أدخِله في الإعدادات')}
        </Link>
    ) : (
        <span className="font-medium text-[#b45309]">{t('غير مُدخَل في الإعدادات')}</span>
    );
}

/**
 * ضريبة القيمة المضافة — ما حصّلتَه وما دفعتَه، والفرقُ المستحقّ.
 *
 * وكان الحسابُ موجودًا في `Demo::vatReport` ولا شاشةَ تعرضه ولا مسارَ يصل
 * إليه: يُحسب في كل نشرةٍ ولا يقرؤه أحد. فصاحبُ المحلّ يُقدّم إقرارَه بجمع
 * فواتيره على ورقة، والرقم في نظامه.
 *
 * والصفوف شهريّة لأنّ الإقرار في عُمان ربعُ سنويّ: من اختار «السنة» يجمع
 * ثلاثةَ أسطرٍ بعينها فيقرأ رُبعَه. واليوميُّ يعطيه ثلاثمئة سطرٍ لا يجمع
 * منها شيئًا.
 */
export default function ReportsVat() {
    const { rows, summary, filters, truncated, range, rangeLabel, context, auth } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    /*
     * ومن لا يملك «الإعدادات» لا يُعرض له الرابط.
     *
     * الرقم يُدخَل في شاشةٍ محروسة بقسمها، فرابطٌ يقود موظّفًا إلى ٤٠٣ يجعله
     * يظنّ العطب في النظام — وهو لا يملك إصلاحه أصلًا. فيرى النقص ولا يرى
     * بابًا لا يفتحه.
     */
    const canEdit = (auth?.abilities ?? []).includes('settings');

    const stats = [
        { label: t('المبيعات الخاضعة'), value: m(summary.taxable), icon: 'wallet', color: 'info' },
        { label: t('ضريبة المخرجات'), value: m(summary.output), icon: 'receipt', color: 'primary' },
        { label: t('ضريبة المدخلات'), value: m(summary.input), icon: 'arrow-down-circle', color: 'success' },
        {
            /*
             * الصافي قد يكون سالبًا — وهو ليس خطأً بل رصيدٌ مستردّ.
             *
             * موسمٌ اشترى فيه التاجر أكثر ممّا باع يجعل مدخلاته أكبر من
             * مخرجاته. وتسميتُه «مستحقّ» وهو سالبٌ تجعله يدفع ما لا يجب.
             */
            label: summary.due < 0 ? t('رصيد مستردّ') : t('الصافي المستحقّ'),
            value: m(Math.abs(summary.due)),
            icon: summary.due < 0 ? 'trending-up' : 'trending-down',
            color: summary.due < 0 ? 'success' : 'warning',
        },
    ];

    return (
        <ReportScreen
            reportKey="vat"
            /* ملفُّ الإقرار يخرج لكلّ باقة — انظر CheckPlanFeature::OPEN_EXPORTS */
            exportFeature={null}
            title="ضريبة القيمة المضافة"
            subtitle="ما حصّلتَه من ضريبة وما دفعتَه، والفرقُ المستحقّ — شهرًا بشهر"
            range={range}
            rangeLabel={rangeLabel}
            filters={filters}
            stats={stats}
            truncated={truncated}
        >
            {/*
                رقم التسجيل الضريبيّ ونسبتُه فوق الجدول: إقرارٌ بلا رقمِ
                تسجيلٍ لا يُقدَّم، ومن لم يُدخله في الإعدادات يجب أن يعرف
                ذلك هنا لا عند الجهة.
            */}
            <Card className="mb-6 flex flex-wrap items-center justify-between gap-3 p-4 text-[13px]">
                <span className="text-[#6b7280]">
                    {t('نسبة الضريبة')}:{' '}
                    <span className="font-medium text-[#111]">{summary.rate}%</span>
                </span>
                <span className="text-[#6b7280]">
                    {t('رقم التسجيل الضريبي')}: <TrnValue number={summary.number} canEdit={canEdit} />
                </span>
            </Card>

            <Card className="overflow-hidden">
                <Table>
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead>{t('الشهر')}</TableHead>
                            <TableHead className="text-end">{t('المبيعات الخاضعة')}</TableHead>
                            <TableHead className="text-end">{t('ضريبة المخرجات')}</TableHead>
                            <TableHead className="text-end">{t('المشتريات')}</TableHead>
                            <TableHead className="text-end">{t('ضريبة المدخلات')}</TableHead>
                            <TableHead className="text-end">{t('الصافي')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.length === 0 ? (
                            <TableEmpty colSpan={6}>{t('لا حركة خاضعة في هذه الفترة')}</TableEmpty>
                        ) : (
                            rows.map((r) => (
                                <TableRow key={r.id}>
                                    <TableCell className="font-medium text-[#111]">{r.month}</TableCell>
                                    <TableCell className="text-end tabular-nums">{m(r.taxable)}</TableCell>
                                    <TableCell className="text-end tabular-nums">{m(r.output)}</TableCell>
                                    <TableCell className="text-end tabular-nums text-[#6b7280]">{m(r.purchases)}</TableCell>
                                    <TableCell className="text-end tabular-nums text-[#6b7280]">{m(r.input)}</TableCell>
                                    <TableCell
                                        className={cn(
                                            'text-end tabular-nums font-medium',
                                            r.due < 0 ? 'text-[#047857]' : 'text-[#111]',
                                        )}
                                    >
                                        {m(r.due)}
                                    </TableCell>
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                    {/* سطرُ الإجمالي في جسد الجدول: لا TableFooter في مكتبتنا */}
                    {rows.length > 0 && (
                        <TableBody>
                            <TableRow className="hover:bg-transparent">
                                <TableCell className="font-bold text-[#111]">{t('الإجمالي')}</TableCell>
                                <TableCell className="text-end tabular-nums font-bold">{m(summary.taxable)}</TableCell>
                                <TableCell className="text-end tabular-nums font-bold">{m(summary.output)}</TableCell>
                                <TableCell className="text-end tabular-nums font-bold">{m(summary.purchases)}</TableCell>
                                <TableCell className="text-end tabular-nums font-bold">{m(summary.input)}</TableCell>
                                <TableCell className="text-end tabular-nums font-bold">{m(summary.due)}</TableCell>
                            </TableRow>
                        </TableBody>
                    )}
                </Table>
            </Card>

            {/*
                ما لا يدخل الوعاء يُقال، لا يُترك ليُكتشف عند الجهة.

                رسومُ التوصيل لا يفرض عليها النظام ضريبة، وضريبةُ المدخلات
                تُقرأ من سندات المورّدين وحدها — فمشترياتٌ بلا سندٍ مسجَّل
                لا تُخصم وإن دُفعت.
            */}
            <div className="mt-4 space-y-1.5 text-[12px] leading-relaxed text-[#9ca3af]">
                <p>
                    {t('رسوم التوصيل في هذه الفترة :n — والنظام لا يفرض عليها ضريبة، فهي خارج الوعاء أعلاه.', {
                        n: m(summary.delivery),
                    })}
                </p>
                <p>
                    {t('ضريبة المدخلات تُقرأ من سندات المورّدين المسجَّلة — ومشترياتٌ بلا سندٍ لا تُخصم وإن دُفعت.')}
                </p>
                <p>{t('هذا كشفٌ يعينك على الإقرار، ولا يقوم مقام مراجعة محاسبك.')}</p>
            </div>
        </ReportScreen>
    );
}
