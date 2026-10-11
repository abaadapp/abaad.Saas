import { type ReactNode, useEffect, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ChevronLeft, CircleAlert, Eye, EyeOff, ImageOff, Loader2, Pencil, Plus, Save, Trash2, Upload, X } from 'lucide-react';

import { useConfirm } from '@/Components/ConfirmDialog';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { csrfHeaders } from '@/lib/csrf';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * لوحتا «تسوّق حسب الفئة» و«وصل حديثًا» — كما يبنيهما الخادم (`Store\CatalogTools`).
 *
 * ولا تصل إلّا لمتجرٍ في قائمتها. ومن لم تصله يبقى صفّاه على سطر «يُكتب
 * في…» كما كانا — فالشاشةُ لا تسأل عن متجرٍ بعينه، تسأل: أوصلت الحمولة؟
 */
export interface CatalogCategory {
    id: number;
    name: string;
    name_en: string | null;
    /** أصنافُها المفعَّلة المنشورة — وصفرٌ يعني أنّها لا تُرسم */
    shown_count: number;
    /** أخفى بطاقتَها من «تسوّق حسب الفئة» — وأصنافُها باقيةٌ في المتجر */
    card_hidden: boolean;
    /** صورةُ بطاقتها — وبلاها الخطوطُ الملوّنة */
    card_image: string | null;
    /** رقمُها في ترتيب صاحبها — وفارغٌ لما لم يُرتَّب بعد فيقع بالاسم آخرًا */
    sort_order: number | null;
}

/**
 * ترتيبُ الموقع كما يكتبه الخادم (`Category::scopeInStoreOrder`): المرتَّبُ
 * برقمه، ثمّ ما لم يُرتَّب بالاسم. فالفئةُ المضافة الآن تقع حيث تقع في الموقع.
 */
export function inStoreOrder(list: CatalogCategory[]): CatalogCategory[] {
    return [...list].sort(
        (a, b) =>
            Number(a.sort_order === null) - Number(b.sort_order === null) ||
            (a.sort_order ?? 0) - (b.sort_order ?? 0) ||
            a.name.localeCompare(b.name, 'ar'),
    );
}

/** زرّا التحريك — لمسةُ إصبعٍ كاملة، ويبهتان عند الطرف */
const arrowButton = 'flex size-9 shrink-0 items-center justify-center rounded-[8px] text-[#6b7280] enabled:hover:bg-[#f3f4f6] disabled:opacity-30';

export interface CatalogArrival {
    id: number;
    name: string;
    image: string | null;
    category: string | null;
}

export interface CatalogTools {
    categories: CatalogCategory[];
    /** ما يعرضه القسمُ الآن — تلقائيًّا أو يدويًّا (`NewArrivals::pick`) */
    new_arrivals: CatalogArrival[];
    /**
     * الطريقةُ والمعرّفاتُ كما تُحرَّر — لمتجرٍ في قائمة الاختيار اليدويّ
     * وحده (`NewArrivals::allowed`). وغيابُهما يعني اللوحةَ عرضًا كما كانت.
     */
    new_arrivals_mode?: ArrivalsMode;
    new_arrival_ids?: number[];
}

export type ArrivalsMode = 'auto' | 'manual';

/** كم صنفًا يعرض «وصل حديثًا» — `StorePage::NEW_ARRIVALS` */
export const NEW_ARRIVALS_MAX = 4;

export interface ArrivalProduct {
    id: number;
    name: string;
    image: string | null;
}

/**
 * الاختيارُ اليدويّ — حالُه في نموذج المحرّر لا هنا.
 *
 * فالمعاينةُ ترسم ما في النموذج قبل الحفظ (انظر `ThemeEditor`)، والحفظُ
 * يمرّ بـ`save` نفسِه الذي تمرّ به حقولُ الصفوف: مفتاحان لا حمولةَ الشاشة.
 */
export interface ArrivalsCuration {
    mode: ArrivalsMode;
    /** بترتيبه — ممّا يُعرض الآن وحده */
    ids: number[];
    /** المعروضُ من أصناف متجره — ما يجوز اختيارُه */
    products: ArrivalProduct[];
    onChange: (mode: ArrivalsMode, ids: number[]) => void;
    onSave: () => void;
    saving: boolean;
    error?: string;
}

/** رابطٌ بشكل الزرّ الصغير — بابٌ في اللوحة لا فعلٌ هنا */
function Door({ href, children }: { href: string; children: ReactNode }) {
    return (
        <Button asChild size="sm" variant="outline">
            <Link href={href}>
                {children}
            </Link>
        </Button>
    );
}

