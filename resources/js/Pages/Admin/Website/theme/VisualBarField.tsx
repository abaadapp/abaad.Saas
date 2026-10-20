import { useState } from 'react';
import { ArrowDown, ArrowUp, ChevronDown, CircleAlert, Eye, EyeOff, ImageOff, Plus, Trash2 } from 'lucide-react';

import Field from '@/Components/Field';
import StoreImageField from '@/Components/StoreImageField';
import Toggle from '@/Components/Toggle';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { StoreCollection } from './CollectionsField';
import type { ListProduct } from './ProductListField';
import { fromKey, parseTarget, reaches as reachable, type StoreTargetValue, type TargetPage, type TargetSources, TargetSelect, targetKey } from './StoreTargets';

export { fromKey, targetKey };

/**
 * الشريطُ المصوّر في محرّر الواجهة — بطاقاتٌ بصورها فوق قائمة الصفحات.
 *
 * ═══ والقيمةُ نصٌّ كسائر حقول المحرّر ═══
 *
 * الشريطُ كلُّه مفتاحٌ واحد (`store_visual_bar`) نصَّ JSON: أمشغَّلٌ هو، وأين
 * يظهر، وبطاقاتُه بترتيبها. ويُحفظ بزرّ الصفّ ويُعاين قبل الحفظ كأخواته،
 * والخادمُ يقرأ ما يصل بصرامة (`Store\StoreVisualBar::validated`).
 *
 * ═══ ولا رابطَ يُكتب باليد ═══
 *
 * البطاقةُ تفتح مجموعةً أو فئةً أو منتجًا أو صفحةً من متجره — تُختار من
 * قائمة، لا تُكتب. فلا رابطَ خارجيٌّ ولا خطأُ حرفٍ يقود إلى «غير موجود».
 */

export interface BarCard {
    image: string;
    title_ar: string;
    title_en: string;
    sub_ar: string;
    sub_en: string;
    cta_ar: string;
    cta_en: string;
    target: StoreTargetValue;
    visible: boolean;
    /** «كاملة» أو «ملء الإطار» — وغيابُها «ملء الإطار» كما كانت */
    fit?: 'contain' | 'cover';
}

export interface VisualBar {
    on: boolean;
    pages: 'home' | 'browse';
    cards: BarCard[];
}

const OFF: VisualBar = { on: false, pages: 'home', cards: [] };

/** النصُّ المحفوظ ← شريط — وما لا يُقرأ يسقط ولا يُسقط الشاشة */
export function parseBar(raw: string | undefined): VisualBar {
    if (! raw) return { ...OFF, cards: [] };

    let bar: unknown;
    try {
        bar = JSON.parse(raw);
    } catch {
        return { ...OFF, cards: [] };
    }

    if (! bar || typeof bar !== 'object' || Array.isArray(bar)) return { ...OFF, cards: [] };

    const b = bar as Record<string, unknown>;
    const cards = Array.isArray(b.cards) ? b.cards : [];

    return {
        on: b.on === true,
        pages: b.pages === 'browse' ? 'browse' : 'home',
        cards: cards
            .filter((c): c is Record<string, unknown> => !! c && typeof c === 'object')
            // والرابطُ المكتوبُ لرابط القائمة وزرّ الصفحة وحدهما — لا لبطاقة الشريط (`StoreVisualBar::TARGETS`)
            .filter((c) => { const t = parseTarget(c.target); return t !== null && t.type !== 'url'; })
            .map((c) => {
                const text = (k: string) => String(c[k] ?? '');

                return {
                    image: text('image'),
                    title_ar: text('title_ar'), title_en: text('title_en'),
                    sub_ar: text('sub_ar'), sub_en: text('sub_en'),
                    cta_ar: text('cta_ar'), cta_en: text('cta_en'),
                    target: parseTarget(c.target)!,
                    visible: c.visible !== false,
                    ...(c.fit === 'contain' || c.fit === 'cover' ? { fit: c.fit } : {}),
                };
            }),
    };
}

