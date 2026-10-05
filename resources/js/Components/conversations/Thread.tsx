import { type ReactNode, useLayoutEffect, useRef } from 'react';
import { AlertCircle, ArrowLeft, Check, CheckCheck, Download, FileText, Lock, PanelRightOpen } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { fileSize } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { DOODLE_LIGHT } from './wallpaper';

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
const GHOST_ON_THEME = 'text-[var(--cv-icon,#54656f)] hover:bg-[var(--cv-icon-hover,rgba(11,20,26,0.06))] hover:text-[var(--cv-ink,#111b21)]';

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
        <header className="flex min-h-[59px] shrink-0 items-center gap-3 border-b border-[var(--cv-border,#e9edef)] bg-[var(--cv-bar,#f0f2f5)] px-4 py-2">
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
                <p className="truncate text-[16px] font-medium text-[var(--cv-ink,#111b21)]">{title}</p>
                {subtitle && <p className="truncate text-[13px] text-[var(--cv-muted,#667781)]">{subtitle}</p>}
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
            /*
                خلفيّةُ واتساب: لونُ الخيط ونقشُه المتكرّر. والرسائلُ في الوسط
                بهامشين كما هناك — لا ملتصقةً بحافّتَي الشاشة العريضة.
            */
            style={{ backgroundImage: `var(--cv-doodle, ${DOODLE_LIGHT})`, backgroundSize: '360px' }}
            className={cn('min-h-0 flex-1 space-y-1 overflow-y-auto bg-[var(--cv-thread,#efeae2)] px-3 py-4 sm:px-[5%] xl:px-[7%]', className)}
        >
            {children}
        </div>
    );
}

/** فاصلُ يومٍ أو حدثُ نظام: سطرٌ محايدٌ في الوسط لا فقاعة */
export function SystemRow({ children }: { children: ReactNode }) {
    return (
        <div className="flex justify-center py-1.5">
            <span className="max-w-[85%] rounded-[7.5px] bg-[var(--cv-system,#fff)] px-3 py-1.5 text-center text-[12.5px] text-[var(--cv-system-ink,#54656f)] shadow-[0_1px_0.5px_rgba(11,20,26,0.13)]">
                {children}
            </span>
        </div>
    );
}

/** حالُ التسليم كما تُرسم في الفقاعة — علامةٌ أو علامتان، وحمراءُ لما لم يخرج */
export type DeliveryMark = 'sent' | 'delivered' | 'read' | 'failed' | 'blocked' | 'partial' | null;

function Ticks({ status, label }: { status: DeliveryMark; label?: string | null }) {
    if (!status) return null;

    const common = { 'aria-label': label ?? undefined, role: label ? 'img' : undefined } as const;

    if (status === 'failed' || status === 'blocked') {
        return (
            <span {...common} title={label ?? undefined} className="text-[var(--cv-fail,#ea0038)]">
                <AlertCircle className="size-[15px]" />
            </span>
        );
    }

    if (status === 'partial') {
        return (
            <span {...common} title={label ?? undefined} className="text-[var(--cv-warn,#d97706)]">
                <AlertCircle className="size-[15px]" />
            </span>
        );
    }

    return (
        <span
            {...common}
            title={label ?? undefined}
            className={status === 'read' ? 'text-[var(--cv-tick-read,#53bdeb)]' : 'text-[var(--cv-tick,#8696a0)]'}
        >
            {status === 'sent' ? <Check className="size-4" /> : <CheckCheck className="size-4" />}
        </span>
    );
}

