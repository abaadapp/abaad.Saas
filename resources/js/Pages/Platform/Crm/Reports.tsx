import { usePage } from '@inertiajs/react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Slice {
    key: string;
    label: string;
    n: number;
}

interface Props {
    sources: Slice[];
    lostReasons: Slice[];
    funnel: Slice[];
    staff: { id: number; name: string; active: number; won: number; lost: number }[];
    whatsappLeads: number;
    trialToPaid: { trials: number; won: number };
}

/**
 * شريطٌ بنسبةٍ من الأكبر — لا رسمٌ بمكتبةٍ خارجية.
 *
 * والمقياسُ أكبرُ قيمةٍ في المجموعة لا مجموعُها: شريطٌ يُرسم بنسبةٍ من
 * المجموع يصير خيطًا لا يُرى حين تكثر الفئات.
 */
function Bars({ rows, empty }: { rows: Slice[]; empty: string }) {
    const t = useTranslate();
    const max = Math.max(1, ...rows.map((r) => r.n));

    if (rows.length === 0) {
        return <p className="text-[13px] text-[#9ca3af]">{t(empty)}</p>;
    }

    return (
        <ul className="space-y-2.5">
            {rows.map((r) => (
                <li key={r.key}>
                    <div className="mb-1 flex items-center justify-between text-[12px]">
                        <span className="text-[#4b5563]">{r.label}</span>
                        <span className="font-medium text-[#111]">{r.n}</span>
                    </div>
                    <div className="h-1.5 overflow-hidden rounded-full bg-[#f3f4f6]">
                        <div className="h-full rounded-full bg-[#111]" style={{ width: `${(r.n / max) * 100}%` }} />
                    </div>
                </li>
            ))}
        </ul>
    );
}

export default function CrmReports() {
    const { sources, lostReasons, funnel, staff, whatsappLeads, trialToPaid } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const trialRate = trialToPaid.trials > 0 ? Math.round((trialToPaid.won / trialToPaid.trials) * 1000) / 10 : null;

    return (
        <PlatformLayout title={t('تقارير CRM')}>
            <PageHeader title="تقارير CRM" subtitle={t('كلُّ رقمٍ محسوبٌ من الدفتر')} />

            <div className="grid gap-4 lg:grid-cols-2">
                <section className="rounded-xl border border-[#e8e8e8] bg-white p-5">
                    <h2 className="mb-1 font-bold text-[#111]">{t('مسار التحويل')}</h2>
                    {/*
                        ويُقرأ من تاريخ المراحل لا من عمود المرحلة الحاليّ.
                        العمودُ يحمل «الآن» وحدَه: عميلٌ مرّ بـ«مهتمّ» ثمّ صار
                        مشتركًا لا يُعدّ في «مهتمّ» أبدًا — فيقول المسارُ إنّ
                        أحدًا لم يهتمّ قطّ.
                    */}
                    <p className="mb-3 text-[12px] text-[#6b7280]">
                        {t('عددُ من بلغ كلَّ مرحلةٍ يومًا — لا من هو فيها الآن.')}
                    </p>
                    <Bars rows={funnel} empty="لا انتقالات مسجّلة بعد." />
                </section>

                <section className="rounded-xl border border-[#e8e8e8] bg-white p-5">
                    <h2 className="mb-3 font-bold text-[#111]">{t('مصادر العملاء')}</h2>
                    <Bars rows={sources} empty="لا عملاء محتملون بعد." />
                    <p className="mt-3 border-t border-[#f3f4f6] pt-3 text-[12px] text-[#6b7280]">
                        {t('عملاء WhatsApp المحتملون')}: <span className="font-medium text-[#111]">{whatsappLeads}</span>
                    </p>
                </section>

                <section className="rounded-xl border border-[#e8e8e8] bg-white p-5">
                    <h2 className="mb-3 font-bold text-[#111]">{t('أسباب خسارة العملاء')}</h2>
                    <Bars rows={lostReasons} empty="لم تُسجَّل خسارةٌ بعد." />
                </section>

                <section className="rounded-xl border border-[#e8e8e8] bg-white p-5">
                    <h2 className="mb-1 font-bold text-[#111]">{t('تحويل التجربة إلى اشتراك')}</h2>
                    {trialRate === null ? (
                        <p className="text-[13px] text-[#6b7280]">{t('لم يبلغ أحدٌ مرحلةَ التجربة بعد — فلا نسبةَ تُقاس.')}</p>
                    ) : (
                        <>
                            <div className="text-[32px] font-bold text-[#111]">{trialRate}%</div>
                            <p className="mt-1 text-[13px] text-[#6b7280]">
                                {t(':won من :trials جرّبوا', {
                                    won: String(trialToPaid.won),
                                    trials: String(trialToPaid.trials),
                                })}
                            </p>
                        </>
                    )}
                </section>
            </div>

            <section className="mt-4 rounded-xl border border-[#e8e8e8] bg-white p-5">
                <h2 className="mb-3 font-bold text-[#111]">{t('أداء موظفي المبيعات')}</h2>
                {staff.length === 0 ? (
                    <p className="text-[13px] text-[#9ca3af]">{t('لا موظفي منصّة.')}</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-[13px]">
                            <thead>
                                <tr className="border-b border-[#f3f4f6] text-[12px] text-[#6b7280]">
                                    <th className="py-2 text-start font-medium">{t('الموظف')}</th>
                                    <th className="py-2 text-end font-medium">{t('نشط')}</th>
                                    <th className="py-2 text-end font-medium">{t('تم الاشتراك')}</th>
                                    <th className="py-2 text-end font-medium">{t('مفقود')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {staff.map((s) => (
                                    <tr key={s.id} className="border-b border-[#fafafa]">
                                        <td className="py-2 text-[#111]">{s.name}</td>
                                        <td className="py-2 text-end text-[#4b5563]">{s.active}</td>
                                        <td className="py-2 text-end text-[#15803d]">{s.won}</td>
                                        <td className="py-2 text-end text-[#b91c1c]">{s.lost}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>
        </PlatformLayout>
    );
}
