import { Check, Sparkles } from 'lucide-react';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * اختيار التخطيط بالنظر لا بالاسم.
 *
 * «شكل الشبكة: متفاوتة» لا تعني شيئًا لصاحب محلّ ورود. وهو لا يريد أن يتعلّم
 * مفرداتنا — يريد أن يرى الشكلين ويختار. فكلُّ خيارٍ هنا رسمٌ مصغَّر يقول ما
 * يفعله: عمودان، أم صورةٌ تملأ العرض، أم بطاقاتٌ متفاوتة.
 *
 * والرسمُ خطوطٌ ومربّعات لا صورةُ معاينة: صورةٌ من موقعٍ آخر تكذب على من
 * ألوانُه غير ألوانه، ولقطةٌ من موقعه تحتاج أن يُرسم الموقعُ مرّتين لكلّ
 * خيار. والخطّان والمربّع يقولان «نصٌّ وصورة» في لمحة، وهو كلُّ المطلوب.
 *
 * و«كما في القالب» أوّلُ الخيارات لا آخرَها: هو الحال قبل أن يلمس شيئًا،
 * ومعناه أنّ قسمَه يتبع قالبه فيتبدّل معه إن بدّله.
 *
 * و`family` تقول أيَّ شيءٍ نرسم: `editorial` في الترويسة غيرُها في الشبكة
 * غيرُها في البطاقة. ولولاها لرُسم للشبكة رسمُ ترويسةٍ لأنّ الاسم واحد.
 */

export type Family = 'header' | 'hero' | 'grid' | 'categories' | 'card' | 'footer';

interface Option {
    value: string;
    label: string;
}

