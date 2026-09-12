import { useEffect, useState, type ReactNode } from 'react';
import { ChevronDown, type LucideIcon } from 'lucide-react';
import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * هيكلُ صفحات الإعدادات والتهيئة والربط.
 *
 * ═══ لماذا ═══
 *
 * كانت كلُّ شاشةِ إعدادٍ تخترع تخطيطَها: هذه عمودان، وتلك ثلاثة، وثالثةٌ
 * بطاقةٌ داخل بطاقةٍ داخل نموذج. والنتيجةُ أنّ المهمّة الواحدة تتفرّق على
 * صناديقَ متنافسة، فيقف من يفتحها يبحث بعينه عن الخطوة التالية.
 *
 * وهذه الملفّاتُ تقول القاعدة مرّةً: **عمودٌ واحد بعرض قراءة، من أعلى إلى
 * أسفل**. الأهمُّ أوّلًا، والمتقدّمُ مطويٌّ آخرًا، والإجراءُ في القدم.
 *
 * ولا تُفرَض على كلّ شاشة: صفحةُ الإدارة (جدولٌ وصفوف) ولوحةُ العرض تبقيان
 * على عرض اللوحة. الاختيار بحسب وظيفة الصفحة لا بحسب شكلها.
 */

/**
 * عرضُ العمود.
 *
 * `form` هو الافتراضي — ٧٦٨ بكسل، وهو عرضُ نموذج الإعدادات في نظام التصميم.
 * و`wide` لما يحمل معاينةً أو قائمةَ أصنافٍ لا تُقرأ في عمودٍ ضيّق.
 */
export type SettingsWidth = 'form' | 'wide' | 'full';

const WIDTH: Record<SettingsWidth, string> = {
    form: 'max-w-3xl',
    wide: 'max-w-5xl',
    full: '',
};

/**
 * عمودُ الصفحة — مركزيٌّ محدودُ العرض.
 *
 * والسقفُ هنا لا في اللوحة: سقفُ `AdminLayout` (١٦٠٠) يشمل الجداول
 * والتقارير، وهي تحتاجه. أمّا نموذجٌ حقولُه حقلان فيمتدّ تحته حتى يصير
 * السطرُ الواحد عرضَ الشاشة، فتُقرأ التسمية في طرفٍ وقيمتُها في طرف.
 */
export function SettingsPage({
    width = 'form',
    className,
    children,
}: {
    width?: SettingsWidth;
    className?: string;
    children: ReactNode;
}) {
    // min-w-0: يمنع جدولًا أو معاينةً عريضة من دفع العمود خارج الشاشة
    return <div className={cn('mx-auto min-w-0 space-y-6', WIDTH[width], className)}>{children}</div>;
}

/**
 * قسمٌ في الصفحة — عنوانٌ وسطرُ شرحٍ وما تحتهما.
 *
 * وهو البطاقةُ الوحيدة المسموحة في هذه الشاشات: ما تحته يُجمَّع بـ
 * `SettingsGroup` لا ببطاقةٍ ثانيةٍ داخلها. «بطاقةٌ داخل بطاقة» تُضاعف
 * الحدودَ والظلالَ ولا تُضيف معنًى — والهرميّة تُقرأ من العنوان والفراغ.
 */
