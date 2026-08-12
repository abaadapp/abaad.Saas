import { useId, useMemo, useState } from 'react';
import { motion } from 'framer-motion';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

interface AreaChartProps {
    labels: string[];
    data: number[];
    /** تسمية كاملة لكل نقطة تُقرأ في التلميح — «الأحد ١٠ أغسطس» مقابل «١٠» على المحور */
    fullLabels?: string[];
    /** عدد الطلبات في كل نقطة — المبلغ وحده لا يفرّق بين طلبٍ كبير وأربعين صغيرًا */
    counts?: number[];
    /** لتنسيق القيمة في التلميح والمحور */
    format?: (value: number) => string;
    className?: string;
    height?: number;
}

const PAD = { top: 12, right: 8, bottom: 26, left: 56 };

/**
 * سقفٌ يقبل القسمة على ثلاثة بأرقامٍ تُقرأ.
 *
 * كان السقف ceil(max × 1.1) فتخرج خطوةٌ مثل ٤٫٦٦٧: محورٌ رأسيّ بأرقامٍ
 * كسريّة لا يُقاس عليه شيء بالنظر. فيُرفع السقف إلى أقرب خطوةٍ من عائلة
 * ١ / ٢ / ٢٫٥ / ٥ / ١٠ — وهي التي يقرؤها الناس دون حساب.
 */
function niceCeiling(max: number, steps: number): number {
    if (max <= 0) return 1;
    const raw = (max * 1.1) / steps;
    const mag = 10 ** Math.floor(Math.log10(raw));
    const norm = raw / mag;
    const step = (norm <= 1 ? 1 : norm <= 2 ? 2 : norm <= 2.5 ? 2.5 : norm <= 5 ? 5 : 10) * mag;

    return step * steps;
}

/**
 * سلسلة زمنية بسلسلة واحدة — لا حاجة لمفتاح ألوان، العنوان يسمّيها.
 * خط 2px بلون واحد + تعبئة متدرّجة خافتة + تعقّب بالمؤشر.
 *
 * والتسميات كلّها HTML داخل foreignObject، لا <text>.
 *
 * WebKit لا يُشكّل العربية ولا يرتّبها داخل <text>: «سبتمبر» تخرج «ربتبس»
 * حروفًا مفكّكة معكوسة. والمتجر يعمل على آيباد، أي Safari — فتسميات الأشهر
 * في ستّ شاشات كانت طلاسم عند التاجر وسليمة عند كل من فحصها على كروم.
 * والنصّ في DOM صحيح، فلا يكشفه اختبارٌ يقرأ الشجرة ولا فاحص الترجمة؛ لا
 * يُرى إلا بالعين على المحرّك الصحيح.
 *
 * فيبقى SVG للخطوط والمسارات — وهي لا لغة لها — ويُترك النصّ لمحرّك النصّ.
 */
