import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import {
    Check,
    ChevronDown,
    ChevronLeft,
    CircleAlert,
    ExternalLink,
    Eye,
    EyeOff,
    Loader2,
    Monitor,
    RefreshCw,
    Save,
    Smartphone,
    Tablet,
} from 'lucide-react';

import AdminLayout from '@/Layouts/AdminLayout';
import Field from '@/Components/Field';
import StoreImageField from '@/Components/StoreImageField';
import Toggle from '@/Components/Toggle';
import { Button } from '@/Components/ui/button';
import { Input, Textarea } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import PublishBar, { type PublishState } from './theme/PublishBar';
import ThemeHeader, { type ThemeShell } from './theme/Shell';

/** صفحةٌ من صفحات القالب الأربع — كما تقرؤها `StoreNav::rows` */
export interface EditorPage {
    key: string;
    path: string;
    fixed: boolean;
    on: boolean;
    blocked: string | null;
}

/**
 * بابُ تحرير كلّ صفحة — ولكلٍّ بابٌ مختلف.
 *
 * فمحرّرُ الأقسام هذا للرئيسية وحدَها: «المتجر» بضاعتُه تُكتب في المنتجات،
 * و«من نحن» نبذتُها هي نبذةُ قسم «عنّا» في هذه الصفحة نفسِها، و«تواصل معنا»
 * هاتفُه وعنوانُه في إعدادات النشاط.
 *
 * والمنتقي ينقل إلى الباب ولا يدّعي أنّه يُحرّرها هنا — ومنتقًى يُبدّل
 * الاسمَ ولا يُبدّل ما تحته مقبضٌ شكليّ.
 */
const PAGE_DOOR: Record<string, { label: string; route: string; row: string | null }> = {
    home: { label: 'الرئيسية', route: 'admin.website.design', row: null },
    shop: { label: 'المتجر', route: 'admin.products.index', row: null },
    about: { label: 'من نحن', route: 'admin.website.design', row: 'about' },
    contact: { label: 'تواصل معنا', route: 'admin.settings.index', row: null },
};

/**
 * منتقي الصفحات — يقول أيَّ صفحةٍ تُحرَّر، وينقل إلى باب أختها.
 *
 * ═══ ولمَ ينقل ولا يُبدّل ═══
 *
 * صفحاتُ القالب أربعٌ ومحتواها في أربعة أبواب، ومحرّرُ الأقسام هذا للرئيسية
 * وحدَها. فمنتقٍ يُبدّل الاسمَ ويترك الأقسامَ كما هي مقبضٌ يكذب: يقرأ صاحبُه
 * «تواصل معنا» ويرتّب أقسامَ الرئيسية وهو يحسبها صفحةَ التواصل.
 *
 * وصفحةٌ مُطفأةٌ أو بلا محتوًى لا تُعرض — `StoreNav::rows` تُسقطها كما
 * تُسقطها من قائمة زبونه، فلا يفتح المنتقي بابًا يردّ «غير موجود».
 */
function PagePicker({ pages }: { pages: EditorPage[] }) {
    const t = useTranslate();
    const live = pages.filter((p) => p.on && p.blocked === null && PAGE_DOOR[p.key]);

    return (
        <select
            data-testid="page-picker"
            aria-label={t('الصفحة التي تُحرَّر')}
            value="home"
            onChange={(e) => {
                const door = PAGE_DOOR[e.target.value];

                if (door) router.visit(route(door.route, door.row ? { row: door.row } : undefined));
            }}
            className="min-w-0 rounded-[8px] border border-[var(--ui-border,#e8e8e8)] bg-white px-2.5 py-1.5 text-[13px] font-semibold text-[#111]"
        >
            {live.map((p) => (
                <option key={p.key} value={p.key}>
                    {t(PAGE_DOOR[p.key].label)}
                </option>
            ))}
        </select>
    );
}

/** مقاساتُ المعاينة — عرضُ الجهاز كما يفتحه الزبون، لا كسرٌ من شاشة التاجر */
export type PreviewSize = 'desktop' | 'tablet' | 'phone';

export const DEVICE_WIDTH: Record<PreviewSize, number> = {
    desktop: 1280,
    tablet: 820,
    phone: 390,
};

