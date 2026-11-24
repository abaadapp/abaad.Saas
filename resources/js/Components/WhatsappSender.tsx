import { router } from '@inertiajs/react';
import { Check, CircleDashed, Clock, XCircle } from 'lucide-react';
import { useConfirm } from '@/Components/ConfirmDialog';
import StatusPill, { type SetupState } from '@/Components/StatusPill';
import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type StepState = 'ok' | 'wait' | 'fail' | 'unknown';

/** رقمُ إرسال المتجر كما حسبه الخادم — انظر App\Support\WhatsAppSender::view. بلا رمزٍ ولا معرّف. */
export interface SenderView {
    sends_from: 'own' | 'shared';
    phone: string | null;
    own_app: boolean;
    /** `checking` رُبط ويُتحقَّق منه · `adopted` صار رقم الإرسال · `problem` يُرسل منه وتعطّل · `none` لا شيء يُقال */
    state: 'checking' | 'adopted' | 'problem' | 'none';
    steps: { key: string; label: string; state: StepState; note: string | null }[];
    blocking: { text: string; who: 'merchant' | 'abaad' | 'meta' } | null;
    checked_at: string | null;
    adopted_at: string | null;
    problem: { text: string; who: 'merchant' | 'abaad' | 'meta'; since: string | null } | null;
    /** أيُتاح له رقم أبعاد إن اختاره صراحةً؟ */
    shared_possible: boolean;
    history: { at: string | null; from: string | null; to: string; phone: string | null; by: string }[];
}

const PILL: Record<Exclude<SenderView['state'], 'none'>, { state: SetupState; label: string }> = {
    checking: { state: 'progress', label: 'قيد التحقّق' },
    adopted: { state: 'ready', label: 'معتمد' },
    problem: { state: 'error', label: 'متعطّل' },
};

const ICON: Record<StepState, { icon: typeof Check; cls: string }> = {
    ok: { icon: Check, cls: 'text-[#047857]' },
    wait: { icon: Clock, cls: 'text-[#2563eb]' },
    fail: { icon: XCircle, cls: 'text-[#b91c1c]' },
    unknown: { icon: CircleDashed, cls: 'text-[#9ca3af]' },
};

/** من يفعل ما بقي — يُقال بجانب السبب فلا ينتظر أحدٌ ما ليس عليه */
const WHO: Record<'merchant' | 'abaad' | 'meta', string> = {
    merchant: 'على صاحب المتجر',
    abaad: 'على فريق أبعاد',
    meta: 'نتابعه تلقائيًّا',
};

/**
 * رقمُ إرسال المتجر — يصير رقمَه وحده حين تثبت جاهزيّتُه، ويُقال ما بقي ومن يفعله.
 *
 * شاشةُ التاجر وبطاقةُ المتجر عند فريق أبعاد تعرضان الشيء نفسه من الخادم نفسه. ورقمٌ تعطّل لا تُنقل
 * رسائلُه إلى رقم أبعاد: زرُّ ذلك بيد التاجر وحده، وبتأكيد.
 */
