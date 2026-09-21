import type { ReactNode } from 'react';
import { ArrowLeft } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * العمودُ الثالث: تفاصيلُ ما هو مفتوح — أقسامٌ مضغوطةٌ تتمرّر وحدَها.
 *
 * عرضٌ لا بيانات: الدعمُ يضع فيه المتجرَ والحالةَ والمسؤول، والمبيعاتُ
 * تضع العميلَ والمرحلةَ والإشارات. ولا يُفرض على أحدهما حقلُ الآخر.
 */
export function ConversationDetailsPanel({
    title,
    tabs,
    onBack,
    children,
}: {
    title: string;
    tabs?: { items: { key: string; label: string }[]; value: string; onChange: (key: string) => void };
    onBack: () => void;
    children: ReactNode;
}) {
    const t = useTranslate();

    return (
        <>
            <div className="flex shrink-0 items-center gap-2 border-b border-[var(--ui-border,#e8e8e8)] px-3 py-2">
                <Button variant="ghost" size="icon" className="xl:hidden" onClick={onBack} aria-label={t('رجوع')}>
                    <ArrowLeft className="rtl:rotate-180" />
                </Button>

                {tabs ? (
                    <div role="tablist" aria-label={title} className="flex flex-1 gap-1">
                        {tabs.items.map((tab) => (
                            <button
                                key={tab.key}
                                type="button"
                                role="tab"
                                aria-selected={tabs.value === tab.key}
                                onClick={() => tabs.onChange(tab.key)}
                                className={cn(
                                    'rounded-[8px] px-2.5 py-1.5 text-[13px] font-medium transition-colors',
                                    tabs.value === tab.key
                                        ? 'bg-[#e9f6ef] text-[#047857]'
                                        : 'text-[#71717a] hover:bg-[#fafaf9] hover:text-[#111]',
                                )}
                            >
                                {tab.label}
                            </button>
                        ))}
                    </div>
                ) : (
                    <h2 className="flex-1 text-[14px] font-bold text-[#111]">{title}</h2>
                )}
            </div>

            <div className="min-h-0 flex-1 space-y-3 overflow-y-auto p-3">{children}</div>
        </>
    );
}

/** قسمٌ معنون داخل اللوحة */
export function DetailSection({
    title,
    action,
    children,
    className,
}: {
    title?: string;
    action?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <section className={cn('rounded-[12px] border border-[#f0f0ef] bg-[#fcfcfb] p-3', className)}>
            {(title || action) && (
                <div className="mb-2 flex items-center justify-between gap-2">
                    {title && <h3 className="text-[12.5px] font-bold text-[#111]">{title}</h3>}
                    {action}
                </div>
            )}
            {children}
        </section>
    );
}

/** سطرُ معلومة — والمجهولُ يُقال ولا يُخترع */
export function DetailLine({
    label,
    value,
    ltr,
    icon,
    empty,
}: {
    label: string;
    value: ReactNode;
    ltr?: boolean;
    icon?: ReactNode;
    /** ما يُكتب حين لا قيمة — وإن غاب أُخفي السطرُ كلُّه */
    empty?: string;
}) {
    if ((value === null || value === undefined || value === '') && !empty) return null;

    const missing = value === null || value === undefined || value === '';

    return (
        <div className="flex items-start justify-between gap-3 py-1">
            <dt className="flex shrink-0 items-center gap-1.5 text-[12px] text-[#71717a]">
                {icon}
                {label}
            </dt>
            <dd
                className={cn('min-w-0 truncate text-end text-[12.5px]', missing ? 'text-[#a1a1aa]' : 'text-[#111]')}
                dir={ltr && !missing ? 'ltr' : undefined}
            >
                {missing ? empty : value}
            </dd>
        </div>
    );
}

/** حقلُ اختيارٍ داخل التفاصيل — نفسُ الشكل للحالة والمسؤول والأولويّة */
export function DetailSelect({
    label,
    value,
    onChange,
    options,
}: {
    label: string;
    value: string;
    onChange: (v: string) => void;
    options: { value: string; label: string }[];
}) {
    return (
        <label className="block">
            <span className="mb-1 block text-[11.5px] text-[#71717a]">{label}</span>
            <select
                value={value}
                onChange={(e) => onChange(e.target.value)}
                aria-label={label}
                className="h-9 w-full rounded-[9px] border border-[var(--ui-border,#e8e8e8)] bg-white px-2 text-[12.5px] outline-none focus:border-[#059669]"
            >
                {options.map((o) => (
                    <option key={o.value} value={o.value}>
                        {o.label}
                    </option>
                ))}
            </select>
        </label>
    );
}
