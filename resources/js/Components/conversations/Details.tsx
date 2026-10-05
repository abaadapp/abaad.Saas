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
            <div className="flex min-h-[59px] shrink-0 items-center gap-2 border-b border-[var(--cv-border,#e9edef)] bg-[var(--cv-bar,#f0f2f5)] px-3 py-2">
                <Button
                    variant="ghost"
                    size="icon"
                    className={cn(
                        'xl:hidden',
                        'text-[var(--cv-icon,#54656f)] hover:bg-[var(--cv-icon-hover,rgba(11,20,26,0.06))] hover:text-[var(--cv-ink,#111b21)]',
                    )}
                    onClick={onBack}
                    aria-label={t('رجوع')}
                >
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
                                    'rounded-full px-3 py-1 text-[13px] transition-colors',
                                    tabs.value === tab.key
                                        ? 'bg-[var(--cv-chip-on,#e7fce3)] font-medium text-[var(--cv-chip-on-ink,#008069)]'
                                        : 'text-[var(--cv-chip-ink,#54656f)] hover:bg-[var(--cv-chip-hover,#e9edef)]',
                                )}
                            >
                                {tab.label}
                            </button>
                        ))}
                    </div>
                ) : (
                    <h2 className="flex-1 text-[16px] font-medium text-[var(--cv-ink,#111b21)]">{title}</h2>
                )}
            </div>

            {/* أقسامٌ بيضاءُ على رماديّ — كمعلومات جهة الاتّصال في واتساب */}
            <div className="min-h-0 flex-1 space-y-2.5 overflow-y-auto bg-[var(--cv-details,#f0f2f5)] py-2.5">{children}</div>
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
        <section className={cn('bg-[var(--cv-soft,#fff)] px-4 py-3.5 shadow-[0_1px_3px_rgba(11,20,26,0.08)]', className)}>
            {(title || action) && (
                <div className="mb-2 flex items-center justify-between gap-2">
                    {title && <h3 className="text-[14px] text-[var(--cv-muted,#667781)]">{title}</h3>}
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
            <dt className="flex shrink-0 items-center gap-1.5 text-[12px] text-[var(--cv-muted,#667781)]">
                {icon}
                {label}
            </dt>
            <dd
                className={cn('min-w-0 truncate text-end text-[12.5px]', missing ? 'text-[var(--cv-faint,#8696a0)]' : 'text-[var(--cv-ink,#111b21)]')}
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
            <span className="mb-1 block text-[11.5px] text-[var(--cv-muted,#667781)]">{label}</span>
            <select
                value={value}
                onChange={(e) => onChange(e.target.value)}
                aria-label={label}
                className="h-9 w-full rounded-[9px] border border-[var(--cv-field-border,#e9edef)] bg-[var(--cv-field,#fff)] px-2 text-[12.5px] text-[var(--cv-ink,#111b21)] outline-none focus:border-[var(--cv-accent,#00a884)]"
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
            <p className="mt-2.5 text-[15px] font-bold text-[var(--cv-ink,#111b21)]">{name}</p>
            {subtitle && <div className="mt-0.5 text-[12px] text-[var(--cv-muted,#667781)]">{subtitle}</div>}
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
            action={<span className="rounded-full bg-[var(--cv-accent-soft,#d9fdd3)] px-2 py-0.5 text-[11px] font-bold text-[var(--cv-accent-soft-ink,#008069)]">{files.length}</span>}
        >
            <ul className="space-y-1.5">
                {files.slice(0, limit).map((f) => (
                    <li key={f.id}>
                        <a
                            href={f.url}
                            target={f.image ? '_blank' : undefined}
                            rel={f.image ? 'noopener noreferrer' : undefined}
                            className="flex items-center gap-2.5 rounded-[10px] bg-[var(--cv-panel,#fff)] p-1.5 text-[12px] hover:bg-[var(--cv-hover,#f5f6f6)]"
                        >
                            {f.image ? (
                                <img src={f.url} alt="" className="size-9 shrink-0 rounded-[8px] object-cover" loading="lazy" />
                            ) : (
                                <span className="flex size-9 shrink-0 items-center justify-center rounded-[8px] bg-[#fef2f2] text-[#dc2626]">
                                    <FileText className="size-4" />
                                </span>
                            )}
                            <span className="min-w-0 flex-1">
                                <span className="block truncate font-medium text-[var(--cv-ink,#111b21)]" dir="auto">
                                    {f.name}
                                </span>
                                <span className="block text-[11px] text-[var(--cv-faint,#667781)]">{fileSize(f.size)}</span>
                            </span>
                        </a>
                    </li>
                ))}
            </ul>
        </DetailSection>
    );
}