export default function WhatsappSender({ sender, audience, mayManage = false }: {
    sender: SenderView;
    audience: 'merchant' | 'platform';
    mayManage?: boolean;
}) {
    const t = useTranslate();
    const [ask, dialog] = useConfirm();

    if (sender.state === 'none' && sender.history.length === 0) return null;

    const mine = audience === 'merchant';
    const pill = sender.state === 'none' ? null : PILL[sender.state];

    const useShared = async () => {
        const yes = await ask({
            title: 'الإرسال من رقم أبعاد',
            message: 'ستخرج رسائل متجرك من رقم أبعاد المشترك وتُحتسب من حصّتك، ويقرأ عملاؤك رقمًا غير رقمك. يبقى ذلك حتى تعيد ربط رقمك. متابعة؟',
            action: 'أرسل من رقم أبعاد',
        });

        if (yes) router.post(route('admin.integrations.whatsapp.mode'), { mode: 'abaad_shared' }, { preserveScroll: true });
    };

    return (
        <div className="rounded-[12px] border border-[var(--ui-border,#e8e8e8)] bg-white p-4 text-[13px]" data-testid="wa-sender" data-state={sender.state}>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="font-semibold text-[#111]">
                    {t('رقم الإرسال')}
                    {sender.phone && <span className="ms-2 font-normal text-[#6b7280]"><span dir="ltr">{sender.phone}</span></span>}
                </p>
                {pill && <StatusPill state={pill.state} label={t(pill.label)} />}
            </div>

            {sender.state === 'adopted' && (
                <p className="mt-1 leading-relaxed text-[#047857]" data-testid="wa-sender-adopted">
                    {mine ? t('كلّ رسائل متجرك تخرج من رقمك.') : t('كلّ رسائل المتجر تخرج من رقمه.')}
                </p>
            )}

            {sender.state === 'checking' && (
                <>
                    <p className="mt-1 leading-relaxed text-[#6b7280]" data-testid="wa-sender-checking">
                        {sender.sends_from === 'shared'
                            ? mine
                                ? t('رسائل متجرك ما زالت تخرج من رقم أبعاد. يصير رقمك رقم الإرسال تلقائيًّا حين تكتمل هذه المراحل.')
                                : t('رسائل المتجر ما زالت تخرج من رقم أبعاد. يصير رقمه رقم الإرسال تلقائيًّا حين تكتمل هذه المراحل.')
                            : t('نتحقّق من جاهزيّة الرقم. نقول ما بقي حتى يكتمل.')}
                    </p>
                    <ul className="mt-3 space-y-1.5" data-testid="wa-sender-steps">
                        {sender.steps.map((s) => {
                            const Icon = ICON[s.state].icon;

                            return (
                                <li key={s.key} className="flex items-start gap-2" data-testid={`wa-sender-step-${s.key}`} data-state={s.state}>
                                    <Icon className={cn('mt-0.5 size-4 shrink-0', ICON[s.state].cls)} />
                                    <span>
                                        <span className="text-[#374151]">{t(s.label)}</span>
                                        {s.note && <span className="block text-[12px] leading-relaxed text-[#6b7280]">{s.note}</span>}
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                    {sender.blocking && (
                        <p className="mt-2 text-[12px] font-medium text-[#92400e]" data-testid="wa-sender-who" data-who={sender.blocking.who}>
                            {t(WHO[sender.blocking.who])}
                        </p>
                    )}
                    {sender.checked_at && (
                        <p className="mt-1 text-[11px] text-[#9ca3af]">
                            {t('آخر فحص')}: <span dir="ltr">{sender.checked_at}</span>
                        </p>
                    )}
                </>
            )}

            {sender.state === 'problem' && sender.problem && (
                <div className="mt-2 rounded-[10px] bg-[#fef2f2] px-3 py-2 text-[12px] leading-relaxed text-[#b91c1c]" data-testid="wa-sender-problem" data-who={sender.problem.who}>
                    <p className="font-semibold">{sender.problem.text}</p>
                    <p className="mt-0.5">
                        {mine
                            ? t('رسائل متجرك متوقّفة حتى يعود رقمك — ولا تخرج من رقم أبعاد ولا من رقم آخر.')
                            : t('رسائل المتجر متوقّفة حتى يعود رقمه — ولا تخرج من رقم أبعاد ولا من رقم آخر.')}
                    </p>
                    <p className="mt-0.5 opacity-80">
                        {t(WHO[sender.problem.who])}
                        {sender.problem.since && <> · {t('منذ')} <span dir="ltr">{sender.problem.since}</span></>}
                    </p>
                    {mine && mayManage && sender.shared_possible && (
                        <Button type="button" variant="outline" size="sm" className="mt-2" onClick={() => void useShared()} data-testid="wa-sender-use-shared">
                            {t('أرسل من رقم أبعاد مؤقّتًا')}
                        </Button>
                    )}
                </div>
            )}

            {sender.history.length > 0 && (
                <div className="mt-3 border-t border-[#f0f0f0] pt-2">
                    <p className="text-[12px] font-semibold text-[#6b7280]">{t('سجلّ رقم الإرسال')}</p>
                    <ul className="mt-1 space-y-0.5 text-[12px] text-[#4b4b4b]" data-testid="wa-sender-history">
                        {sender.history.map((h, i) => (
                            <li key={i}>
                                <span dir="ltr">{h.at}</span> — {h.from && h.from !== h.to ? <>{h.from} ← {h.to}</> : h.to}
                                {h.phone && <> (<span dir="ltr">{h.phone}</span>)</>} · {h.by}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            {dialog}
        </div>
    );
}
