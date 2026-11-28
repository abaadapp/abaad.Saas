import { useEffect, useState } from 'react';
import { AlertTriangle, Check, Clock, Info, Minus, X } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';

/**
 * ما تشترك فيه شاشتا «الظهور في البحث» — الموقعُ المبنيّ والواجهةُ الخاصّة.
 *
 * ═══ ولمَ في ملفٍّ واحد ═══
 *
 * الشاشتان تحفظان في مكانين مختلفين (`websites.seo` ومفاتيحُ المتجر)، لكنّ
 * ما يقرؤه صاحبُ المتجر فيهما واحد: كيف يظهر في غوغل، وأطويلٌ عنوانُه أم
 * قصير، وأحُفظ ما كتب ومتى، وما الذي ينقص ليُفهرس. ولو كُتب في كلٍّ منهما
 * لَقالت إحداهما «قصير» عن عنوانٍ تقول عنه أختُها «جيّد».
 */

export interface Limits {
    title: number;
    desc: number;
    titleMin: number;
    descMin: number;
}

export interface ReadinessItem {
    key: string;
    label: string;
    state: 'pass' | 'warn' | 'fail' | 'off';
    detail: string | null;
    fix: string | null;
}

export interface Readiness {
    published: boolean;
    indexable: boolean;
    canonical: string | null;
    sitemap: string | null;
    items: ReadinessItem[];
}

/** نتيجةُ بحثٍ كما تُرسم في غوغل — بلغة الصفحة واتّجاهها */
export function SerpPreview({
    url,
    title,
    description,
    lang = 'ar',
    testId = 'seo-preview',
}: {
    url: string | null;
    title: string;
    description: string;
    lang?: 'ar' | 'en';
    testId?: string;
}) {
    const t = useTranslate();

    return (
        <div
            data-testid={testId}
            lang={lang}
            dir={lang === 'en' ? 'ltr' : 'rtl'}
            className="rounded-[12px] border border-[#e5e7eb] bg-white p-4 text-start"
        >
            <p dir="ltr" className="truncate text-start text-[12px] text-[#4b5563]">
                {url ?? t('عنوان موقعك يظهر هنا حين يُنشر')}
            </p>
            <p className="mt-1 truncate text-[18px] leading-tight text-[#1a0dab]">{title}</p>
            <p className="mt-1 line-clamp-2 text-[13px] leading-relaxed text-[#4d5156]">
                {description || t('لا وصف بعد — اكتب سطرين يقنعان من يقرؤهما بأن يضغط.')}
            </p>
        </div>
    );
}

/**
 * عدّادٌ وتنبيهٌ تحت الحقل — يقول «ناقص» و«قصير» و«طويل» وهو يكتب.
 *
 * والحدُّ إرشادٌ لا منع: ما تجاوزه يُحفظ ويُقصّ في نتيجة غوغل. والفارغُ ليس
 * خطأً: يظهر مكانه ما يُحسب من اسمه ونبذته — ويُقال ذلك ولا يُترك يُخمَّن.
 */
export function LengthNote({
    value,
    min,
    max,
    kind,
    testId,
}: {
    value: string;
    min: number;
    max: number;
    kind: 'title' | 'desc';
    testId?: string;
}) {
    const t = useTranslate();
    const n = value.trim().length;

    const [tone, text] =
        n === 0
            ? ['warn', kind === 'title' ? t('فارغ — يظهر ما يُحسب من اسم متجرك.') : t('فارغ — غوغل يختار سطرًا من صفحتك بدلًا عنك.')]
            : n < min
              ? ['warn', t('قصير — :min حرفًا فأكثر أوضح لمن يبحث.', { min: number(min) })]
              : n > max
                ? ['warn', t('طويل — غوغل يقطع ما بعد :max حرفًا تقريبًا.', { max: number(max) })]
                : ['ok', t('طولٌ مناسب.')];

    return (
        <p data-testid={testId} data-tone={tone} className="mt-1 flex flex-wrap items-center gap-x-2 text-[12px]">
            <span className={n > max ? 'text-[#b91c1c]' : 'text-[#9ca3af]'}>
                {number(n)} / {number(max)}
            </span>
            <span className={tone === 'ok' ? 'text-[#047857]' : 'text-[#b45309]'}>{text}</span>
        </p>
    );
}

