import { router } from '@inertiajs/react';
import { Bot, LayoutTemplate, ListChecks, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import StatusPill from '@/Components/StatusPill';
import { SettingsSection } from '@/Components/Settings';
import { useConfirm } from '@/Components/ConfirmDialog';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';
import AutomationWizard, {
    AUTOMATION_ROUTES,
    ChatPreview,
    blankDraft,
    draftFrom,
    draftFromTemplate,
    type AutomationChannel,
    type AutomationDraft,
    type AutomationLimits,
    type AutomationProduct,
    type AutomationRow,
    type AutomationTemplate,
    type WizardContext,
} from '@/Pages/Admin/Integrations/partials/AutomationWizard';
import type { AutoReplyState } from '@/Pages/Admin/Integrations/partials/WhatsappAutoReply';

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

/** ما يختلف بين القناتين من كلام — والشاشةُ واحدة */
const COPY: Record<AutomationChannel, { how: [string, string][]; customer: string; reply: string; buttons: { type: 'link'; title: string }[]; list: string }> = {
    whatsapp: {
        how: [
            ['اختر قالبًا أو ابدأ من الصفر', 'لكل سؤال يتكرر عندك قالب جاهز: السعر، الرابط، حالة الطلب.'],
            ['اكتب الكلمات التي يكتبها زبونك', '«السعر» و«بكم» — ونفهم «السّعر؟» و«سعره» أيضًا.'],
            ['اكتب الرد مرة واحدة وانشره', 'يصل وحده من رقم متجرك، ويظهر في المحادثة باسم الأتمتة.'],
        ],
        customer: 'بكم باقة الورد؟',
        reply: 'باقة الجوري بسعر 12.500 ر.ع — تفاصيلها وطلبها من هنا 👇',
        buttons: [{ type: 'link', title: 'اطلب الآن' }],
        list: 'أتمتة واحدة ترد على كل رسالة — الكلمة التامة أولًا ثم الأطول.',
    },
    instagram: {
        how: [
            ['اختر قالبًا أو ابدأ من الصفر', 'تعليق يصل صاحبه رد خاص، أو رسالة يصلها ردها.'],
            ['حدّد الكلمات والمنشورات', '«بكم» و«السعر» — على كل منشوراتك أو على ما تختاره منها.'],
            ['اكتب الرد مرة واحدة وانشره', 'يصل من حسابك على الخاص، ويُكتب تحت التعليق أنه أُرسل.'],
        ],
        customer: 'بكم باقة الورد؟',
        reply: 'باقة الجوري بسعر 12.500 ر.ع — تفاصيلها وطلبها من هنا 👇',
        buttons: [],
        list: 'أتمتة واحدة ترد على كل تعليق أو رسالة — أتمتة المنشور أولًا، ثم الكلمة التامة، ثم الأطول.',
    },
};

const TRIGGER_LABEL: Record<string, string> = { comment: 'تعليق', message: 'رسالة خاصة' };

/**
 * ‏‏أتمتةُ قناة — ردودٌ تخرج وحدها حين يكتب الزبون كلمةً يحدّدها التاجر.
 *
 * طريقةُ العمل بثلاث خطوات ومثالُ محادثة، ثمّ قوالبُ يبدأ منها، ثمّ أتمتاتُه — والترحيبُ أوّلُها في
 * واتساب بإعداداته القائمة. والمعالجُ يفتح مكان القائمة لا فوقها، فيعمل على الجوّال.
 */
export function AutomationsScreen({ view, channel = 'whatsapp' }: { view: AutomationsView; channel?: AutomationChannel }) {
    const t = useTranslate();
    const [ask, dialog] = useConfirm();
    const [editing, setEditing] = useState<{ draft: AutomationDraft; id: number | null } | null>(null);

    const copy = COPY[channel];
    const instagram = channel === 'instagram';
    const goWelcome = () => router.visit(route('admin.integrations.whatsapp'));

    if (editing) {
        return (
            <AutomationWizard
                key={editing.id ?? 'new'}
                channel={channel}
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
        router.post(route(AUTOMATION_ROUTES[channel].status, a.id), { status: a.status === 'published' ? 'paused' : 'published' }, { preserveScroll: true });

    const remove = async (a: AutomationRow) => {
        if (await ask({ message: 'حذف الأتمتة «:name»؟ لا يخرج منها رد بعد الحذف.', values: { name: a.name }, danger: true, action: 'حذف' })) {
            router.delete(route(AUTOMATION_ROUTES[channel].destroy, a.id), { preserveScroll: true });
        }
    };

    const full = view.automations.length >= view.limits.automations;

    return (
        <>
            {dialog}

            <SettingsSection title="طريقة العمل" icon={ListChecks}>
                <div className="grid gap-6 md:grid-cols-[minmax(0,1fr)_280px]">
                    <ol className="space-y-4" data-testid="how-it-works">
                        {copy.how.map(([title, line], i) => (
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
                        channel={channel}
                        customer={t(copy.customer)}
                        reply={t(copy.reply)}
                        buttons={copy.buttons.map((b) => ({ ...b, title: t(b.title) }))}
                        store={view.context.store}
                        comment={instagram}
                        publicReply={instagram ? t('أرسلنا لك التفاصيل على الخاص') : ''}
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
                            onClick={() => setEditing({ draft: draftFromTemplate(tpl, channel), id: null })}
                            className="rounded-[12px] border border-[#e5e7eb] p-4 text-start transition-colors hover:border-[#111] disabled:opacity-50"
                            data-testid={`template-${tpl.key}`}
                        >
                            <p className="font-bold text-[#111]">{tpl.name}</p>
                            <p className="mt-1 text-[12px] leading-relaxed text-[#6b7280]">{tpl.description}</p>
                            <p className="mt-2 flex flex-wrap gap-1">
                                {instagram && tpl.trigger && <span className="rounded-full bg-[#fdf2f8] px-2 py-0.5 text-[11px] text-[#be185d]">{t(TRIGGER_LABEL[tpl.trigger] ?? '')}</span>}
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
                description={copy.list}
                icon={Bot}
                action={view.may_manage ? (
                    <Button type="button" onClick={() => setEditing({ draft: blankDraft(channel), id: null })} disabled={full} data-testid="automation-new">
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
                                    {instagram && a.trigger && (
                                        <span className="rounded-full bg-[#fdf2f8] px-2 py-0.5 text-[11px] text-[#be185d]" data-testid="automation-trigger">
                                            {t(TRIGGER_LABEL[a.trigger] ?? '')}
                                            {a.trigger === 'comment' && <> · {(a.posts ?? []).length === 0 ? t('كل المنشورات') : t(':n منشور', { n: (a.posts ?? []).length })}</>}
                                        </span>
                                    )}
                                    {a.match === 'any' && <span className="rounded-full bg-[#f3f4f6] px-2 py-0.5 text-[11px] text-[#4b4b4b]">{t('أي تعليق')}</span>}
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
                                    <Button type="button" variant="outline" aria-label={t('تعديل')} onClick={() => setEditing({ draft: draftFrom(a, channel), id: a.id })} data-testid="automation-edit">
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
