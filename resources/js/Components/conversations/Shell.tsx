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
            className="grid min-h-0 flex-1 grid-cols-1 gap-3 lg:grid-cols-[320px_minmax(0,1fr)] xl:grid-cols-[340px_minmax(0,1fr)_320px]"
        >
            <section
                className={cn(
                    'flex min-h-0 min-w-0 flex-col overflow-hidden rounded-[16px] border border-[var(--ui-border,#e8e8e8)] bg-white',
                    pane !== 'list' && 'hidden lg:flex',
                )}
            >
                {list}
            </section>

            <section
                className={cn(
                    'flex min-h-0 min-w-0 flex-col overflow-hidden rounded-[16px] border border-[var(--ui-border,#e8e8e8)] bg-white',
                    pane === 'details' ? 'hidden xl:flex' : pane === 'list' ? 'hidden lg:flex' : 'flex',
                )}
            >
                {thread}
            </section>

            <section
                className={cn(
                    'flex min-h-0 min-w-0 flex-col overflow-hidden rounded-[16px] border border-[var(--ui-border,#e8e8e8)] bg-white',
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
                    tone === 'green' ? 'bg-[#e6f6ee] text-[#047857]' : 'bg-[#f5f3ff] text-[#6d28d9]',
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
            <div className="shrink-0 border-b border-[var(--ui-border,#e8e8e8)] p-3">{header}</div>
            <ul className="min-h-0 flex-1 divide-y divide-[#f1f1f0] overflow-y-auto">{children}</ul>
            {footer && <div className="shrink-0 border-t border-[var(--ui-border,#e8e8e8)] p-2">{footer}</div>}
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
            <Search className="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-[#9ca3af]" />
            <Input
                value={value}
                onChange={(e) => onChange(e.target.value)}
                onKeyDown={(e) => e.key === 'Enter' && onSubmit()}
                onBlur={onBlur}
                placeholder={placeholder}
                className="h-9 rounded-[10px] bg-[#f7f7f5] ps-9 text-[13px]"
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
                            'rounded-full font-medium transition-colors',
                            size === 'md' ? 'px-2.5 py-1 text-[12px]' : 'px-2 py-0.5 text-[11px]',
                            on ? 'bg-[#111] text-white' : 'bg-[#f2f2f0] text-[#4b4b4b] hover:bg-[#e9e9e6]',
                        )}
                    >
                        {item.label}
                        {item.n !== undefined && item.n > 0 && (
                            <span
                                className={cn(
                                    'ms-1.5 rounded-full px-1.5 tabular-nums',
                                    on ? 'bg-white/20' : 'bg-white text-[#71717a]',
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
export function Avatar({ name, src, size = 'md' }: { name: string; src?: string | null; size?: 'sm' | 'md' | 'lg' }) {
    const initials = name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0])
        .join('');

    return (
        <span
            className={cn(
                'flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-[#e6f6ee] font-bold text-[#047857]',
                size === 'sm' ? 'size-9 text-[11px]' : size === 'md' ? 'size-11 text-[12px]' : 'size-14 text-[15px]',
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
                    'flex w-full gap-3 px-3 py-2.5 text-start transition-colors hover:bg-[#fafaf9]',
                    active && 'bg-[#e9f6ef] shadow-[inset_3px_0_0_#059669] hover:bg-[#e9f6ef] rtl:shadow-[inset_-3px_0_0_#059669]',
                )}
            >
                {avatar}

                <span className="min-w-0 flex-1">
                    <span className="flex items-baseline gap-2">
                        <span className={cn('truncate text-[14px] text-[#111]', unread > 0 ? 'font-bold' : 'font-semibold')}>
                            {title}
                        </span>
                        {time && <span className="ms-auto shrink-0 text-[11px] text-[#9ca3af]">{time}</span>}
                    </span>

                    {subtitle && <span className="mt-px block truncate text-[12px] text-[#71717a]">{subtitle}</span>}

                    <span className="mt-0.5 flex items-center gap-2">
                        <span
                            className={cn(
                                'min-w-0 flex-1 truncate text-[12.5px]',
                                unread > 0 ? 'font-medium text-[#111]' : 'text-[#71717a]',
                            )}
                        >
                            {preview || '—'}
                        </span>
                        {unread > 0 && (
                            <span
                                className="flex h-[18px] min-w-[18px] shrink-0 items-center justify-center rounded-full bg-[#059669] px-1 text-[10px] font-bold tabular-nums text-white"
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
        <div className="flex flex-1 flex-col items-center justify-center p-10 text-center">
            <span className="flex size-14 items-center justify-center rounded-full bg-[#f4f4f5] text-[#a1a1aa]">
                {icon ?? <Inbox className="size-6" />}
            </span>
            <p className="mt-3 text-[14px] text-[#71717a]">{text}</p>
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
                        l.active ? 'bg-[#111] text-white' : 'text-[#4b4b4b] hover:bg-[#fafafa]',
                        !l.url && 'cursor-not-allowed opacity-40',
                    )}
                    dangerouslySetInnerHTML={{ __html: l.label }}
                />
            ))}
        </nav>
    );
}
