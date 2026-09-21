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
        <header className="flex shrink-0 items-center gap-3 border-b border-[var(--ui-border,#e8e8e8)] px-3 py-2.5">
            <Button variant="ghost" size="icon" className="lg:hidden" onClick={onBack} aria-label={t('رجوع')}>
                <ArrowLeft className="rtl:rotate-180" />
            </Button>

            {avatar}

            <div className="min-w-0 flex-1">
                <p className="truncate text-[15px] font-bold text-[#111]">{title}</p>
                {subtitle && <p className="truncate text-[12px] text-[#71717a]">{subtitle}</p>}
            </div>

            {badges && <div className="hidden shrink-0 items-center gap-1.5 sm:flex">{badges}</div>}

            {actions}

            <Button
                variant="ghost"
                size="icon"
                className="xl:hidden"
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

    return (
        <div
            ref={box}
            onScroll={track}
            className={cn('min-h-0 flex-1 space-y-2 overflow-y-auto bg-[#f7f8f7] px-4 py-3', className)}
        >
            {children}
        </div>
    );
}

/** فاصلُ يومٍ أو حدثُ نظام: سطرٌ محايدٌ في الوسط لا فقاعة */
export function SystemRow({ children }: { children: ReactNode }) {
    return (
        <div className="flex justify-center py-1">
            <span className="max-w-[85%] rounded-full bg-white px-3 py-1 text-center text-[11px] text-[#71717a] shadow-[0_1px_2px_rgba(0,0,0,0.05)]">
                {children}
            </span>
        </div>
    );
}

/**
 * الفقاعة.
 *
 * `side`: `out` منّا (خضراءُ خفيفة)، `in` منهم (بيضاء). و`tone: 'internal'`
 * لما لا يخرج إلى الطرف الآخر — لونٌ آخر وقفلٌ وكلمةٌ صريحة، فلا يُعتمد على
 * اللون وحدَه.
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
}) {
    const internal = tone === 'internal';

    return (
        <div className={cn('flex', side === 'out' ? 'justify-end' : 'justify-start')}>
            <div
                className={cn(
                    'max-w-[78%] rounded-[14px] px-3 py-2 shadow-[0_1px_1px_rgba(0,0,0,0.04)]',
                    files && files.length > 0 && 'sm:max-w-[420px]',
                    internal
                        ? 'border border-dashed border-[#f59e0b] bg-[#fffbeb]'
                        : side === 'out'
                          ? 'rounded-ee-[4px] bg-[#e3f5ea] rtl:rounded-ee-[14px] rtl:rounded-es-[4px]'
                          : 'rounded-ss-[4px] bg-white rtl:rounded-ss-[14px] rtl:rounded-se-[4px]',
                )}
            >
                {internal && internalLabel && (
                    <p className="mb-1 flex items-center gap-1 text-[10.5px] font-bold text-[#b45309]">
                        <Lock className="size-3" />
                        {internalLabel}
                    </p>
                )}

                {body && (
                    <p className="whitespace-pre-wrap break-words text-[13.5px] leading-[1.55] text-[#111]" dir="auto">
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

                <p className="mt-1 flex items-center justify-end gap-1 text-[10px] text-[#8a8a8a]">
                    {sender && <span className="truncate">{sender}</span>}
                    {sender && time && <span aria-hidden="true">·</span>}
                    {time && <span dir="ltr">{time}</span>}
                </p>

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
                className="block overflow-hidden rounded-[10px] border border-black/5 bg-white"
                title={name}
            >
                <img src={url} alt={name} className="max-h-44 w-auto max-w-full object-contain" loading="lazy" />
                <span className="flex items-center gap-2 px-2 py-1 text-[11px] text-[#71717a]">
                    <span className="min-w-0 flex-1 truncate">{name}</span>
                    <span className="shrink-0">{fileSize(size)}</span>
                </span>
            </a>
        );
    }

    return (
        <a
            href={url}
            className="flex items-center gap-2.5 rounded-[10px] border border-black/5 bg-white p-2 text-[12px] hover:bg-[#fafafa]"
            title={t('تنزيل')}
        >
            <span className="flex size-9 shrink-0 items-center justify-center rounded-[8px] bg-[#fef2f2] text-[#dc2626]">
                <FileText className="size-4" />
            </span>
            <span className="min-w-0 flex-1">
                <span className="block truncate font-medium text-[#111]" dir="auto">
                    {name}
                </span>
                <span className="block text-[11px] text-[#9ca3af]">{fileSize(size)}</span>
            </span>
            <Download className="size-4 shrink-0 text-[#71717a]" />
        </a>
    );
}