/**
 * الفقاعة — كما في واتساب ويب.
 *
 * `side`: `out` منّا (خضراءُ فاتحة)، `in` منهم (بيضاء). ولأوّل فقاعةٍ في
 * سلسلة الجهة ذيلٌ عند زاويتها العليا (`first`)، وما يتلوها بلا ذيل. والوقتُ
 * وعلامتا التسليم داخلها في الزاوية السفلى، واسمُ الكاتب أعلاها.
 *
 * و`tone: 'internal'` لما لا يخرج إلى الطرف الآخر — لونٌ آخر وقفلٌ وكلمةٌ
 * صريحة، فلا يُعتمد على اللون وحدَه.
 *
 * و`status` حالُ التسليم علامةً (✓ ✓✓ ✓✓ زرقاء، أو تنبيه)، ومعناها بكلمته
 * في `statusLabel` لقارئ الشاشة ولمن يمرّ عليها. وما يحتاج شرحًا — خطأٌ من
 * ميتا مثلًا — يمرّ في `footer` تحت الفقاعة.
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
    status = null,
    statusLabel,
    first = true,
}: {
    side: 'in' | 'out';
    tone?: 'default' | 'internal';
    body: string | null;
    sender?: string | null;
    time?: string | null;
    /** ما يُكتب تحت الفقاعة: شرحُ تسليمٍ لم يتمّ مثلًا — بمعناه من الصفحة */
    footer?: ReactNode;
    files?: { id: number; name: string; size: number; image: boolean; url: string }[];
    internalLabel?: string;
    /** وجهُ الكاتب — لا يُرسم في شكل واتساب، ويبقى المقبضُ لمن يمرّره */
    avatar?: ReactNode;
    /** حالُ التسليم — للصادر وحدَه */
    status?: DeliveryMark;
    statusLabel?: string | null;
    /** أوّلُ فقاعةٍ في سلسلة جهتها — لها الذيل */
    first?: boolean;
}) {
    const internal = tone === 'internal';
    const out = side === 'out';
    const mark = out && !internal ? status : null;
    const hasFiles = !!files && files.length > 0;

    /* مكانُ الوقت والعلامة في آخر السطر — يُحجز فلا يقع النصُّ تحته */
    const reserve = (time?.length ?? 0) * 6.5 + (mark ? 22 : 0) + 10;

    void avatar;

    return (
        <div className={cn('flex', out ? 'justify-end' : 'justify-start', first && 'pt-1.5')}>
            <div className={cn('flex max-w-[85%] flex-col sm:max-w-[65%]', out ? 'items-end' : 'items-start', hasFiles && 'sm:max-w-[360px]')}>
                <div
                    className={cn(
                        'relative w-fit max-w-full rounded-[7.5px] px-[9px] pb-[7px] pt-[6px] text-[14.2px] shadow-[0_1px_0.5px_rgba(11,20,26,0.13)]',
                        internal
                            ? 'border border-dashed border-[var(--cv-note-border,#f59e0b)] bg-[var(--cv-note,#fffbeb)] text-[var(--cv-note-ink,#111b21)]'
                            : out
                              ? 'bg-[var(--cv-out,#d9fdd3)] text-[var(--cv-out-ink,#111b21)]'
                              : 'bg-[var(--cv-in,#fff)] text-[var(--cv-in-ink,#111b21)]',
                        first && !internal && (out ? 'rounded-se-none' : 'rounded-ss-none'),
                    )}
                >
                    {/* الذيل — مثلّثٌ بلون الفقاعة عند زاويتها العليا، ويُقلب مع الاتّجاه */}
                    {first && !internal && (
                        <span
                            aria-hidden="true"
                            className={cn(
                                'absolute top-0 h-[13px] w-[8px] rtl:-scale-x-100',
                                out
                                    ? '-end-[8px] bg-[var(--cv-out,#d9fdd3)] [clip-path:polygon(0_0,100%_0,0_100%)]'
                                    : '-start-[8px] bg-[var(--cv-in,#fff)] [clip-path:polygon(0_0,100%_0,100%_100%)]',
                            )}
                        />
                    )}

                    {internal && internalLabel && (
                        <p className="mb-1 flex items-center gap-1 text-[11px] font-bold text-[var(--cv-note-head,#b45309)]">
                            <Lock className="size-3" />
                            {internalLabel}
                        </p>
                    )}

                    {sender && (
                        <p className="mb-0.5 text-[12.8px] font-medium text-[var(--cv-sender,#008069)]" dir="auto">
                            {sender}
                        </p>
                    )}

                    {hasFiles && (
                        <div className={cn('space-y-1', body ? 'mb-1' : 'mb-4')}>
                            {files!.map((f) => (
                                <AttachmentCard key={f.id} {...f} />
                            ))}
                        </div>
                    )}

                    {body && (
                        <p className="whitespace-pre-wrap break-words leading-[19px]" dir="auto">
                            {body}
                            <span aria-hidden="true" className="inline-block align-middle" style={{ width: reserve }} />
                        </p>
                    )}

                    {(time || mark) && (
                        <span
                            className={cn(
                                'absolute bottom-[3px] end-[7px] flex items-center gap-[3px] text-[11px] leading-none',
                                out ? 'text-[var(--cv-out-meta,#667781)]' : 'text-[var(--cv-meta,#667781)]',
                            )}
                        >
                            {time && <span dir="ltr">{time}</span>}
                            <Ticks status={mark} label={statusLabel} />
                        </span>
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
                className="block overflow-hidden rounded-[6px] bg-[var(--cv-attach,#f7f8fa)]"
                title={name}
            >
                <img src={url} alt={name} className="max-h-44 w-auto max-w-full object-contain" loading="lazy" />
                <span className="flex items-center gap-2 px-2 py-1 text-[11px] text-[var(--cv-attach-muted,#667781)]">
                    <span className="min-w-0 flex-1 truncate">{name}</span>
                    <span className="shrink-0">{fileSize(size)}</span>
                </span>
            </a>
        );
    }

    return (
        <a
            href={url}
            className="flex min-w-[220px] items-center gap-2.5 rounded-[6px] bg-[var(--cv-attach,#f7f8fa)] p-2.5 text-[13px] text-[var(--cv-attach-ink,#111b21)] hover:bg-[var(--cv-attach-hover,#eef0f2)]"
            title={t('تنزيل')}
        >
            <span className="flex size-9 shrink-0 items-center justify-center rounded-[8px] bg-[#fef2f2] text-[#dc2626]">
                <FileText className="size-4" />
            </span>
            <span className="min-w-0 flex-1">
                <span className="block truncate font-medium text-[var(--cv-attach-ink,#111b21)]" dir="auto">
                    {name}
                </span>
                <span className="block text-[11px] text-[var(--cv-attach-muted,#667781)]">{fileSize(size)}</span>
            </span>
            <Download className="size-4 shrink-0 text-[var(--cv-attach-muted,#667781)]" />
        </a>
    );
}