export function SettingsSection({
    title,
    description,
    icon: Icon,
    status,
    action,
    divided = false,
    className,
    bodyClassName,
    id,
    children,
}: {
    title?: string;
    description?: string;
    icon?: LucideIcon;
    /** شارةُ حالٍ إلى جانب العنوان — StatusPill غالبًا */
    status?: ReactNode;
    /** إجراءٌ ثانويّ للقسم وحده: «افتح»، «حدِّث الآن» */
    action?: ReactNode;
    /** حين يحمل القسم أكثر من مجموعة، يفصلها خطٌّ رفيع */
    divided?: boolean;
    className?: string;
    bodyClassName?: string;
    id?: string;
    children?: ReactNode;
}) {
    const t = useTranslate();
    const head = title || description || status || action;

    return (
        <Card id={id} asChild className={cn('p-5 sm:p-6', className)}>
            <section>
                {head && (
                    <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
                        <div className="flex min-w-0 items-start gap-3">
                            {Icon && (
                                <span className="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-[#f3f4f6] text-[#111]">
                                    <Icon className="size-[18px]" />
                                </span>
                            )}
                            <div className="min-w-0">
                                {title && <h2 className="font-bold text-[#111]">{t(title)}</h2>}
                                {description && (
                                    <p className="mt-0.5 text-[13px] leading-relaxed text-[#6b7280]">
                                        {t(description)}
                                    </p>
                                )}
                            </div>
                        </div>
                        {(status || action) && (
                            <div className="flex shrink-0 flex-wrap items-center gap-2">
                                {status}
                                {action}
                            </div>
                        )}
                    </div>
                )}

                <div
                    className={cn(
                        divided && 'divide-y divide-[var(--ui-border,#e8e8e8)]',
                        bodyClassName,
                    )}
                >
                    {children}
                </div>
            </section>
        </Card>
    );
}

/**
 * مجموعةٌ داخل القسم — عنوانٌ فرعيٌّ يفصله خطّ.
 *
 * تُستعمل داخل `SettingsSection divided`: الخطُّ يأتي من الحاوية فلا يُرسم
 * فوق الأولى ولا تحت الأخيرة، ولا يحتاج كلُّ موضعٍ أن يحسب موقعه بنفسه.
 */
export function SettingsGroup({
    title,
    description,
    action,
    className,
    children,
}: {
    title?: string;
    description?: string;
    action?: ReactNode;
    className?: string;
    children: ReactNode;
}) {
    const t = useTranslate();

    return (
        <div className={cn('py-6 first:pt-0 last:pb-0', className)}>
            {(title || description || action) && (
                <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        {title && <h3 className="font-bold text-[#111]">{t(title)}</h3>}
                        {description && (
                            <p className="mt-0.5 text-[13px] leading-relaxed text-[#6b7280]">{t(description)}</p>
                        )}
                    </div>
                    {action && <div className="flex shrink-0 flex-wrap items-center gap-2">{action}</div>}
                </div>
            )}
            {children}
        </div>
    );
}

/**
 * قسمٌ مطويّ — لما لا يحتاجه أحدٌ في أوّل مرّة.
 *
 * ومطويٌّ لا مخفيّ: العنوان يبقى مقروءًا فيعرف صاحبُه أنّ الإعداد موجود
 * وأين هو. والمطويُّ اختيارٌ لما هو **متقدِّمٌ أو اختياريّ** وحده — إعدادٌ
 * يُفتح في كلّ زيارةٍ خلف نقرةٍ أسوأ من صفحةٍ مزدحمة.
 *
 * و`<details>` لا زرٌّ بحالة: المتصفّح يمنحها لوحة المفاتيح والقارئَ الآليّ
 * مجّانًا — `Enter` و`Space` تفتحان، والحالةُ تُعلَن. وزرٌّ نكتبه يحتاج
 * `aria-expanded` و`aria-controls` ونسيانُهما لا يظهر في شاشة.
 */
