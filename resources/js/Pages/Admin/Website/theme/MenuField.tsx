import { useState } from 'react';
import {
    ArrowDown, ArrowUp, ChevronDown, CircleAlert, Eye, EyeOff, FileText, Heading, House, Images, Link2, PanelsTopLeft,
    Plus, RotateCcw, Shapes, ShoppingBag, Trash2, X,
} from 'lucide-react';

import { useConfirm } from '@/Components/ConfirmDialog';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { StoreCollection } from './CollectionsField';
import PageContent from './PageContent';
import { blankPage, type CustomPage, type PageKind, parsePages, serializePages } from './pages';
import type { ListProduct } from './ProductListField';
import { parseTarget, reaches, type StoreTargetValue, type TargetSources, TargetSelect } from './StoreTargets';

/**
 * القائمةُ الرئيسيّة وصفحاتُها — الرئيسية | المتجر | المناسبات | عروضنا | من نحن.
 *
 * ═══ «+ إضافة قسم أو صفحة» — ثمّ الاسمُ وحده ═══
 *
 * يختار صاحبُ المتجر ما يريد ببطاقة: عنوانٌ في القائمة لا يُضغط، أو صفحةُ كتابة،
 * أو صفحةُ صور، أو كتابةٌ وصور، أو صفحةُ منتجات، أو محتوى متنوّع، أو رابط. ثمّ
 * يكتب اسمَه — والإنجليزيُّ اختياريّ — ويضغط «أضف». فلا رابطَ يُطلب إلّا في
 * «رابط»، والصفحةُ تُنشأ برابطها الداخليّ وحدها ويُكتب محتواها في مكانها هنا.
 *
 * ═══ والعنوانُ غيرُ صفحة الكتابة ═══
 *
 * «عنوان فقط» كلمةٌ تُقرأ في القائمة بلا رابط (`label`). و«صفحة كتابة» رابطٌ إلى
 * صفحةٍ فيها عنوانٌ وفقرات. ولكلٍّ بطاقتُه — لا يُخلط أحدُهما بالآخر.
 *
 * ═══ والصفحاتُ الأربعُ تُرتَّب ولا تُحذف، والحذفُ لا يُضيّع صفحة ═══
 *
 * إزالةُ قسمٍ له صفحةٌ تُخرجه من القائمة وحدها، وصفحتُه تبقى تحت «صفحات ليست في
 * القائمة» — تُعاد أو تُحذف من هناك بتأكيد. والقيمتان نصّا JSON: القائمةُ
 * (`store_menu`) والصفحاتُ (`store_custom_pages`)، يقرؤهما الخادمُ بصرامة.
 */

export type BuiltIn = 'home' | 'shop' | 'about' | 'contact';

export const BUILT_INS: BuiltIn[] = ['home', 'shop', 'about', 'contact'];

export interface MenuLink {
    type: 'link';
    id: string;
    title_ar: string;
    title_en: string;
    target: StoreTargetValue;
    visible: boolean;
}

/** عنوانٌ في القائمة لا يُضغط — اسمٌ بلا وجهة */
export interface MenuLabel {
    type: 'label';
    id: string;
    title_ar: string;
    title_en: string;
    visible: boolean;
}

export type MenuItem = { type: 'page'; key: BuiltIn } | MenuLink | MenuLabel;

/** ما يُختار من «+ إضافة قسم أو صفحة» */
export type NewItem = 'label' | PageKind | 'link';

const BUILT_IN_LABEL: Record<BuiltIn, string> = { home: 'الرئيسية', shop: 'المتجر', about: 'من نحن', contact: 'تواصل معنا' };

const DEFAULT: MenuItem[] = BUILT_INS.map((key) => ({ type: 'page', key }));

