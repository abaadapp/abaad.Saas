import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Check, Clock, X } from 'lucide-react';
import { ToolMark } from '@/Components/BrandMarks';
import Gate from '@/Components/Gate';
import { SetupProgress } from '@/Components/Settings';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

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
 * بابُ الأداة قبل الربط — شعارٌ واسمٌ وزرّ، ولا شيء غيرها.
 *
 * والهيئةُ من `Gate` المشترك: أربعُ شاشاتٍ كانت ترسم البابَ نفسه بيدها
 * فاختلفت مقاساتُها. وما يخصّ هذه وحدها شيئان: شعارُ الأداة من `ToolMark`،
 * والزرُّ يكتب علامةَ البدء.
 */
export function ConnectGate({
    name,
    line,
    tool,
    note,
}: {
    /* مترجَمةً من المنادي لا هنا: `t()` داخل مكوّنٍ يبتلع النصّ من حارس
       الترجمة — يفحص `t('…')` في المصدر وخصائصَ معدودة، لا خاصّيةً نخترعها */
    name: string;
    line: string;
    /** الأداة كما يعرفها المسار — انظر IntegrationsController::connect */
    tool: 'whatsapp' | 'google';
    /** سطرٌ تحت الزرّ حين يكون على أبعاد شيءٌ قبل أن يبدأ */
    note?: string | null;
}) {
    const t = useTranslate();
    const [busy, setBusy] = useState(false);

    return (
        <Gate
            /* شعارُ الأداة نفسه — يُعرف قبل أن يُقرأ اسمُه تحته */
            mark={<ToolMark tool={tool} name={name} size={80} />}
            title={name}
            description={line}
            note={note}
            action={
                /* زرٌّ لا رابط: يكتب علامة البدء، فلا يُنفَّذ بجلبٍ مسبق */
                <Button
                    size="lg"
                    loading={busy}
                    onClick={() => {
                        setBusy(true);
                        router.post(route('admin.integrations.connect', tool), {}, {
                            onFinish: () => setBusy(false),
                        });
                    }}
                >
                    {t('ربط مع أبعاد')}
                </Button>
            }
        />
    );
}

/**
 * مراحلُ الربط بترتيبها — وحالُ كلٍّ منها، وأيُّها الآن.
 *
 * والرمز يفرّق بين ما تمّ، وما ينتظر فيه أبعاد، وما بيد التاجر: ساعةٌ صفراء
 * لا تُطلب منه، وعلامةُ نقصٍ حمراء تُقال بصيغة الأمر. ولو خُلطا لَبقي ينتظر
 * ما عليه أن يفعله، أو حاول ما لا يملكه.
 *
 * و**الخطوة الحالية مُعلَّمة**: أوّلُ ما لم يتمّ. وقائمةٌ من ستّ خطواتٍ
 * ثلاثُها خضراء تترك صاحبَها يعدّ بعينه أين وقف — وهو أوّل ما جاء يسأل عنه.
 */
export function ConnectSteps({ readiness, title, done, waiting, className }: {
    readiness: Readiness;
    /* مترجَمةً من المنادي — كما في ConnectGate */
    title: string;
    /** ما يُقال حين تمّت المراحل كلُّها */
    done: string;
    /** وما يُقال قبل ذلك */
    waiting: string;
    className?: string;
}) {
    const t = useTranslate();
    const at = readiness.steps.filter((s) => s.done).length;
    const total = readiness.steps.length;
    // الخطوة الحالية: أوّلُ ما لم يتمّ — وبعد التمام لا خطوةَ حاليّة
    const current = readiness.ready ? -1 : readiness.steps.findIndex((s) => ! s.done);

    return (
        <Card className={cn('p-5 sm:p-6', className)} asChild>
            <section>
                <div className="flex items-start gap-3">
                    <span
                        className={cn(
                            'mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-[10px] text-[13px] font-bold',
                            readiness.ready ? 'bg-[#f0fdf4] text-[#166534]' : 'bg-[#fafafa] text-[#6b7280]',
                        )}
                    >
                        {readiness.ready ? <Check className="size-[18px]" /> : `${at}/${total}`}
                    </span>
                    <div className="min-w-0">
                        <h2 className="font-bold text-[#111]">{title}</h2>
                        <p className="mt-0.5 text-[13px] leading-relaxed text-[#6b7280]">
                            {readiness.ready ? done : waiting}
                        </p>
                    </div>
                </div>

                <SetupProgress
                    className="mt-4"
                    at={at}
                    total={total}
                    done={readiness.ready}
                    label={t('مراحل مكتملة')}
                />

                <ol className="mt-5 divide-y divide-[var(--ui-border,#e8e8e8)]">
                    {readiness.steps.map((s, i) => (
                        <li
                            key={s.key}
                            aria-current={i === current ? 'step' : undefined}
                            className="flex items-start gap-3 py-3 first:pt-0 last:pb-0"
                        >
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
                            <div className="min-w-0 flex-1">
                                <p
                                    className={cn(
                                        'text-[13px]',
                                        i === current ? 'font-bold text-[#111]' : 'font-medium text-[#111]',
                                        s.done && 'text-[#6b7280]',
                                    )}
                                >
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

                            {/* و«الآن» تُقال بالحرف: لونٌ أغمق وحده يُقرأ زينةً */}
                            {i === current && (
                                <span className="shrink-0 rounded-full bg-[#111] px-2 py-0.5 text-[11px] font-medium text-white">
                                    {t('الخطوة الحالية')}
                                </span>
                            )}
                        </li>
                    ))}
                </ol>
            </section>
        </Card>
    );
}
