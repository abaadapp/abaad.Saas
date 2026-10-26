import { useState } from 'react';
import {
    ArrowDown, ArrowUp, CircleAlert, Eye, FileText, Heading, Image as ImageIcon, Images, LayoutGrid, Megaphone,
    MousePointerClick, PanelsTopLeft, Shapes, ShoppingBag, Trash2, Type, X,
} from 'lucide-react';

import { useConfirm } from '@/Components/ConfirmDialog';
import Field from '@/Components/Field';
import StoreImageField, { type ImageFit } from '@/Components/StoreImageField';
import { Button } from '@/Components/ui/button';
import { Input, Textarea } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { StoreCollection } from './CollectionsField';
import ChosenProducts from './ProductPicker';
import type { ListProduct } from './ProductListField';
import {
    blankBlock, blockFilled, type BlockType, convertKind, type CustomPage, KIND_BLOCKS, PAGE_KINDS, type PageBlock, type PageKind,
} from './pages';
import { type TargetSources, TargetSelect } from './StoreTargets';

/**
 * محتوى صفحةٍ مخصّصة — يُكتب في مكانه من القائمة الرئيسية (`MenuField`).
 *
 * ═══ اختر ماذا تريد، ثمّ اكتب ما يلزم وحده ═══
 *
 * نوعُ الصفحة بطاقاتٌ خمس، وكلُّ نوعٍ يعرض أزرارَ ما يُضاف إليه وحده: صفحةُ
 * الكتابة لا تسأل عن صورة، وصفحةُ الصور لا تسأل عن نصّ، وصفحةُ المنتجات تبدأ
 * بـ«اختيار المنتجات» — لا مجموعةَ تُنشأ ولا رابطَ يُكتب. ولا اسمَ مختصرًا
 * ولا JSON ولا معرّفَ منتج: رابطُ الصفحة يُنشأ وحده.
 *
 * ═══ واللغةُ مفتاحٌ واحدٌ للصفحة كلّها ═══
 *
 * كانت كلُّ خانةٍ تُكتب مرّتين — عربيّةً وإنجليزيّة — فتتضاعف الشاشة. فصار
 * المحرّرُ يكتب بلغةٍ واحدةٍ في المرّة: «العربية» أو «English»، والنصُّ الذي
 * لم يُكتب بلغةٍ لا يُرسم في صفحتها ولا يقع على الأخرى.
 *
 * ═══ وتبديلُ النوع لا يُضيّع شيئًا بلا سؤال ═══
 *
 * ما يناسب النوعَ الجديد يبقى أو يتحوّل (`convertKind`)، وما لا يناسبه يُعدّ —
 * فيُسأل صاحبُه قبل أن يذهب.
 */

const KIND_LABEL: Record<PageKind, { label: string; hint: string; icon: typeof FileText }> = {
    text: { label: 'كتابة', hint: 'عنوانٌ ووصفٌ وفقرات', icon: FileText },
    images: { label: 'صور', hint: 'صورةٌ أو أكثر تظهر كاملة', icon: Images },
    textimage: { label: 'كتابة وصور', hint: 'أقسامٌ من نصٍّ وصورة', icon: PanelsTopLeft },
    products: { label: 'منتجات', hint: 'تختارها وترتّبها', icon: ShoppingBag },
    mixed: { label: 'محتوى متنوّع', hint: 'عناوين ونصوص وصور ومنتجات', icon: Shapes },
};

export const BLOCK_LABEL: Record<BlockType, { label: string; add: string; icon: typeof FileText }> = {
    heading: { label: 'عنوان', add: 'عنوان', icon: Heading },
    text: { label: 'فقرة', add: 'فقرة', icon: Type },
    image: { label: 'صورة', add: 'صورة', icon: ImageIcon },
    gallery: { label: 'معرض صور', add: 'معرض صور', icon: LayoutGrid },
    textimage: { label: 'نص وصورة', add: 'قسم نص وصورة', icon: PanelsTopLeft },
    products: { label: 'منتجات', add: 'منتجات', icon: ShoppingBag },
    collection: { label: 'مجموعة', add: 'مجموعة منتجات', icon: Shapes },
    button: { label: 'زر', add: 'زر', icon: MousePointerClick },
    promo: { label: 'عرض ترويجي', add: 'عرض ترويجي', icon: Megaphone },
};

type Lang = 'ar' | 'en';

