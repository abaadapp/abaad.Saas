import { useId, useMemo, useState } from 'react';
import { motion } from 'framer-motion';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

interface AreaChartProps {
    labels: string[];
    /** null = لم يأتِ بعد. صفرٌ يعني «لم يُبَع شيء» وهما ليسا واحدًا */
    data: (number | null)[];
    /** تسمية كاملة لكل نقطة تُقرأ في التلميح — «الأحد ١٠ أغسطس» مقابل «١٠» على المحور */
    fullLabels?: string[];
    /** عدد الطلبات في كل نقطة — المبلغ وحده لا يفرّق بين طلبٍ كبير وأربعين صغيرًا */
    counts?: (number | null)[];
    /** لتنسيق القيمة في التلميح والمحور */
    format?: (value: number) => string;
    className?: string;
    height?: number;
}

/** GUTTER: حصّة أرقام المحور الرأسي. EDGE: هامش الطرف الآخر */
const PAD = { top: 12, bottom: 26, gutter: 56, edge: 8 };

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

    /*
     * الزمن يجري باتجاه القراءة.
     *
     * كان المحور يبدأ من اليسار دائمًا: في صفحةٍ عربية تقع العين أوّلًا على
     * أقصى اليمين فتقرأ الشهر الأحدث، ثم تمضي يسارًا فتجد ما قبله — أغسطس
     * ثمّ يوليو ثمّ يونيو. فيبدو المحور مقلوبًا وهو مرتَّب، ويُقرأ صعودُ
     * المبيعات هبوطًا.
     *
     * فالبداية عند اليمين في RTL، وأرقام المحور الرأسي تنتقل إلى اليمين
     * معها — الصفر يسكن عند بداية القراءة لا عند نهايتها.
     */
    const rtl = typeof document !== 'undefined' && document.documentElement.dir === 'rtl';
    const padStart = rtl ? PAD.edge : PAD.gutter;

    const width = 720;
    const innerW = width - PAD.gutter - PAD.edge;
    const innerH = height - PAD.top - PAD.bottom;
    const plotStart = padStart;
    const plotEnd = padStart + innerW;

    const { points, ticks, slot } = useMemo(() => {
        const known = data.filter((v): v is number => v !== null);
        const niceMax = niceCeiling(Math.max(...known, 0), 3);
        const stepX = data.length > 1 ? innerW / (data.length - 1) : 0;

        return {
            max: niceMax,
            // عرض ما تشغله التسمية الواحدة: المسافة بين نقطتين. الأشهر
            // الاثنا عشر تُعرض كلّها، فلكلٍّ حصّتها ولا تتداخل مع جارتها
            slot: stepX || innerW,
            points: data.map((value, i) => ({
                x: rtl ? plotEnd - i * stepX : plotStart + i * stepX,
                // المستقبل بلا ارتفاع — لا يُرسم أصلًا، ولا يُسحب الخطّ إلى القاع
                y: value === null ? null : PAD.top + innerH - (value / niceMax) * innerH,
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
    }, [data, labels, fullLabels, counts, innerH, innerW, plotStart, plotEnd, rtl]);

    /*
     * خطٌّ واحد متّصل على المحور كلّه — بقرار المالك.
     *
     * ما لم يأتِ بعدُ يُرسم عند القاع كبقيّة الخطّ لا متقطّعًا. وقد نبّهتُ
     * أن الهبوط إلى الصفر في شهرٍ لم يبدأ يُقرأ توقّفَ بيع بعد شهرين، فاختار
     * الشكل المتّصل. فبقي التمييز حيث لا يُفسد الشكل: لمسُ العمود يقول «لم
     * يأتِ بعد» لا «٠ ر.ع»، وتسميته باهتة، ولا يدخل تصديرًا ولا ملفّ PDF.
     */
    const baseline = PAD.top + innerH;
    const line = points.map((p) => ({ x: p.x, y: p.y ?? baseline }));

    const linePath = line.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x},${p.y}`).join(' ');
    const areaPath = line.length
        ? `${linePath} L${line[line.length - 1].x},${baseline} L${line[0].x},${baseline} Z`
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
                        x1={plotStart}
                        y1={tick.y}
                        x2={plotEnd}
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
                        {/* النقطة على الخطّ حيثما وقع — والتلميح يقول أهي قياسٌ أم لا */}
                        <circle
                            cx={points[hover].x}
                            cy={points[hover].y ?? baseline}
                            r="5"
                            fill={points[hover].y === null ? '#c4b5fd' : '#7c3aed'}
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
                        /* الحصّة تتبع جهة البداية: يمينًا في العربية */
                        x={rtl ? plotEnd + 8 : 0}
                        y={tick.y - 7}
                        width={PAD.gutter - 8}
                        height={14}
                        className="pointer-events-none"
                    >
                        <div
                            dir="ltr"
                            className={cn(
                                'truncate text-[10px] leading-[14px] text-[#9ca3af]',
                                rtl ? 'text-start' : 'text-end',
                            )}
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
                {points.map((p, i) => {
                    /*
                     * التسمية تُحصر داخل الإطار، ونصّها يُزاح إلى الطرف الذي
                     * أُزيحت إليه.
                     *
                     * حصّة أوّل نقطةٍ وآخرها تمتدّ نصفَ حصّةٍ خارج الإطار،
                     * فكان نصف الكلمة يُقصّ: «أغسطس» تُقرأ «سطس». وبإزاحة
                     * الحصّة إلى الداخل وحدها تركب التسمية جارتَها — «يوليو
                     * ٢٦ أغسطس ٢٦» كلمتين فوق بعضهما. فالحصّة تُزاح والنصّ
                     * يُحاذي طرفَها، فيبقى كلٌّ في مكانه ولا يُقصّ ولا يركب.
                     */
                    const raw = p.x - slot / 2;
                    const x = Math.min(Math.max(raw, 0), width - slot);
                    const align = x > raw ? 'text-start' : x < raw ? 'text-end' : 'text-center';

                    return (
                        <foreignObject
                            key={`l${i}`}
                            x={x}
                            y={height - 18}
                            width={slot}
                            height={14}
                            className="pointer-events-none"
                        >
                            <div
                                dir="ltr"
                                className={cn(
                                    'truncate text-[9px] leading-[14px]',
                                    align,
                                    hover === i
                                        ? 'font-bold text-[#7c3aed]'
                                        /* ما لم يأتِ بعدُ باهتٌ على المحور: موجودٌ ليُقرأ
                                           الموضع، لا ليُقرأ على أنه قياس */
                                        : p.value === null
                                          ? 'text-[#d8d8d8]'
                                          : 'text-[#9ca3af]',
                                )}
                            >
                                {p.label}
                            </div>
                        </foreignObject>
                    );
                })}
            </svg>

            {/* التلميح كنص أسفل الرسم — يتجنّب مشاكل تموضع RTL */}
            <p className="mt-1 h-5 text-center text-[12px] text-[#6b7280]">
                {hover !== null && points[hover] ? (
                    <>
                        <span className="font-medium text-[#111]">{points[hover].full}</span>
                        {' · '}
                        {points[hover].value === null ? (
                            /* «٠ ر.ع» عن يومٍ لم يأتِ خبرٌ كاذب عن الغد */
                            <span className="text-[#c7c7c7]">{t('لم يأتِ بعد')}</span>
                        ) : (
                            <>
                                {format(points[hover].value as number)}
                                {/* عددُ الطلبات يفصل بيعةً كبيرة عن يومٍ مزدحم — والمبلغ وحده لا يفعل */}
                                {points[hover].count != null && (
                                    <>
                                        {' · '}
                                        {points[hover].count === 0
                                            ? t('لا طلبات')
                                            : `${points[hover].count} ${t('طلب')}`}
                                    </>
                                )}
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