/**
 * بطاقةُ القسم في «تسوّق حسب الفئة» — تُخفى وتُكشف، وتلبس صورةً وتُنزع عنها.
 *
 * ═══ وتُحفظ فورًا — لا مع «احفظ» المحرّر ═══
 *
 * البطاقةُ من صفّ القسم نفسِه لا من حقول الصفحة، فلا تمرّ بمسوّدتها: ما
 * يُضغط هنا يقع على الموقع الآن، ويُقال ذلك فوق القائمة. وبـfetch لا
 * بتنقّل — كإضافة القسم: ما كُتب في المحرّر ولم يُحفظ لا يُمحى لأجل صورة.
 *
 * والإخفاءُ للبطاقة وحدها: أصنافُ القسم تبقى في المتجر ويبقى يُختار في رفّه.
 */
function CardControls({ category, onSaved, children }: { category: CatalogCategory; onSaved: (c: CatalogCategory) => void; children?: ReactNode }) {
    const t = useTranslate();
    const input = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const send = async (url: string, method: string, body?: BodyInit, json = false) => {
        setBusy(true);
        setError(null);
        try {
            const res = await fetch(url, {
                method,
                headers: { ...(json ? { 'Content-Type': 'application/json' } : {}), Accept: 'application/json', ...csrfHeaders() },
                body,
            });
            const out = await res.json().catch(() => null);

            if (! res.ok || ! out?.category) {
                setError(out?.errors?.image?.[0] ?? out?.errors?.card_hidden?.[0] ?? out?.message ?? t('تعذّر حفظ البطاقة'));

                return;
            }

            onSaved({ ...category, card_hidden: Boolean(out.category.card_hidden), card_image: out.category.card_image || null });
        } catch {
            setError(t('تعذّر الاتصال بالخادم'));
        } finally {
            setBusy(false);
            if (input.current) input.current.value = '';
        }
    };

    const toggle = () =>
        send(
            route('admin.products.categories.card', category.id),
            'PATCH',
            JSON.stringify({ card_hidden: ! category.card_hidden }),
            true,
        );

    const upload = (file: File | undefined) => {
        if (! file) return;

        const form = new FormData();
        form.append('image', file);
        send(route('admin.products.categories.cardImage', category.id), 'POST', form);
    };

    const clear = () => send(route('admin.products.categories.cardImage.clear', category.id), 'DELETE');

    return (
        <div className="flex w-full flex-wrap items-center gap-2" data-testid={`catalog-card-${category.id}`}>
            <Button
                type="button"
                size="sm"
                variant="outline"
                disabled={busy}
                aria-pressed={category.card_hidden}
                onClick={toggle}
                data-testid={`catalog-card-toggle-${category.id}`}
            >
                {category.card_hidden ? <Eye /> : <EyeOff />}
                {t(category.card_hidden ? 'إظهار البطاقة' : 'إخفاء البطاقة')}
            </Button>

            {/* والحقلُ يُخفى ويُنادى من زرٍّ بالعربيّة — كما في `StoreImageField` */}
            <input
                ref={input}
                type="file"
                accept="image/*"
                aria-label={`${t('صورة البطاقة')} — ${category.name}`}
                disabled={busy}
                onChange={(e) => upload(e.target.files?.[0])}
                className="sr-only"
                data-testid={`catalog-card-file-${category.id}`}
            />
            <Button type="button" size="sm" variant="outline" disabled={busy} onClick={() => input.current?.click()}>
                <Upload />
                {t(category.card_image ? 'استبدل الصورة' : 'أضف صورة للبطاقة')}
            </Button>

            {category.card_image && (
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    disabled={busy}
                    onClick={clear}
                    aria-label={`${t('أزل صورة البطاقة')} — ${category.name}`}
                    data-testid={`catalog-card-clear-${category.id}`}
                >
                    <X />
                    {t('أزل الصورة')}
                </Button>
            )}

            {children}

            {busy && <Loader2 className="size-4 shrink-0 animate-spin text-[#6b7280]" />}

            {/* البطاقةُ تملأ إطارًا أفقيًّا وتقصّ ما يزيد — فالطوليّةُ تفقد رأسَها وقدمَها */}
            <p className="w-full text-[11.5px] text-[#9ca3af]" data-testid={`catalog-card-hint-${category.id}`}>
                {t('الأفضل صورة أفقية 4:3 — مثل 1200×900. الطولية تُقصّ من أعلاها وأسفلها.')}
            </p>

            {error && (
                <p role="alert" className="flex w-full items-start gap-1.5 text-[12px] text-[#b91c1c]">
                    <CircleAlert className="mt-0.5 size-3.5 shrink-0" />
                    {error}
                </p>
            )}
        </div>
    );
}