/** الشريط ← نصٌّ يُحفظ. ومطفأٌ بلا بطاقاتٍ فراغ */
export function serializeBar(bar: VisualBar): string {
    return ! bar.on && bar.cards.length === 0 ? '' : JSON.stringify(bar);
}

export default function VisualBarField({
    name,
    label,
    hint,
    value,
    collections,
    categories,
    products,
    pages,
    customPages = [],
    max,
    onChange,
    error,
}: {
    name: string;
    label: string;
    hint?: string;
    value: string;
    /** المجموعاتُ كما في الشاشة الآن — ولو لم تُحفظ بعد */
    collections: StoreCollection[];
    categories: { id: number; name: string; shown?: number }[];
    products: ListProduct[];
    /** صفحاتُ القالب — «من نحن» و«تواصل معنا» وجهتان ما دامتا تُفتحان */
    pages: { key: string; on: boolean; blocked: string | null }[];
    /** وصفحاتُه المخصّصة كما في الشاشة الآن — `StoreCustomPages` */
    customPages?: TargetPage[];
    max: number;
    onChange: (value: string) => void;
    error?: string;
}) {
    const t = useTranslate();
    const bar = parseBar(value);
    const [open, setOpen] = useState<number | null>(null);
    const [doomed, setDoomed] = useState<number | null>(null);

    const write = (next: VisualBar) => onChange(serializeBar(next));
    const patch = (at: number, changes: Partial<BarCard>) =>
        write({ ...bar, cards: bar.cards.map((c, k) => (k === at ? { ...c, ...changes } : c)) });

    const move = (i: number, by: number) => {
        const j = i + by;
        if (j < 0 || j >= bar.cards.length) return;

        const cards = [...bar.cards];
        [cards[i], cards[j]] = [cards[j], cards[i]];
        write({ ...bar, cards });
        setOpen(open === i ? j : open === j ? i : open);
        setDoomed(null);
    };

    const add = () => {
        const first = collections[0];
        const card: BarCard = {
            image: '', title_ar: first?.title_ar ?? '', title_en: first?.title_en ?? '', sub_ar: '', sub_en: '', cta_ar: '', cta_en: '',
            target: first ? { type: 'collection', value: first.slug } : { type: 'shop', value: '' },
            visible: true,
        };
        write({ ...bar, cards: [...bar.cards, card] });
        setOpen(bar.cards.length);
    };

    const remove = (at: number) => {
        write({ ...bar, cards: bar.cards.filter((_, k) => k !== at) });
        setDoomed(null);
        setOpen(null);
    };

    const sources: TargetSources = { collections, pages: customPages, categories, products, storePages: pages };

    /** أتُفتح الوجهةُ الآن؟ — ما لا يُفتح لا تُرسم بطاقتُه في المتجر، فيُقال هنا */
    const reaches = (target: StoreTargetValue): boolean => reachable(target, sources);

    const imageOf = (c: BarCard) =>
        c.image || (c.target.type === 'collection' ? collections.find((x) => x.slug === c.target.value)?.image ?? '' : '');

    const iconButton = 'flex size-10 shrink-0 items-center justify-center rounded-[8px] text-[#6b7280] enabled:hover:bg-[#f3f4f6] disabled:opacity-30';

    return (
        <Field label={label} hint={hint} error={error}>
            <div className="space-y-3" data-testid={`visualbar-${name}`}>
                <Toggle
                    on={bar.on}
                    onChange={(on) => write({ ...bar, on })}
                    label="شغّل الشريط"
                    hint="فوق قائمة الصفحات، ويمرّ مع الصفحة — والترويسةُ تبقى لاصقة"
                />

                <div role="radiogroup" aria-label={t('أين يظهر الشريط')} className="flex gap-2" data-testid="visualbar-pages">
                    {([['home', 'الرئيسية وحدها'], ['browse', 'كل صفحات التصفّح']] as const).map(([key, text]) => (
                        <button
                            key={key}
                            type="button"
                            role="radio"
                            aria-checked={bar.pages === key}
                            data-testid={`visualbar-pages-${key}`}
                            onClick={() => write({ ...bar, pages: key })}
                            className={cn(
                                'min-h-[40px] flex-1 rounded-[10px] border px-3 text-[13px] transition-colors',
                                bar.pages === key
                                    ? 'border-[#111] bg-[#111] text-white'
                                    : 'border-[var(--ui-border,#e8e8e8)] bg-white text-[#111] hover:bg-[#f3f4f6]',
                            )}
                        >
                            {t(text)}
                        </button>
                    ))}
                </div>
                <p className="text-[12px] leading-relaxed text-[#6b7280]">
                    {t('ولا يظهر في السلّة والإتمام: هناك يدفع زبونك.')}
                </p>

                {bar.cards.length === 0 ? (
                    <p data-testid="visualbar-none" className="rounded-[10px] bg-[#f9fafb] px-3 py-3 text-[12px] leading-relaxed text-[#6b7280]">
                        {t('لا بطاقات بعد — أضف بطاقةً بصورةٍ واسمٍ، واختر ما تفتحه.')}
                    </p>
                ) : (
                    <ol className="divide-y divide-[var(--ui-border,#e8e8e8)] overflow-hidden rounded-[12px] bg-white ring-1 ring-[var(--ui-border,#e8e8e8)]">
                        {bar.cards.map((c, i) => {
                            const expanded = open === i;
                            const title = c.title_ar || c.title_en;
                            const thumb = imageOf(c);
                            const ok = reaches(c.target);

                            return (
                                <li key={i} data-testid={`vcard-${i}`} className="bg-white">
                                    <div className="flex items-center gap-1.5 px-2 py-1.5">
                                        {thumb ? (
                                            <img src={thumb} alt="" className="h-12 w-10 shrink-0 rounded-lg bg-[#efe8de]" style={{ objectFit: c.fit === 'contain' ? 'contain' : 'cover' }} />
                                        ) : (
                                            <span className="flex h-12 w-10 shrink-0 items-center justify-center rounded-lg bg-[#f3f4f6] text-[#c4c4c4]">
                                                <ImageOff className="size-4" />
                                            </span>
                                        )}
                                        <button type="button" onClick={() => setOpen(expanded ? null : i)} aria-expanded={expanded} className="min-w-0 flex-1 px-1 text-start">
                                            <span className={cn('block truncate text-[13px] font-medium', c.visible ? 'text-[#111]' : 'text-[#9ca3af]')}>
                                                {title || t('بطاقة بلا عنوان')}
                                            </span>
                                            {! ok && (
                                                <span data-testid={`vcard-${i}-unreachable`} className="flex items-center gap-1 text-[11px] text-[#b45309]">
                                                    <CircleAlert className="size-3" />
                                                    {t('وجهتُها لا تُفتح الآن — فلا تظهر')}
                                                </span>
                                            )}
                                        </button>
                                        <button
                                            type="button"
                                            role="switch"
                                            aria-checked={c.visible}
                                            aria-label={`${t('ظاهرة لزبائنك')} ${title}`}
                                            data-testid={`vcard-${i}-visible`}
                                            onClick={() => patch(i, { visible: ! c.visible })}
                                            className={iconButton}
                                        >
                                            {c.visible ? <Eye className="size-4" /> : <EyeOff className="size-4" />}
                                        </button>
                                        <button type="button" aria-label={`${t('تحريك لأعلى')} ${title}`} disabled={i === 0} onClick={() => move(i, -1)} className={iconButton}>
                                            <ArrowUp className="size-4" />
                                        </button>
                                        <button type="button" aria-label={`${t('تحريك لأسفل')} ${title}`} disabled={i === bar.cards.length - 1} onClick={() => move(i, 1)} className={iconButton}>
                                            <ArrowDown className="size-4" />
                                        </button>
                                        <ChevronDown className={cn('size-4 shrink-0 text-[#d1d5db] transition-transform', expanded && 'rotate-180')} />
                                    </div>

                                    {expanded && (
                                        <div className="space-y-4 border-t border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] px-3 py-4">
                                            <StoreImageField
                                                name={`vcard-${i}-image`}
                                                label="صورة البطاقة"
                                                hint="في إطارٍ طوليّ (٤ إلى ٥) — كاملةً بنسبتها أو تملؤه. وبلا صورةٍ تُستعار صورةُ المجموعة"
                                                value={c.image}
                                                onChange={(url) => patch(i, { image: url })}
                                                fit={c.fit}
                                                onFitChange={(fit) => patch(i, { fit })}
                                                ratio="4 / 5"
                                            />
                                            <Field label="ما تفتحه البطاقة">
                                                <TargetSelect
                                                    value={c.target}
                                                    onChange={(target) => patch(i, { target })}
                                                    from={sources}
                                                    label="ما تفتحه البطاقة"
                                                    testid={`vcard-${i}-target`}
                                                />
                                            </Field>
                                            {([
                                                ['title', 'العنوان', 40],
                                                ['sub', 'سطرٌ تحته — اختياري', 80],
                                                ['cta', 'نصّ الزرّ — اختياري', 24],
                                            ] as const).map(([field, text, limit]) => (
                                                <div key={field} className="grid gap-3 sm:grid-cols-2">
                                                    <Field label={`${text} — العربية`}>
                                                        <Input
                                                            value={c[`${field}_ar`]}
                                                            maxLength={limit}
                                                            onChange={(e) => patch(i, { [`${field}_ar`]: e.target.value } as Partial<BarCard>)}
                                                            aria-label={t(`${text} — العربية`)}
                                                            data-testid={`vcard-${i}-${field}-ar`}
                                                        />
                                                    </Field>
                                                    <Field label={`${text} — English`}>
                                                        <Input
                                                            dir="ltr"
                                                            value={c[`${field}_en`]}
                                                            maxLength={limit}
                                                            onChange={(e) => patch(i, { [`${field}_en`]: e.target.value } as Partial<BarCard>)}
                                                            aria-label={t(`${text} — English`)}
                                                            data-testid={`vcard-${i}-${field}-en`}
                                                        />
                                                    </Field>
                                                </div>
                                            ))}
                                            <p className="text-[12px] leading-relaxed text-[#6b7280]">
                                                {t('ولكلّ لغةٍ نصُّها: بطاقةٌ بلا عنوانٍ إنجليزيّ لا تظهر في الصفحة الإنجليزيّة.')}
                                            </p>
                                            <div className="flex justify-end">
                                                {doomed === i ? (
                                                    <span className="flex items-center gap-2">
                                                        <Button type="button" variant="danger" size="sm" data-testid={`vcard-${i}-delete-confirm`} onClick={() => remove(i)}>
                                                            {t('تأكيد الحذف')}
                                                        </Button>
                                                        <Button type="button" variant="ghost" size="sm" onClick={() => setDoomed(null)}>
                                                            {t('تراجع')}
                                                        </Button>
                                                    </span>
                                                ) : (
                                                    <Button type="button" variant="ghost" size="sm" data-testid={`vcard-${i}-delete`} onClick={() => setDoomed(i)}>
                                                        <Trash2 />
                                                        {t('احذف البطاقة')}
                                                    </Button>
                                                )}
                                            </div>
                                        </div>
                                    )}
                                </li>
                            );
                        })}
                    </ol>
                )}

                <Button type="button" variant="outline" size="sm" disabled={bar.cards.length >= max} onClick={add} data-testid="visualbar-add">
                    <Plus />
                    {bar.cards.length >= max ? t('بلغت الحدّ — :max بطاقة', { max: String(max) }) : t('بطاقة جديدة')}
                </Button>
            </div>
        </Field>
    );
}