export const NEW_ITEMS: { type: NewItem; label: string; hint: string; icon: typeof FileText }[] = [
    { type: 'label', label: 'عنوان فقط', hint: 'اسمٌ يظهر في القائمة ولا يُضغط', icon: Heading },
    { type: 'text', label: 'صفحة كتابة', hint: 'عنوانٌ ووصفٌ وفقرات — بلا صورة', icon: FileText },
    { type: 'images', label: 'صفحة صور', hint: 'صورةٌ أو أكثر تظهر كاملة', icon: Images },
    { type: 'textimage', label: 'صفحة كتابة وصور', hint: 'أقسامٌ من نصٍّ وصورة', icon: PanelsTopLeft },
    { type: 'products', label: 'صفحة منتجات', hint: 'تختار منتجاتها بصورها وترتّبها', icon: ShoppingBag },
    { type: 'mixed', label: 'صفحة محتوى متنوّع', hint: 'عناوين ونصوص وصور ومنتجات وأزرار', icon: Shapes },
    { type: 'link', label: 'رابط', hint: 'إلى صفحةٍ أو مجموعةٍ أو منتج، أو رابطٍ تكتبه', icon: Link2 },
];

/** النصُّ المحفوظ ← قائمة — والفراغُ القائمةُ القديمة، والغائبُ من الأربع يُلحق آخرَها */
export function parseMenu(raw: string | undefined): MenuItem[] {
    let list: unknown = null;

    try {
        list = raw ? JSON.parse(raw) : null;
    } catch {
        list = null;
    }

    if (! Array.isArray(list) || list.length === 0) return DEFAULT.map((i) => ({ ...i }));

    const out: MenuItem[] = [];
    const seen = new Set<string>();

    for (const item of list) {
        if (! item || typeof item !== 'object') continue;

        const i = item as Record<string, unknown>;

        if (i.type === 'page' && BUILT_INS.includes(i.key as BuiltIn) && ! seen.has(`page:${i.key}`)) {
            seen.add(`page:${i.key}`);
            out.push({ type: 'page', key: i.key as BuiltIn });
            continue;
        }

        const id = String(i.id ?? '');

        if ((i.type !== 'link' && i.type !== 'label') || ! /^[a-z0-9]{1,16}$/.test(id) || seen.has(`item:${id}`)) continue;

        const title = { title_ar: String(i.title_ar ?? ''), title_en: String(i.title_en ?? '') };

        if (i.type === 'label') {
            seen.add(`item:${id}`);
            out.push({ type: 'label', id, ...title, visible: i.visible !== false });
            continue;
        }

        const target = parseTarget(i.target);

        if (! target) continue;

        seen.add(`item:${id}`);
        out.push({ type: 'link', id, ...title, target, visible: i.visible !== false });
    }

    for (const key of BUILT_INS) {
        if (! seen.has(`page:${key}`)) out.push({ type: 'page', key });
    }

    return out;
}

/** القائمة ← نصٌّ يُحفظ. والقديمةُ نفسُها بترتيبها وبلا عنصرٍ فراغ */
export function serializeMenu(items: MenuItem[]): string {
    const plain = items.length === BUILT_INS.length && items.every((i, k) => i.type === 'page' && i.key === BUILT_INS[k]);

    return plain ? '' : JSON.stringify(items);
}

/** معرّفُ عنصرٍ لم يُؤخذ — حروفٌ صغيرةٌ وأرقام */
export function freshLinkId(taken: string[]): string {
    let id = '';
    do id = 'l' + Math.random().toString(36).slice(2, 9); while (taken.includes(id));

    return id;
}

/** صفحاتٌ لا يقود إليها عنصرٌ في القائمة — تُعرض ليُعاد إليها أو تُحذف */
export function orphanPages(items: MenuItem[], pages: CustomPage[]): CustomPage[] {
    const linked = new Set(items.flatMap((i) => (i.type === 'link' && i.target.type === 'page' ? [String(i.target.value)] : [])));

    return pages.filter((p) => ! linked.has(p.slug));
}