/**
 * ارتفاعُ ما يُرى من المعاينة — ثابتٌ مهما تبدّل المقاس.
 *
 * والإطارُ يأخذ منه مقسومًا على المقياس ليعود بعد التصغير إليه: فصندوقُ
 * المعاينة لا يصير شريطًا ارتفاعُه ٢٩٥ بكسل حين يُختار الكمبيوتر، ويرى
 * التاجرُ من صفحته قدرًا واحدًا في المقاسات الثلاثة.
 */
export const FRAME_HEIGHT = 'max(480px, 70vh)';

/**
 * ما يُصغَّر به الإطارُ ليسع عمودَ المعاينة.
 *
 * ولا يُكبَّر أبدًا: `Math.min(1, ...)` تمنع هاتفًا عرضُه ٣٩٠ أن يُنفخ إلى
 * عرض العمود فيُري التاجرَ حروفًا أكبرَ ممّا يراها زبونُه.
 *
 * وعمودٌ لم يُقَس بعد (`avail === 0` في أوّل رسمة، وفي jsdom دائمًا) يردّ
 * الواحدَ لا القسمةَ على صفر.
 */
export function previewFit(want: number, avail: number): number {
    if (avail <= 0) return 1;

    return Math.min(1, avail / want);
}

export interface EditorField {
    key: string;
    kind: 'text' | 'textarea' | 'image' | 'toggle' | 'featured';
    label: string;
    hint?: string;
    dir?: string;
    gate?: string;
}

export interface EditorRow {
    key: string;
    label: string;
    hint: string;
    fixed: boolean;
    on: boolean;
    silent: string | null;
    source: { label: string; route: string } | null;
    fields: EditorField[];
}

interface Props extends ThemeShell {
    /** حالُ النشر — و`null` لمن لم يُفتح له النظام بعد */
    publishing: PublishState | null;
    /** صفحاتُ القالب الأربع — للمنتقي، لا للتحرير هنا */
    pages: EditorPage[];
    rows: EditorRow[];
    values: Record<string, string>;
    order: string[];
    maxFeatured: number;
    products: { id: number; name: string; image: string | null }[];
}

/** ما يقوله سطرُ الحال — وكلٌّ منها حالٌ وقعت لا حالٌ مفترضة */
type Status = 'clean' | 'saving' | 'saved' | 'failed';

/**
 * محرّرُ صفحة الواجهة الخاصّة — الصفوفُ إلى جانب الصفحة نفسِها.
 *
 * ═══ ولمَ لا تبقى في الإعدادات ═══
 *
 * كانت ثلاثون مقبضًا في بطاقةٍ واحدة مرتّبةً بترتيب ما أُضيف: عنوانُ الواجهة
 * تحت «شكل الصفحة»، وصورةُ الشريط على بعد شاشتين من المفتاح الذي يُشغّل
 * الشريط، وسطرُ التذييل فوق قائمة الأقسام وهو ليس قسمًا. فمن أراد أن يبدّل
 * شيئًا في صفحته لم يكن له موضعٌ يبحث فيه — وسائرُ متاجر أبعاد لها محرّر.
 *
 * فصارت له صفوفٌ بترتيب ما يراه زبونُه، وكلُّ حقلٍ في الصفّ الذي يظهر فيه،
 * والصفحةُ إلى جانبها تتحدّث بعد كلّ حفظ.
 *
 * ═══ ولا «انشر» هنا ═══
 *
 * الواجهةُ تقرأ الإعدادات مباشرةً، فما يُحفظ يراه زبونُه في اللحظة نفسِها.
 * وزرُّ نشرٍ لا يؤجّل شيئًا يَعِد بما لا يفعل.
 */