/**
 * اسمُ القسم وحذفُه — من صفّه في المحرّر، حيث يراه على بطاقته.
 *
 * التسميةُ بالباب القائم (`admin.products.categories.update`): قواعدُ نموذج
 * المنتج نفسُها وتفرّدُ الاسم في المتجر. والحذفُ للفارغ وحده — والخادمُ
 * يردّ قسمًا فيه صنفٌ مطفأ أو محذوف بسببه، فلا يُخمَّن هنا.
 */
function CategoryNaming({
    category,
    onRenamed,
    onRemoved,
}: {
    category: CatalogCategory;
    onRenamed: (c: CatalogCategory) => void;
    onRemoved: (id: number) => void;
}) {
    const t = useTranslate();
    const [ask, dialog] = useConfirm();
    const [editing, setEditing] = useState(false);
    const [name, setName] = useState(category.name);
    const [nameEn, setNameEn] = useState(category.name_en ?? '');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const call = async (url: string, method: string, body?: unknown) => {
        setBusy(true);
        setError(null);
        try {
            const res = await fetch(url, {
                method,
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeaders() },
                body: body === undefined ? undefined : JSON.stringify(body),
            });
            const out = await res.json().catch(() => null);

            if (! res.ok) {
                setError(Object.values((out?.errors ?? {}) as Record<string, string[]>)[0]?.[0] ?? out?.message ?? t('تعذّر الحفظ'));

                return null;
            }

            return out;
        } catch {
            setError(t('تعذّر الاتصال بالخادم'));

            return null;
        } finally {
            setBusy(false);
        }
    };

    const rename = async () => {
        const clean = name.trim();
        if (! clean) return;

        const out = await call(route('admin.products.categories.update', category.id), 'PATCH', { name: clean, name_en: nameEn.trim() || null });
        if (! out?.category) return;

        onRenamed({ ...category, name: String(out.category.name), name_en: out.category.name_en || null });
        setEditing(false);
    };

    const remove = async () => {
        if (! (await ask({ message: t('حذف فئة «:name»؟ ليس فيها منتجات.', { name: category.name }), action: 'حذف الفئة', danger: true }))) return;

        const out = await call(route('admin.products.categories.destroy', category.id), 'DELETE');
        if (out?.ok) onRemoved(category.id);
    };

    return (
        <>
            {! editing && (
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    disabled={busy}
                    onClick={() => {
                        setName(category.name);
                        setNameEn(category.name_en ?? '');
                        setEditing(true);
                    }}
                    aria-label={`${t('تعديل الاسم')} — ${category.name}`}
                    data-testid={`catalog-rename-${category.id}`}
                >
                    <Pencil />
                    {t('تعديل الاسم')}
                </Button>
            )}

            {/* والزرُّ للفئة التي لا يُعرض فيها شيء — وما فيها صنفٌ مخفيّ يردّه الخادمُ بسببه */}
            {category.shown_count === 0 && (
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    disabled={busy}
                    onClick={remove}
                    aria-label={`${t('حذف الفئة')} — ${category.name}`}
                    data-testid={`catalog-delete-${category.id}`}
                    className="text-[#b91c1c]"
                >
                    <Trash2 />
                    {t('حذف')}
                </Button>
            )}

            {editing && (
                <div className="grid w-full gap-2 sm:grid-cols-[1fr_1fr_auto]" data-testid={`catalog-rename-form-${category.id}`}>
                    <Input
                        autoFocus
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                rename();
                            }
                        }}
                        aria-label={`${t('اسم القسم')} — ${category.name}`}
                    />
                    <Input
                        dir="ltr"
                        value={nameEn}
                        onChange={(e) => setNameEn(e.target.value)}
                        placeholder={t('الاسم بالإنجليزية (اختياري)')}
                        aria-label={`${t('الاسم بالإنجليزية (اختياري)')} — ${category.name}`}
                    />
                    <div className="flex gap-2">
                        <Button type="button" size="sm" loading={busy} disabled={! name.trim()} onClick={rename}>
                            {t('حفظ')}
                        </Button>
                        <Button type="button" size="sm" variant="ghost" onClick={() => {
                            setEditing(false);
                            setError(null);
                        }}>
                            {t('إلغاء')}
                        </Button>
                    </div>
                </div>
            )}

            {error && (
                <p role="alert" className="flex w-full items-start gap-1.5 text-[12px] text-[#b91c1c]" data-testid={`catalog-naming-error-${category.id}`}>
                    <CircleAlert className="mt-0.5 size-3.5 shrink-0" />
                    {error}
                </p>
            )}

            {dialog}
        </>
    );
}

