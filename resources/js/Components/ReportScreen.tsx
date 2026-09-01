import { type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import PrintReport from '@/Components/PrintReport';
import BackToReports from '@/Components/BackToReports';
import RangeTabs, { type ReportRange } from '@/Components/RangeTabs';
import StatCard from '@/Components/StatCard';
import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export interface Option {
    value: string;
    label: string;
}

export type Filter =
    | { kind: 'select'; key: string; label: string; options: Option[] }
    | { kind: 'toggle'; key: string; label: string };

export interface Stat {
    label: string;
    value: string;
    icon: string;
    color: string;
}

interface Props {
    title: string;
    subtitle: string;
    /** الفترة، أو null لتقريرٍ لا معنى للفترة فيه (رصيدُ اليوم لا مدّته) */
    range: ReportRange | null;
    rangeLabel: string;
    filters: Record<string, string | null>;
    controls?: Filter[];
    stats: Stat[];
    truncated?: { shown: number; total: number } | null;
    children: ReactNode;
}

/**
 * هيكلُ صفحة تقرير — ما يشترك فيه الجميع، لا ما يميّز كلًّا.
 *
 * وليس هذا عودةً إلى القالب الواحد الذي حُذف: ذاك كان يرسم **البيانات**
 * نفسها أعمدةً وصفوفًا لأيّ تقرير، فلا فرق بين تقريرٍ وآخر إلا محتوى
 * الخلايا. وهذا يرسم **الإطار** وحده — الترويسة والتنقّل والمرشّحات
 * والمؤشّرات — ويترك لكلّ صفحةٍ أن تقول مؤشّراتِها وأعمدتَها ومخطّطاتِها.
 *
 * والفرقُ عمليّ: زرُّ طباعةٍ يُضاف هنا مرّةً يصل التقارير كلَّها، ولا يُنسى
 * في واحدٍ منها كما نُسي حين كُتبت الصفحات الثلاث الأولى بأيديها.
 */
export default function ReportScreen({
    title, subtitle, range, rangeLabel,
    filters, controls, stats, truncated, children,
}: Props) {
    const t = useTranslate();

    /*
     * كل تبديلٍ يُعيد التحميل من الخادم لا يُرشّح في المتصفّح: الجدول مبتورٌ
     * بسقف، فترشيحُ ما وصل وحده يبحث في أوّل خمسمئة صفٍّ ويقول «لا نتائج».
     *
     * والمرشّحات تُحمل كلُّها في كل انتقال، وإلا أسقط تبديلُ واحدٍ البقيّة.
     */
    const go = (patch: Record<string, string | null>) =>
        router.get(window.location.pathname, { ...filters, ...patch }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });

    return (
        <AdminLayout title={title}>
            {/* الكشف للطابعة: القاعدة العامة تُخفي الصفحة كلّها إلا الإيصال الحراري */}
            <div className="printable-report">
                {/* الرجوع فوق العنوان: أوّلُ ما تقع عليه العين عند الخروج */}
                <div className="no-print">
                    <BackToReports />
                </div>

                <PageHeader title={title} subtitle={t(subtitle)} actions={<PrintReport />} />

                {/* الأدوات لا تُطبع: التقرير ورقةٌ لا لوحةُ تحكّم */}
                <div className="no-print">
                    {range && <RangeTabs current={range} params={filters} />}

                    {controls && controls.length > 0 && (
                        <Card className="mb-6 flex flex-wrap items-end gap-3 p-4">
                            {controls.map((c) =>
                                c.kind === 'select' ? (
                                    <label key={c.key} className="flex min-w-[10rem] flex-1 flex-col gap-1">
                                        <span className="text-[12px] text-[#6b7280]">{t(c.label)}</span>
                                        <select
                                            value={filters[c.key] ?? ''}
                                            onChange={(e) => go({ [c.key]: e.target.value || null })}
                                            className="h-10 rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white px-3 text-[13px] text-[#111] focus:border-[#d1d5db] focus:outline-none"
                                        >
                                            <option value="">{t('الكل')}</option>
                                            {c.options.map((o) => (
                                                <option key={o.value} value={o.value}>
                                                    {o.label}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                ) : (
                                    <label key={c.key} className="flex h-10 cursor-pointer items-center gap-2 text-[13px] text-[#111]">
                                        <input
                                            type="checkbox"
                                            checked={filters[c.key] === '1'}
                                            onChange={(e) => go({ [c.key]: e.target.checked ? '1' : null })}
                                            className="size-4 rounded border-[var(--ui-border,#e8e8e8)]"
                                        />
                                        {t(c.label)}
                                    </label>
                                ),
                            )}
                        </Card>
                    )}
                </div>

                {stats.length > 0 && (
                    <div className={cn('mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2', stats.length >= 4 ? 'lg:grid-cols-4' : 'lg:grid-cols-3')}>
                        {stats.map((s, i) => (
                            <StatCard key={s.label} stat={s} index={i} />
                        ))}
                    </div>
                )}

                {/* السقف يُقال حين يُبلَغ: جدولٌ مبتورٌ بلا ما يقول ذلك يُقرأ على أنه كلّ ما في المتجر */}
                {truncated && (
                    <Card className="mb-4 border-[#fed7aa] bg-[#fff7ed] p-3 text-[13px] text-[#9a3412]">
                        {t('تُعرض :shown من :total صفًّا. ضيّق بالفترة أو بالمرشّحات لرؤية الباقي.', truncated)}
                    </Card>
                )}

                <p className="mb-3 text-[12px] text-[#9ca3af]">{rangeLabel}</p>

                {children}
            </div>
        </AdminLayout>
    );
}