export function Advanced({
    title,
    description,
    icon: Icon,
    status,
    defaultOpen = false,
    /** يُفتح رغمًا عن الطيّ — خطأُ تحقّقٍ في حقلٍ بالداخل لا يُترك تحته */
    forceOpen = false,
    className,
    children,
}: {
    title: string;
    description?: string;
    icon?: LucideIcon;
    /** حالُ ما تحت الطيّ — يُقرأ وهو مطويّ، وإلّا فُتح ليُعرف */
    status?: ReactNode;
    defaultOpen?: boolean;
    forceOpen?: boolean;
    className?: string;
    children: ReactNode;
}) {
    const t = useTranslate();
    const [open, setOpen] = useState(defaultOpen || forceOpen);

    /*
        خطأٌ تحت الطيّ لا يُرى: التاجر يضغط «حفظ» فلا شيء يقع، والرسالةُ
        مكتوبةٌ داخل قسمٍ مغلق. فيُفتح القسم من نفسه حين يصل الخطأ.
    */
    useEffect(() => {
        if (forceOpen) {
            setOpen(true);
        }
    }, [forceOpen]);

    return (
        <Card className={cn('overflow-hidden', className)}>
            <details
                open={open}
                onToggle={(e) => setOpen((e.currentTarget as HTMLDetailsElement).open)}
            >
                <summary
                    className={cn(
                        'flex cursor-pointer list-none items-center gap-3 p-5 transition-colors hover:bg-[#fafafa]',
                        'focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-[rgba(17,17,17,0.35)]',
                        '[&::-webkit-details-marker]:hidden',
                    )}
                >
                    {Icon && (
                        <span className="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-[#f3f4f6] text-[#111]">
                            <Icon className="size-[18px]" />
                        </span>
                    )}
                    <span className="min-w-0 flex-1">
                        <span className="block font-bold text-[#111]">{t(title)}</span>
                        {description && (
                            <span className="mt-0.5 block text-[13px] leading-relaxed text-[#6b7280]">
                                {t(description)}
                            </span>
                        )}
                    </span>
                    {status}
                    {/*
                        سهمٌ رأسيّ لا أفقيّ: الأفقيُّ ينقلب بين العربية
                        والإنجليزية فيحتاج شيفرةً تعكسه، والرأسيُّ واحدٌ
                        في الاتجاهين — يهبط مغلقًا ويرتفع مفتوحًا.
                    */}
                    <ChevronDown
                        aria-hidden
                        className={cn(
                            'size-4 shrink-0 text-[#9ca3af] transition-transform',
                            open && 'rotate-180',
                        )}
                    />
                </summary>

                <div className="border-t border-[var(--ui-border,#e8e8e8)] p-5">{children}</div>
            </details>
        </Card>
    );
}

/**
 * شريطُ التقدّم — كم من الشرط تمّ.
 *
 * ويُقرأ قبل أن يُعدّ: «٣ من ٥» رقمٌ يُحسب، والشريطُ نسبةٌ تُرى. ولا يقوم
 * أحدهما مقام الآخر، فيُعرضان معًا حيث يُعرضان.
 */
export function SetupProgress({
    at,
    total,
    /** خضراء حين تمّ الكلّ — واللونُ وحده لا يُعتمد عليه، معه العدد */
    done = false,
    label,
    className,
}: {
    at: number;
    total: number;
    done?: boolean;
    label: string;
    className?: string;
}) {
    if (total <= 0) {
        return null;
    }

    return (
        <div
            className={cn('h-1 w-full overflow-hidden rounded-full bg-[#f2f2f0]', className)}
            role="progressbar"
            aria-valuemin={0}
            aria-valuemax={total}
            aria-valuenow={at}
            aria-label={label}
        >
            <span
                className={cn(
                    'block h-full rounded-full transition-[width] duration-500',
                    done ? 'bg-[#059669]' : 'bg-[#111]',
                )}
                style={{ width: `${Math.round((at / total) * 100)}%` }}
            />
        </div>
    );
}

/**
 * قدمُ الصفحة — إجراؤها الأساسيّ.
 *
 * والترتيب من النهاية: الأساسيُّ آخرًا فيقع على حافّة النهاية (يسارًا في
 * العربية) حيث تقف العين بعد قراءة النموذج. وعلى الجوّال عمودٌ كامل العرض
 * لا صفٌّ ينضغط.
 */
export function PageActions({
    note,
    className,
    children,
}: {
    /** سطرٌ يقول ما سيقع بالحفظ، أو لماذا تعطّل الزرّ */
    note?: ReactNode;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div
            className={cn(
                'flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-end',
                className,
            )}
        >
            {note && <div className="text-[12px] leading-relaxed text-[#9ca3af] sm:me-auto">{note}</div>}
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">{children}</div>
        </div>
    );
}
