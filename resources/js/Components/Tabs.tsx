import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export interface TabItem {
    key: string;
    label: string;
}

interface Props {
    tabs: TabItem[];
    current: string;
    onChange: (key: string) => void;
    className?: string;
}

/**
 * تبويبات داخل الصفحة — بديل x-data="{ tab: … }" في القوالب.
 *
 * تُميَّز عن SectionTabs: تلك تنقل بين مسارات، وهذه تبدّل جزءًا من الصفحة
 * نفسها. المظهر واحد عمدًا فلا يشعر المستخدم بفارق بين النوعين.
 *
 * أزرار داخل role="tablist" لا روابط: لا وجهة تُفتح في تبويب جديد.
 */
export default function Tabs({ tabs, current, onChange, className }: Props) {
    const t = useTranslate();

    return (
        <div
            role="tablist"
            className={cn(
                'flex items-center gap-1 overflow-x-auto border-b border-[var(--ui-border,#e8e8e8)] px-4',
                className,
            )}
        >
            {tabs.map((tab) => {
                const active = tab.key === current;

                return (
                    <button
                        key={tab.key}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        onClick={() => onChange(tab.key)}
                        className={cn(
                            '-mb-px whitespace-nowrap border-b-2 px-4 py-3 text-sm font-medium transition-colors',
                            active
                                ? 'border-[#111] text-[#111]'
                                : 'border-transparent text-[#6b7280] hover:text-[#374151]',
                        )}
                    >
                        {t(tab.label)}
                    </button>
                );
            })}
        </div>
    );
}