/**
 * «الفئات في الموقع» — كلُّ فئةٍ بعدد ما يُعرض فيها، وهل تظهر.
 *
 * ═══ والفئةُ تُضاف من هنا — بالباب القائم نفسِه ═══
 *
 * `admin.products.categories.store` هو ما ينادي به نموذجُ المنتج
 * (`ProductForm`): قواعدُه وتفرّدُ الاسم في النشاط وحصرُه بمتجر من سجّل
 * الدخول كلُّها هناك. وبـfetch لا بتنقّل: ما كُتب في المحرّر ولم يُحفظ لا
 * يُمحى لأجل فئة.
 *
 * والفئةُ الجديدة فارغة — فتُقال «لن تظهر» من أوّل لحظة، ولا يُنشأ لها صنف.
 *
 * وبطاقةُ كلِّ فئةٍ تُخفى وتلبس صورةً من صفّها (`CardControls`). وما
 * يتبدّل فيها يقع على الموقع لا على المسوّدة — فيُنادى `onCardChange`
 * لتُعاد المعاينة: الإطارُ يقرأ الفئاتِ من الخادم لا من النموذج.
 *
 * واسمُها يُعدَّل من صفّها، والفارغةُ تُحذف منه (`CategoryNaming`) — ومعاينةٌ
 * تُعاد بعدهما أيضًا: الاسمُ مكتوبٌ على البطاقة.
 *
 * ═══ والترتيبُ بسهمين لا بسحب ═══
 *
 * كما في «وصل حديثًا»: يعملان بالإصبع وبلوحة المفاتيح وبالقارئ الصوتيّ.
 * ويُكتب الترتيبُ كلُّه بعد كلّ نقلة (`admin.products.categories.order`)،
 * ويُعرض قبل أن يعود الجواب — فإن رُدّ عاد كما كان وقيل لماذا.
 */
