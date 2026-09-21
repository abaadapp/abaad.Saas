import type { ReactNode } from 'react';
import { ArrowLeft, FileText } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { fileSize } from '@/lib/format';
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
                                        ? 'bg-[#eef4ff] text-[#1d4ed8]'
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
                className="h-9 w-full rounded-[9px] border border-[var(--ui-border,#e8e8e8)] bg-white px-2 text-[12.5px] outline-none focus:border-[#2563eb]"
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

/** هويّةُ الطرف في أعلى اللوحة — وجهٌ كبيرٌ في الوسط واسمٌ وسطرٌ تحته */
export function DetailIdentity({
    avatar,
    name,
    subtitle,
    children,
}: {
    avatar: ReactNode;
    name: string;
    subtitle?: ReactNode;
    children?: ReactNode;
}) {
    return (
        <div className="flex flex-col items-center py-3 text-center">
            {avatar}
            <p className="mt-2.5 text-[15px] font-bold text-[#111]">{name}</p>
            {subtitle && <div className="mt-0.5 text-[12px] text-[#71717a]">{subtitle}</div>}
            {children && <div className="mt-2 flex flex-wrap justify-center gap-1.5">{children}</div>}
        </div>
    );
}

/** قائمةُ ملفّات المحادثة — ما مرّ في الخيط من مرفقات، وعدُّه في الرأس */
export function DetailFiles({
    title,
    files,
    limit = 6,
}: {
    title: string;
    files: { id: number; name: string; size: number; image: boolean; url: string }[];
    limit?: number;
}) {
    if (files.length === 0) return null;

    return (
        <DetailSection
            title={title}
            action={<span className="rounded-full bg-[#eef4ff] px-2 py-0.5 text-[11px] font-bold text-[#1d4ed8]">{files.length}</span>}
        >
            <ul className="space-y-1.5">
                {files.slice(0, limit).map((f) => (
                    <li key={f.id}>
                        <a
                            href={f.url}
                            target={f.image ? '_blank' : undefined}
                            rel={f.image ? 'noopener noreferrer' : undefined}
                            className="flex items-center gap-2.5 rounded-[10px] bg-white p-1.5 text-[12px] hover:bg-[#f7f8fb]"
                        >
                            {f.image ? (
                                <img src={f.url} alt="" className="size-9 shrink-0 rounded-[8px] object-cover" loading="lazy" />
                            ) : (
                                <span className="flex size-9 shrink-0 items-center justify-center rounded-[8px] bg-[#fef2f2] text-[#dc2626]">
                                    <FileText className="size-4" />
                                </span>
                            )}
                            <span className="min-w-0 flex-1">
                                <span className="block truncate font-medium text-[#111]" dir="auto">
                                    {f.name}
                                </span>
                                <span className="block text-[11px] text-[#9ca3af]">{fileSize(f.size)}</span>
                            </span>
                        </a>
                    </li>
                ))}
            </ul>
        </DetailSection>
    );
}