export default function ThemeEditor({ site: shell, publishing, pages, rows, values, order, maxFeatured, products }: Props) {
    const t = useTranslate();

    /* الترتيبُ محلّيٌّ لأنّه يُكتب بالضغط — والباقي يأتي من الخادم بعد كلّ حفظ */
    const [chosen, setChosen] = useState<string[]>(order);
    const [open, setOpen] = useState<string | null>(null);
    const [status, setStatus] = useState<Status>('clean');
    const [frame, setFrame] = useState(0);

    /*
     * مقاسُ المعاينة — ثلاثةٌ كما عند جاره في البانِي.
     *
     * والتابلت ليس زينةً بين الاثنين: شبكةُ البطاقات في قالب الواجهة تنكسر
     * عنده لا عند الهاتف (ثلاثٌ في الصفّ تصير اثنتين)، فمن عاين على
     * الطرفين وحدَهما لم يرَ الحالَ التي تنكسر فيها صفحتُه.
     *
     * والعرضُ بالبكسل لا بالنسبة (`DEVICE_WIDTH`): ثلاثتُها مقاساتُ ما
     * يفتحه زبونُه فعلًا، لا كسرٌ من عرض شاشة التاجر.
     */
    const [size, setSize] = useState<PreviewSize>('desktop');

    /*
        ═══ والمقاسُ يُرسم بعرضه الحقيقيّ ثمّ يُصغَّر ليُرى ═══

        كان الإطارُ يأخذ `w-[820px] max-w-full` — وعمودُ المعاينة نصفُ
        الصفحة، يقف عند ٧٦٦ بكسل مهما اتّسعت الشاشة. فالـ٨٢٠ لا تسري
        أبدًا، و«تابلت» زرٌّ يُضيء ولا يُبدّل شيئًا.

        والصوابُ ما في `preview/SitePreview`: الإطارُ يُعطى عرضَ الجهاز
        كاملًا فتشتغل فيه استعلاماتُ القالب كما تشتغل عند الزبون، ثمّ
        يُصغَّر بـ`transform` ليسع العمود. و`transform` تُصغّر ما يُرسم
        ولا تُصغّر ما يشغله في التخطيط، فيُضبط ارتفاعُ الصندوق بالنسبة
        نفسِها وإلّا بقي تحته فراغٌ لا يُفهم من أين جاء.

        و«الكمبيوتر» صار عرضًا حقيقيًّا كذلك: كان يأخذ عرضَ العمود — أي
        ٥٤٠ بكسل — فيُري التاجرَ تخطيطَ الهاتف ويُسمّيه كمبيوتر.
    */
    const stage = useRef<HTMLDivElement>(null);
    const [avail, setAvail] = useState(0);

    useEffect(() => {
        const box = stage.current;

        if (! box) return;

        const read = () => setAvail(box.clientWidth);

        read();

        const observer = new ResizeObserver(read);

        observer.observe(box);

        return () => observer.disconnect();
    }, []);

    const want = DEVICE_WIDTH[size];
    const scale = previewFit(want, avail);

    const form = useForm<Record<string, string>>({ ...values });

    /*
        ═══ المعاينةُ تُرسَل بنموذجٍ إلى الإطار — لا برابط ═══

        وما يُعاين هو ما **لم يُحفظ بعد**: يكتب صاحبُ المحلّ عنوانًا فيراه
        كما يراه زبونُه قبل أن يُطبّقه على موقعٍ يعمل.

        وبنموذجٍ `POST` لا برابطٍ فيه القيم: الحمولةُ تبلغ آلافَ الحروف
        (نبذةٌ ونصُّ قسمٍ وروابطُ صور)، ورابطٌ بهذا الطول يُقصّ في الطريق.
        ولأنّه `POST` لا يُحفظ في تاريخ المتصفّح ولا يُشارَك: رابطُ معاينةٍ
        مُشارَكٌ يجب أن يفتح المتجرَ كما هو محفوظ — لا كما كان أحدُهم يجرّب.

        ولا حالةَ على الخادم: التراكبُ يعيش عمرَ الطلب ويموت معه — انظر
        `MarketingSettings::overlay`.
    */
    const previewForm = useRef<HTMLFormElement>(null);

    /*
        والرمزُ من خصائص Inertia لا من وسم `meta`.

        الوسمُ يُطبع مرّةً عند أوّل تحميل ولا يتغيّر، والدخولُ يجدّد رمزَ
        الجلسة — فيبقى الوسمُ على رمزٍ مُبطَل ويُردّ كلُّ نموذجٍ يقرؤه
        بـ٤١٩. وخاصّيةُ `csrf` تتجدّد مع كلّ استجابة. انظر `lib/csrf.ts`.
    */
    const token = String((usePage().props as { csrf?: string }).csrf ?? '');

    const draft = useMemo(
        () => JSON.stringify({ ...form.data, store_sections: chosen.join(',') }),
        [form.data, chosen],
    );

    const paint = () => previewForm.current?.submit();

    /*
        وتُرسَل بعد أن يسكت القلم لا مع كلّ حرف: تصييرُ الصفحة كاملةً عند
        كلّ ضغطةِ مفتاح يُثقل الخادمَ ويُرعش الإطار تحت عين الكاتب.
    */
    useEffect(() => {
        const id = window.setTimeout(paint, 600);

        return () => window.clearTimeout(id);
    }, [draft, frame]);

    const byKey = useMemo(() => new Map(rows.map((r) => [r.key, r])), [rows]);

    /*
     * الصفوفُ بترتيب ما يُرى: الواجهةُ أوّلًا، ثمّ المختارُ بترتيبه، ثمّ
     * المطفأُ بترتيبه الأصليّ، ثمّ التذييل. والمطفأُ يبقى معروضًا ليُرفع
     * ثانية — لا يخرج من الشاشة إلى العدم.
     */
    const movable = useMemo(() => {
        const rest = rows.filter((r) => ! r.fixed && ! chosen.includes(r.key)).map((r) => r.key);

        return [...chosen.filter((k) => byKey.has(k)), ...rest];
    }, [rows, chosen, byKey]);

    const refresh = () => setFrame((n) => n + 1);

    /** حفظُ مفاتيحَ بعينها — لا حمولةَ الشاشة كلَّها */
    const save = (keys: string[]) => {
        setStatus('saving');
        form.transform((d) => Object.fromEntries(keys.map((k) => [k, d[k] ?? ''])));
        form.post(route('admin.marketing.store.save'), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setStatus('saved');
                refresh();
            },
            onError: () => setStatus('failed'),
            onFinish: () => form.transform((d) => d),
        });
    };

    /** والترتيبُ يُحفظ بالضغطة — لا ينتظر زرًّا، فالمعاينةُ تُجيب فورًا */
    const writeOrder = (next: string[]) => {
        setChosen(next);
        setStatus('saving');
        router.post(
            route('admin.marketing.store.save'),
            { store_sections: next.join(',') },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    setStatus('saved');
                    refresh();
                },
                onError: () => setStatus('failed'),
            },
        );
    };

    /*
     * ولا تُطفأ كلُّها.
     *
     * قائمةٌ فارغةٌ تُقرأ «ما كان» في `StorePage::order` فتعود الأقسامُ
     * السبعةُ كلُّها — وهي قاعدةٌ صحيحةٌ لترقيةٍ تنزل على متجرٍ قائم، وكذبةٌ
     * هنا: يُطفئ آخرَ قسمٍ فيراها كلَّها ترجع. فيُمنع الإطفاءُ الأخير ويُقال
     * لمَ — لا يُترك يُجرَّب فيُربك.
     */
    const last = chosen.length === 1;

    const toggleRow = (key: string) =>
        writeOrder(
            chosen.includes(key)
                ? chosen.filter((k) => k !== key)
                : [...movable.filter((k) => k === key || chosen.includes(k))],
        );

    const move = (key: string, by: number) => {
        const i = chosen.indexOf(key);
        const j = i + by;

        if (i < 0 || j < 0 || j >= chosen.length) return;

        const next = [...chosen];
        [next[i], next[j]] = [next[j], next[i]];
        writeOrder(next);
    };

    /* المختارات — بترتيب اختيارها، وأربعةٌ لا أكثر */
    const featured = (form.data.store_featured ?? '')
        .split(',')
        .map((v) => Number(v.trim()))
        .filter((v) => Number.isInteger(v) && v > 0);

    const toggleFeatured = (id: number) =>
        form.setData(
            'store_featured',
            (featured.includes(id)
                ? featured.filter((v) => v !== id)
                : [...featured, id].slice(0, maxFeatured)
            ).join(','),
        );

    const field = (f: EditorField) => {
        if (f.gate && (form.data[f.gate] ?? '0') !== '1') return null;

        const error = form.errors[f.key as keyof typeof form.errors] as string | undefined;

        if (f.kind === 'image') {
            return (
                <StoreImageField
                    key={f.key}
                    label={f.label}
                    hint={f.hint}
                    value={form.data[f.key] ?? ''}
                    onChange={(v) => form.setData(f.key, v)}
                />
            );
        }

        if (f.kind === 'toggle') {
            return (
                <Toggle
                    key={f.key}
                    on={(form.data[f.key] ?? '0') === '1'}
                    onChange={(v) => form.setData(f.key, v ? '1' : '0')}
                    label={f.label}
                    hint={f.hint}
                />
            );
        }

        if (f.kind === 'featured') {
            return (
                <Field key={f.key} label={f.label} hint={f.hint} error={error}>
                    {products.length === 0 ? (
                        <p className="text-[13px] text-[#6b7280]">
                            {t('لا صنفَ معروضًا في متجرك بعد.')}
                        </p>
                    ) : (
                        <ul className="max-h-[240px] divide-y divide-[var(--ui-border,#e8e8e8)] overflow-y-auto rounded-[12px] border border-[var(--ui-border,#e8e8e8)]">
                            {products.map((p) => {
                                const on = featured.includes(p.id);

                                return (
                                    <li key={p.id} className="flex items-center gap-3 px-3 py-2">
                                        <input
                                            type="checkbox"
                                            checked={on}
                                            aria-label={p.name}
                                            disabled={! on && featured.length >= maxFeatured}
                                            onChange={() => toggleFeatured(p.id)}
                                            className="size-4 accent-[#111] disabled:opacity-30"
                                        />
                                        {p.image && (
                                            <img src={p.image} alt="" className="size-8 shrink-0 rounded-lg object-cover" />
                                        )}
                                        <span className="min-w-0 flex-1 truncate text-[13px] text-[#111]">{p.name}</span>
                                        {on && (
                                            <span className="text-[11px] text-[#6b7280]">{featured.indexOf(p.id) + 1}</span>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </Field>
            );
        }

        return (
            <Field key={f.key} label={f.label} hint={f.hint} error={error}>
                {f.kind === 'textarea' ? (
                    <Textarea
                        rows={4}
                        value={form.data[f.key] ?? ''}
                        onChange={(e) => form.setData(f.key, e.target.value)}
                        aria-label={t(f.label)}
                    />
                ) : (
                    <Input
                        dir={f.dir}
                        value={form.data[f.key] ?? ''}
                        onChange={(e) => form.setData(f.key, e.target.value)}
                        aria-label={t(f.label)}
                    />
                )}
            </Field>
        );
    };

    const row = (r: EditorRow) => {
        const expanded = open === r.key;
        const i = chosen.indexOf(r.key);

        return (
            <li key={r.key} className="bg-white" data-testid={`row-${r.key}`}>
                <div className="flex items-center gap-3 px-4 py-3">
                    {r.fixed ? (
                        /* والواجهةُ والتذييلُ هويّةُ الصفحة لا قسمًا يُطفأ — فلا مقبضَ لهما */
                        <span className="size-7 shrink-0" />
                    ) : (
                        /*
                            وعينٌ لا مربّعُ اختيار — كما في ODAY.
                            والمربّعُ يقول «مُختار»، والسؤالُ هنا «أيُرى أم
                            لا يُرى». و`role="switch"` يُبقي للقارئ الصوتيّ
                            حالًا يقرؤها، فالشكلُ تبدّل والمعنى لم يتبدّل.
                        */
                        <button
                            type="button"
                            role="switch"
                            aria-checked={r.on}
                            aria-label={t(r.label)}
                            disabled={r.on && last}
                            onClick={() => toggleRow(r.key)}
                            className={cn(
                                'flex size-7 shrink-0 items-center justify-center rounded-[8px] transition-colors',
                                r.on ? 'text-[#111] hover:bg-[#f3f4f6]' : 'text-[#c4c4c4] hover:bg-[#f3f4f6]',
                                'disabled:pointer-events-none disabled:opacity-30',
                            )}
                        >
                            {r.on ? <Eye className="size-4" /> : <EyeOff className="size-4" />}
                        </button>
                    )}

                    <button
                        type="button"
                        onClick={() => setOpen(expanded ? null : r.key)}
                        className="min-w-0 flex-1 text-start"
                    >
                        <span className={cn('block truncate text-[13px] font-medium', r.on ? 'text-[#111]' : 'text-[#9ca3af]')}>
                            {t(r.label)}
                        </span>
                        <span className="block truncate text-[12px] text-[#9ca3af]">{t(r.hint)}</span>
                    </button>

                    {! r.fixed && (
                        <>
                            <button
                                type="button"
                                aria-label={`${t('ارفع')} ${t(r.label)}`}
                                disabled={! r.on || i <= 0}
                                onClick={() => move(r.key, -1)}
                                className="rounded-md px-2 py-1 text-[13px] text-[#6b7280] enabled:hover:bg-[#f3f4f6] disabled:opacity-30"
                            >
                                ↑
                            </button>
                            <button
                                type="button"
                                aria-label={`${t('أنزل')} ${t(r.label)}`}
                                disabled={! r.on || i < 0 || i >= chosen.length - 1}
                                onClick={() => move(r.key, 1)}
                                className="rounded-md px-2 py-1 text-[13px] text-[#6b7280] enabled:hover:bg-[#f3f4f6] disabled:opacity-30"
                            >
                                ↓
                            </button>
                        </>
                    )}

                    <ChevronDown
                        className={cn('size-4 shrink-0 text-[#d1d5db] transition-transform', expanded && 'rotate-180')}
                    />
                </div>

                {/*
                    و«مُشغَّلٌ ولا يظهر» يُقال في صفّه لا في آخر الشاشة.

                    من شغّل «آراء الزبائن» ثمّ فتح متجره فلم يجدها يظنّ العطبَ
                    في النظام — والسببُ عندنا مكتوب.
                */}
                {r.silent && (
                    <p
                        data-testid={`silent-${r.key}`}
                        className="mx-4 mb-3 flex items-start gap-2 rounded-[10px] bg-[#fffbeb] px-3 py-2 text-[12px] leading-relaxed text-[#b45309]"
                    >
                        <EyeOff className="mt-0.5 size-3.5 shrink-0" />
                        {t(r.silent)}
                    </p>
                )}

                {expanded && (
                    <div className="border-t border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] px-4 py-4">
                        <div className="space-y-4">{r.fields.map(field)}</div>

                        {/*
                            وما لا يُكتب هنا يُقال أين يُكتب.

                            «تسوّق حسب الفئة» تُبنى من فئاته، و«آراء الزبائن»
                            ممّا نشره منها. وحقلٌ يُنسخ إلى هنا يعني عمودًا
                            يُكتب من بابين — فيُشار إلى بابه بدل أن يُفتح ثانٍ.
                        */}
                        {r.source && (
                            <p className="mt-4 flex flex-wrap items-center gap-1.5 text-[12px] text-[#6b7280]">
                                {t('محتوى هذا القسم يُكتب في:')}
                                <Link
                                    href={route(r.source.route)}
                                    className="inline-flex items-center gap-1 font-medium text-[#111] underline"
                                >
                                    {t(r.source.label)}
                                    <ChevronLeft className="size-3.5" />
                                </Link>
                            </p>
                        )}

                        {r.fields.length > 0 && (
                            <div className="mt-4 flex justify-end">
                                <Button
                                    type="button"
                                    size="sm"
                                    loading={form.processing}
                                    onClick={() => save(r.fields.map((f) => f.key))}
                                >
                                    <Save />
                                    {t('احفظ')}
                                </Button>
                            </div>
                        )}
                    </div>
                )}
            </li>
        );
    };

    const hero = byKey.get('hero');
    const foot = byKey.get('foot');

    return (
        <AdminLayout title="الموقع الإلكتروني">
            {/*
                والتبويبُ المضيءُ «التصميم» لا «المحرّر».

                فالمحرّرُ ليس تبويبًا في الشريط: هو ما يصل إليه «التصميم»
                كما يصل إليه عند جاره (انظر `DesignController::index`).
                و«التصميم» اسمُ مساره `admin.website.design` — فيُمرَّر هو،
                ولو تُرك `…editor` لَأضاء المُطابِقُ بالبادئة تبويبًا
                بالقرعة: ستّتُها تبدأ بـ`admin.website.`.
            */}
            <ThemeHeader
                site={shell}
                current="admin.website.design"
                /*
                    والوصفُ يقول الحقّ في الحالين: من فُتح له النشر يحفظ
                    فينتظر، ومن لم يُفتح له يحفظ فيظهر. وسطرٌ واحدٌ لحالين
                    يكذب على أحدهما.
                */
                subtitle={t(publishing
                    ? 'رتّب أقسام صفحتك واكتب فيها — ثمّ انشر ليراه زبونك'
                    : 'رتّب أقسام صفحتك واكتب فيها — وما تحفظه يراه زبونك في الحال')}
            />

            {/* وشريطُ النشر لمن فُتح له وحدَه — ومقبضٌ لا يعمل أسوأ من غيابه */}
            {publishing && <PublishBar state={publishing} />}

            <div className="mb-4 flex items-center justify-end gap-2">
                <span className="flex items-center gap-1.5 text-[12px] text-[#6b7280]">
                    {status === 'saving' && <Loader2 className="size-3.5 animate-spin" />}
                    {status === 'saved' && <Check className="size-3.5 text-[#059669]" />}
                    {status === 'failed' && <CircleAlert className="size-3.5 text-[#b91c1c]" />}
                    {status !== 'clean' &&
                        t(status === 'saving' ? 'يُحفظ…' : status === 'saved' ? 'حُفظ' : 'تعذّر الحفظ')}
                </span>
            </div>

            {/*
                ولا يُشترط النشرُ لفتح المحرّر — فمن يجهّز متجره يرتّب صفحتَه
                قبل أن يفتحها، ويُقال له إنّها لا تُفتح بعد ويُشار إلى بابها.
            */}
            {! shell.published && (
                <p className="mb-4 flex flex-wrap items-center gap-1.5 rounded-[12px] bg-[#eff6ff] px-4 py-3 text-[13px] leading-relaxed text-[#1d4ed8]">
                    {t('متجرك لا يفتحه أحدٌ بعد — رتّب صفحتك هنا، ثمّ انشره من')}
                    <Link href={route('admin.website.domain')} className="font-medium underline">
                        {t('العنوان والنشر')}
                    </Link>
                </p>
            )}

            {/*
                ═══ شريطُ المحرّر — ما يُحرَّر، وبأيّ مقاسٍ يُرى ═══

                ثلاثةُ مواضع كما في ODAY: المنتقي حيث تبدأ القراءة، والمقاسات
                في الوسط فوق المعاينة التي تخصّها، والأفعالُ في الطرف.
            */}
            <div
                data-testid="editor-bar"
                className="mb-4 flex flex-wrap items-center gap-3 rounded-[14px] border border-[var(--ui-border,#e8e8e8)] bg-white px-3 py-2"
            >
                <div className="flex min-w-0 items-center gap-2">
                    <span className="shrink-0 text-[13px] text-[#6b7280]">{t('تُحرّر')}</span>
                    <PagePicker pages={pages} />
                </div>

                {/* والمقاساتُ أزرارٌ لا شاشات — الصفحةُ واحدةٌ يضيق بها الإطار */}
                <div
                    data-testid="preview-sizes"
                    className="flex overflow-hidden rounded-[8px] border border-[var(--ui-border,#e8e8e8)] sm:mx-auto"
                >
                    {([
                        ['desktop', Monitor, 'عرض الكمبيوتر'],
                        ['tablet', Tablet, 'عرض التابلت'],
                        ['phone', Smartphone, 'عرض الجوال'],
                    ] as const).map(([key, Icon, label]) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => setSize(key)}
                            aria-pressed={size === key}
                            aria-label={t(label)}
                            data-testid={'preview-' + key}
                            className={cn(
                                'px-2.5 py-1.5',
                                size === key ? 'bg-[#111] text-white' : 'text-[#6b7280]',
                            )}
                        >
                            <Icon className="size-3.5" />
                        </button>
                    ))}
                </div>

                <div className="flex items-center gap-1">
                    <Button type="button" variant="ghost" size="sm" onClick={refresh}>
                        <RefreshCw />
                        {t('تحديث')}
                    </Button>
                    {/* ورابطُ المتجر لا يُعرض إلّا إن كان يُفتح */}
                    {shell.url && (
                        <Button variant="ghost" size="sm" asChild>
                            <a href={shell.url} target="_blank" rel="noreferrer">
                                <ExternalLink />
                                {t('افتح متجرك')}
                            </a>
                        </Button>
                    )}
                </div>
            </div>

            {/*
                ═══ والمعاينةُ أوّلًا فتقع يمينًا ═══

                الصفحةُ عربيّةٌ، وأوّلُ ابنٍ في الشبكة يقع في يمينها. وكانت
                القائمةُ هي الأوّلَ فأخذت اليمينَ ونصفَ العرض، والمعاينةُ
                تُزاحَم في اليسار — وهي المقصودةُ من المحرّر.

                فصارت المعاينةُ تأخذ ما بقي، واللوحةُ عرضًا ثابتًا.

                ═══ ولمَ ثابتٌ لا كسرٌ ═══

                جُرّبت بالكسر (ثلثان وثلث) فانكسرت على التابلت: عمودُ اللوحة
                صار مئتين وثلاثين بكسلًا، فقُصّت أسماءُ الأقسام إلى «تسوّق …»
                و«الأكثر مب…». وقائمةٌ لا تُقرأ أسماؤها ليست قائمة.

                والعتبةُ `xl` لا `lg`: بين الألف والألفِ ومئتين لا يتّسع
                الصفُّ لعمودَين، فتنزل اللوحةُ تحت المعاينة بعرضها كاملًا.
            */}
            <div className="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
                <div className="xl:order-2">
                    {/* وعنوانُ المجموعة كما في ODAY — يقول أقسامُ أيّ صفحةٍ هذه */}
                    <p className="mb-2 text-[12px] font-semibold text-[#6b7280]">
                        {t('أقسام «الرئيسية»')}
                    </p>
                    <ul className="divide-y divide-[var(--ui-border,#e8e8e8)] overflow-hidden rounded-[16px] border border-[var(--ui-border,#e8e8e8)]">
                        {hero && row(hero)}
                        {movable.map((k) => {
                            const r = byKey.get(k);

                            return r ? row(r) : null;
                        })}
                        {foot && row(foot)}
                    </ul>

                    {/* ولمَ لا يُطفأ الأخير — يُقال، لا يُترك يُجرَّب فيُربك */}
                    {last && (
                        <p data-testid="last-section" className="mt-3 text-[12px] leading-relaxed text-[#6b7280]">
                            {t('لا تُطفأ كلُّها — صفحةٌ بلا قسمٍ واحد تعود إلى ترتيبها الأصليّ.')}
                        </p>
                    )}

                    {/*
                        وما ليس من الصفحة يُقال أين هو: الدفعُ والتوصيلُ
                        والعنوانُ والنشر بطاقةٌ في الإعدادات — لا صفٌّ هنا.
                    */}
                    <p className="mt-4 flex flex-wrap items-center gap-1.5 text-[13px] text-[#6b7280]">
                        {t('والدفعُ والتوصيلُ في')}
                        <Link
                            href={route('admin.website.shop')}
                            className="inline-flex items-center gap-1 font-medium text-[#111] underline"
                        >
                            {t('المتجر والطلبات')}
                            <ChevronLeft className="size-3.5" />
                        </Link>
                    </p>
                </div>

                {/* والمعاينةُ صفحتُه نفسُها في إطار — لا رسمٌ يشبهها */}
                <div className="xl:order-1 xl:sticky xl:top-4 xl:self-start">
                    <div className="overflow-hidden rounded-[16px] border border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] p-2">
                        {/*
                            النموذجُ مخفيٌّ ويُصيب الإطارَ باسمه: هو الذي
                            يحمل المسوّدة، والإطارُ يعرض جوابَه.
                        */}
                        <form
                            ref={previewForm}
                            method="POST"
                            action={route('admin.store.preview')}
                            target="rb-preview"
                            className="hidden"
                            data-testid="preview-form"
                        >
                            <input type="hidden" name="_token" value={token} />
                            <input type="hidden" name="draft" value={draft} />
                        </form>
                        {/* والمقياسُ يُقاس على هذا الصندوق — بلا حشوٍ يكذب عليه */}
                        <div ref={stage} className="w-full">
                            <div
                                data-testid="preview-stage"
                                className="mx-auto overflow-hidden transition-[width]"
                                style={{ width: want * scale, height: FRAME_HEIGHT }}
                            >
                                <iframe
                                    key={frame}
                                    name="rb-preview"
                                    title={t('معاينة المتجر')}
                                    data-testid="preview-frame"
                                    className="rounded-[12px] border border-[var(--ui-border,#e8e8e8)] bg-white"
                                    style={{
                                        width: want,
                                        /* والارتفاعُ يُقسَم كما يُقسَم العرض — وإلّا صار الصندوقُ شريطًا */
                                        height: `calc(${FRAME_HEIGHT} / ${scale})`,
                                        transform: `scale(${scale})`,
                                        /* والمبدأُ يمينٌ لا يسار: الصفحةُ عربيّةٌ تبدأ من هناك */
                                        transformOrigin: 'top right',
                                    }}
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