export function CategoriesPanel({ categories, onCardChange }: { categories: CatalogCategory[]; onCardChange?: () => void }) {
    const t = useTranslate();

    const [list, setList] = useState(() => inStoreOrder(categories));
    const [moving, setMoving] = useState(false);
    const [orderError, setOrderError] = useState<string | null>(null);
    const [name, setName] = useState('');
    const [nameEn, setNameEn] = useState('');
    const [adding, setAdding] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    // وحفظٌ في المحرّر يُعيد الخصائص — فما يقوله الخادم هو الأحدث
    useEffect(() => setList(inStoreOrder(categories)), [categories]);

    const move = async (index: number, step: -1 | 1) => {
        const to = index + step;
        if (to < 0 || to >= list.length) return;

        const before = list;
        const after = [...list];
        [after[index], after[to]] = [after[to], after[index]];
        const numbered = after.map((c, i) => ({ ...c, sort_order: i + 1 }));

        setList(numbered);
        setMoving(true);
        setOrderError(null);
        try {
            const res = await fetch(route('admin.products.categories.order'), {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeaders() },
                body: JSON.stringify({ ids: numbered.map((c) => c.id) }),
            });
            const out = await res.json().catch(() => null);

            if (! res.ok) {
                setList(before);
                setOrderError(Object.values((out?.errors ?? {}) as Record<string, string[]>)[0]?.[0] ?? out?.message ?? t('تعذّر حفظ الترتيب'));

                return;
            }

            onCardChange?.();
        } catch {
            setList(before);
            setOrderError(t('تعذّر الاتصال بالخادم'));
        } finally {
            setMoving(false);
        }
    };

    const add = async () => {
        const clean = name.trim();
        if (! clean) return;

        setSaving(true);
        setError(null);
        try {
            const res = await fetch(route('admin.products.categories.store'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeaders() },
                body: JSON.stringify({ name: clean, name_en: nameEn.trim() || null }),
            });
            const body = await res.json().catch(() => null);

            if (! res.ok) {
                setError(
                    body?.errors?.name?.[0] ?? body?.errors?.name_en?.[0] ?? body?.message ?? t('تعذّر إضافة القسم'),
                );

                return;
            }

            const made: CatalogCategory = {
                id: Number(body.category.id),
                name: String(body.category.name),
                name_en: body.category.name_en || null,
                shown_count: 0,
                card_hidden: false,
                card_image: null,
                sort_order: null,
            };

            setList((prev) => inStoreOrder([...prev, made]));
            setName('');
            setNameEn('');
            setAdding(false);
        } catch {
            setError(t('تعذّر الاتصال بالخادم'));
        } finally {
            setSaving(false);
        }
    };

    return (
        <div data-testid="catalog-categories" className="space-y-3">
            <div>
                <h4 className="text-[13px] font-semibold text-[#111]">{t('الفئات في الموقع')}</h4>
                <p className="mt-0.5 text-[12px] leading-relaxed text-[#6b7280]">
                    {t('تظهر الفئة في الموقع عندما يكون فيها منتج مفعّل ومنشور.')}
                </p>
                <p className="mt-0.5 text-[12px] leading-relaxed text-[#6b7280]">
                    {t('ولكل فئة بطاقتها: ترتّبها بالسهمين، أو تخفيها من هذا القسم، أو تضع لها صورة — يُطبَّق على الموقع فورًا، ومنتجاتها تبقى في المتجر.')}
                </p>
            </div>

            {orderError && (
                <p role="alert" data-testid="catalog-order-error" className="flex items-start gap-1.5 text-[12px] text-[#b91c1c]">
                    <CircleAlert className="mt-0.5 size-3.5 shrink-0" />
                    {orderError}
                </p>
            )}

            {list.length === 0 ? (
                <p className="rounded-[10px] bg-white px-3 py-3 text-[12px] text-[#6b7280] ring-1 ring-[var(--ui-border,#e8e8e8)]">
                    {t('لا فئات بعد.')}
                </p>
            ) : (
                <ul className="divide-y divide-[var(--ui-border,#e8e8e8)] overflow-hidden rounded-[10px] bg-white ring-1 ring-[var(--ui-border,#e8e8e8)]">
                    {list.map((c, i) => {
                        const stocked = c.shown_count > 0;
                        const shows = stocked && ! c.card_hidden;

                        return (
                            <li
                                key={c.id}
                                data-testid={`catalog-category-${c.id}`}
                                className="flex flex-wrap items-center gap-x-3 gap-y-2 px-3 py-2.5"
                            >
                                <div className="flex shrink-0 items-center" data-testid={`catalog-order-${c.id}`}>
                                    <button
                                        type="button"
                                        aria-label={`${t('تحريك لأعلى')} ${c.name}`}
                                        disabled={moving || i === 0}
                                        onClick={() => move(i, -1)}
                                        className={arrowButton}
                                    >
                                        <ArrowUp className="size-4" />
                                    </button>
                                    <button
                                        type="button"
                                        aria-label={`${t('تحريك لأسفل')} ${c.name}`}
                                        disabled={moving || i === list.length - 1}
                                        onClick={() => move(i, 1)}
                                        className={arrowButton}
                                    >
                                        <ArrowDown className="size-4" />
                                    </button>
                                </div>
                                {c.card_image ? (
                                    <img
                                        src={c.card_image}
                                        alt=""
                                        className="h-10 w-[53px] shrink-0 rounded-[8px] object-cover ring-1 ring-[var(--ui-border,#e8e8e8)]"
                                        data-testid={`catalog-card-image-${c.id}`}
                                    />
                                ) : (
                                    <span className="flex h-10 w-[53px] shrink-0 items-center justify-center rounded-[8px] bg-[#f3efe9] text-[#9ca3af]">
                                        <ImageOff className="size-4" />
                                    </span>
                                )}
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-[13px] font-medium text-[#111]">
                                        {c.name}
                                        {c.name_en && (
                                            <span dir="ltr" className="ms-1.5 text-[12px] font-normal text-[#9ca3af]">
                                                {c.name_en}
                                            </span>
                                        )}
                                    </p>
                                    <p
                                        className={cn(
                                            'mt-0.5 flex items-center gap-1 text-[12px]',
                                            shows ? 'text-[#047857]' : 'text-[#b45309]',
                                        )}
                                    >
                                        {shows ? <Eye className="size-3.5 shrink-0" /> : <EyeOff className="size-3.5 shrink-0" />}
                                        {shows
                                            ? t('تظهر في الموقع')
                                            : c.card_hidden
                                              ? t('مخفيّة من «تسوّق حسب الفئة» — منتجاتها تبقى في المتجر')
                                              : t('لن تظهر — لا يوجد منتج مفعّل ومنشور')}
                                        {stocked && (
                                            <span className="text-[#6b7280]">
                                                · {t(':count منتج مفعّل ومنشور', { count: c.shown_count })}
                                            </span>
                                        )}
                                    </p>
                                </div>
                                <Link
                                    href={route('admin.products.index', { category: c.name })}
                                    className="inline-flex shrink-0 items-center gap-1 text-[12px] font-medium text-[#111] underline"
                                >
                                    {t('عرض المنتجات')}
                                    <ChevronLeft className="size-3.5" />
                                </Link>
                                <CardControls
                                    category={c}
                                    onSaved={(saved) => {
                                        setList((prev) => prev.map((x) => (x.id === saved.id ? saved : x)));
                                        onCardChange?.();
                                    }}
                                >
                                    <CategoryNaming
                                        category={c}
                                        onRenamed={(renamed) => {
                                            // وما لم يُرتَّب يقع بالاسم — فالاسمُ الجديد قد ينقله
                                            setList((prev) => inStoreOrder(prev.map((x) => (x.id === renamed.id ? renamed : x))));
                                            onCardChange?.();
                                        }}
                                        onRemoved={(id) => {
                                            setList((prev) => prev.filter((x) => x.id !== id));
                                            onCardChange?.();
                                        }}
                                    />
                                </CardControls>
                            </li>
                        );
                    })}
                </ul>
            )}

            {adding && (
                <div data-testid="catalog-add-category" className="space-y-2 rounded-[10px] bg-white p-3 ring-1 ring-[var(--ui-border,#e8e8e8)]">
                    <div className="grid gap-2 sm:grid-cols-2">
                        <Input
                            autoFocus
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    add();
                                }
                            }}
                            placeholder={t('اسم القسم')}
                            aria-label={t('اسم القسم')}
                        />
                        <Input
                            dir="ltr"
                            value={nameEn}
                            onChange={(e) => setNameEn(e.target.value)}
                            placeholder={t('الاسم بالإنجليزية (اختياري)')}
                            aria-label={t('الاسم بالإنجليزية (اختياري)')}
                        />
                    </div>
                    {error && (
                        <p role="alert" className="flex items-start gap-1.5 text-[12px] text-[#b91c1c]">
                            <CircleAlert className="mt-0.5 size-3.5 shrink-0" />
                            {error}
                        </p>
                    )}
                    <div className="flex flex-wrap justify-end gap-2">
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => {
                                setAdding(false);
                                setError(null);
                            }}
                        >
                            {t('إلغاء')}
                        </Button>
                        <Button type="button" size="sm" loading={saving} disabled={! name.trim()} onClick={add}>
                            {t('إضافة')}
                        </Button>
                    </div>
                </div>
            )}

            <div className="flex flex-wrap gap-2">
                {! adding && (
                    <Button type="button" size="sm" variant="outline" onClick={() => setAdding(true)}>
                        <Plus />
                        {t('إضافة قسم')}
                    </Button>
                )}
                <Door href={route('admin.products.create')}>
                    <Plus />
                    {t('إضافة منتج')}
                </Door>
                <Door href={route('admin.products.index')}>{t('إدارة كل المنتجات')}</Door>
            </div>
        </div>
    );
}