export default function PageContent({
    page,
    onChange,
    sources,
    products,
    hidden = [],
    collections,
    maxBlocks,
    maxProducts,
    onPreview,
    testid,
}: {
    page: CustomPage;
    onChange: (page: CustomPage) => void;
    /** وجهاتُ الأزرار — كما في الشاشة الآن */
    sources: TargetSources;
    products: ListProduct[];
    hidden?: ListProduct[];
    collections: StoreCollection[];
    maxBlocks: number;
    maxProducts: number;
    onPreview?: () => void;
    testid: string;
}) {
    const t = useTranslate();
    const [ask, confirmDialog] = useConfirm();
    const [lang, setLang] = useState<Lang>('ar');
    const sfx = `_${lang}` as const;
    const dir = lang === 'en' ? 'ltr' : undefined;

    // والمفاتيحُ تُبنى من اللغة (`title_ar`/`title_en`) — فتُمرَّر سجلًّا ويُعاد النوع
    const patch = (changes: Record<string, unknown>) => onChange({ ...page, ...changes } as CustomPage);
    const setBlock = (j: number, b: PageBlock) => patch({ blocks: page.blocks.map((x, k) => (k === j ? b : x)) });
    const edit = (j: number, b: PageBlock, changes: Record<string, unknown>) => setBlock(j, { ...b, ...changes } as PageBlock);

    const move = (j: number, by: number) => {
        const k = j + by;
        if (k < 0 || k >= page.blocks.length) return;

        const next = [...page.blocks];
        [next[j], next[k]] = [next[k], next[j]];
        patch({ blocks: next });
    };

    const add = (type: BlockType) => {
        if (page.blocks.length >= maxBlocks) return;

        patch({ blocks: [...page.blocks, blankBlock(type)] });
    };

    const changeKind = async (to: PageKind) => {
        if (to === page.kind) return;

        const { page: next, lost } = convertKind(page, to);

        if (lost.length > 0) {
            const what = [...new Set(lost)].map((b) => t(BLOCK_LABEL[b].label)).join('، ');
            const go = await ask({
                title: 'تغيير نوع الصفحة',
                message: 'صفحة «:kind» لا تعرض: :what — ستُحذف من الصفحة. تابع؟',
                values: { kind: t(KIND_LABEL[to].label), what },
                action: 'غيّر النوع',
                danger: true,
            });

            if (! go) return;
        }

        onChange(next);
    };

    // وزرُّ الصفحة لا يفتح الصفحةَ نفسَها
    const targets: TargetSources = { ...sources, pages: sources.pages.filter((p) => p.slug !== page.slug) };
    const full = page.blocks.length >= maxBlocks;
    const iconButton = 'flex size-10 shrink-0 items-center justify-center rounded-[8px] text-[#6b7280] enabled:hover:bg-[#f3f4f6] disabled:opacity-30';

    const text = (label: string, value: string, set: (v: string) => void, id: string, max: number, area = false) =>
        area ? (
            <Field label={label}>
                <Textarea rows={4} dir={dir} value={value} maxLength={max} onChange={(e) => set(e.target.value)} aria-label={t(label)} data-testid={id} />
            </Field>
        ) : (
            <Field label={label}>
                <Input dir={dir} value={value} maxLength={max} onChange={(e) => set(e.target.value)} aria-label={t(label)} data-testid={id} />
            </Field>
        );

    const fitChoice = (fit: ImageFit | undefined, set: (fit: ImageFit) => void, id: string) => (
        <div className="flex gap-2" role="radiogroup" aria-label={t('طريقة عرض الصور')}>
            {(['contain', 'cover'] as ImageFit[]).map((f) => (
                <button
                    key={f}
                    type="button"
                    role="radio"
                    aria-checked={(fit ?? 'cover') === f}
                    onClick={() => set(f)}
                    data-testid={`${id}-fit-${f}`}
                    className={cn(
                        'min-h-11 flex-1 rounded-[10px] px-3 text-[12px] ring-1',
                        (fit ?? 'cover') === f ? 'bg-[#111] text-white ring-[#111]' : 'bg-white text-[#374151] ring-[var(--ui-border,#e8e8e8)]',
                    )}
                >
                    {f === 'contain' ? t('الصورة كاملة') : t('ملء الإطار')}
                </button>
            ))}
        </div>
    );

    const body = (b: PageBlock, j: number) => {
        const id = `${testid}-block-${j}`;

        switch (b.type) {
            case 'heading':
                return text('العنوان', b[`text${sfx}`], (v) => edit(j, b, { [`text${sfx}`]: v }), `${id}-text`, 120);
            case 'text':
                return text('النص', b[`body${sfx}`], (v) => edit(j, b, { [`body${sfx}`]: v }), `${id}-body`, 2000, true);
            case 'image':
                return (
                    <StoreImageField name={id} label="الصورة" value={b.image} onChange={(url) => edit(j, b, { image: url })} fit={b.fit} onFitChange={(fit) => edit(j, b, { fit })} ratio="16 / 9" />
                );
            case 'gallery':
                return (
                    <div className="space-y-3">
                        {b.images.length > 0 && (
                            <ul className="grid grid-cols-3 gap-2" data-testid={`${id}-images`}>
                                {b.images.map((url, k) => (
                                    <li key={url + k} className="relative">
                                        <img src={url} alt="" className="aspect-square w-full rounded-[10px] bg-[#f3f4f6] object-contain" />
                                        <button
                                            type="button"
                                            aria-label={t('إزالة الصورة')}
                                            onClick={() => edit(j, b, { images: b.images.filter((_, x) => x !== k) })}
                                            className="absolute end-1 top-1 flex size-8 items-center justify-center rounded-full bg-white/90 text-[#111] shadow"
                                        >
                                            <X className="size-4" />
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {b.images.length < 12 && (
                            <StoreImageField name={`${id}-add`} label="أضف صورة إلى المعرض" value="" onChange={(url) => url && edit(j, b, { images: [...b.images, url] })} ratio="1 / 1" />
                        )}
                        {fitChoice(b.fit, (fit) => edit(j, b, { fit }), id)}
                    </div>
                );
            case 'textimage':
                return (
                    <div className="space-y-3">
                        <StoreImageField name={id} label="الصورة" hint="اختيارية" value={b.image} onChange={(url) => edit(j, b, { image: url })} fit={b.fit} onFitChange={(fit) => edit(j, b, { fit })} ratio="16 / 9" />
                        <div className="flex gap-2" role="radiogroup" aria-label={t('مكان الصورة')}>
                            {(['side', 'top'] as const).map((l) => (
                                <button
                                    key={l}
                                    type="button"
                                    role="radio"
                                    aria-checked={b.layout === l}
                                    onClick={() => edit(j, b, { layout: l })}
                                    data-testid={`${id}-layout-${l}`}
                                    className={cn(
                                        'min-h-11 flex-1 rounded-[10px] px-3 text-[12px] ring-1',
                                        b.layout === l ? 'bg-[#111] text-white ring-[#111]' : 'bg-white text-[#374151] ring-[var(--ui-border,#e8e8e8)]',
                                    )}
                                >
                                    {l === 'side' ? t('الصورة بجانب النص') : t('الصورة فوق النص')}
                                </button>
                            ))}
                        </div>
                        {text('العنوان', b[`title${sfx}`], (v) => edit(j, b, { [`title${sfx}`]: v }), `${id}-title`, 80)}
                        {text('النص', b[`body${sfx}`], (v) => edit(j, b, { [`body${sfx}`]: v }), `${id}-body`, 2000, true)}
                    </div>
                );
            case 'products':
                return (
                    <div className="space-y-3">
                        {text('عنوان فوق المنتجات — اختياري', b[`title${sfx}`], (v) => edit(j, b, { [`title${sfx}`]: v }), `${id}-title`, 80)}
                        <ChosenProducts name={id} label="المنتجات" ids={b.products} products={products} hidden={hidden} max={maxProducts} onChange={(ids) => edit(j, b, { products: ids })} />
                    </div>
                );
            case 'collection':
                return (
                    <div className="space-y-3">
                        <Field label="المجموعة">
                            {collections.length === 0 ? (
                                <p className="rounded-[10px] bg-[#f9fafb] px-3 py-2 text-[12px] text-[#6b7280]">{t('لا مجموعات بعد — أنشئها من «مجموعات المتجر»، أو اختر المنتجات هنا مباشرةً.')}</p>
                            ) : (
                                <select
                                    value={b.slug}
                                    onChange={(e) => edit(j, b, { slug: e.target.value })}
                                    aria-label={t('المجموعة')}
                                    data-testid={`${id}-collection`}
                                    className="h-11 w-full rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white px-3 text-[13px]"
                                >
                                    <option value="">{t('اختر مجموعة…')}</option>
                                    {collections.map((c) => (
                                        <option key={c.slug} value={c.slug}>{(c.title_ar || c.title_en) + (c.visible ? '' : ` — ${t('مخفيّة')}`)}</option>
                                    ))}
                                </select>
                            )}
                        </Field>
                        {text('عنوان آخر — اختياري', b[`title${sfx}`], (v) => edit(j, b, { [`title${sfx}`]: v }), `${id}-title`, 80)}
                    </div>
                );
            case 'button':
                return (
                    <div className="space-y-3">
                        {text('نص الزر', b[`label${sfx}`], (v) => edit(j, b, { [`label${sfx}`]: v }), `${id}-label`, 30)}
                        <Field label="ما يفتحه الزر">
                            <TargetSelect value={b.target} onChange={(target) => edit(j, b, { target })} from={targets} label="ما يفتحه الزر" testid={`${id}-target`} withUrl />
                        </Field>
                    </div>
                );
            case 'promo':
                return (
                    <div className="space-y-3">
                        <StoreImageField name={id} label="الصورة" hint="اختيارية" value={b.image} onChange={(url) => edit(j, b, { image: url })} fit={b.fit} onFitChange={(fit) => edit(j, b, { fit })} ratio="4 / 3" />
                        {text('العنوان', b[`title${sfx}`], (v) => edit(j, b, { [`title${sfx}`]: v }), `${id}-title`, 80)}
                        {text('النص', b[`body${sfx}`], (v) => edit(j, b, { [`body${sfx}`]: v }), `${id}-body`, 2000, true)}
                        {text('نص الزر — اختياري', b[`label${sfx}`], (v) => edit(j, b, { [`label${sfx}`]: v, target: b.target ?? { type: 'shop', value: '' } }), `${id}-label`, 30)}
                        {(b.label_ar !== '' || b.label_en !== '') && b.target && (
                            <Field label="ما يفتحه الزر">
                                <TargetSelect value={b.target} onChange={(target) => edit(j, b, { target })} from={targets} label="ما يفتحه الزر" testid={`${id}-target`} withUrl />
                            </Field>
                        )}
                    </div>
                );
        }
    };

    return (
        <div className="space-y-4" data-testid={testid}>
            {confirmDialog}

            <div className="space-y-2">
                <p className="text-[12px] font-semibold text-[#6b7280]">{t('نوع الصفحة')}</p>
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3" role="radiogroup" aria-label={t('نوع الصفحة')}>
                    {PAGE_KINDS.map((k) => {
                        const { label, hint, icon: Icon } = KIND_LABEL[k];

                        return (
                            <button
                                key={k}
                                type="button"
                                role="radio"
                                aria-checked={page.kind === k}
                                onClick={() => void changeKind(k)}
                                data-testid={`${testid}-kind-${k}`}
                                className={cn(
                                    'flex min-h-14 items-start gap-2 rounded-[12px] p-2.5 text-start ring-1',
                                    page.kind === k ? 'bg-[#111] text-white ring-[#111]' : 'bg-white text-[#111] ring-[var(--ui-border,#e8e8e8)]',
                                )}
                            >
                                <Icon className="mt-0.5 size-4 shrink-0" />
                                <span>
                                    <span className="block text-[13px] font-medium">{t(label)}</span>
                                    <span className={cn('block text-[11px]', page.kind === k ? 'text-white/70' : 'text-[#9ca3af]')}>{t(hint)}</span>
                                </span>
                            </button>
                        );
                    })}
                </div>
            </div>

            {/* واللغةُ مفتاحٌ واحد — والإنجليزيّةُ اختياريّة: ما لم يُكتب بها لا يظهر في صفحتها */}
            <div className="flex items-center gap-2">
                <div className="flex overflow-hidden rounded-[10px] ring-1 ring-[var(--ui-border,#e8e8e8)]" role="radiogroup" aria-label={t('لغة الكتابة')}>
                    {(['ar', 'en'] as Lang[]).map((l) => (
                        <button
                            key={l}
                            type="button"
                            role="radio"
                            aria-checked={lang === l}
                            onClick={() => setLang(l)}
                            data-testid={`${testid}-lang-${l}`}
                            className={cn('min-h-10 px-4 text-[12px]', lang === l ? 'bg-[#111] text-white' : 'bg-white text-[#374151]')}
                        >
                            {l === 'ar' ? 'العربية' : 'English'}
                        </button>
                    ))}
                </div>
                <span className="text-[11px] text-[#9ca3af]">{lang === 'en' ? t('اختياريّة — وما لم يُكتب بالإنجليزية لا يظهر في الصفحة الإنجليزية') : ''}</span>
            </div>

            {text(lang === 'en' ? 'عنوان الصفحة — English' : 'عنوان الصفحة', page[`title${sfx}`], (v) => patch({ [`title${sfx}`]: v }), `${testid}-title-${lang}`, 80)}
            {text(lang === 'en' ? 'وصف الصفحة — English' : 'وصف الصفحة — اختياري', page[`intro${sfx}`], (v) => patch({ [`intro${sfx}`]: v }), `${testid}-intro-${lang}`, 800, true)}

            {page.blocks.length > 0 && (
                <ol className="space-y-3">
                    {page.blocks.map((b, j) => {
                        const { label, icon: Icon } = BLOCK_LABEL[b.type];

                        return (
                            <li key={j} className="space-y-3 rounded-[12px] bg-white p-3 ring-1 ring-[var(--ui-border,#e8e8e8)]" data-testid={`${testid}-block-${j}`} data-type={b.type}>
                                <div className="flex items-center gap-1.5">
                                    <Icon className="size-4 shrink-0 text-[#9ca3af]" />
                                    <span className="min-w-0 flex-1 truncate text-[12px] font-medium text-[#111]">{t(label)}</span>
                                    <button type="button" aria-label={`${t('تحريك لأعلى')} ${t(label)}`} disabled={j === 0} onClick={() => move(j, -1)} className={iconButton}>
                                        <ArrowUp className="size-4" />
                                    </button>
                                    <button type="button" aria-label={`${t('تحريك لأسفل')} ${t(label)}`} disabled={j === page.blocks.length - 1} onClick={() => move(j, 1)} className={iconButton}>
                                        <ArrowDown className="size-4" />
                                    </button>
                                    <button type="button" aria-label={`${t('احذف')} ${t(label)}`} data-testid={`${testid}-block-${j}-delete`} onClick={() => patch({ blocks: page.blocks.filter((_, k) => k !== j) })} className={iconButton}>
                                        <Trash2 className="size-4" />
                                    </button>
                                </div>
                                {! blockFilled(b) && (
                                    <p className="flex items-center gap-1 text-[11px] text-[#b45309]" data-testid={`${testid}-block-${j}-empty`}>
                                        <CircleAlert className="size-3" />
                                        {t('فارغة — لن تُحفظ حتى تكتب فيها أو تضيف صورة.')}
                                    </p>
                                )}
                                {body(b, j)}
                            </li>
                        );
                    })}
                </ol>
            )}

            {/* وأزرارُ ما يُضاف — ما يناسب نوعَ الصفحة وحده */}
            <div className="space-y-2">
                {/*
                    والحدُّ للصفحة لا للموقع: يُقال ذلك، ويُدلّ على ما يتّسع له المزيد —
                    وما في الصفحة يبقى، والحفظُ متاح.
                */}
                <p className="text-[12px] font-semibold text-[#6b7280]" data-testid={`${testid}-blocks-limit`}>
                    {full
                        ? t('بلغت هذه الصفحة حدَّها — :max وحدة. ما فيها باقٍ ويُحفظ؛ ولإضافة المزيد أنشئ صفحةً أخرى من «عناصر القائمة».', { max: String(maxBlocks) })
                        : t('أضف إلى الصفحة')}
                </p>
                <div className="flex flex-wrap gap-2">
                    {KIND_BLOCKS[page.kind].map((type) => {
                        const { add: addLabel, icon: Icon } = BLOCK_LABEL[type];

                        return (
                            <Button key={type} type="button" variant="outline" size="sm" disabled={full} onClick={() => add(type)} data-testid={`${testid}-add-${type}`}>
                                <Icon />
                                {t(addLabel)}
                            </Button>
                        );
                    })}
                </div>
            </div>

            {onPreview && (
                <Button type="button" variant="ghost" size="sm" onClick={onPreview} data-testid={`${testid}-preview`}>
                    <Eye />
                    {t('عاين الصفحة')}
                </Button>
            )}
        </div>
    );
}
