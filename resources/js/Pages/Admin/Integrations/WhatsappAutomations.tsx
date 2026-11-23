import { router, usePage } from '@inertiajs/react';
import { Bot, LayoutTemplate, ListChecks, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import BackLink from '@/Components/BackLink';
import StatusPill from '@/Components/StatusPill';
import { SettingsPage, SettingsSection } from '@/Components/Settings';
import { useConfirm } from '@/Components/ConfirmDialog';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';
import AutomationWizard, {
    ChatPreview,
    blankDraft,
    draftFrom,
    draftFromTemplate,
    type AutomationDraft,
    type AutomationLimits,
    type AutomationProduct,
    type AutomationRow,
    type AutomationTemplate,
    type WizardContext,
} from '@/Pages/Admin/Integrations/partials/AutomationWizard';
import type { AutoReplyState } from '@/Pages/Admin/Integrations/partials/WhatsappAutoReply';
import type { PageProps } from '@/types';

/** ما يعرضه الخادم — `ChannelAutomations::view` */
export interface AutomationsView {
    available: boolean;
    may_manage: boolean;
    automations: AutomationRow[];
    welcome: { enabled: boolean; state: AutoReplyState } | null;
    templates: AutomationTemplate[];
    products: AutomationProduct[];
    context: WizardContext;
    variables: Record<string, string>;
    limits: AutomationLimits;
}

const STATUS: Record<AutomationRow['status'], { label: string; variant: 'success' | 'neutral' | 'warning' }> = {
    published: { label: 'تعمل', variant: 'success' },
    paused: { label: 'موقوفة', variant: 'warning' },
    draft: { label: 'مسوّدة', variant: 'neutral' },
};

/**
 * ‏‏أتمتةُ واتساب — ردودٌ تخرج وحدها حين يكتب الزبون كلمةً يحدّدها التاجر.
 *
 * طريقةُ العمل بثلاث خطوات ومثالُ محادثة، ثمّ قوالبُ يبدأ منها، ثمّ أتمتاتُه — والترحيبُ أوّلُها
 * بإعداداته القائمة. والمعالجُ يفتح مكان القائمة لا فوقها، فيعمل على الجوّال.
 */
export function AutomationsScreen({ view }: { view: AutomationsView }) {
    const t = useTranslate();
    const [ask, dialog] = useConfirm();
    const [editing, setEditing] = useState<{ draft: AutomationDraft; id: number | null } | null>(null);

    const goWelcome = () => router.visit(route('admin.integrations.whatsapp'));

    if (editing) {
        return (
            <AutomationWizard
                key={editing.id ?? 'new'}
                initial={editing.draft}
                editingId={editing.id}
                products={view.products}
                context={view.context}
                limits={view.limits}
                mayManage={view.may_manage}
                onClose={() => setEditing(null)}
                onWelcome={goWelcome}
            />
        );
    }

    const flip = (a: AutomationRow) =>
        router.post(route('admin.integrations.whatsapp.automations.status', a.id), { status: a.status === 'published' ? 'paused' : 'published' }, { preserveScroll: true });

    const remove = async (a: AutomationRow) => {
        if (await ask({ message: 'حذف الأتمتة «:name»؟ لا يخرج منها رد بعد الحذف.', values: { name: a.name }, danger: true, action: 'حذف' })) {
            router.delete(route('admin.integrations.whatsapp.automations.destroy', a.id), { preserveScroll: true });
        }
    };

    const full = view.automations.length >= view.limits.automations;

    return (
        <>
            {dialog}

            <SettingsSection title="طريقة العمل" icon={ListChecks}>
                <div className="grid gap-6 md:grid-cols-[minmax(0,1fr)_280px]">
                    <ol className="space-y-4" data-testid="how-it-works">
                        {[
                            ['اختر قالبًا أو ابدأ من الصفر', 'لكل سؤال يتكرر عندك قالب جاهز: السعر، الرابط، حالة الطلب.'],
                            ['اكتب الكلمات التي يكتبها زبونك', '«السعر» و«بكم» — ونفهم «السّعر؟» و«سعره» أيضًا.'],
                            ['اكتب الرد مرة واحدة وانشره', 'يصل وحده من رقم متجرك، ويظهر في المحادثة باسم الأتمتة.'],
                        ].map(([title, line], i) => (
                            <li key={title} className="flex gap-3">
                                <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-[#111] text-[12px] font-bold text-white tabular-nums">{i + 1}</span>
                                <div>
                                    <p className="font-bold text-[#111]">{t(title)}</p>
                                    <p className="mt-0.5 text-[13px] leading-relaxed text-[#6b7280]">{t(line)}</p>
                                </div>
                            </li>
                        ))}
                    </ol>
                    <ChatPreview
                        customer={t('بكم باقة الورد؟')}
                        reply={t('باقة الجوري بسعر 12.500 ر.ع — تفاصيلها وطلبها من هنا 👇')}
                        buttons={[{ type: 'link', title: t('اطلب الآن') }]}
                        store={view.context.store}
                    />
                </div>
            </SettingsSection>

            <SettingsSection title="قوالب تبدأ منها" description="اضغط قالبًا فيفتح جاهزًا — عدّل ما تريد ثم انشره." icon={LayoutTemplate}>
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" data-testid="templates">
                    {view.templates.map((tpl) => (
                        <button
                            key={tpl.key}
                            type="button"
                            disabled={! view.may_manage || full}
                            onClick={() => setEditing({ draft: draftFromTemplate(tpl), id: null })}
                            className="rounded-[12px] border border-[#e5e7eb] p-4 text-start transition-colors hover:border-[#111] disabled:opacity-50"
                            data-testid={`template-${tpl.key}`}
                        >
                            <p className="font-bold text-[#111]">{tpl.name}</p>
                            <p className="mt-1 text-[12px] leading-relaxed text-[#6b7280]">{tpl.description}</p>
                            <p className="mt-2 flex flex-wrap gap-1">
                                {tpl.keywords.slice(0, 3).map((k) => (
                                    <span key={k} className="rounded-full bg-[#f3f4f6] px-2 py-0.5 text-[11px] text-[#4b4b4b]">{k}</span>
                                ))}
                            </p>
                        </button>
                    ))}
                </div>
            </SettingsSection>

            <SettingsSection
                title="أتمتاتك"
                description="أتمتة واحدة ترد على كل رسالة — الكلمة التامة أولًا ثم الأطول."
                icon={Bot}
                action={view.may_manage ? (
                    <Button type="button" onClick={() => setEditing({ draft: blankDraft(), id: null })} disabled={full} data-testid="automation-new">
                        <Plus />
                        {t('أتمتة جديدة')}
                    </Button>
                ) : undefined}
            >
                <ul className="divide-y divide-[#f0f0f0]" data-testid="automation-list">
                    {view.welcome && (
                        <li className="flex flex-wrap items-center justify-between gap-3 py-3" data-testid="automation-welcome">
                            <div className="min-w-0">
                                <p className="font-bold text-[#111]">{t('رسالة الترحيب')}</p>
                                <p className="mt-0.5 text-[12px] text-[#6b7280]">{t('أول رسالة في محادثة جديدة — وما لم يطابق كلمة.')}</p>
                            </div>
                            <div className="flex items-center gap-2">
                                {view.welcome.state.state === 'working'
                                    ? <StatusPill state="ready" label="يعمل" />
                                    : view.welcome.state.state === 'waiting'
                                      ? <StatusPill state="progress" label="بانتظار اكتمال الربط/الفوترة" />
                                      : <StatusPill state="off" label="متوقف" />}
                                <Button type="button" variant="outline" onClick={goWelcome}>
                                    <Pencil />
                                    {t('تعديل')}
                                </Button>
                            </div>
                        </li>
                    )}

                    {view.automations.map((a) => (
                        <li key={a.id} className="flex flex-wrap items-center justify-between gap-3 py-3" data-testid={`automation-${a.id}`}>
                            <div className="min-w-0 space-y-1">
                                <p className="flex flex-wrap items-center gap-2 font-bold text-[#111]">
                                    {a.name}
                                    <Badge variant={STATUS[a.status].variant} data-testid="automation-status">{t(STATUS[a.status].label)}</Badge>
                                </p>
                                <p className="flex flex-wrap gap-1">
                                    {a.keywords.slice(0, 5).map((k) => (
                                        <span key={k} className="rounded-full bg-[#f3f4f6] px-2 py-0.5 text-[11px] text-[#4b4b4b]">{k}</span>
                                    ))}
                                    {a.keywords.length > 5 && <span className="text-[11px] text-[#9ca3af]">+{a.keywords.length - 5}</span>}
                                </p>
                                <p className="text-[12px] text-[#6b7280] tabular-nums" data-testid="automation-counts">
                                    {t('تشغّلت :n', { n: a.counts.matched })} · {t('وصلت :n', { n: a.counts.sent })}
                                    {a.counts.failed > 0 && <> · <span className="text-[#b91c1c]">{t('فشلت :n', { n: a.counts.failed })}</span></>}
                                    {a.last_run_at && <> · {t('آخرها :when', { when: new Date(a.last_run_at).toLocaleString() })}</>}
                                </p>
                            </div>
                            {view.may_manage && (
                                <div className="flex items-center gap-2">
                                    {a.status !== 'draft' && (
                                        <Button type="button" variant="outline" onClick={() => flip(a)} data-testid="automation-flip">
                                            {a.status === 'published' ? t('أوقف') : t('شغّل')}
                                        </Button>
                                    )}
                                    <Button type="button" variant="outline" aria-label={t('تعديل')} onClick={() => setEditing({ draft: draftFrom(a), id: a.id })} data-testid="automation-edit">
                                        <Pencil />
                                    </Button>
                                    <Button type="button" variant="outline" aria-label={t('حذف')} onClick={() => remove(a)} data-testid="automation-delete">
                                        <Trash2 />
                                    </Button>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>

                {view.automations.length === 0 && (
                    <p className="mt-2 rounded-[10px] bg-[#f9fafb] px-3 py-3 text-[13px] text-[#6b7280]" data-testid="automation-empty">
                        {t('لا أتمتة بعد — ابدأ من قالب فوق، أو أنشئ واحدة.')}
                    </p>
                )}
                {full && <p className="mt-2 text-[12px] text-[#b45309]">{t('وصلت إلى :max أتمتة — احذف واحدة قبل أن تضيف غيرها.', { max: view.limits.automations })}</p>}
                {! view.may_manage && <p className="mt-2 text-[12px] text-[#9ca3af]">{t('إنشاء الأتمتة وتعديلها لصاحب الحساب.')}</p>}
            </SettingsSection>
        </>
    );
}

export default function IntegrationsWhatsappAutomations() {
    const { view } = usePage<PageProps<{ view: AutomationsView }>>().props;
    const t = useTranslate();

    return (
        <AdminLayout title="أتمتة واتساب">
            <BackLink routeName="admin.integrations.whatsapp" href={route('admin.integrations.whatsapp')} label="واتساب بزنس" />
            <PageHeader title="أتمتة واتساب" subtitle={t('ردود تخرج وحدها حين يكتب زبونك كلمة تحددها')} />
            <SettingsPage width="wide">
                <AutomationsScreen view={view} />
            </SettingsPage>
        </AdminLayout>
    );
}