/**
 * «وصل حديثًا» — ما تعرضه الواجهةُ الآن، أو ما يختاره صاحبُه بيده.
 *
 * التلقائيّ: أحدثُ أربعة أصنافٍ مفعَّلةٍ منشورة بترتيب إضافتها. وتعديلُ صنفٍ
 * قديم لا يرفعه — ويُقال له ذلك كي لا ينتظره.
 *
 * واليدويّ لمن في قائمته وحده (`curation`): حتّى أربعةٍ بترتيبه، بسهمَين
 * لكلّ صنف لا بسحب — يعملان بالإصبع وبلوحة المفاتيح وبالقارئ الصوتيّ.
 * ومن لم يصله `curation` يرى اللوحةَ عرضًا كما كانت.
 */
export function NewArrivalsPanel({ items, curation = null }: { items: CatalogArrival[]; curation?: ArrivalsCuration | null }) {
    const t = useTranslate();
    const manual = curation?.mode === 'manual';

    return (
        <div data-testid="catalog-new-arrivals" className="space-y-3">
            {curation && <ModePicker curation={curation} items={items} />}

            {manual ? (
                <ManualArrivals curation={curation!} />
            ) : (
                <AutoArrivals items={items} />
            )}

            {curation && (
                <div className="space-y-2">
                    {curation.error && (
                        <p role="alert" data-testid="catalog-arrivals-error" className="flex items-start gap-1.5 text-[12px] text-[#b91c1c]">
                            <CircleAlert className="mt-0.5 size-3.5 shrink-0" />
                            {curation.error}
                        </p>
                    )}
                    <div className="flex justify-end">
                        <Button
                            type="button"
                            size="sm"
                            data-testid="catalog-arrivals-save"
                            loading={curation.saving}
                            disabled={manual && curation.ids.length === 0}
                            onClick={curation.onSave}
                        >
                            <Save />
                            {t('احفظ')}
                        </Button>
                    </div>
                </div>
            )}

            <div className="flex flex-wrap gap-2">
                <Door href={route('admin.products.create')}>
                    <Plus />
                    {t('إضافة منتج جديد')}
                </Door>
                <Door href={route('admin.products.index')}>{t('إدارة المنتجات')}</Door>
            </div>
        </div>
    );
}

