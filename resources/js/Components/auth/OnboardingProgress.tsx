import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

interface Props {
    /** رقمُ الخطوة الحاليّة، من صفر */
    current: number;
    /** عددُ الخطوات — من القائمة نفسِها لا رقمًا مكتوبًا، انظر أدناه */
    total: number;
    className?: string;
}

/**
 * شريطُ التقدّم — خطوطٌ قصيرة، لا نسبةٌ مئويّة ولا دائرةٌ تدور.
 *
 * ═══ والعددُ من الخطوات لا مكتوبًا ═══
 *
 * `total` يأتي من طول قائمة الخطوات التي يرسمها المعالج. ورقمٌ مكتوبٌ هنا
 * («٦») يفترق عنها عند أوّل خطوةٍ تُضاف أو تُخطّى، فيقف الشريطُ على خمسةٍ
 * من ستّة وقد انتهى المستخدم.
 *
 * ═══ والحالُ ثلاثة، ولا يحملها اللونُ وحده ═══
 *
 * المنقضيةُ داكنةٌ كاملة، والحاليّةُ داكنةٌ **وأعرض**، والقادمةُ فاتحة.
 * فالعرضُ يقول أين نحن حتّى لمن لا يفرّق الدرجات. و`aria` تقول العددَ
 * نصًّا لمن يقرأ بالصوت — وخطوطٌ ملوّنةٌ لا تُقرأ.
 */
export default function OnboardingProgress({ current, total, className }: Props) {
    const t = useTranslate();

    return (
        <div
            role="progressbar"
            aria-valuemin={1}
            aria-valuemax={total}
            aria-valuenow={current + 1}
            aria-label={t('خطوة :n من :total', { n: String(current + 1), total: String(total) })}
            className={cn('flex items-center gap-1.5', className)}
        >
            {Array.from({ length: total }).map((_, i) => (
                <span
                    key={i}
                    className={cn(
                        'h-1.5 rounded-full transition-all duration-300',
                        i === current ? 'w-7 bg-[#111]' : i < current ? 'w-4 bg-[#111]/55' : 'w-4 bg-[#111]/12',
                    )}
                />
            ))}
        </div>
    );
}
