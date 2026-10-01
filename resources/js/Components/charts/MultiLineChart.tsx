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

/** سقفٌ «مستدير» للمحور — ١٢٣٤ يُقرأ ١٥٠٠ لا ١٢٣٤ */
function nice(v: number): number {
    if (v <= 0) return 0;
    const mag = 10 ** Math.floor(Math.log10(v));
    const norm = v / mag;
    return (norm <= 1 ? 1 : norm <= 2 ? 2 : norm <= 5 ? 5 : 10) * mag;
}

/**
 * منحنياتٌ على محورٍ واحد — وقد تنزل تحت الصفر.
 *
 * `AreaChart` سلسلةٌ واحدة لا تعرف السالب: الخسارةُ تُرسم عنده تحت القاع
 * خارج الإطار. وصافي الربح قد يكون خسارة، فهنا خطُّ الصفر خطٌّ ظاهر، وما
 * تحته يُقرأ تحته.
 *
 * والزمنُ يجري باتّجاه القراءة كما في `AreaChart`: من اليمين في العربيّة.
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
        const all = series.flatMap((s) => s.data);
        const top = nice(Math.max(0, ...all));
        const bottom = -nice(Math.max(0, ...all.map((v) => -v)));
        const span = top - bottom || 1;
        const y = (v: number) => PAD.top + ((top - v) / span) * innerH;
        const stepX = labels.length > 1 ? innerW / (labels.length - 1) : 0;
        const x = (i: number) => (labels.length === 1 ? (plotStart + plotEnd) / 2 : rtl ? plotEnd - i * stepX : plotStart + i * stepX);
        const ticks = Array.from({ length: 5 }, (_, i) => bottom + (span / 4) * i);

        return { y, x, ticks, zero: y(0), slot: stepX || innerW };
    }, [series, labels.length, innerH, innerW, plotStart, plotEnd, rtl]);

    // تسمياتٌ متباعدة على المحور: واحدٌ وثلاثون يومًا لا تتّسع لواحدٍ وثلاثين اسمًا
    const every = Math.max(1, Math.ceil(labels.length / 8));

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
                    className="h-auto w-full overflow-visible"
                    role="img"
                    aria-label={series.map((s) => s.label).join('، ')}
                    onMouseLeave={() => setHover(null)}
                >
                    {geo.ticks.map((v) => (
                        <g key={v}>
                            <line x1={plotStart} x2={plotEnd} y1={geo.y(v)} y2={geo.y(v)} stroke="#eeeeee" strokeWidth={1} />
                            <text
                                x={rtl ? plotEnd + 6 : plotStart - 6}
                                y={geo.y(v) + 4}
                                textAnchor={rtl ? 'start' : 'end'}
                                className="fill-[#9ca3af] text-[10px]"
                            >
                                {format(v)}
                            </text>
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

                    {labels.map((label, i) =>
                        i % every === 0 || i === labels.length - 1 ? (
                            <text key={i} x={geo.x(i)} y={height - 6} textAnchor="middle" className="fill-[#9ca3af] text-[10px]">
                                {label}
                            </text>
                        ) : null,
                    )}

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
