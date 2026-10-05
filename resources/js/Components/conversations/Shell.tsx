import type { ReactNode } from 'react';
import { Inbox, Search } from 'lucide-react';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * قشرةُ المحادثات — ثلاثةُ أعمدةٍ على الحاسوب، وواحدٌ على الهاتف.
 *
 * ═══ عرضٌ لا بيانات ═══
 *
 * هذه المكوّناتُ ترسم ولا تعرف: لا تعرف أهي محادثةُ دعمٍ بين أبعاد وصاحب
 * متجر، أم محادثةُ مبيعاتٍ مع عميلٍ محتمَل. الصفحتان نظامان مختلفان —
 * جدولان ومتحكّمان ومساران وعدّادان — يتشاركان **لغةَ الشكل** وحدَها.
 * فلا منطقَ هنا يخصّ أحدَهما، ولا استعلامَ، ولا مسارَ تنزيل.
 *
 * ═══ الأعمدة ═══
 *
 * `pane` يقول أيَّها يُعرض حين لا تتّسع الشاشة للثلاثة:
 * - xl فأعلى: القائمةُ والخيطُ والتفاصيلُ معًا.
 * - lg: القائمةُ ثابتة، وبجانبها الخيطُ أو التفاصيل.
 * - دونها: عمودٌ واحدٌ في كلّ مرّة، ورجوعٌ واضحٌ بينها.
 *
 * ═══ والشكلُ شكلُ واتساب ويب ═══
 *
 * لوحٌ واحدٌ لا بطاقاتٌ متباعدة: القائمةُ والخيطُ والتفاصيلُ أعمدةٌ يفصلها
 * خطّ. ورؤوسُ الأعمدة شريطٌ رماديّ، والخيطُ على خلفيّته المنقوشة، والفقاعةُ
 * بذيلها ووقتِها وعلامتَي التسليم داخلها (انظر Thread.tsx). والألوانُ ألوانُ
 * واتساب ويب الفاتح بديلًا لكلّ `--cv-*` — وصفحةٌ تريد الداكن تُعرّفها.
 *
 * ═══ الارتفاع ═══
 *
 * الشاشةُ تُملأ ولا تُمرَّر: القائمةُ تتمرّر وحدَها، والرسائلُ وحدَها،
 * والتفاصيلُ وحدَها، والمُحرِّرُ في القاع لا يُبحث عنه. والحسابُ يطرح
 * ما فوق المحتوى: شريطَ الانتحال إن وُجد (`--chrome-top`)، والترويسةَ،
 * وهوامشَ الحاوية، ورأسَ الصفحة.
 */
export type Pane = 'list' | 'thread' | 'details';

/**
 * الصفحةُ كلُّها عمودٌ بارتفاع الشاشة: رأسُها وما فوق القشرة من تنبيهاتٍ
 * يأخذ حجمَه، والقشرةُ تأخذ الباقي. فلا يُحسب ارتفاعُ الرأس ولا ارتفاعُ
 * تنبيهٍ قد يظهر أو يغيب — يُطرح ما هو ثابتٌ وحدَه: الترويسةُ (4rem)
 * وهوامشُ الحاوية (2rem على الهاتف، 3rem من lg).
 */
export function ConversationPage({ children }: { children: ReactNode }) {
    return (
        <div className="flex min-h-[520px] flex-col h-[calc(100dvh-var(--chrome-top,0px)-6rem)] lg:h-[calc(100dvh-var(--chrome-top,0px)-7rem)]">
            {children}
        </div>
    );
}