export default function MenuField({
    label,
    hint,
    value,
    pagesValue,
    sources,
    products,
    hidden = [],
    collections,
    max,
    maxPages,
    maxBlocks,
    maxProducts = 40,
    onChange,
    onPagesChange,
    onPreviewPage,
    error,
}: {
    label: string;
    hint?: string;
    value: string;
    /** الصفحاتُ المخصّصة نصَّ JSON — تُكتب من هنا مع عناصرها */
    pagesValue: string;
    /** ما يُختار وجهةً — كما في الشاشة الآن، ولو لم يُحفظ بعد */
    sources: TargetSources;
    products: ListProduct[];
    hidden?: ListProduct[];
    collections: StoreCollection[];
    max: number;
    maxPages: number;
    maxBlocks: number;
    maxProducts?: number;
    onChange: (value: string) => void;
    onPagesChange: (value: string) => void;
    /** يعاين الصفحةَ في إطار المعاينة — قبل الحفظ */
    onPreviewPage?: (slug: string) => void;
    error?: string;
}) {
    const t = useTranslate();
    const [ask, confirmDialog] = useConfirm();
    const items = parseMenu(value);
    const pages = parsePages(pagesValue);
    const custom = items.filter((i): i is MenuLink | MenuLabel => i.type !== 'page');
    const [open, setOpen] = useState<string | null>(null);
    const [adding, setAdding] = useState<NewItem | 'choose' | null>(null);
    const [name, setName] = useState({ ar: '', en: '' });
    const [newTarget, setNewTarget] = useState<StoreTargetValue>({ type: 'shop', value: '' });
    const [orphanOpen, setOrphanOpen] = useState<string | null>(null);
    const [retyping, setRetyping] = useState<string | null>(null);

    const write = (next: MenuItem[]) => onChange(serializeMenu(next));
    const writePages = (next: CustomPage[]) => onPagesChange(serializePages(next));
    const pageOf = (i: MenuItem): CustomPage | undefined =>
        i.type === 'link' && i.target.type === 'page' ? pages.find((p) => p.slug === i.target.value) : undefined;

    const patchItem = (id: string, changes: Partial<MenuLink> | Partial<MenuLabel>) =>
        write(items.map((i) => (i.type !== 'page' && i.id === id ? ({ ...i, ...changes } as MenuItem) : i)));

    const patchPage = (slug: string, next: CustomPage) => writePages(pages.map((p) => (p.slug === slug ? next : p)));

    const move = (at: number, by: number) => {
        const j = at + by;
        if (j < 0 || j >= items.length) return;

        const next = [...items];
        [next[at], next[j]] = [next[j], next[at]];
        write(next);
    };

    /** وموضعُ الجديد قبل «من نحن» — بعد الرئيسية والمتجر حيث يُتوقَّع */
    const insert = (item: MenuItem): MenuItem[] => {
        const at = items.findIndex((i) => i.type === 'page' && i.key === 'about');
        const next = [...items];
        next.splice(at < 0 ? next.length : at, 0, item);

        return next;
    };

    const full = custom.length >= max;
    const pagesFull = pages.length >= maxPages;
    const named = name.ar.trim() !== '' || name.en.trim() !== '';

    const startAdding = () => {
        setName({ ar: '', en: '' });
        setNewTarget({ type: 'shop', value: '' });
        setAdding('choose');
    };

    const confirmAdd = () => {
        if (! named || adding === null || adding === 'choose') return;

        const id = freshLinkId(custom.map((i) => i.id));
        const title = { title_ar: name.ar.trim(), title_en: name.en.trim() };

        if (adding === 'label') {
            write(insert({ type: 'label', id, ...title, visible: true }));
        } else if (adding === 'link') {
            write(insert({ type: 'link', id, ...title, target: newTarget, visible: true }));
        } else {
            // والصفحةُ تُنشأ بنوعها واسمها — ورابطُها الداخليّ وحده
            const page = blankPage(pages.map((p) => p.slug), { ar: title.title_ar, en: title.title_en }, adding);

            onPagesChange(serializePages([...pages, page]));
            write(insert({ type: 'link', id, ...title, target: { type: 'page', value: page.slug }, visible: true }));
        }

        setAdding(null);
        setOpen(id);
    };

    /** اسمُ القسم يتبعه عنوانُ صفحته — ما دام العنوانُ اسمَه القديم أو فارغًا */
    const rename = (item: MenuLink | MenuLabel, lang: 'ar' | 'en', text: string) => {
        const key = lang === 'ar' ? 'title_ar' : 'title_en';
        const page = pageOf(item);

        patchItem(item.id, { [key]: text });

        if (page && (page[key] === '' || page[key] === item[key])) {
            // والكتابتان في دفعةٍ واحدة لا تُرسل إحداهما قبل الأخرى — كلٌّ في مفتاحه
            patchPage(page.slug, { ...page, [key]: text });
        }
    };

    const remove = async (item: MenuLink | MenuLabel) => {
        const page = pageOf(item);
        const title = item.title_ar || item.title_en;
        // ونافذةُ التأكيد تترجم رسالتَها بنفسها — فتُرسَل كما كُتبت مع بدائلها
        const go = await ask(page
            ? {
                title: 'إزالة من القائمة',
                message: 'إزالة «:name» من القائمة؟ صفحتُه تبقى محفوظة تحت «صفحات ليست في القائمة» — تُعيدها أو تحذفها من هناك.',
                values: { name: title },
                action: 'أزل من القائمة',
            }
            : {
                title: 'حذف من القائمة',
                message: 'حذف «:name» من القائمة؟ لا يُحذف معه منتجٌ ولا مجموعة.',
                values: { name: title },
                action: 'احذف',
                danger: true,
            });

        if (! go) return;

        write(items.filter((i) => ! (i.type !== 'page' && i.id === item.id)));
        setOpen(null);
    };

    /**
     * تغييرُ نوع القسم بعد إنشائه — ولا يضيع شيءٌ بلا سؤال.
     *
     * والعنوانُ والرابطُ يصيران صفحةً بنوعها واسمِهما؛ والصفحةُ تصير عنوانًا أو
     * رابطًا وتبقى هي محفوظةً تحت «صفحات ليست في القائمة». ووجهةُ رابطٍ تذهب
     * حين يصير غيرَ رابط — فيُسأل عنها. وبين أنواع الصفحة يتبدّل النوعُ في محرّرها
     * (`PageContent`) بقاعدته.
     */
    const retype = async (item: MenuLink | MenuLabel, to: NewItem) => {
        const page = pageOf(item);
        const name = item.title_ar || item.title_en;
        const title = { title_ar: item.title_ar, title_en: item.title_en };
        const linkGoes = item.type === 'link' && ! page && to !== 'link';

        if (page || linkGoes) {
            const go = await ask({
                title: 'تغيير نوع القسم',
                message: page
                    ? 'تحويل «:name»؟ صفحتُه تبقى محفوظة تحت «صفحات ليست في القائمة» — تُعيدها من هناك متى شئت.'
                    : 'تحويل «:name»؟ ما يفتحه الرابط الآن يُزال منه.',
                values: { name },
                action: 'حوّل',
            });

            if (! go) return;
        }

        const swap = (next: MenuItem) => write(items.map((i) => (i.type !== 'page' && i.id === item.id ? next : i)));

        if (to === 'label') {
            swap({ type: 'label', id: item.id, ...title, visible: item.visible });
        } else if (to === 'link') {
            swap({ type: 'link', id: item.id, ...title, target: { type: 'shop', value: '' }, visible: item.visible });
        } else {
            const fresh = blankPage(pages.map((p) => p.slug), { ar: item.title_ar, en: item.title_en }, to);

            onPagesChange(serializePages([...pages, fresh]));
            swap({ type: 'link', id: item.id, ...title, target: { type: 'page', value: fresh.slug }, visible: item.visible });
        }

        setRetyping(null);
    };

    const deletePage = async (page: CustomPage) => {
        const go = await ask({
            title: 'حذف الصفحة',
            message: 'حذف صفحة «:name» نهائيًّا؟ لا يُحذف معها منتجٌ ولا مجموعة، والأزرارُ التي تقود إليها تختفي.',
            values: { name: page.title_ar || page.title_en },
            action: 'احذف الصفحة',
            danger: true,
        });

        if (go) writePages(pages.filter((p) => p.slug !== page.slug));
    };

    const restore = (page: CustomPage) => {
        if (full) return;

        const id = freshLinkId(custom.map((i) => i.id));
        write(insert({ type: 'link', id, title_ar: page.title_ar, title_en: page.title_en, target: { type: 'page', value: page.slug }, visible: page.visible }));
        setOpen(id);
    };

    const pageState = (key: BuiltIn): string | null => {
        const p = sources.storePages.find((x) => x.key === key);

        if (! p) return null;
        if (! p.on) return t('مطفأة — تُشغَّل من شاشة «الصفحات»');

        return p.blocked ? t(p.blocked) : null;
    };

    const kindName = (kind: PageKind) => t(NEW_ITEMS.find((n) => n.type === kind)?.label ?? '');
    const orphans = orphanPages(items, pages);
    const iconButton = 'flex size-10 shrink-0 items-center justify-center rounded-[8px] text-[#6b7280] enabled:hover:bg-[#f3f4f6] disabled:opacity-30';

    const pageEditor = (page: CustomPage, testid: string) => (
        <PageContent
            page={page}
            onChange={(next) => patchPage(page.slug, next)}
            sources={sources}
            products={products}
            hidden={hidden}
            collections={collections}
            maxBlocks={maxBlocks}
            maxProducts={maxProducts}
            onPreview={onPreviewPage ? () => onPreviewPage(page.slug) : undefined}
            testid={testid}
        />
    );

    return (
        <Field label={label} hint={hint} error={error}>
            <div className="space-y-3" data-testid="menu">
                {confirmDialog}
                <ol className="divide-y divide-[var(--ui-border,#e8e8e8)] overflow-hidden rounded-[12px] bg-white ring-1 ring-[var(--ui-border,#e8e8e8)]">
                    {items.map((item, at) => {
                        if (item.type === 'page') {
                            const state = pageState(item.key);

                            return (
                                <li key={`page:${item.key}`} className="flex items-center gap-1.5 px-2 py-1.5" data-testid={`menu-page-${item.key}`}>
                                    <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-[#f3f4f6] text-[11px] font-semibold text-[#111]">{at + 1}</span>
                                    <House className="size-3.5 shrink-0 text-[#9ca3af]" />
                                    <span className="min-w-0 flex-1 px-1">
                                        <span className={cn('block truncate text-[13px] font-medium', state ? 'text-[#9ca3af]' : 'text-[#111]')}>{t(BUILT_IN_LABEL[item.key])}</span>
                                        {state && <span className="block truncate text-[11px] text-[#b45309]" data-testid={`menu-page-${item.key}-state`}>{state}</span>}
                                    </span>
                                    <button type="button" aria-label={`${t('تحريك لأعلى')} ${t(BUILT_IN_LABEL[item.key])}`} disabled={at === 0} onClick={() => move(at, -1)} className={iconButton}>
                                        <ArrowUp className="size-4" />
                                    </button>
                                    <button type="button" aria-label={`${t('تحريك لأسفل')} ${t(BUILT_IN_LABEL[item.key])}`} disabled={at === items.length - 1} onClick={() => move(at, 1)} className={iconButton}>
                                        <ArrowDown className="size-4" />
                                    </button>
                                </li>
                            );
                        }

                        const title = item.title_ar || item.title_en;
                        const expanded = open === item.id;
                        const page = pageOf(item);
                        const ok = item.type === 'label' || reaches(item.target, sources);
                        const Icon = item.type === 'label' ? Heading : page ? FileText : Link2;
                        const kind = item.type === 'label'
                            ? t('عنوان فقط — لا يُضغط')
                            : page ? `${t('صفحة')} · ${kindName(page.kind)}` : t('رابط');

                        return (
                            <li key={`item:${item.id}`} className="bg-white" data-testid={`menu-item-${at}`} data-kind={item.type === 'label' ? 'label' : page ? 'page' : 'link'}>
                                <div className="flex items-center gap-1.5 px-2 py-1.5">
                                    <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-[#111] text-[11px] font-semibold text-white">{at + 1}</span>
                                    <button type="button" onClick={() => setOpen(expanded ? null : item.id)} aria-expanded={expanded} className="min-w-0 flex-1 px-1 text-start" data-testid={`menu-item-${at}-open`}>
                                        <span className={cn('flex items-center gap-1 truncate text-[13px] font-medium', item.visible ? 'text-[#111]' : 'text-[#9ca3af]')}>
                                            <Icon className="size-3.5 shrink-0 text-[#9ca3af]" />
                                            {title || t('بلا اسم')}
                                        </span>
                                        <span className="block truncate text-[11px] text-[#9ca3af]">{kind}</span>
                                        {! ok && (
                                            <span className="flex items-center gap-1 text-[11px] text-[#b45309]" data-testid={`menu-item-${at}-unreachable`}>
                                                <CircleAlert className="size-3" />
                                                {t('وجهتُه لا تُفتح الآن — فلا يظهر')}
                                            </span>
                                        )}
                                    </button>
                                    <button
                                        type="button"
                                        role="switch"
                                        aria-checked={item.visible}
                                        aria-label={`${t('ظاهر لزبائنك')} ${title}`}
                                        data-testid={`menu-item-${at}-visible`}
                                        onClick={() => {
                                            patchItem(item.id, { visible: ! item.visible });
                                            // وظهورُ القسم ظهورُ صفحته — مفتاحٌ واحدٌ لا اثنان
                                            if (page) patchPage(page.slug, { ...page, visible: ! item.visible });
                                        }}
                                        className={iconButton}
                                    >
                                        {item.visible ? <Eye className="size-4" /> : <EyeOff className="size-4" />}
                                    </button>
                                    <button type="button" aria-label={`${t('تحريك لأعلى')} ${title}`} disabled={at === 0} onClick={() => move(at, -1)} className={iconButton}>
                                        <ArrowUp className="size-4" />
                                    </button>
                                    <button type="button" aria-label={`${t('تحريك لأسفل')} ${title}`} disabled={at === items.length - 1} onClick={() => move(at, 1)} className={iconButton}>
                                        <ArrowDown className="size-4" />
                                    </button>
                                    <ChevronDown className={cn('size-4 shrink-0 text-[#d1d5db] transition-transform', expanded && 'rotate-180')} />
                                </div>

                                {expanded && (
                                    <div className="space-y-4 border-t border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] px-3 py-4">
                                        {/* ونوعُ القسم يُبدَّل بعد إنشائه — والصفحةُ لا تضيع */}
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="text-[12px] text-[#6b7280]">{t('نوع القسم:')} <span className="font-medium text-[#111]">{kind}</span></span>
                                            <Button type="button" variant="ghost" size="sm" onClick={() => setRetyping(retyping === item.id ? null : item.id)} data-testid={`menu-item-${at}-retype`}>
                                                {t('تغيير النوع')}
                                            </Button>
                                        </div>
                                        {retyping === item.id && (
                                            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2" data-testid={`menu-item-${at}-types`}>
                                                {NEW_ITEMS
                                                    // وأنواعُ الصفحة تُبدَّل في محرّرها — هنا: صفحةٌ أو عنوانٌ أو رابط
                                                    .filter(({ type }) => (page ? type === 'label' || type === 'link' : (item.type === 'label' ? type !== 'label' : type !== 'link')))
                                                    .map(({ type, label: l, icon: TypeIcon }) => (
                                                        <button
                                                            key={type}
                                                            type="button"
                                                            disabled={type !== 'label' && type !== 'link' && ! page && pagesFull}
                                                            onClick={() => void retype(item, type)}
                                                            data-testid={`menu-item-${at}-to-${type}`}
                                                            className="flex min-h-11 items-center gap-2 rounded-[10px] bg-white px-3 text-start text-[13px] ring-1 ring-[var(--ui-border,#e8e8e8)] enabled:hover:ring-[#9ca3af] disabled:opacity-40"
                                                        >
                                                            <TypeIcon className="size-4 shrink-0 text-[#6b7280]" />
                                                            {t(l)}
                                                        </button>
                                                    ))}
                                            </div>
                                        )}
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <Field label="الاسم في القائمة">
                                                <Input value={item.title_ar} maxLength={30} onChange={(e) => rename(item, 'ar', e.target.value)} aria-label={t('الاسم في القائمة')} data-testid={`menu-item-${at}-name-ar`} />
                                            </Field>
                                            <Field label="Name — English" hint="اختياري — وبلا اسمٍ إنجليزيّ يظهر الاسمُ العربيّ">
                                                <Input dir="ltr" value={item.title_en} maxLength={30} onChange={(e) => rename(item, 'en', e.target.value)} aria-label="Name — English" data-testid={`menu-item-${at}-name-en`} />
                                            </Field>
                                        </div>

                                        {item.type === 'label' && (
                                            <p className="text-[12px] leading-relaxed text-[#6b7280]">{t('يظهر في القائمة اسمًا لا يُضغط — بلا صفحةٍ ولا رابط.')}</p>
                                        )}

                                        {item.type === 'link' && ! page && (
                                            <Field label="ما يفتحه الرابط">
                                                <TargetSelect value={item.target} onChange={(target) => patchItem(item.id, { target })} from={sources} label="ما يفتحه الرابط" testid={`menu-item-${at}-target`} withUrl />
                                            </Field>
                                        )}

                                        {page && pageEditor(page, `menu-item-${at}-page`)}

                                        <div className="flex justify-end">
                                            <Button type="button" variant="ghost" size="sm" data-testid={`menu-item-${at}-delete`} onClick={() => void remove(item)}>
                                                <Trash2 />
                                                {page ? t('أزل من القائمة') : t('احذف')}
                                            </Button>
                                        </div>
                                    </div>
                                )}
                            </li>
                        );
                    })}
                </ol>

                {/* ═══ «+ إضافة قسم أو صفحة» — اختر النوع، ثمّ الاسم ═══ */}
                {adding === null ? (
                    <div className="flex flex-wrap gap-2">
                        <Button type="button" size="sm" disabled={full} onClick={startAdding} data-testid="menu-add">
                            <Plus />
                            {full ? t('بلغت الحدّ — :max عناصر', { max: String(max) }) : t('إضافة قسم أو صفحة')}
                        </Button>
                        {value !== '' && (
                            <Button type="button" variant="ghost" size="sm" onClick={() => { setOpen(null); onChange(''); }} data-testid="menu-reset">
                                <RotateCcw />
                                {t('عُد إلى القائمة الأصليّة')}
                            </Button>
                        )}
                    </div>
                ) : (
                    <div className="space-y-3 rounded-[12px] bg-[#fafafa] p-3 ring-1 ring-[var(--ui-border,#e8e8e8)]" data-testid="menu-new">
                        <div className="flex items-center justify-between gap-2">
                            <p className="text-[13px] font-semibold text-[#111]">{adding === 'choose' ? t('ماذا تريد أن تضيف؟') : t(NEW_ITEMS.find((n) => n.type === adding)?.label ?? '')}</p>
                            <button type="button" aria-label={t('إلغاء')} onClick={() => setAdding(null)} className={iconButton} data-testid="menu-new-cancel">
                                <X className="size-4" />
                            </button>
                        </div>

                        {adding === 'choose' ? (
                            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                {NEW_ITEMS.map(({ type, label: l, hint: h, icon: Icon }) => {
                                    const blocked = type !== 'label' && type !== 'link' && pagesFull;

                                    return (
                                        <button
                                            key={type}
                                            type="button"
                                            disabled={blocked}
                                            onClick={() => setAdding(type)}
                                            data-testid={`menu-type-${type}`}
                                            className="flex min-h-14 items-start gap-2.5 rounded-[12px] bg-white p-3 text-start ring-1 ring-[var(--ui-border,#e8e8e8)] enabled:hover:ring-[#9ca3af] disabled:opacity-40"
                                        >
                                            <Icon className="mt-0.5 size-4 shrink-0 text-[#6b7280]" />
                                            <span>
                                                <span className="block text-[13px] font-medium text-[#111]">{t(l)}</span>
                                                <span className="block text-[11px] leading-relaxed text-[#6b7280]">{blocked ? t('بلغت الحدّ — :max صفحة', { max: String(maxPages) }) : t(h)}</span>
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>
                        ) : (
                            <div className="space-y-3">
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <Field label="الاسم في القائمة" hint="مثل: المناسبات، عروضنا، خدماتنا">
                                        <Input autoFocus value={name.ar} maxLength={30} onChange={(e) => setName({ ...name, ar: e.target.value })} aria-label={t('الاسم في القائمة')} data-testid="menu-new-name-ar" />
                                    </Field>
                                    <Field label="Name — English" hint="اختياري">
                                        <Input dir="ltr" value={name.en} maxLength={30} onChange={(e) => setName({ ...name, en: e.target.value })} aria-label="Name — English" data-testid="menu-new-name-en" />
                                    </Field>
                                </div>
                                {adding === 'link' && (
                                    <Field label="ما يفتحه الرابط">
                                        <TargetSelect value={newTarget} onChange={setNewTarget} from={sources} label="ما يفتحه الرابط" testid="menu-new-target" withUrl />
                                    </Field>
                                )}
                                <div className="flex flex-wrap gap-2">
                                    <Button type="button" size="sm" disabled={! named} onClick={confirmAdd} data-testid="menu-new-add">
                                        <Plus />
                                        {t('أضف')}
                                    </Button>
                                    <Button type="button" variant="ghost" size="sm" onClick={() => setAdding('choose')}>
                                        {t('نوعٌ آخر')}
                                    </Button>
                                </div>
                            </div>
                        )}
                    </div>
                )}

                {value !== '' && adding === null && (
                    <p className="text-[11px] leading-relaxed text-[#9ca3af]">
                        {t('والعودةُ إلى القائمة الأصليّة تُخرج أقسامك منها وحدها — صفحاتُك ومجموعاتُك تبقى.')}
                    </p>
                )}

                {/* ═══ وصفحاتٌ لا يقود إليها عنصرٌ في القائمة — لا تضيع ═══ */}
                {orphans.length > 0 && (
                    <div className="space-y-2" data-testid="menu-orphans">
                        <p className="text-[12px] font-semibold text-[#6b7280]">{t('صفحات ليست في القائمة')}</p>
                        <ul className="divide-y divide-[var(--ui-border,#e8e8e8)] overflow-hidden rounded-[12px] bg-white ring-1 ring-[var(--ui-border,#e8e8e8)]">
                            {orphans.map((p) => (
                                <li key={p.slug} data-testid={`menu-orphan-${p.slug}`}>
                                    <div className="flex flex-wrap items-center gap-2 px-3 py-2">
                                        <FileText className="size-3.5 shrink-0 text-[#9ca3af]" />
                                        <span className="min-w-0 flex-1 truncate text-[13px] text-[#111]">{p.title_ar || p.title_en || t('صفحة بلا عنوان')}</span>
                                        <Button type="button" variant="outline" size="sm" disabled={full} onClick={() => restore(p)} data-testid={`menu-orphan-${p.slug}-add`}>
                                            {t('أعدها إلى القائمة')}
                                        </Button>
                                        <Button type="button" variant="ghost" size="sm" onClick={() => setOrphanOpen(orphanOpen === p.slug ? null : p.slug)} data-testid={`menu-orphan-${p.slug}-edit`}>
                                            {t('حرّر')}
                                        </Button>
                                        <Button type="button" variant="ghost" size="sm" onClick={() => void deletePage(p)} data-testid={`menu-orphan-${p.slug}-delete`}>
                                            <Trash2 />
                                            {t('احذف')}
                                        </Button>
                                    </div>
                                    {orphanOpen === p.slug && (
                                        <div className="border-t border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] px-3 py-4">{pageEditor(p, `menu-orphan-${p.slug}-page`)}</div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        </Field>
    );
}