export default function LayoutPicker({
    value,
    options,
    family,
    onChange,
    columns = 3,
}: {
    value: string;
    options: Option[];
    family: Family;
    onChange: (value: string) => void;
    columns?: 2 | 3;
}) {
    const t = useTranslate();

    return (
        <div className={cn('grid gap-2', columns === 2 ? 'grid-cols-2' : 'grid-cols-2 sm:grid-cols-3')}>
            {options.map((o) => {
                const on = value === o.value;

                return (
                    <button
                        key={o.value}
                        type="button"
                        onClick={() => onChange(o.value)}
                        aria-pressed={on}
                        title={t(o.label)}
                        className={cn(
                            'overflow-hidden rounded-[10px] border p-1.5 text-start transition-all',
                            on
                                ? 'border-[#111] ring-1 ring-[#111]'
                                : 'border-[var(--ui-border,#e8e8e8)] hover:border-[#c9c9c9]',
                        )}
                    >
                        <Sketch family={family} shape={o.value} />
                        <span className="mt-1.5 flex items-center gap-1 px-0.5">
                            <span className="truncate text-[11.5px] font-semibold text-[#374151]">{t(o.label)}</span>
                            {on && <Check className="size-3 shrink-0 text-[#15803d]" />}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}

/* ============================ الرسوم ============================ */

const bar = 'rounded-[2px] bg-[#c9ced6]';
const solid = 'rounded-[3px] bg-[#9aa2ae]';
const ghost = 'rounded-[3px] bg-[#e7eaee]';

function Frame({ children, className }: { children?: React.ReactNode; className?: string }) {
    return (
        <span
            aria-hidden
            className={cn(
                'flex h-[54px] w-full flex-col gap-[3px] overflow-hidden rounded-[6px] bg-[#f6f7f9] p-[5px]',
                className,
            )}
        >
            {children}
        </span>
    );
}

/** خطٌّ من خطوط النصّ — عرضُه بالنسبة المئوية */
function Line({ w = 60, tall }: { w?: number; tall?: boolean }) {
    return <span className={cn(bar, tall ? 'h-[4px]' : 'h-[2px]')} style={{ width: `${w}%` }} />;
}

function Cells({ cols, count, feature }: { cols: number; count: number; feature?: boolean }) {
    return (
        <span className="grid flex-1 gap-[3px]" style={{ gridTemplateColumns: `repeat(${cols}, 1fr)` }}>
            {Array.from({ length: count }).map((_, i) => (
                <span
                    key={i}
                    className={i === 0 && feature ? solid : ghost}
                    style={feature && i === 0 ? { gridColumn: 'span 2', gridRow: 'span 2' } : undefined}
                />
            ))}
        </span>
    );
}

/**
 * الرسمُ لعائلته وشكله — وما لا رسمَ له يُرسم إطارًا محايدًا.
 *
 * فقيمةٌ تُضاف في المكتبة لا تكسر الشاشة قبل أن يُرسم لها شكل.
 */
function Sketch({ family, shape }: { family: Family; shape: string }) {
    if (shape === 'auto') {
        return (
            <Frame className="items-center justify-center border border-dashed border-[#d7dbe1] bg-white">
                <Sparkles className="size-4 text-[#9aa2ae]" />
            </Frame>
        );
    }

    switch (`${family}:${shape}`) {
        /* ---------------------------- الترويسة ---------------------------- */

        case 'header:minimal':
            return (
                <Frame>
                    <span className="flex items-center gap-[4px] border-b border-[#e3e6ea] pb-[5px]">
                        <span className={cn(solid, 'h-[6px] w-[14px]')} />
                        <span className="ms-auto flex gap-[4px]">
                            <span className={cn(bar, 'h-[2px] w-[8px]')} />
                            <span className={cn(bar, 'h-[2px] w-[8px]')} />
                        </span>
                    </span>
                    <span className="flex-1 rounded-[3px] bg-[#eceff3]" />
                </Frame>
            );

        case 'header:centered':
            return (
                <Frame>
                    <span className="flex flex-col items-center gap-[3px] border-b border-[#e3e6ea] pb-[4px]">
                        <span className={cn(solid, 'h-[6px] w-[16px]')} />
                        <span className="flex gap-[3px]">
                            <span className={cn(bar, 'h-[2px] w-[8px]')} />
                            <span className={cn(bar, 'h-[2px] w-[8px]')} />
                            <span className={cn(bar, 'h-[2px] w-[8px]')} />
                        </span>
                    </span>
                    <span className="flex-1 rounded-[3px] bg-[#eceff3]" />
                </Frame>
            );

        case 'header:commerce':
            return (
                <Frame>
                    <span className="flex items-center gap-[4px]">
                        <span className={cn(solid, 'h-[6px] w-[12px]')} />
                        <span className="h-[7px] flex-1 rounded-full bg-[#dfe3e8]" />
                    </span>
                    <span className="flex gap-[4px] border-y border-[#e3e6ea] py-[3px]">
                        <span className={cn(bar, 'h-[2px] w-[10px]')} />
                        <span className={cn(bar, 'h-[2px] w-[10px]')} />
                        <span className={cn(bar, 'h-[2px] w-[10px]')} />
                        <span className={cn(bar, 'h-[2px] w-[10px]')} />
                    </span>
                    <span className="flex-1 rounded-[3px] bg-[#eceff3]" />
                </Frame>
            );

        case 'header:editorial':
            return (
                <Frame>
                    <span className="flex items-center justify-between gap-[4px] pb-[8px]">
                        <span className={cn(bar, 'h-[3px] w-[18px]')} />
                        <span className="flex gap-[6px]">
                            <span className={cn(bar, 'h-[2px] w-[7px]')} />
                            <span className={cn(bar, 'h-[2px] w-[7px]')} />
                        </span>
                    </span>
                    <span className="flex-1 rounded-[3px] bg-[#eceff3]" />
                </Frame>
            );

        /* ---------------------------- الواجهة ---------------------------- */

        case 'hero:classic':
            return (
                <Frame className="items-center justify-center bg-[#c9ced6] p-0">
                    <span className="h-[4px] w-[28px] rounded-[2px] bg-white/90" />
                    <span className="h-[2px] w-[18px] rounded-[2px] bg-white/70" />
                </Frame>
            );

        case 'hero:centered':
            return (
                <Frame className="items-center">
                    <span className="flex w-full flex-col items-center gap-[3px] pb-[4px]">
                        <Line w={55} tall />
                        <Line w={38} />
                    </span>
                    <span className={cn(solid, 'h-[18px] w-full')} />
                </Frame>
            );

        case 'hero:split':
            return (
                <Frame className="flex-row items-center gap-[5px]">
                    <span className="flex flex-1 flex-col gap-[3px]">
                        <Line w={85} tall />
                        <Line w={62} />
                        <span className={cn(solid, 'mt-[2px] h-[5px] w-[18px]')} />
                    </span>
                    <span className={cn(solid, 'h-full w-[38%]')} />
                </Frame>
            );

        case 'hero:editorial':
            return (
                <Frame className="justify-end bg-[#9aa2ae] p-[6px]">
                    <span className="h-px w-[12px] bg-white/70" />
                    <span className="h-[5px] w-[34px] rounded-[2px] bg-white/90" />
                    <span className="h-[2px] w-[24px] rounded-[2px] bg-white/60" />
                </Frame>
            );

        case 'hero:showcase':
            return (
                <Frame className="flex-row gap-0 p-0">
                    <span className="flex flex-1 flex-col justify-center gap-[3px] bg-[#eceff3] p-[6px]">
                        <Line w={80} tall />
                        <Line w={55} />
                    </span>
                    <span className="w-[42%] bg-[#9aa2ae]" />
                </Frame>
            );

        /* ----------------------------- الشبكة ----------------------------- */

        case 'grid:classic':
            return (
                <Frame>
                    <Cells cols={3} count={6} />
                </Frame>
            );

        case 'grid:dense':
            return (
                <Frame>
                    <Cells cols={4} count={12} />
                </Frame>
            );

        case 'grid:large':
            return (
                <Frame>
                    <Cells cols={2} count={2} />
                </Frame>
            );

        case 'grid:editorial':
            return (
                <Frame>
                    <Cells cols={4} count={7} feature />
                </Frame>
            );

        /* ---------------------------- البطاقة ---------------------------- */

        case 'card:plain':
            return (
                <Frame className="flex-row gap-[4px]">
                    {[0, 1].map((i) => (
                        <span key={i} className="flex flex-1 flex-col gap-[3px]">
                            <span className={cn(ghost, 'flex-1')} />
                            <Line w={70} />
                            <Line w={40} />
                        </span>
                    ))}
                </Frame>
            );

        case 'card:soft':
            return (
                <Frame className="flex-row gap-[4px]">
                    {[0, 1].map((i) => (
                        <span key={i} className="flex flex-1 flex-col gap-[3px] rounded-[5px] bg-white p-[3px] shadow-sm">
                            <span className={cn(ghost, 'flex-1 rounded-[4px]')} />
                            <Line w={70} />
                            <span className="h-[5px] w-full rounded-full bg-[#dfe3e8]" />
                        </span>
                    ))}
                </Frame>
            );

        case 'card:commerce':
            return (
                <Frame className="flex-row gap-[4px]">
                    {[0, 1].map((i) => (
                        <span key={i} className="flex flex-1 flex-col overflow-hidden rounded-[4px] border border-[#dfe3e8] bg-white">
                            <span className="flex-1 bg-[#e7eaee]" />
                            <span className="flex flex-col gap-[2px] p-[3px]">
                                <Line w={80} />
                                <span className={cn(solid, 'h-[5px] w-full')} />
                            </span>
                        </span>
                    ))}
                </Frame>
            );

        case 'card:editorial':
            return (
                <Frame className="flex-row gap-[6px] px-[7px]">
                    {[0, 1].map((i) => (
                        <span key={i} className="flex flex-1 flex-col items-center gap-[3px]">
                            <span className={cn(solid, 'w-full flex-1')} />
                            <Line w={70} />
                        </span>
                    ))}
                </Frame>
            );

        case 'card:bare':
            return (
                <Frame className="flex-row gap-[6px]">
                    {[0, 1].map((i) => (
                        <span key={i} className="flex flex-1 flex-col gap-[3px]">
                            <span className={cn(ghost, 'w-full flex-1')} />
                            <Line w={62} />
                            <span className={cn(bar, 'h-px w-[30%]')} />
                        </span>
                    ))}
                </Frame>
            );

        /* ---------------------------- التصنيفات ---------------------------- */

        case 'categories:cards':
            return (
                <Frame>
                    <span className="grid flex-1 grid-cols-4 gap-[3px]">
                        {Array.from({ length: 4 }).map((_, i) => (
                            <span
                                key={i}
                                className="flex flex-col justify-end rounded-[3px] border border-[#dfe3e8] bg-white p-[3px]"
                            >
                                <span className={cn(bar, 'h-[2px] w-full')} />
                            </span>
                        ))}
                    </span>
                </Frame>
            );

        case 'categories:pills':
            return (
                <Frame className="items-center justify-center">
                    <span className="flex flex-wrap justify-center gap-[4px]">
                        {[16, 22, 14, 19].map((w, i) => (
                            <span key={i} className="h-[7px] rounded-full bg-[#dfe3e8]" style={{ width: w }} />
                        ))}
                    </span>
                </Frame>
            );

        case 'categories:covers':
            return (
                <Frame>
                    <span className="grid flex-1 grid-cols-3 gap-[3px]">
                        {Array.from({ length: 3 }).map((_, i) => (
                            <span key={i} className="flex flex-col overflow-hidden rounded-[3px] border border-[#dfe3e8]">
                                <span className="flex-1 bg-[#c9ced6]" />
                                <span className="h-[8px] bg-white" />
                            </span>
                        ))}
                    </span>
                </Frame>
            );

        case 'categories:tiles':
            return (
                <Frame>
                    <span className="grid flex-1 grid-cols-3 gap-[3px]">
                        {Array.from({ length: 3 }).map((_, i) => (
                            <span
                                key={i}
                                className={cn(solid, 'flex items-end p-[3px]')}
                                style={i === 0 ? { gridColumn: 'span 2' } : undefined}
                            >
                                <span className="h-[2px] w-full rounded-[2px] bg-white/80" />
                            </span>
                        ))}
                    </span>
                </Frame>
            );

        case 'categories:list':
            return (
                <Frame className="justify-center gap-[6px] px-[8px]">
                    {[70, 55, 62].map((w, i) => (
                        <span key={i} className="flex flex-col gap-[5px]">
                            <Line w={w} />
                            <span className="h-px w-full bg-[#e3e6ea]" />
                        </span>
                    ))}
                </Frame>
            );

        /* ---------------------------- التذييل ---------------------------- */

        case 'footer:columns':
            return (
                <Frame className="justify-end">
                    <span className="grid grid-cols-4 gap-[4px] border-t border-[#e3e6ea] pt-[5px]">
                        {Array.from({ length: 4 }).map((_, i) => (
                            <span key={i} className="flex flex-col gap-[2px]">
                                <span className={cn(bar, 'h-[2px] w-full')} />
                                <span className={cn(bar, 'h-[2px] w-[70%]')} />
                                <span className={cn(bar, 'h-[2px] w-[80%]')} />
                            </span>
                        ))}
                    </span>
                </Frame>
            );

        case 'footer:minimal':
            return (
                <Frame className="justify-end">
                    <span className="flex items-center justify-between border-t border-[#e3e6ea] pt-[6px]">
                        <span className={cn(solid, 'h-[5px] w-[14px]')} />
                        <span className="flex gap-[4px]">
                            <span className={cn(bar, 'h-[2px] w-[8px]')} />
                            <span className={cn(bar, 'h-[2px] w-[8px]')} />
                        </span>
                    </span>
                </Frame>
            );

        case 'footer:brand':
            return (
                <Frame className="items-center justify-end gap-[4px] pb-[6px]">
                    <span className={cn(solid, 'h-[6px] w-[20px]')} />
                    <Line w={60} />
                    <span className="flex gap-[3px]">
                        <span className="size-[5px] rounded-full bg-[#dfe3e8]" />
                        <span className="size-[5px] rounded-full bg-[#dfe3e8]" />
                        <span className="size-[5px] rounded-full bg-[#dfe3e8]" />
                    </span>
                </Frame>
            );

        case 'footer:split':
            return (
                <Frame className="flex-row items-end gap-[8px] border-t border-[#e3e6ea]">
                    <span className="flex flex-[1.3] flex-col gap-[3px]">
                        <span className={cn(solid, 'h-[6px] w-[18px]')} />
                        <Line w={90} />
                    </span>
                    <span className="flex flex-1 gap-[5px]">
                        {[0, 1].map((i) => (
                            <span key={i} className="flex flex-1 flex-col gap-[2px]">
                                <span className={cn(bar, 'h-[2px] w-full')} />
                                <span className={cn(bar, 'h-[2px] w-[70%]')} />
                            </span>
                        ))}
                    </span>
                </Frame>
            );

        default:
            return (
                <Frame>
                    <Cells cols={3} count={6} />
                </Frame>
            );
    }
}