export function ConversationShell({
    pane,
    list,
    thread,
    details,
}: {
    pane: Pane;
    list: ReactNode;
    thread: ReactNode;
    details: ReactNode;
}) {
    return (
        <div
            className="grid min-h-0 flex-1 grid-cols-1 overflow-hidden rounded-[6px] border border-[var(--cv-border,#e9edef)] bg-[var(--cv-panel,#fff)] shadow-[0_1px_3px_rgba(11,20,26,0.08)] lg:grid-cols-[360px_minmax(0,1fr)] xl:grid-cols-[380px_minmax(0,1fr)_340px]"
        >
            <section
                className={cn(
                    'flex min-h-0 min-w-0 flex-col overflow-hidden bg-[var(--cv-panel,#fff)] lg:border-e lg:border-[var(--cv-border,#e9edef)]',
                    pane !== 'list' && 'hidden lg:flex',
                )}
            >
                {list}
            </section>

            <section
                className={cn(
                    'flex min-h-0 min-w-0 flex-col overflow-hidden bg-[var(--cv-thread,#efeae2)]',
                    pane === 'details' ? 'hidden xl:flex' : pane === 'list' ? 'hidden lg:flex' : 'flex',
                )}
            >
                {thread}
            </section>

            <section
                className={cn(
                    'flex min-h-0 min-w-0 flex-col overflow-hidden bg-[var(--cv-details,#f0f2f5)] xl:border-s xl:border-[var(--cv-border,#e9edef)]',
                    pane === 'details' ? 'flex' : 'hidden xl:flex',
                )}
            >
                {details}
            </section>
        </div>
    );
}

/**
 * رأسُ الصفحة: سطرٌ واحدٌ مضغوط — العنوانُ وحبّةٌ تقول أين أنت والوصفُ
 * بجانبهما.
 *
 * كان سطرين بحجم رأسِ صفحةٍ عاديّة، فأكل ما يقارب ستّين بكسلًا من ارتفاعٍ
 * تحتاجه الرسائل. والمحادثاتُ شاشةُ تطبيقٍ لا صفحةُ تقرير: كلُّ بكسلٍ فوق
 * الأعمدة يُؤخذ من الخيط.
 */
export function ConversationPageHeader({
    title,
    subtitle,
    context,
    tone = 'green',
    children,
}: {
    title: string;
    subtitle: string;
    context: string;
    tone?: 'green' | 'violet';
    children?: ReactNode;
}) {
    return (
        <div className="mb-2 flex shrink-0 flex-wrap items-center gap-x-2 gap-y-0.5">
            <h1 className="text-[17px] font-bold leading-tight text-[#111]">{title}</h1>
            <span
                className={cn(
                    'rounded-full px-2 py-0.5 text-[11px] font-bold leading-tight',
                    tone === 'green' ? 'bg-[#e8efff] text-[#1d4ed8]' : 'bg-[#f5f3ff] text-[#6d28d9]',
                )}
            >
                {context}
            </span>
            <p className="text-[12px] text-[#71717a]">{subtitle}</p>
            {children && <div className="ms-auto">{children}</div>}
        </div>
    );
}

/** العمودُ الأوّل: رأسٌ ثابت (بحثٌ ومرشِّحات) وقائمةٌ تتمرّر وذيلٌ للصفحات */
export function ConversationList({
    header,
    footer,
    children,
}: {
    header: ReactNode;
    footer?: ReactNode;
    children: ReactNode;
}) {
    return (
        <>
            <div className="shrink-0 bg-[var(--cv-panel,#fff)] px-3 pb-2 pt-3">{header}</div>
            <ul className="min-h-0 flex-1 overflow-y-auto">{children}</ul>
            {footer && <div className="shrink-0 border-t border-[var(--cv-border,#e9edef)] bg-[var(--cv-bar,#f0f2f5)] p-2">{footer}</div>}
        </>
    );
}

export function ConversationSearch({
    value,
    onChange,
    onSubmit,
    onBlur,
    placeholder,
    label,
}: {
    value: string;
    onChange: (v: string) => void;
    onSubmit: () => void;
    onBlur?: () => void;
    placeholder: string;
    label: string;
}) {
    return (
        <div className="relative">
            <Search className="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-[var(--cv-icon,#54656f)]" />
            <Input
                value={value}
                onChange={(e) => onChange(e.target.value)}
                onKeyDown={(e) => e.key === 'Enter' && onSubmit()}
                onBlur={onBlur}
                placeholder={placeholder}
                className="h-9 rounded-[8px] border-transparent bg-[var(--cv-search,#f0f2f5)] ps-10 text-[14px] text-[var(--cv-ink,#111b21)] shadow-none placeholder:text-[var(--cv-faint,#667781)] focus-visible:border-transparent focus-visible:ring-0"
                aria-label={label}
            />
        </div>
    );
}

