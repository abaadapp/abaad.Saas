import { type ReactNode, useLayoutEffect, useRef } from 'react';
import { ArrowLeft, Download, FileText, Lock, PanelRightOpen } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { fileSize } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * العمودُ الأوسط: ترويسةٌ ثابتة، وخيطٌ يتمرّر، ومُحرِّرٌ في القاع.
 *
 * عرضٌ لا بيانات — انظر Shell.tsx. الفقاعةُ تُرسم بجهةٍ (`side`) ونبرةٍ
 * (`tone`)، ولا تعرف أهذا `scope` دعمٍ أم `direction` مبيعات: كلُّ صفحةٍ
 * تترجم معناها إلى شكلٍ قبل أن تمرّره.
 */
/**
 * زرٌّ شبحيٌّ يتبع لوحَ الصفحة إن لوّنته.
 *
 * والافتراضيُّ ألوانُ `ghost` نفسُها حرفًا بحرف — فصفحةٌ لا تُعرّف
 * `--cv-*` لا يتبدّل فيها شيء.
 */
const GHOST_ON_THEME = 'text-[var(--cv-muted,#4b4b4b)] hover:bg-[var(--cv-hover,rgba(17,17,17,0.045))] hover:text-[var(--cv-ink,#111)]';

export function ConversationHeader({
    avatar,
    title,
    subtitle,
    badges,
    actions,
    onBack,
    onDetails,
}: {
    avatar: ReactNode;
    title: string;
    subtitle?: ReactNode;
    badges?: ReactNode;
    actions?: ReactNode;
    onBack: () => void;
    onDetails: () => void;
}) {
    const t = useTranslate();

    return (
        <header className="flex shrink-0 items-center gap-3 border-b border-[var(--cv-border,var(--ui-border,#e8e8e8))] px-3 py-2.5">
            <Button
                variant="ghost"
                size="icon"
                className={cn('lg:hidden', GHOST_ON_THEME)}
                onClick={onBack}
                aria-label={t('رجوع')}
            >
                <ArrowLeft className="rtl:rotate-180" />
            </Button>

            {avatar}

            <div className="min-w-0 flex-1">
                <p className="truncate text-[15px] font-bold text-[var(--cv-ink,#111)]">{title}</p>
                {subtitle && <p className="truncate text-[12px] text-[var(--cv-muted,#71717a)]">{subtitle}</p>}
            </div>

            {badges && <div className="hidden shrink-0 items-center gap-1.5 sm:flex">{badges}</div>}

            {actions}

            <Button
                variant="ghost"
                size="icon"
                className={cn('xl:hidden', GHOST_ON_THEME)}
                onClick={onDetails}
                aria-label={t('التفاصيل')}
                title={t('التفاصيل')}
            >
                <PanelRightOpen className="rtl:rotate-180" />
            </Button>
        </header>
    );
}

/**
 * الخيط: يُفتح على آخر الرسائل، ويلحق بالجديد إن كان القارئُ في القاع.
 *
 * ولا يُسحب من يقرأ القديم: وصولُ رسالةٍ وهو في منتصف الخيط لا يقفز به —
 * فالقفزُ يُقاس بقربه من القاع قبل التحديث لا بعده.
 */
export function ConversationThread({
    threadKey,
    count,
    children,
    className,
}: {
    /** يتبدّل حين تُفتح محادثةٌ أخرى — فيُقفز إلى القاع بلا شرط */
    threadKey: string | number;
    /** عددُ الرسائل — زيادتُه تعني رسالةً جديدة */
    count: number;
    children: ReactNode;
    className?: string;
}) {
    const box = useRef<HTMLDivElement>(null);
    const seen = useRef<{ key: string | number; count: number } | null>(null);
    const nearBottom = useRef(true);

    const track = () => {
        const el = box.current;
        if (!el) return;
        nearBottom.current = el.scrollHeight - el.scrollTop - el.clientHeight < 120;
    };

    useLayoutEffect(() => {
        const el = box.current;
        if (!el) return;

        const opened = seen.current?.key !== threadKey;
        const grew = !opened && (seen.current?.count ?? 0) < count;

        if (opened || (grew && nearBottom.current)) {
            el.scrollTop = el.scrollHeight;
            nearBottom.current = true;
        }

        seen.current = { key: threadKey, count };
    }, [threadKey, count]);

    /*
     * والصورةُ تصل بعد الرسائل: الخيطُ يُقاس ويُقفز إلى قاعه قبل أن تُحمَّل
     * الصورُ فيه، ثمّ تُحمَّل فيطول الخيطُ ويبقى القاعُ تحت النظر. فكلُّ
     * صورةٍ تكتمل وهو في القاع تُعيده إليه.
     */
    const settle = () => {
        const el = box.current;
        if (el && nearBottom.current) el.scrollTop = el.scrollHeight;
    };

    return (
        <div
            ref={box}
            onScroll={track}
            onLoadCapture={settle}
            className={cn('min-h-0 flex-1 space-y-3 overflow-y-auto bg-[var(--cv-thread,var(--cv-panel,#fff))] px-4 py-3', className)}
        >
            {children}
        </div>
    );
}

/** فاصلُ يومٍ أو حدثُ نظام: سطرٌ محايدٌ في الوسط لا فقاعة */
export function SystemRow({ children }: { children: ReactNode }) {
    return (
        <div className="flex justify-center py-1">
            <span className="max-w-[85%] rounded-full bg-[var(--cv-chip,#f3f5f9)] px-3 py-1 text-center text-[11px] text-[var(--cv-muted,#71717a)]">
                {children}
            </span>
        </div>
    );
}

