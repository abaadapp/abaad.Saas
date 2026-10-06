import { useMemo, useState } from 'react';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export interface LineSeries {
    key: string;
    label: string;
    data: number[];
}

interface Props {
    labels: string[];
    series: LineSeries[];
    format?: (value: number) => string;
    height?: number;
    className?: string;
}

/**
 * ألوانُ السلاسل بترتيبٍ ثابت — لا تُدوَّر ولا تتبع الرتبة.
 *
 * الخاناتُ الثلاث الأولى من لوحةٍ مُتحقَّقٍ منها لعمى الألوان (أزرق، برتقاليّ،
 * فيروزيّ). وأقلُّها تباينًا مع البياض يُعوَّض بأسماء السلاسل في المفتاح
 * وبالجدول تحت المنحنى — فلا يُقرأ رقمٌ باللون وحده.
 */
const COLORS = ['#2a78d6', '#eb6834', '#1baf7a'];

const PAD = { top: 14, bottom: 26, gutter: 64, edge: 10 };

/**
 * أيُّ التسميات تُكتب على المحور — والفاصلُ بينها لا يقلّ عن `every`.
 *
 * كانت كلُّ `every`-ـيّةٍ ومعها الأخيرةُ دائمًا: فتقع الأخيرةُ أحيانًا بجوار
 * سابقتها بنقطةٍ واحدة فتركبها. فالأخيرةُ تحلّ محلَّ سابقتها إن ضاق ما بينهما.
 * والترتيبُ ترتيبُ الخادم — لا يُعكس شيء.
 */
export function axisIndexes(count: number, every: number): number[] {
    if (count <= 0) return [];
    const shown: number[] = [];
    for (let i = 0; i < count; i += every) shown.push(i);
    const last = count - 1;
    const prev = shown[shown.length - 1];
    if (prev !== last) {
        if (last - prev >= every || shown.length === 1) shown.push(last);
        else shown[shown.length - 1] = last;
    }

    return shown;
}

/** سقفٌ «مستدير» للمحور — ١٢٣٤ يُقرأ ١٥٠٠ لا ١٢٣٤ */
function nice(v: number): number {
    if (v <= 0) return 0;
    const mag = 10 ** Math.floor(Math.log10(v));
    const norm = v / mag;
    return (norm <= 1 ? 1 : norm <= 2 ? 2 : norm <= 5 ? 5 : 10) * mag;
}

export interface LineDomain {
    top: number;
    bottom: number;
    /** علاماتُ المحور — كلُّها داخل [bottom, top] */
    ticks: number[];
}

/**
 * مدى المحور الرأسيّ من القيم — والعلاماتُ منه هو لا من مدًى مُفترَض.
 *
 * ═══ ولمَ صار دالّةً مستقلّة ═══
 *
 * كان المدى يُحسب في الرسم: `span = top - bottom || 1`. فإن كانت القيمُ كلُّها
 * أصفارًا صار `top` و`bottom` صفرين والمدى واحدًا — فخرجت العلاماتُ ٠ و٠٫٢٥
 * و٠٫٥ و٠٫٧٥ و١، بينما الموضعُ يُقاس من `top = 0`. فوقعت العلاماتُ الموجبة
 * فوق الرسم بإحداثيّاتٍ سالبة، و`overflow-visible` أخرجها فوق بطاقات الصفحة:
 * «1.000 ر.ع» تطفو على صافي الربح وهو صفر.
 *
 * فالمدى الذي لا سعةَ له (كلُّها أصفار، أو لا قيم) علامتُه واحدة: الصفر. ولا
 * يُكتب على المحور رقمٌ لم يبلغه شيء — محورٌ يقول «١ ر.ع» وصافي الربح صفرٌ
 * يُقرأ ربحًا لم يقع.
 */
export function lineDomain(values: number[]): LineDomain {
    const finite = values.filter((v) => Number.isFinite(v));
    const top = nice(Math.max(0, ...finite));
    // و`|| 0`: سالبُ الصفر يُكتب «-0» في بعض التنسيقات
    const bottom = -nice(Math.max(0, ...finite.map((v) => -v))) || 0;

    if (top === bottom) {
        return { top: 0, bottom: 0, ticks: [0] };
    }

    const span = top - bottom;

    return { top, bottom, ticks: Array.from({ length: 5 }, (_, i) => bottom + (span / 4) * i) };
}

/**
 * موضعُ قيمةٍ على المحور — داخل `[padTop, padTop + innerH]` أبدًا.
 *
 * والمدى بلا سعةٍ يضع كلَّ شيءٍ على خطّ القاع: صفرٌ مستوٍ يُقرأ صفرًا. وما ليس
 * رقمًا يُرسم صفرًا، وما جاوز المدى (فاصلةٌ عشريّةٌ فيه) يُحبس عند حدّه — فلا
 * يخرج خطٌّ ولا علامةٌ من الإطار مهما جاءت القيم.
 */