/**
 * تلقائيٌّ أم يدويّ.
 *
 * ومن ينتقل إلى اليدويّ وقائمتُه فارغة تبدأ له بما يعرضه القسمُ الآن —
 * فيرتّب ما يراه لا صفحةً بيضاء.
 */
function ModePicker({ curation, items }: { curation: ArrivalsCuration; items: CatalogArrival[] }) {
    const t = useTranslate();

    const pick = (mode: ArrivalsMode) => {
        if (mode === curation.mode) return;

        if (mode === 'manual' && curation.ids.length === 0) {
            const shown = new Set(curation.products.map((p) => p.id));
            curation.onChange(mode, items.map((p) => p.id).filter((id) => shown.has(id)).slice(0, NEW_ARRIVALS_MAX));

            return;
        }

        curation.onChange(mode, curation.ids);
    };

    const option = (mode: ArrivalsMode, label: string) => (
        <label
            className={cn(
                'flex min-h-11 cursor-pointer items-center gap-2.5 rounded-[10px] bg-white px-3 py-2 text-[13px] ring-1',
                curation.mode === mode ? 'text-[#111] ring-[#111]' : 'text-[#374151] ring-[var(--ui-border,#e8e8e8)]',
            )}
        >
            <input
                type="radio"
                name="store_new_arrivals_mode"
                value={mode}
                checked={curation.mode === mode}
                onChange={() => pick(mode)}
                className="size-4 accent-[#111]"
            />
            {t(label)}
        </label>
    );

    return (
        <fieldset data-testid="catalog-arrivals-mode" className="space-y-2">
            <legend className="mb-2 text-[13px] font-semibold text-[#111]">{t('طريقة العرض')}</legend>
            <div className="grid gap-2 sm:grid-cols-2">
                {option('auto', 'تلقائي — آخر 4 منتجات أضفتها')}
                {option('manual', 'اختيار يدوي')}
            </div>
        </fieldset>
    );
}

