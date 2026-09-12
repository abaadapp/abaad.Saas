import { router, usePage } from '@inertiajs/react';
import { AlertTriangle, Inbox } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Card {
    id: number;
    name: string;
    businessName: string | null;
    source: string;
    assignee: string | null;
    plan: string | null;
    expectedValue: number | null;
    nextFollowUpAt: string | null;
    followUpOverdue: boolean;
    lastContactAt: string | null;
    url: string;
}

interface Column {
    stage: string;
    label: string;
    tone: string;
    total: number;
    /** ما لم يُحمَّل من العمود — يُقال عددًا ولا يُخفى */
    hidden: number;
    cards: Card[];
}

interface Props {
    columns: Column[];
    assignment: string;
}

const HEAD: Record<string, string> = {
    info: 'border-t-[#3b82f6]',
    primary: 'border-t-[#8b5cf6]',
    warning: 'border-t-[#f59e0b]',
    success: 'border-t-[#22c55e]',
    danger: 'border-t-[#ef4444]',
    gray: 'border-t-[#d1d5db]',
};

export default function CrmPipeline() {
    const { columns, assignment } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const views = [
        { key: 'all', label: 'الكل' },
        { key: 'mine', label: 'محادثاتي' },
        { key: 'unassigned', label: 'غير معيّن' },
    ];

    const empty = columns.every((c) => c.total === 0);

    return (
        <PlatformLayout title={t('مسار البيع')}>
            <PageHeader
                title="مسار البيع"
                subtitle={t('المراحل الحيّة — و«تم الاشتراك» و«مفقود» يُقرآن في تقارير CRM')}
                actions={views.map((v) => (
                    <Button
                        key={v.key}
                        variant={assignment === v.key ? 'primary' : 'outline'}
                        size="sm"
                        onClick={() =>
                            router.get(route('super-admin.crm.pipeline'), { assignment: v.key }, { preserveScroll: true })
                        }
                    >
                        {t(v.label)}
                    </Button>
                ))}
            />

            {empty ? (
                <div className="rounded-xl border border-[#e8e8e8] bg-[#fafafa] p-10 text-center">
                    <Inbox className="mx-auto mb-2 size-8 text-[#d1d5db]" />
                    <p className="text-[13px] text-[#6b7280]">{t('لا عملاء محتملون في المسار.')}</p>
                </div>
            ) : (
                /*
                    تمريرٌ أفقيٌّ داخل الحاوية وحدَها — لا في الصفحة.
                    سبعةُ أعمدةٍ لا تسع شاشةَ جوّال، وتمريرُ الصفحة كلِّها
                    أفقيًّا يُزيح الشريطَ الجانبيَّ والترويسةَ معها.
                */
                <div className="-mx-1 overflow-x-auto pb-2">
                    <div className="flex min-w-max gap-3 px-1">
                        {columns.map((col) => (
                            <section
                                key={col.stage}
                                className={`w-[260px] shrink-0 rounded-xl border border-[#e8e8e8] border-t-[3px] bg-[#fafafa] p-3 ${HEAD[col.tone] ?? HEAD.gray}`}
                            >
                                <header className="mb-3 flex items-center justify-between">
                                    <h2 className="text-[13px] font-bold text-[#111]">{col.label}</h2>
                                    <span className="rounded-full bg-white px-2 py-0.5 text-[12px] font-medium text-[#6b7280]">
                                        {col.total}
                                    </span>
                                </header>

                                {col.cards.length === 0 ? (
                                    <p className="py-6 text-center text-[12px] text-[#9ca3af]">{t('فارغ')}</p>
                                ) : (
                                    <ul className="space-y-2">
                                        {col.cards.map((card) => (
                                            <li key={card.id}>
                                                <SmartLink
                                                    routeName="super-admin.crm.leads.show"
                                                    href={card.url}
                                                    className="block rounded-lg border border-[#e8e8e8] bg-white p-3 transition hover:border-[#111]"
                                                >
                                                    <div className="truncate text-[13px] font-medium text-[#111]">
                                                        {card.name}
                                                    </div>
                                                    {card.businessName && (
                                                        <div className="truncate text-[12px] text-[#6b7280]">
                                                            {card.businessName}
                                                        </div>
                                                    )}

                                                    <div className="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-[#9ca3af]">
                                                        <span>{card.source}</span>
                                                        {card.assignee && <span>· {card.assignee}</span>}
                                                        {card.plan && <span>· {card.plan}</span>}
                                                        {card.expectedValue !== null && (
                                                            <span>· {card.expectedValue}</span>
                                                        )}
                                                    </div>

                                                    {card.nextFollowUpAt && (
                                                        <div
                                                            className={`mt-1.5 text-[11px] ${card.followUpOverdue ? 'font-medium text-[#b91c1c]' : 'text-[#6b7280]'}`}
                                                        >
                                                            {card.followUpOverdue && (
                                                                <AlertTriangle className="me-1 inline size-3" />
                                                            )}
                                                            {t('المتابعة القادمة')}: {card.nextFollowUpAt}
                                                        </div>
                                                    )}
                                                </SmartLink>
                                            </li>
                                        ))}
                                    </ul>
                                )}

                                {/* وما لم يُحمَّل يُقال عددًا — لا عمودٌ يبدو أقصرَ ممّا هو */}
                                {col.hidden > 0 && (
                                    <p className="mt-2 text-center text-[11px] text-[#9ca3af]">
                                        {t('و:n غيرها — افتح القائمة لتراها كلّها', { n: String(col.hidden) })}
                                    </p>
                                )}
                            </section>
                        ))}
                    </div>
                </div>
            )}

            {/*
                ولا سحبٌ ولا إفلات في هذه النسخة.
                سحبٌ لا يعمل باللمس ولا بلوحة المفاتيح يجعل تغييرَ المرحلة
                مستحيلًا على من يعمل من جوّاله — وهو أكثرُ من يفتح هذه
                الشاشة. والتغييرُ من ملفّ العميل بقائمةٍ تعمل في كلّ جهاز.
            */}
            <p className="mt-4 text-[12px] text-[#9ca3af]">
                {t('تُغيَّر المرحلة من ملفّ العميل المحتمل — اضغط على بطاقته.')}
            </p>
        </PlatformLayout>
    );
}