export function yPosition(domain: LineDomain, v: number, innerH: number, padTop: number): number {
    const span = domain.top - domain.bottom;

    if (!(span > 0)) {
        return padTop + innerH;
    }

    const value = Number.isFinite(v) ? Math.min(Math.max(v, domain.bottom), domain.top) : 0;

    return padTop + ((domain.top - value) / span) * innerH;
}

/**
 * منحنياتٌ على محورٍ واحد — وقد تنزل تحت الصفر.
 *
 * `AreaChart` سلسلةٌ واحدة لا تعرف السالب: الخسارةُ تُرسم عنده تحت القاع
 * خارج الإطار. وصافي الربح قد يكون خسارة، فهنا خطُّ الصفر خطٌّ ظاهر، وما
 * تحته يُقرأ تحته.
 *
 * والزمنُ يجري باتّجاه القراءة كما في `AreaChart`: من اليمين في العربيّة.
 *
 * ═══ والنصوصُ HTML داخل foreignObject — لا <text> ═══
 *
 * WebKit (Safari وآيباد) لا يُشكّل العربيّة ولا يرتّبها داخل <text>: أسماءُ
 * الأيّام والأشهر تخرج حروفًا مفكّكةً معكوسة، و«ر.ع» تنقلب حول الرقم. والنصُّ
 * في DOM سليم، فلا يراه إلّا من فتح الصفحة على Safari. وهو حلُّ `AreaChart`
 * نفسُه: الخطوطُ SVG، والنصُّ لمحرّك النصّ.
 */
