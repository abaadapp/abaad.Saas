import { AlertTriangle, Check, CircleDashed, Clock, Plug, PowerOff, XCircle } from 'lucide-react';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * حالُ ميزةٍ تُهيَّأ أو أداةٍ تُربط — بمفرداتٍ واحدة في اللوحة كلّها.
 *
 * ═══ لماذا مفرداتٌ مغلقة ═══
 *
 * كانت كلُّ شاشةٍ تسمّي الحال باسمها: «مربوط» هنا و«جاهز» هناك و«مفعّل»
 * في ثالثة، وثلاثتُها تعني شيئًا واحدًا أو ثلاثة أشياء — لا يُعرف. ومن
 * قرأ «مفعّل» في شاشةٍ وانتظر أن تعمل، ثمّ قرأ «جاهز» في أخرى، لا يدري
 * أبينهما فرقٌ أم لا.
 *
 * فالحالات ستٌّ لا سابعة لها، ولكلٍّ معنًى مفروزٌ عن أخيه:
 *
 * | الحال      | متى                                              |
 * |------------|--------------------------------------------------|
 * | `idle`     | لم يبدأ شيء — لا إعداد ولا محاولة                 |
 * | `progress` | بدأ ولم يكتمل — بقيت مرحلةٌ أو أكثر               |
 * | `ready`    | اكتمل الإعداد ويعمل                               |
 * | `action`   | ينتظر فعلًا من التاجر — نقصٌ يقف عنده العمل        |
 * | `error`    | جُرِّب فسقط — خطأٌ يُقرأ لا نقصٌ يُكمَل             |
 * | `off`      | مُطفأ بقرار صاحبه، لا بعطب                        |
 *
 * ═══ ولا لونَ وحده ═══
 *
 * لكلّ حالٍ **أيقونةٌ ونصّ** مع اللون. من لا يفرّق الأخضر من الأحمر — وهم
 * واحدٌ من اثني عشر رجلًا — يقرأ شارةً ملوّنة بلا خبر.
 */
export type SetupState = 'idle' | 'progress' | 'ready' | 'action' | 'error' | 'off';

const TONE: Record<SetupState, { cls: string; icon: typeof Check; label: string }> = {
    idle: { cls: 'bg-[#f2f2f0] text-[#4b4b4b]', icon: CircleDashed, label: 'غير مهيّأ' },
    progress: { cls: 'bg-[#eff6ff] text-[#2563eb]', icon: Clock, label: 'قيد الإعداد' },
    ready: { cls: 'bg-[#ecfdf5] text-[#047857]', icon: Check, label: 'جاهز' },
    action: { cls: 'bg-[#fffbeb] text-[#b45309]', icon: AlertTriangle, label: 'يحتاج إجراء' },
    error: { cls: 'bg-[#fef2f2] text-[#b91c1c]', icon: XCircle, label: 'يوجد خطأ' },
    off: { cls: 'bg-[#f2f2f0] text-[#6b7280]', icon: PowerOff, label: 'مُطفأ' },
};

/** حالُ أداةٍ مربوطة — «متّصل» لا «جاهز»: الوصلةُ قائمةٌ بذاتها */
export const CONNECTED = { cls: TONE.ready.cls, icon: Plug, label: 'متّصل' };

export default function StatusPill({
    state,
    label,
    connected = false,
    className,
}: {
    state: SetupState;
    /** نصٌّ يحلّ محلّ الاسم القياسيّ حين يقول الشاشةُ ما هو أدقّ */
    label?: string;
    /** «متّصل» بدل «جاهز» — لأداةٍ خارجيّة لها وصلة */
    connected?: boolean;
    className?: string;
}) {
    const t = useTranslate();
    const tone = connected && state === 'ready' ? { ...TONE.ready, ...CONNECTED } : TONE[state];
    const Icon = tone.icon;

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-[12px] font-medium',
                tone.cls,
                className,
            )}
        >
            <Icon aria-hidden className="size-3.5 shrink-0" />
            {label ? t(label) : t(tone.label)}
        </span>
    );
}

/**
 * حالُ الربط مقروءًا من مراحله — فلا تحسبه كلُّ شاشةٍ بطريقتها.
 *
 * و«بدأ ولم يكتمل» غير «لم يبدأ»: الأولى تُكمَل من حيث وقفت، والثانية
 * تُفتح من أوّلها. وجمعُهما في «غير مهيّأ» يجعل من قطع نصف الطريق يظنّ
 * أنّ ما فعله ضاع.
 */
export function readinessState(readiness: { connected: boolean; ready: boolean }): SetupState {
    if (readiness.ready) {
        return 'ready';
    }

    return readiness.connected ? 'progress' : 'idle';
}