/** حبّاتُ الترشيح — كلٌّ بعدّه، والمختارةُ داكنة */
export function FilterChips({
    items,
    value,
    onChange,
    label,
    size = 'md',
}: {
    items: { key: string; label: string; n?: number }[];
    value: string;
    onChange: (key: string) => void;
    label: string;
    size?: 'md' | 'sm';
}) {
    return (
        <div role="group" aria-label={label} className="flex flex-wrap gap-1.5">
            {items.map((item) => {
                const on = value === item.key;

                return (
                    <button
                        key={item.key}
                        type="button"
                        onClick={() => onChange(item.key)}
                        aria-pressed={on}
                        className={cn(
                            'rounded-full transition-colors',
                            size === 'md' ? 'px-3 py-1 text-[13px]' : 'px-2.5 py-0.5 text-[12px]',
                            on
                                ? 'bg-[var(--cv-chip-on,#e7fce3)] font-medium text-[var(--cv-chip-on-ink,#008069)]'
                                : 'bg-[var(--cv-chip,#f0f2f5)] text-[var(--cv-chip-ink,#54656f)] hover:bg-[var(--cv-chip-hover,#e9edef)]',
                        )}
                    >
                        {item.label}
                        {item.n !== undefined && item.n > 0 && (
                            <span
                                className={cn(
                                    'ms-1.5 tabular-nums',
                                    on ? 'text-[var(--cv-chip-on-ink,#008069)]' : 'text-[var(--cv-faint,#667781)]',
                                )}
                            >
                                {item.n}
                            </span>
                        )}
                    </button>
                );
            })}
        </div>
    );
}

/** وجهٌ حين يوجد، وأحرفٌ أولى حين لا — ولا وجهَ يُختلق */
export function Avatar({ name, src, size = 'md' }: { name: string; src?: string | null; size?: 'sm' | 'md' | 'lg' | 'xl' }) {
    const initials = name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0])
        .join('');

    return (
        <span
            className={cn(
                'flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-[var(--cv-avatar,#dfe5e7)] font-semibold text-[var(--cv-avatar-ink,#54656f)]',
                size === 'sm'
                    ? 'size-10 text-[13px]'
                    : size === 'md'
                      ? 'size-[49px] text-[15px]'
                      : size === 'lg'
                        ? 'size-16 text-[18px]'
                        : 'size-[120px] text-[34px]',
            )}
            aria-hidden="true"
        >
            {src ? <img src={src} alt="" className="size-full object-cover" /> : initials}
        </span>
    );
}

/**
 * صفٌّ في القائمة: وجهٌ، واسمٌ، ومعاينةٌ، ووقتٌ، وما لم يُقرأ.
 *
 * وما يحمله كلُّ نظامٍ من حالٍ وأولويّةٍ ومرحلةٍ يمرّ في `badges` — القائمةُ
 * لا تعرف معناه.
 */