export default function MultiLineChart({ labels, series, format = (v) => String(v), height = 280, className }: Props) {
    const t = useTranslate();
    const [hover, setHover] = useState<number | null>(null);

    const rtl = typeof document !== 'undefined' && document.documentElement.dir === 'rtl';
    const width = 720;
    const innerW = width - PAD.gutter - PAD.edge;
    const innerH = height - PAD.top - PAD.bottom;
    const plotStart = rtl ? PAD.edge : PAD.gutter;
    const plotEnd = plotStart + innerW;

    const geo = useMemo(() => {
        const domain = lineDomain(series.flatMap((s) => s.data));
        const y = (v: number) => yPosition(domain, v, innerH, PAD.top);
        const stepX = labels.length > 1 ? innerW / (labels.length - 1) : 0;
        const x = (i: number) => (labels.length === 1 ? (plotStart + plotEnd) / 2 : rtl ? plotEnd - i * stepX : plotStart + i * stepX);

        return { y, x, ticks: domain.ticks, zero: y(0), slot: stepX || innerW };
    }, [series, labels.length, innerH, innerW, plotStart, plotEnd, rtl]);

    // تسمياتٌ متباعدة على المحور: واحدٌ وثلاثون يومًا لا تتّسع لواحدٍ وثلاثين اسمًا
    const every = Math.max(1, Math.ceil(labels.length / 8));
    const shown = axisIndexes(labels.length, every);
    /*
     * حصّةُ التسمية: ما بين نقطتها والتسمية التالية — لا حصّةُ نقطةٍ واحدة.
     * فلا تمتدّ واحدةٌ على جارتها مهما طال الاسم، و`truncate` يقصّ ما يفيض.
     */
    const labelW = labels.length > 1 ? Math.min(geo.slot * every, innerW) : innerW;

    return (
        <div className={cn('w-full', className)} data-testid="profit-chart">
            <ul className="mb-3 flex flex-wrap gap-x-5 gap-y-1 text-[12.5px] text-[#374151]">
                {series.map((s, i) => (
                    <li key={s.key} className="flex items-center gap-2">
                        <span aria-hidden className="inline-block h-[2px] w-4 rounded" style={{ background: COLORS[i] }} />
                        {s.label}
                    </li>
                ))}
            </ul>

            <div className="relative">
                <svg
                    viewBox={`0 0 ${width} ${height}`}
                    /*
                        `overflow-hidden` حارسٌ ثانٍ لا الإصلاح: المدى أعلاه يضع كلَّ
                        شيءٍ داخل الإطار. وكان `overflow-visible` يُخرج ما شذّ عنه فوق
                        بطاقات الصفحة. وكلُّ ما يُرسم هنا داخل viewBox أصلًا.
                    */
                    className="h-auto w-full overflow-hidden"
                    role="img"
                    aria-label={series.map((s) => s.label).join('، ')}
                    onMouseLeave={() => setHover(null)}
                >
                    {geo.ticks.map((v) => (
                        <g key={v}>
                            <line x1={plotStart} x2={plotEnd} y1={geo.y(v)} y2={geo.y(v)} stroke="#eeeeee" strokeWidth={1} />
                            {/*
                                الرقمُ وعملتُه LTR في الصفحة العربيّة أيضًا — «١٬٥٠٠ ر.ع» لا
                                «ر.ع ١٬٥٠٠» مقلوبةً. والحصّةُ في جهة البداية: يمينًا في العربيّة.
                            */}
                            <foreignObject
                                x={rtl ? plotEnd + 6 : 0}
                                y={geo.y(v) - 7}
                                width={PAD.gutter - 6}
                                height={14}
                                className="pointer-events-none"
                                data-axis="y"
                            >
                                <div
                                    dir="ltr"
                                    className={cn('truncate text-[10px] leading-[14px] text-[#9ca3af]', rtl ? 'text-start' : 'text-end')}
                                >
                                    {format(v)}
                                </div>
                            </foreignObject>
                        </g>
                    ))}

                    {/* خطُّ الصفر — الخسارةُ تحته لا خارج الإطار */}
                    <line x1={plotStart} x2={plotEnd} y1={geo.zero} y2={geo.zero} stroke="#9ca3af" strokeWidth={1} />

                    {hover !== null && (
                        <line x1={geo.x(hover)} x2={geo.x(hover)} y1={PAD.top} y2={PAD.top + innerH} stroke="#d1d5db" strokeWidth={1} />
                    )}

                    {series.map((s, si) => (
                        <path
                            key={s.key}
                            d={s.data.map((v, i) => `${i === 0 ? 'M' : 'L'}${geo.x(i)},${geo.y(v)}`).join(' ')}
                            fill="none"
                            stroke={COLORS[si]}
                            strokeWidth={2}
                            strokeLinejoin="round"
                            strokeLinecap="round"
                        />
                    ))}

                    {hover !== null &&
                        series.map((s, si) => (
                            <circle key={s.key} cx={geo.x(hover)} cy={geo.y(s.data[hover] ?? 0)} r={4} fill={COLORS[si]} stroke="#ffffff" strokeWidth={2} />
                        ))}

                    {shown.map((i) => {
                        /*
                         * والتسميةُ داخل الإطار: حصّةُ الأولى والأخيرة تُزاح إلى الداخل
                         * ويُحاذي نصُّها طرفَها — كما في `AreaChart` — فلا يُقصّ نصفُ
                         * الاسم ولا يركب جارتَه. و`dir="auto"`: الاسمُ العربيّ يُقرأ من
                         * اليمين والإنجليزيّ من اليسار، والأرقامُ في مكانها منه.
                         */
                        const raw = geo.x(i) - labelW / 2;
                        const x = Math.min(Math.max(raw, 0), width - labelW);
                        const align = x > raw ? 'text-left' : x < raw ? 'text-right' : 'text-center';

                        return (
                            <foreignObject
                                key={i}
                                x={x}
                                y={height - 18}
                                width={labelW}
                                height={14}
                                className="pointer-events-none"
                                data-axis="x"
                                data-index={i}
                            >
                                <div
                                    dir="auto"
                                    className={cn(
                                        'truncate px-0.5 text-[10px] leading-[14px]',
                                        align,
                                        hover === i ? 'font-bold text-[#374151]' : 'text-[#9ca3af]',
                                    )}
                                >
                                    {labels[i]}
                                </div>
                            </foreignObject>
                        );
                    })}

                    {/* مساحاتُ اللمس أعرضُ من الخطّ — عمودٌ كاملٌ لكلّ نقطة */}
                    {labels.map((_, i) => (
                        <rect
                            key={i}
                            x={geo.x(i) - geo.slot / 2}
                            y={PAD.top}
                            width={geo.slot}
                            height={innerH}
                            fill="transparent"
                            className="cursor-pointer"
                            onMouseEnter={() => setHover(i)}
                            onClick={() => setHover(i)}
                        />
                    ))}
                </svg>

                {hover !== null && (
                    <div
                        data-testid="profit-chart-tip"
                        className="pointer-events-none absolute top-0 rounded-lg border border-[var(--ui-border,#e8e8e8)] bg-white px-3 py-2 text-[12px] shadow-sm"
                        style={{ insetInlineStart: 8 }}
                    >
                        <p className="mb-1 font-semibold text-[#111]">{labels[hover]}</p>
                        {series.map((s, si) => (
                            <p key={s.key} className="flex items-center gap-2 text-[#374151]">
                                <span aria-hidden className="inline-block size-2 rounded-full" style={{ background: COLORS[si] }} />
                                {s.label}: <span className="tabular-nums">{format(s.data[hover] ?? 0)}</span>
                            </p>
                        ))}
                    </div>
                )}
            </div>
            <p className="sr-only">{t('التفاصيل في الجدول أدناه')}</p>
        </div>
    );
}