/** صورةُ صنفٍ صغيرة — أو علامةُ «بلا صورة» */
function Thumb({ image }: { image: string | null }) {
    return image ? (
        <img src={image} alt="" loading="lazy" className="size-10 shrink-0 rounded-lg object-cover" />
    ) : (
        <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-[#f3f4f6] text-[#c4c4c4]">
            <ImageOff className="size-4" />
        </span>
    );
}

/** اليدويّ: المختارُ مرقّمًا بسهمَيه، وتحته ما يُضاف */
function ManualArrivals({ curation }: { curation: ArrivalsCuration }) {
    const t = useTranslate();
    const byId = new Map(curation.products.map((p) => [p.id, p]));
    const chosen = curation.ids.map((id) => byId.get(id)).filter((p): p is ArrivalProduct => p !== undefined);
    const rest = curation.products.filter((p) => ! curation.ids.includes(p.id));
    const full = chosen.length >= NEW_ARRIVALS_MAX;

    const write = (ids: number[]) => curation.onChange('manual', ids);

    const move = (i: number, by: number) => {
        const j = i + by;
        if (j < 0 || j >= chosen.length) return;

        const next = chosen.map((p) => p.id);
        [next[i], next[j]] = [next[j], next[i]];
        write(next);
    };

    const iconButton = 'flex size-11 shrink-0 items-center justify-center rounded-[8px] text-[#6b7280] enabled:hover:bg-[#f3f4f6] disabled:opacity-30';

    return (
        <div className="space-y-3">
            <p className="text-[12px] leading-relaxed text-[#6b7280]">
                {t('اختر حتى 4 منتجات ورتّبها كما تريد أن تظهر في «وصل حديثًا».')}
            </p>

            {chosen.length === 0 ? (
                <p
                    data-testid="catalog-arrivals-none"
                    className="rounded-[10px] bg-[#fffbeb] px-3 py-3 text-[12px] text-[#b45309]"
                >
                    {t('اختر منتجًا واحدًا على الأقل أو ارجع إلى الترتيب التلقائي.')}
                </p>
            ) : (
                <ol data-testid="catalog-arrivals-chosen" className="divide-y divide-[var(--ui-border,#e8e8e8)] overflow-hidden rounded-[10px] bg-white ring-1 ring-[var(--ui-border,#e8e8e8)]">
                    {chosen.map((p, i) => (
                        <li key={p.id} data-testid={`catalog-chosen-${p.id}`} className="flex items-center gap-2 px-2 py-1.5">
                            <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-[#111] text-[11px] font-semibold text-white">
                                {i + 1}
                            </span>
                            <Thumb image={p.image} />
                            <span className="min-w-0 flex-1 truncate text-[13px] text-[#111]">{p.name}</span>
                            <button
                                type="button"
                                aria-label={`${t('تحريك لأعلى')} ${p.name}`}
                                disabled={i === 0}
                                onClick={() => move(i, -1)}
                                className={iconButton}
                            >
                                <ArrowUp className="size-4" />
                            </button>
                            <button
                                type="button"
                                aria-label={`${t('تحريك لأسفل')} ${p.name}`}
                                disabled={i === chosen.length - 1}
                                onClick={() => move(i, 1)}
                                className={iconButton}
                            >
                                <ArrowDown className="size-4" />
                            </button>
                            <button
                                type="button"
                                aria-label={`${t('إزالة من وصل حديثًا')} ${p.name}`}
                                onClick={() => write(chosen.filter((c) => c.id !== p.id).map((c) => c.id))}
                                className={iconButton}
                            >
                                <X className="size-4" />
                            </button>
                        </li>
                    ))}
                </ol>
            )}

            {rest.length > 0 && (
                <div className="space-y-1.5">
                    <p className="text-[12px] font-medium text-[#374151]">
                        {full ? t('اخترت 4 منتجات — أزل واحدًا لتضيف غيره.') : t('منتجات يمكن إضافتها')}
                    </p>
                    <ul data-testid="catalog-arrivals-rest" className="max-h-[260px] divide-y divide-[var(--ui-border,#e8e8e8)] overflow-y-auto rounded-[10px] bg-white ring-1 ring-[var(--ui-border,#e8e8e8)]">
                        {rest.map((p) => (
                            <li key={p.id} data-testid={`catalog-rest-${p.id}`} className="flex items-center gap-2 px-2 py-1.5">
                                <Thumb image={p.image} />
                                <span className="min-w-0 flex-1 truncate text-[13px] text-[#111]">{p.name}</span>
                                <button
                                    type="button"
                                    aria-label={`${t('إضافة إلى وصل حديثًا')} ${p.name}`}
                                    disabled={full}
                                    onClick={() => write([...chosen.map((c) => c.id), p.id])}
                                    className={iconButton}
                                >
                                    <Plus className="size-4" />
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}

/** التلقائيّ — كما كانت اللوحةُ قبل الاختيار اليدويّ */
function AutoArrivals({ items }: { items: CatalogArrival[] }) {
    const t = useTranslate();

    return (
        <div className="space-y-3">
            <div className="space-y-1 text-[12px] leading-relaxed text-[#6b7280]">
                <p>{t('يظهر هنا تلقائيًا آخر 4 منتجات مفعّلة ومنشورة أضفتها إلى المتجر.')}</p>
                <p>{t('تعديل منتج قديم لا يجعله «وصل حديثًا»؛ الترتيب حسب وقت إضافته للنظام.')}</p>
            </div>

            {items.length === 0 ? (
                <p
                    data-testid="catalog-new-arrivals-empty"
                    className="rounded-[10px] bg-white px-3 py-3 text-[12px] text-[#6b7280] ring-1 ring-[var(--ui-border,#e8e8e8)]"
                >
                    {t('لا يوجد منتج مفعّل ومنشور بعد — أضف منتجًا ليظهر هنا.')}
                </p>
            ) : (
                <ol className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    {items.map((p, i) => (
                        <li
                            key={p.id}
                            data-testid={`catalog-arrival-${p.id}`}
                            className="relative overflow-hidden rounded-[10px] bg-white ring-1 ring-[var(--ui-border,#e8e8e8)]"
                        >
                            <span className="absolute start-1.5 top-1.5 z-10 flex size-5 items-center justify-center rounded-full bg-[#111] text-[11px] font-semibold text-white">
                                {i + 1}
                            </span>
                            {p.image ? (
                                <img src={p.image} alt="" loading="lazy" className="aspect-square w-full object-cover" />
                            ) : (
                                <div className="flex aspect-square w-full items-center justify-center bg-[#f3f4f6] text-[#c4c4c4]">
                                    <ImageOff className="size-5" />
                                </div>
                            )}
                            <div className="px-2 py-1.5">
                                <p className="truncate text-[12px] font-medium text-[#111]">{p.name}</p>
                                {p.category && <p className="truncate text-[11px] text-[#9ca3af]">{p.category}</p>}
                            </div>
                        </li>
                    ))}
                </ol>
            )}
        </div>
    );
}
