import { useId, useMemo, useState } from 'react';
import { motion } from 'framer-motion';
import { cn } from '@/lib/utils';

interface AreaChartProps {
    labels: string[];
    data: number[];
    /** لتنسيق القيمة في التلميح والمحور */
    format?: (value: number) => string;
    className?: string;
    height?: number;
}

const PAD = { top: 12, right: 8, bottom: 26, left: 56 };

/**
 * سلسلة زمنية بسلسلة واحدة — لا حاجة لمفتاح ألوان، العنوان يسمّيها.
 * خط 2px بلون واحد + تعبئة متدرّجة خافتة + تعقّب بالمؤشر.
 */
export default function AreaChart({
    labels,
    data,
    format = (v) => String(v),
    className,
    height = 260,
}: AreaChartProps) {
    const gradientId = useId();
    const [hover, setHover] = useState<number | null>(null);

    const width = 720;
    const innerW = width - PAD.left - PAD.right;
    const innerH = height - PAD.top - PAD.bottom;

    const { points, ticks } = useMemo(() => {
        const maxValue = Math.max(...data, 1);
        // سقف مريح: نقرّب لأعلى حتى تبقى القمة تحت الحافة
        const niceMax = Math.ceil(maxValue * 1.1) || 1;
        const stepX = data.length > 1 ? innerW / (data.length - 1) : 0;

        return {
            max: niceMax,
            points: data.map((value, i) => ({
                x: PAD.left + i * stepX,
                y: PAD.top + innerH - (value / niceMax) * innerH,
                value,
                label: labels[i] ?? '',
            })),
            ticks: Array.from({ length: 4 }, (_, i) => {
                const value = (niceMax / 3) * i;
                return { value, y: PAD.top + innerH - (value / niceMax) * innerH };
            }),
        };
    }, [data, labels, innerH, innerW]);

    const linePath = points.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x},${p.y}`).join(' ');
    const areaPath = points.length
        ? `${linePath} L${points[points.length - 1].x},${PAD.top + innerH} L${points[0].x},${PAD.top + innerH} Z`
        : '';

    return (
        <div className={cn('w-full overflow-x-auto', className)}>
            <svg
                viewBox={`0 0 ${width} ${height}`}
                className="w-full min-w-[520px]"
                role="img"
                onMouseLeave={() => setHover(null)}
            >
                <defs>
                    <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor="#7c3aed" stopOpacity="0.16" />
                        <stop offset="100%" stopColor="#7c3aed" stopOpacity="0" />
                    </linearGradient>
                </defs>

                {/* شبكة خافتة — لا تنافس البيانات */}
                {ticks.map((tick, i) => (
                    <g key={i}>
                        <line
                            x1={PAD.left}
                            y1={tick.y}
                            x2={width - PAD.right}
                            y2={tick.y}
                            stroke="#eeeeee"
                            strokeWidth="1"
                        />
                        <text
                            x={PAD.left - 8}
                            y={tick.y + 4}
                            textAnchor="end"
                            className="fill-[#9ca3af] text-[10px]"
                        >
                            {format(tick.value)}
                        </text>
                    </g>
                ))}

                {areaPath && <path d={areaPath} fill={`url(#${gradientId})`} />}
                {linePath && (
                    <motion.path
                        initial={{ pathLength: 0 }}
                        animate={{ pathLength: 1 }}
                        transition={{ duration: 0.7, ease: 'easeOut' }}
                        d={linePath}
                        fill="none"
                        stroke="#7c3aed"
                        strokeWidth="2"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                    />
                )}

                {/* تسميات المحور الأفقي — واحدة من كل اثنتين حتى لا تتصادم */}
                {points.map((p, i) =>
                    i % 2 === 0 ? (
                        <text
                            key={i}
                            x={p.x}
                            y={height - 8}
                            textAnchor="middle"
                            className="fill-[#9ca3af] text-[10px]"
                        >
                            {p.label}
                        </text>
                    ) : null,
                )}

                {/* التعقّب: خط رأسي + نقطة بحلقة بيضاء تفصلها عن الخط */}
                {hover !== null && points[hover] && (
                    <g>
                        <line
                            x1={points[hover].x}
                            y1={PAD.top}
                            x2={points[hover].x}
                            y2={PAD.top + innerH}
                            stroke="#d1d5db"
                            strokeWidth="1"
                            strokeDasharray="3 3"
                        />
                        <circle
                            cx={points[hover].x}
                            cy={points[hover].y}
                            r="5"
                            fill="#7c3aed"
                            stroke="#ffffff"
                            strokeWidth="2"
                        />
                    </g>
                )}

                {/* مناطق التقاط أعرض من العلامة نفسها */}
                {points.map((p, i) => (
                    <rect
                        key={i}
                        x={p.x - innerW / Math.max(points.length, 1) / 2}
                        y={PAD.top}
                        width={innerW / Math.max(points.length, 1)}
                        height={innerH}
                        fill="transparent"
                        onMouseEnter={() => setHover(i)}
                    />
                ))}
            </svg>

            {/* التلميح كنص أسفل الرسم — يتجنّب مشاكل تموضع RTL */}
            <p className="mt-1 h-5 text-center text-[12px] text-[#6b7280]">
                {hover !== null && points[hover] ? (
                    <>
                        <span className="font-medium text-[#111]">{points[hover].label}</span>
                        {' · '}
                        {format(points[hover].value)}
                    </>
                ) : (
                    <span className="text-[#c7c7c7]">مرّر المؤشر لعرض التفاصيل</span>
                )}
            </p>
        </div>
    );
}