export function ConversationListItem({
    title,
    subtitle,
    preview,
    time,
    unread,
    avatar,
    badges,
    active,
    onClick,
}: {
    title: string;
    subtitle?: string | null;
    preview?: string | null;
    time?: string | null;
    unread: number;
    avatar: ReactNode;
    badges?: ReactNode;
    active: boolean;
    onClick: () => void;
}) {
    return (
        <li>
            <button
                type="button"
                onClick={onClick}
                aria-current={active ? 'true' : undefined}
                className={cn(
                    'flex w-full items-center gap-3 ps-3 text-start transition-colors hover:bg-[var(--cv-hover,#f5f6f6)]',
                    active && 'bg-[var(--cv-sel,#f0f2f5)] hover:bg-[var(--cv-sel,#f0f2f5)]',
                )}
            >
                {avatar}

                {/* والخطُّ الفاصلُ يبدأ بعد الوجه — كما في واتساب */}
                <span className="min-w-0 flex-1 border-b border-[var(--cv-divide,#e9edef)] py-3 pe-4">
                    <span className="flex items-baseline gap-2">
                        <span className={cn('truncate text-[16px] text-[var(--cv-ink,#111b21)]', unread > 0 ? 'font-semibold' : 'font-normal')}>
                            {title}
                        </span>
                        {time && (
                            <span
                                className={cn(
                                    'ms-auto shrink-0 text-[12px]',
                                    unread > 0 ? 'font-medium text-[var(--cv-unread,#25d366)]' : 'text-[var(--cv-faint,#667781)]',
                                )}
                            >
                                {time}
                            </span>
                        )}
                    </span>

                    {subtitle && <span className="mt-px block truncate text-[12.5px] text-[var(--cv-muted,#667781)]">{subtitle}</span>}

                    <span className="mt-0.5 flex items-center gap-2">
                        <span
                            className={cn(
                                'min-w-0 flex-1 truncate text-[14px]',
                                unread > 0 ? 'text-[var(--cv-ink,#111b21)]' : 'text-[var(--cv-muted,#667781)]',
                            )}
                        >
                            {preview || '—'}
                        </span>
                        {unread > 0 && (
                            <span
                                className="flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-[var(--cv-unread,#25d366)] px-1.5 text-[11.5px] font-semibold tabular-nums text-[var(--cv-unread-ink,#fff)]"
                                aria-label={String(unread)}
                            >
                                {unread}
                            </span>
                        )}
                    </span>

                    {badges && <span className="mt-1 flex flex-wrap items-center gap-1.5">{badges}</span>}
                </span>
            </button>
        </li>
    );
}

export function ConversationEmptyState({ text, icon }: { text: string; icon?: ReactNode }) {
    return (
        <div className="flex flex-1 flex-col items-center justify-center bg-[var(--cv-empty,#f0f2f5)] p-10 text-center">
            <span className="flex size-16 items-center justify-center rounded-full bg-[var(--cv-panel,#fff)] text-[var(--cv-icon,#54656f)] shadow-[0_1px_2px_rgba(11,20,26,0.08)]">
                {icon ?? <Inbox className="size-7" />}
            </span>
            <p className="mt-4 max-w-sm text-[14px] leading-relaxed text-[var(--cv-muted,#667781)]">{text}</p>
        </div>
    );
}

/** حبّةُ حالٍ صغيرة — النصُّ فيها دائمًا، واللونُ زيادةٌ لا بديل */
export function Pill({ children, className }: { children: ReactNode; className?: string }) {
    return (
        <span className={cn('inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10.5px] font-medium', className)}>
            {children}
        </span>
    );
}

/** روابطُ الصفحات كما يبنيها Laravel — أزرارٌ صغيرة في ذيل القائمة */
export function PageLinks({
    links,
    onGo,
}: {
    links: { url: string | null; label: string; active: boolean }[];
    onGo: (url: string) => void;
}) {
    const t = useTranslate();

    if (links.length <= 3) return null;

    return (
        <nav aria-label={t('الصفحات')} className="flex flex-wrap items-center justify-center gap-1">
            {links.map((l, i) => (
                <button
                    key={i}
                    type="button"
                    disabled={!l.url}
                    onClick={() => l.url && onGo(l.url)}
                    className={cn(
                        'min-w-7 rounded-[6px] px-2 py-1 text-[12px]',
                        l.active
                            ? 'bg-[var(--cv-accent,#00a884)] text-[var(--cv-accent-ink,#fff)]'
                            : 'text-[var(--cv-chip-ink,#54656f)] hover:bg-[var(--cv-hover,#f5f6f6)]',
                        !l.url && 'cursor-not-allowed opacity-40',
                    )}
                    dangerouslySetInnerHTML={{ __html: l.label }}
                />
            ))}
        </nav>
    );
}
