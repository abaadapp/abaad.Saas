import { motion } from 'framer-motion';
import { cn } from '@/lib/utils';

interface BarChartProps {
    labels: string[];
    series: number[];
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
    const max = Math.max(...series, 1);
    const total = series.reduce((sum, value) => sum + value, 0);

    if (series.length === 0) {
        return (
            <p className={cn('py-12 text-center text-sm text-[#9ca3af]', className)}>
                لا توجد بيانات بعد
            </p>
        );
    }

    return (
        <div className={cn('flex flex-col gap-3', className)}>
            {labels.map((label, i) => {
                const value = series[i] ?? 0;
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