/**
 * ما يُقال عند زرّ الحفظ — أحُفظ، ومتى، وأفيه ما لم يُحفظ، وأفشل الحفظ.
 *
 * و«تُطبَّق فور الحفظ» تُقال قبل أن يضغط: كان صاحبُ المتجر يحفظ ثمّ يبحث عن
 * زرّ نشرٍ لا شأنَ له بالبحث، أو ينشر معه تصميمًا لم يرضَ عنه.
 */
export function SaveState({
    dirty,
    savedAt,
    justSaved,
    failure,
}: {
    dirty: boolean;
    savedAt: string | null;
    justSaved: boolean;
    failure: string | null;
}) {
    const t = useTranslate();

    return (
        <div className="space-y-1.5 text-[12px] leading-relaxed" data-testid="seo-save-state">
            {failure ? (
                <p role="alert" data-testid="seo-save-failed" className="rounded-[10px] bg-[#fef2f2] px-3 py-2 text-[#b91c1c]">
                    {failure}
                </p>
            ) : justSaved && ! dirty ? (
                <p role="status" data-testid="seo-saved" className="flex items-center gap-1.5 text-[#047857]">
                    <Check className="size-4 shrink-0" />
                    {t('حُفظ وطُبّق على موقعك المنشور.')}
                </p>
            ) : dirty ? (
                <p data-testid="seo-unsaved" className="flex items-center gap-1.5 text-[#b45309]">
                    <span className="size-2 shrink-0 rounded-full bg-[#d97706]" />
                    {t('تغييرات غير محفوظة')}
                </p>
            ) : null}

            <p className="flex items-center gap-1.5 text-[#6b7280]" data-testid="seo-saved-at">
                <Clock className="size-3.5 shrink-0" />
                {savedAt ? t('آخر حفظ: :at', { at: savedAt }) : t('لم تحفظ من هذه الشاشة بعد.')}
            </p>
            <p className="text-[#6b7280]">
                {t('يُطبَّق فور الحفظ على موقعك المنشور — بلا نشر، ولا ينشر معه شيئًا من مسوّدة التصميم أو الصفحات.')}
            </p>
        </div>
    );
}

/**
 * ما حُفظ قبل أن يصير البحثُ يسري بالحفظ — ولم يُنشر قطّ.
 *
 * لا يُطبَّق عنه بترقيةٍ لم يطلبها، ولا يُرمى وقد كتبه بيده: يُعرض عليه
 * ليستعمله بضغطة ثمّ يحفظ، أو يتركه.
 */
export function PendingNotice({
    rows,
    onUse,
}: {
    rows: { label: string; value: string }[];
    onUse: () => void;
}) {
    const t = useTranslate();

    if (rows.length === 0) return null;

    return (
        <div data-testid="seo-pending" className="rounded-[12px] border border-[#fde68a] bg-[#fffbeb] p-4 text-[13px] leading-relaxed text-[#92400e]">
            <p className="flex items-center gap-1.5 font-medium">
                <Info className="size-4 shrink-0" />
                {t('عندك قيمٌ حفظتها قبل هذا التحديث ولم تصل موقعك المنشور بعد:')}
            </p>
            <ul className="mt-2 space-y-1">
                {rows.map((r) => (
                    <li key={r.label} className="break-words">
                        <span className="text-[#b45309]">{r.label}: </span>
                        {r.value || t('(فارغ)')}
                    </li>
                ))}
            </ul>
            <p className="mt-2 text-[12px]">{t('الحقول تعرض ما على موقعك الآن. استعمل هذه القيم ثمّ احفظ لتُطبَّق — أو اتركها، وتُنسى عند حفظك التالي.')}</p>
            <Button type="button" variant="outline" size="sm" className="mt-3" onClick={onUse} data-testid="seo-use-pending">
                {t('استعمل هذه القيم')}
            </Button>
        </div>
    );
}

