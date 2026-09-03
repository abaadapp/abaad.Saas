import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Check, Clock, X, type LucideIcon } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';

/** خطوةُ ربطٍ واحدة — شكلُها من App\Support\Integration::step وحده */
export interface Step {
    key: string;
    label: string;
    done: boolean;
    detail: string | null;
    fix: string | null;
    /** خطوةٌ ينتظر فيها أبعاد — لا يُطلب من التاجر إصلاحُها */
    theirs: boolean;
}

export interface Readiness {
    /** بدأ التاجر: ضغط «ربط» وقُطع شوط — غير `ready` التي تعني «تمّ كلُّ شيء» */
    connected: boolean;
    ready: boolean;
    steps: Step[];
}

/**
 * بابُ الأداة قبل الربط — أيقونةٌ واسمٌ وزرّ، ولا شيء غيرها.
 *
 * وكانت الشاشة تُفتح على كلّ ما فيها دفعةً واحدة: مراحلُ ربطٍ لم تبدأ،
 * وحقولُ معرّفاتٍ لا يعرفها، ومقابضُ أحداثٍ لا تُرسل حرفًا قبل الربط. فيقرأ
 * التاجر عشرين سطرًا ليعرف أنّ لا شيء منها يعمل بعد، ثمّ يبحث عن الخطوة
 * الأولى بين البقيّة.
 *
 * فصار البابُ بابًا: شيءٌ واحدٌ يُضغط. وما وراءه يُعرض بعده.
 */
export function ConnectGate({
    icon: Icon,
    name,
    line,
    tool,
    tint,
    note,
}: {
    icon: LucideIcon;
    /* مترجَمةً من المنادي لا هنا: `t()` داخل مكوّنٍ يبتلع النصّ من حارس
       الترجمة — يفحص `t('…')` في المصدر وخصائصَ معدودة، لا خاصّيةً نخترعها */
    name: string;
    line: string;
    /** الأداة كما يعرفها المسار — انظر MarketingController::connect */
    tool: 'whatsapp' | 'google';
    /** لونُ الأداة — واتساب أخضر والخرائط حمراء، فتُعرف قبل أن تُقرأ */
    tint: string;
    /** سطرٌ تحت الزرّ حين يكون على أبعاد شيءٌ قبل أن يبدأ */
    note?: string | null;
}) {
    const t = useTranslate();
    const [busy, setBusy] = useState(false);

    return (
        <Card className="mx-auto flex max-w-xl flex-col items-center px-6 py-16 text-center">
            <span
                className="flex size-20 items-center justify-center rounded-[24px]"
                style={{ background: tint + '14', color: tint }}
            >
                <Icon className="size-9" />
            </span>

            <h2 className="mt-6 text-[20px] font-bold text-[#111]">{name}</h2>
            <p className="mt-2 max-w-sm text-[14px] leading-relaxed text-[#6b7280]">{line}</p>

            {/* زرٌّ لا رابط: يكتب علامة البدء، فلا يُنفَّذ بجلبٍ مسبق */}
            <Button
                size="lg"
                className="mt-8"
                loading={busy}
                onClick={() => {
                    setBusy(true);
                    router.post(route('admin.marketing.connect', tool), {}, {
                        onFinish: () => setBusy(false),
                    });
                }}
            >
                {t('ربط مع أبعاد')}
            </Button>

            {note && <p className="mt-4 max-w-sm text-[12px] text-[#b45309]">{note}</p>}
        </Card>
    );
}

/**
 * مراحلُ الربط بترتيبها — وحالُ كلٍّ منها.
 *
 * والرمز يفرّق بين ما تمّ، وما ينتظر فيه أبعاد، وما بيد التاجر: ساعةٌ صفراء
 * لا تُطلب منه، وعلامةُ نقصٍ حمراء تُقال بصيغة الأمر. ولو خُلطا لَبقي ينتظر
 * ما عليه أن يفعله، أو حاول ما لا يملكه.
 */
export function ConnectSteps({ readiness, title, done, waiting }: {
    readiness: Readiness;
    /* مترجَمةً من المنادي — كما في ConnectGate */
    title: string;
    /** ما يُقال حين تمّت المراحل كلُّها */
    done: string;
    /** وما يُقال قبل ذلك */
    waiting: string;
}) {
    const at = readiness.steps.filter((s) => s.done).length;

    return (
        <Card className="mb-6 max-w-3xl p-6">
            <div className="mb-5 flex items-start gap-3">
                <span
                    className={
                        'mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-[10px] text-[13px] font-bold ' +
                        (readiness.ready ? 'bg-[#f0fdf4] text-[#166534]' : 'bg-[#fafafa] text-[#6b7280]')
                    }
                >
                    {readiness.ready ? <Check className="size-[18px]" /> : `${at}/${readiness.steps.length}`}
                </span>
                <div>
                    <h3 className="font-bold text-[#111]">{title}</h3>
                    <p className="mt-0.5 text-[13px] text-[#6b7280]">{readiness.ready ? done : waiting}</p>
                </div>
            </div>

            <ol className="divide-y divide-[var(--ui-border,#e8e8e8)]">
                {readiness.steps.map((s, i) => (
                    <li key={s.key} className="flex items-start gap-3 py-3 first:pt-0">
                        <span className="mt-0.5 shrink-0">
                            {s.done ? (
                                <Check className="size-4 text-[#047857]" />
                            ) : s.theirs ? (
                                /* ما ينتظر فيه أبعاد ليس عطبًا في يده — والرمز يفرّق */
                                <Clock className="size-4 text-[#b45309]" />
                            ) : (
                                <X className="size-4 text-[#b91c1c]" />
                            )}
                        </span>
                        <div className="min-w-0">
                            <p className="text-[13px] font-medium text-[#111]">
                                <span className="text-[#9ca3af]">{i + 1} · </span>
                                {s.label}
                            </p>
                            {s.detail && <p className="mt-0.5 text-[12px] text-[#6b7280]">{s.detail}</p>}
                            {s.fix && (
                                <p className={'mt-0.5 text-[12px] ' + (s.theirs ? 'text-[#b45309]' : 'text-[#b91c1c]')}>
                                    {s.fix}
                                </p>
                            )}
                        </div>
                    </li>
                ))}
            </ol>
        </Card>
    );
}
