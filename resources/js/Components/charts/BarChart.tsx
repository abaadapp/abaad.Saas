import { motion } from 'framer-motion';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

interface BarChartProps {
    labels: string[];
    /**
     * `null` = لم يأتِ بعد، لا صفر.
     *
     * «نمو الشركات» يُرسم بهذا المكوّن شهرًا بشهر، وأكتوبر في سبتمبر لم
     * يُقَس ليكون صفرًا. فيُكتب اسمُه باهتًا و«لم يأتِ بعد» بلا شريطٍ ولا
     * نسبة، ولا يدخل المجموعَ الذي تُقسم عليه النِّسَب.
     */
    series: (number | null)[];
    format?: (value: number) => string;
    className?: string;
}

/**
 * لوحة الفئات — ترتيب ثابت لا يُدوَّر، واللون يتبع الفئة لا ترتيبها.
 * مُتحقَّق منها بـvalidate_palette.js: كل الفحوص تمر.
 */
const CATEGORICAL = ['#7c3aed', '#059669', '#2563eb', '#d97706', '#ec4899', '#0891b2'];

/**
 * مقارنة مقادير عبر فئات — أشرطة أفقية لأن أسماء طرق الدفع نصّية بطول متفاوت.
 * كل شريط يحمل تسمية مباشرة: ترميز ثانوي يغني عن الاعتماد على اللون وحده
 * (زوج الأزرق/الأخضر متقارب في عمى اللون الثلاثي).
 */
export default function BarChart({ labels, series, format = String, className }: BarChartProps) {
    const t = useTranslate();
    const known = series.filter((v): v is number => v !== null);
    const max = Math.max(...known, 1);
    const total = known.reduce((sum, value) => sum + value, 0);

    if (series.length === 0) {
        return (
            <p className={cn('py-12 text-center text-sm text-[#9ca3af]', className)}>
                {t('لا توجد بيانات بعد')}
            </p>
        );
    }

    return (
        <div className={cn('flex flex-col gap-3', className)}>
            {labels.map((label, i) => {
                const raw = series[i];

                if (raw === null) {
                    return (
                        <div key={label} className="group" data-future="true">
                            <div className="mb-1.5 flex items-baseline justify-between gap-3">
                                <span className="flex items-center gap-2 text-[13px] text-[#c4c4c4]">
                                    <span className="size-2.5 shrink-0 rounded-full bg-[#e5e7eb]" />
                                    {label}
                                </span>
                                <span className="text-[12px] text-[#c7c7c7]">{t('لم يأتِ بعد')}</span>
                            </div>
                            <div className="h-2 w-full rounded-full bg-[#f7f7f5]" />
                        </div>
                    );
                }

                const value = raw ?? 0;
                const share = total > 0 ? (value / total) * 100 : 0;

                return (
                    <div key={label} className="group">
                        <div className="mb-1.5 flex items-baseline justify-between gap-3">
                            <span className="flex items-center gap-2 text-[13px] text-[#111]">
                                {/* علامة اللون بجانب النص — الهوية ليست باللون وحده */}
                                <span
                                    className="size-2.5 shrink-0 rounded-full"
                                    style={{ backgroundColor: CATEGORICAL[i % CATEGORICAL.length] }}
                                />
                                {label}
                            </span>
                            <span className="text-[12px] tabular-nums text-[#6b7280]">
                                {format(value)}
                                <span className="ms-1.5 text-[#9ca3af]">({share.toFixed(0)}%)</span>
                            </span>
                        </div>

                        <div className="h-2 w-full overflow-hidden rounded-full bg-[#f2f2f0]">
                            <motion.div
                                initial={{ width: 0 }}
                                animate={{ width: `${(value / max) * 100}%` }}
                                transition={{ duration: 0.6, delay: i * 0.06, ease: [0.22, 1, 0.36, 1] }}
                                className="h-full rounded-full"
                                style={{ backgroundColor: CATEGORICAL[i % CATEGORICAL.length] }}
                            />
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
