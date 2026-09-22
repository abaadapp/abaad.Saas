import { Crown } from 'lucide-react';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * العلامةُ الذهبيّة — زبونٌ يُعامَل نظامًا مستقلًّا داخل أبعاد.
 *
 * تُرسم حيث يُقرأ اسمُ المتجر: قائمةُ المنصّة وملفُّه وشريطُ لوحته. ولا
 * تُرسم لسواه: `tier` فارغٌ لكلّ متجرٍ عاديّ، والشارةُ تعود `null`.
 */
export default function GoldBadge({ tier, compact = false, className }: { tier?: string | null; compact?: boolean; className?: string }) {
    const t = useTranslate();

    if (tier !== 'gold') return null;

    return (
        <span
            data-testid="gold-badge"
            title={t('نظام مستقل داخل أبعاد — واجهته ومساره الخاصّان')}
            className={cn(
                'inline-flex shrink-0 items-center gap-1 rounded-full border border-[#f2d27a] bg-[#fff8e1] font-semibold text-[#8a6508]',
                compact ? 'px-1.5 py-0.5 text-[10.5px]' : 'px-2 py-0.5 text-[11.5px]',
                className,
            )}
        >
            <Crown className={compact ? 'size-3' : 'size-3.5'} />
            {t('ذهبي')}
        </span>
    );
}