export default function AreaChart({
    labels,
    data,
    fullLabels,
    counts,
    format = (v) => String(v),
    className,
    height = 260,
}: AreaChartProps) {
    const t = useTranslate();
    const gradientId = useId();
    const [hover, setHover] = useState<number | null>(null);

    const width = 720;
    const innerW = width - PAD.left - PAD.right;
    const innerH = height - PAD.top - PAD.bottom;

    const { points, ticks, slot } = useMemo(() => {
        const maxValue = Math.max(...data, 0);
        const niceMax = niceCeiling(maxValue, 3);
        const stepX = data.length > 1 ? innerW / (data.length - 1) : 0;

        return {
            max: niceMax,
            // عرض ما تشغله التسمية الواحدة: المسافة بين نقطتين. الأشهر
            // الاثنا عشر تُعرض كلّها، فلكلٍّ حصّتها ولا تتداخل مع جارتها
            slot: stepX || innerW,
            points: data.map((value, i) => ({
                x: PAD.left + i * stepX,
                y: PAD.top + innerH - (value / niceMax) * innerH,
                value,
                label: labels[i] ?? '',
                full: fullLabels?.[i] ?? labels[i] ?? '',
                count: counts?.[i],
            })),
            ticks: Array.from({ length: 4 }, (_, i) => {
                const value = (niceMax / 3) * i;
                return { value, y: PAD.top + innerH - (value / niceMax) * innerH };
            }),
        };
    }, [data, labels, fullLabels, counts, innerH, innerW]);

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
                    <line
                        key={i}
                        x1={PAD.left}
                        y1={tick.y}
                        x2={width - PAD.right}
                        y2={tick.y}
                        stroke="#eeeeee"
                        strokeWidth="1"
                    />
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

                {/*
                    مناطق التقاط أعرض من العلامة نفسها — وتستجيب للضغط.

                    كانت تسمع onMouseEnter وحده والتلميح يقول «مرّر المؤشر»،
                    والمتجر يعمل على آيباد: لا مؤشّر هناك ولا تمرير، فتفاصيل
                    كل شهرٍ لم تكن تُقرأ أصلًا على الجهاز الذي يُستعمل فعلًا.

                    والضغط لا يُلغي التمرير: على الحاسب يُقرأ بالمرور، وعلى
                    اللمس بالنقر، ونفس المنطقة تخدم الاثنين.
                */}
                {points.map((p, i) => (
                    <rect
                        key={i}
                        x={p.x - slot / 2}
                        y={PAD.top}
                        width={slot}
                        height={innerH}
                        fill="transparent"
                        className="cursor-pointer"
                        onMouseEnter={() => setHover(i)}
                        onClick={() => setHover(i)}
                    />
                ))}

                {/*
                    التسميات: HTML داخل foreignObject لا <text>.

                    وضعُها في طبقة HTML فوق الرسم يبدو أبسط، لكنه يفكّ ارتباط
                    حجمها بالرسم: SVG يتقلّص مع عرض البطاقة (viewBox) والنصّ
                    يبقى ١٠px، فيطفح خارج حدوده ويُقصّ — رأيتُ المحور الرأسي
                    يعرض «ر.ع» بلا أرقام. وداخل foreignObject تُقاس الأبعاد
                    بوحدات الرسم نفسها فتتقلّص معه، ويبقى النصّ نصَّ HTML
                    يشكّله المتصفّح كما يشكّل أي كلمة في الصفحة.

                    وdir=ltr على الصندوق وحده: الإحداثيات تُقرأ من اليسار في
                    SVG مهما كانت لغة الصفحة، أمّا الكلمة فتُشكَّل باتجاهها.
                */}
                {ticks.map((tick, i) => (
                    <foreignObject
                        key={`t${i}`}
                        x={0}
                        y={tick.y - 7}
                        width={PAD.left - 8}
                        height={14}
                        className="pointer-events-none"
                    >
                        <div
                            dir="ltr"
                            className="truncate text-end text-[10px] leading-[14px] text-[#9ca3af]"
                        >
                            {format(tick.value)}
                        </div>
                    </foreignObject>
                ))}

                {/*
                    كل الأشهر تُكتب — لا واحدة من كل اثنتين.

                    كان نصف المحور بلا اسم، فيقرأ الناظر قمّةً ويعدّ بعينه
                    ليعرف شهرها، وقد يُخطئ عمودًا. وعرضُ التسمية محصورٌ بحصّة
                    النقطة (المسافة بين نقطتين) فلا تزحف على جارتها مهما طال
                    الاسم — «سبتمبر» أو «September».
                */}
                {points.map((p, i) => (
                    <foreignObject
                        key={`l${i}`}
                        x={p.x - slot / 2}
                        y={height - 18}
                        width={slot}
                        height={14}
                        className="pointer-events-none"
                    >
                        <div
                            dir="ltr"
                            className={cn(
                                'truncate text-center text-[9px] leading-[14px]',
                                hover === i ? 'font-bold text-[#7c3aed]' : 'text-[#9ca3af]',
                            )}
                        >
                            {p.label}
                        </div>
                    </foreignObject>
                ))}
            </svg>

            {/* التلميح كنص أسفل الرسم — يتجنّب مشاكل تموضع RTL */}
            <p className="mt-1 h-5 text-center text-[12px] text-[#6b7280]">
                {hover !== null && points[hover] ? (
                    <>
                        <span className="font-medium text-[#111]">{points[hover].full}</span>
                        {' · '}
                        {format(points[hover].value)}
                        {/* عددُ الطلبات يفصل بيعةً كبيرة عن يومٍ مزدحم — والمبلغ وحده لا يفعل */}
                        {points[hover].count !== undefined && (
                            <>
                                {' · '}
                                {points[hover].count === 0
                                    ? t('لا طلبات')
                                    : `${points[hover].count} ${t('طلب')}`}
                            </>
                        )}
                    </>
                ) : (
                    /* «مرّر المؤشر» لا معنى له على شاشة لمس، وهي شاشة المتجر.
                       ولا تُسمّى «شهرًا»: نفس المكوّن يرسم الساعات وأيام
                       الأسبوع في التحليلات */
                    <span className="text-[#c7c7c7]">{t('اضغط على الرسم لعرض التفاصيل')}</span>
                )}
            </p>
        </div>
    );
}