function StateIcon({ state }: { state: ReadinessItem['state'] }) {
    if (state === 'pass') return <Check className="size-4 text-[#047857]" />;
    if (state === 'fail') return <X className="size-4 text-[#b91c1c]" />;
    if (state === 'warn') return <AlertTriangle className="size-4 text-[#b45309]" />;

    return <Minus className="size-4 text-[#9ca3af]" />;
}

/**
 * جاهزيّةُ الفهرسة — ما تقرؤه محرّكاتُ البحث من صفحته كما حُفظت الآن.
 *
 * وتحتها ما لا تقوله: أنّ غوغل فهرسه فعلًا. «اسمح» إذنٌ لا وعد، والفهرسةُ
 * قرارُ غوغل ووقتُه — ومن رأى العلاماتِ خضراءَ وظنّها «أنا في غوغل» ينتظر
 * ما لم يُوعَد به.
 */
export function ReadinessList({ readiness }: { readiness: Readiness }) {
    const t = useTranslate();

    return (
        <div data-testid="seo-readiness">
            <ul className="divide-y divide-[var(--ui-border,#e8e8e8)]">
                {readiness.items.map((c) => (
                    <li key={c.key} data-testid={`readiness-${c.key}`} data-state={c.state} className="flex items-start gap-3 py-3 first:pt-0 last:pb-0">
                        <span className="mt-0.5 shrink-0">
                            <StateIcon state={c.state} />
                        </span>
                        <div className="min-w-0">
                            <p className="text-[13px] font-medium text-[#111]">{c.label}</p>
                            {c.detail && (
                                <p dir="auto" className="mt-0.5 break-words text-start text-[12px] text-[#6b7280]">
                                    {c.detail}
                                </p>
                            )}
                            {c.fix && <p className="mt-1 text-[12px] text-[#b45309]">{c.fix}</p>}
                        </div>
                    </li>
                ))}
            </ul>

            <p data-testid="seo-not-a-promise" className="mt-4 rounded-[10px] bg-[#f3f4f6] px-3 py-2.5 text-[12px] leading-relaxed text-[#4b5563]">
                {t('السماح بالفهرسة لا يعني أنّ Google فهرس موقعك فعلًا. هو إذنٌ تقرؤه محرّكات البحث، والفهرسة قرارُها ووقتُها — قد تمرّ أيّامٌ أو أسابيع. تحقّق منها في Google Search Console.')}
            </p>
        </div>
    );
}

/**
 * ما يُقال حين يتعثّر الحفظ — والنموذجُ باقٍ بما كُتب فيه.
 *
 * و`false` من بابي الخطأ تمنع Inertia من أن تستبدل الصفحةَ بنافذة الخطأ:
 * فلا يضيع ما كتبه صاحبُه، ويُقال له ما جرى في مكانه.
 */
export function useSaveFeedback() {
    const t = useTranslate();
    const [failure, setFailure] = useState<string | null>(null);
    const [justSaved, setJustSaved] = useState(false);

    useEffect(() => {
        if (! justSaved) return;
        const id = window.setTimeout(() => setJustSaved(false), 6000);

        return () => window.clearTimeout(id);
    }, [justSaved]);

    const options = {
        preserveScroll: true,
        onStart: () => {
            setFailure(null);
            setJustSaved(false);
        },
        onSuccess: () => setJustSaved(true),
        onError: () => setFailure(t('راجع ما عُلِّم بالأحمر ثمّ احفظ — ما كتبته باقٍ.')),
        onHttpException: (response: { status: number }) => {
            setFailure(
                response.status === 419 || response.status === 401
                    ? t('انتهت جلستك — لم يُحفظ شيء. انسخ ما كتبته، ثمّ حدّث الصفحة وادخل من جديد.')
                    : t('تعذّر الحفظ على الخادم — لم يُحفظ شيء، وتعديلاتك باقية. أعد المحاولة.'),
            );

            return false;
        },
        onNetworkError: () => {
            setFailure(t('تعذّر الاتصال — لم يُحفظ شيء، وما كتبته باقٍ هنا. احفظ حين يعود الاتصال.'));

            return false;
        },
    };

    return { failure, justSaved, options };
}