/**
 * الفقاعة.
 *
 * `side`: `out` منّا (زرقاءُ بنصٍّ أبيض)، `in` منهم (رماديّةٌ فاتحة). واسمُ
 * الكاتب ووقتُه فوق الفقاعة لا داخلها، ووجهُ الوارد بجانبه — كما في
 * تطبيقات الرسائل، فتُقرأ «من» و«متى» قبل «ماذا».
 *
 * و`tone: 'internal'` لما لا يخرج إلى الطرف الآخر — لونٌ آخر وقفلٌ وكلمةٌ
 * صريحة، فلا يُعتمد على اللون وحدَه.
 */
export function MessageBubble({
    side,
    tone = 'default',
    body,
    sender,
    time,
    footer,
    files,
    internalLabel,
    avatar,
}: {
    side: 'in' | 'out';
    tone?: 'default' | 'internal';
    body: string | null;
    sender?: string | null;
    time?: string | null;
    /** ما يُكتب تحت النصّ: حالُ التسليم مثلًا — بمعناه من الصفحة */
    footer?: ReactNode;
    files?: { id: number; name: string; size: number; image: boolean; url: string }[];
    internalLabel?: string;
    /** وجهُ الكاتب — يُرسم بجانب الوارد وحدَه */
    avatar?: ReactNode;
}) {
    const internal = tone === 'internal';
    const out = side === 'out';

    return (
        <div className={cn('flex gap-2', out ? 'justify-end' : 'justify-start')}>
            {!out && avatar && <span className="mt-5 shrink-0">{avatar}</span>}

            <div className={cn('flex max-w-[78%] flex-col', out ? 'items-end' : 'items-start', files && files.length > 0 && 'sm:max-w-[420px]')}>
                {(sender || time) && (
                    <p className={cn('mb-1 flex items-baseline gap-1.5 px-1 text-[11px]', out && 'flex-row-reverse')}>
                        {sender && <span className="font-semibold text-[var(--cv-ink,#111)]">{sender}</span>}
                        {time && (
                            <span className="text-[var(--cv-faint,#9ca3af)]" dir="ltr">
                                {time}
                            </span>
                        )}
                    </p>
                )}

                <div
                    className={cn(
                        'w-fit max-w-full rounded-[14px] px-3.5 py-2.5',
                        internal
                            ? 'border border-dashed border-[var(--cv-note-border,#f59e0b)] bg-[var(--cv-note,#fffbeb)] text-[var(--cv-note-ink,#111)]'
                            : out
                              ? 'rounded-se-[4px] bg-[var(--cv-out,#2563eb)] text-[var(--cv-out-ink,#fff)] shadow-[0_2px_8px_rgba(37,99,235,0.25)]'
                              : 'rounded-ss-[4px] bg-[var(--cv-in,#f3f5f9)] text-[var(--cv-in-ink,#111)]',
                    )}
                >
                    {internal && internalLabel && (
                        <p className="mb-1 flex items-center gap-1 text-[10.5px] font-bold text-[var(--cv-note-head,#b45309)]">
                            <Lock className="size-3" />
                            {internalLabel}
                        </p>
                    )}

                    {body && (
                        <p className="whitespace-pre-wrap break-words text-[13.5px] leading-[1.55]" dir="auto">
                            {body}
                        </p>
                    )}

                    {files && files.length > 0 && (
                        <div className={cn('space-y-1.5', body && 'mt-2')}>
                            {files.map((f) => (
                                <AttachmentCard key={f.id} {...f} />
                            ))}
                        </div>
                    )}
                </div>

                {footer}
            </div>
        </div>
    );
}

/**
 * بطاقةُ مرفق — أيقونةٌ واسمٌ وحجمٌ وبابُ تنزيل.
 *
 * والرابطُ يُمرَّر جاهزًا من الصفحة: مرفقُ الدعم يمرّ بمتحكّم الدعم ومرفقُ
 * المبيعات بمتحكّم المبيعات، ولا تعرف البطاقةُ أيَّهما.
 */
export function AttachmentCard({
    name,
    size,
    image,
    url,
}: {
    name: string;
    size: number;
    image: boolean;
    url: string;
}) {
    const t = useTranslate();

    if (image) {
        return (
            <a
                href={url}
                target="_blank"
                rel="noopener noreferrer"
                className="block overflow-hidden rounded-[10px] border border-black/5 bg-[var(--cv-attach,#fff)]"
                title={name}
            >
                <img src={url} alt={name} className="max-h-44 w-auto max-w-full object-contain" loading="lazy" />
                <span className="flex items-center gap-2 px-2 py-1 text-[11px] text-[var(--cv-attach-muted,#71717a)]">
                    <span className="min-w-0 flex-1 truncate">{name}</span>
                    <span className="shrink-0">{fileSize(size)}</span>
                </span>
            </a>
        );
    }

    return (
        <a
            href={url}
            className="flex items-center gap-2.5 rounded-[10px] border border-black/5 bg-[var(--cv-attach,#fff)] p-2 text-[12px] text-[var(--cv-attach-ink,#111)] hover:bg-[var(--cv-attach-hover,#fafafa)]"
            title={t('تنزيل')}
        >
            <span className="flex size-9 shrink-0 items-center justify-center rounded-[8px] bg-[#fef2f2] text-[#dc2626]">
                <FileText className="size-4" />
            </span>
            <span className="min-w-0 flex-1">
                <span className="block truncate font-medium text-[var(--cv-attach-ink,#111)]" dir="auto">
                    {name}
                </span>
                <span className="block text-[11px] text-[var(--cv-attach-muted,#9ca3af)]">{fileSize(size)}</span>
            </span>
            <Download className="size-4 shrink-0 text-[var(--cv-attach-muted,#71717a)]" />
        </a>
    );
}
