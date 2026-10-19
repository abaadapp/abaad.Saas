import { useState } from 'react';
import { ArrowDown, ArrowUp, ChevronDown, CircleAlert, Eye, EyeOff, Plus, Trash2 } from 'lucide-react';

import Field from '@/Components/Field';
import StoreImageField, { type ImageFit } from '@/Components/StoreImageField';
import { Button } from '@/Components/ui/button';
import { Input, Textarea } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { freshSlug, validSlug } from './CollectionsField';
import { parseTarget, type StoreTargetValue, type TargetSources, TargetSelect } from './StoreTargets';

/**
 * الصفحاتُ المخصّصة في محرّر الواجهة — «خدماتنا» و«المناسبات» وما يكتبه صاحبُها.
 *
 * ═══ صفحةٌ لا رابطٌ فارغ ═══
 *
 * لكلّ صفحةٍ عنوانٌ ونبذةٌ بلغتيها، وصورةٌ رئيسيّة، وأقسامٌ من صورةٍ ونصٍّ يرتّبها،
 * وزرٌّ اختياريٌّ إلى وجهةٍ في متجره (`StoreTargets`). ورابطُها `‎/page/{slug}‎`
 * يُكتب مرّةً ولا يتبدّل بإعادة التسمية. وتُعايَن قبل أن تُحفظ، ولا يراها زبونٌ
 * حتّى تُنشر.
 *
 * والقيمةُ نصُّ JSON في مفتاحٍ واحد (`store_custom_pages`)، يقرؤه الخادمُ بصرامة
 * (`Store\StoreCustomPages::validated`): الصورُ من مجلّده، والزرُّ إلى متجره،
 * والنصوصُ نصوصٌ تُطبع مُهرَّبة — لا وسمَ يُرسم.
 */

export interface PageSection {
    image: string;
    fit?: ImageFit;
    title_ar: string;
    title_en: string;
    body_ar: string;
    body_en: string;
}

export interface CustomPage {
    slug: string;
    title_ar: string;
    title_en: string;
    intro_ar: string;
    intro_en: string;
    image: string;
    fit?: ImageFit;
    sections: PageSection[];
    cta: { label_ar: string; label_en: string; target: StoreTargetValue } | null;
    visible: boolean;
}

const text = (o: Record<string, unknown>, k: string) => String(o[k] ?? '');
const fitIn = (o: Record<string, unknown>) => (o.fit === 'contain' || o.fit === 'cover' ? { fit: o.fit as ImageFit } : {});

/** النصُّ المحفوظ ← صفحات — وما لا يُقرأ يسقط ولا يُسقط الشاشة */
export function parsePages(raw: string | undefined): CustomPage[] {
    let list: unknown;

    try {
        list = raw ? JSON.parse(raw) : [];
    } catch {
        return [];
    }

    if (! Array.isArray(list)) return [];

    return list
        .filter((p): p is Record<string, unknown> => !! p && typeof p === 'object')
        .map((p) => {
            const cta = p.cta && typeof p.cta === 'object' ? (p.cta as Record<string, unknown>) : null;
            const target = cta ? parseTarget(cta.target) : null;

            return {
                slug: text(p, 'slug'),
                title_ar: text(p, 'title_ar'), title_en: text(p, 'title_en'),
                intro_ar: text(p, 'intro_ar'), intro_en: text(p, 'intro_en'),
                image: text(p, 'image'),
                ...fitIn(p),
                sections: (Array.isArray(p.sections) ? p.sections : [])
                    .filter((s): s is Record<string, unknown> => !! s && typeof s === 'object')
                    .map((s) => ({
                        image: text(s, 'image'), ...fitIn(s),
                        title_ar: text(s, 'title_ar'), title_en: text(s, 'title_en'),
                        body_ar: text(s, 'body_ar'), body_en: text(s, 'body_en'),
                    })),
                cta: cta && target ? { label_ar: text(cta, 'label_ar'), label_en: text(cta, 'label_en'), target } : null,
                visible: p.visible !== false,
            };
        });
}

/** الصفحات ← نصٌّ يُحفظ. والفراغُ «لا صفحات» */
export function serializePages(list: CustomPage[]): string {
    return list.length ? JSON.stringify(list) : '';
}

/** صفحةٌ جديدةٌ فارغةٌ باسمٍ — ورابطُها من اسمها الإنجليزيّ إن كان */
export function blankPage(taken: string[], title = { ar: '', en: '' }): CustomPage {
    return {
        slug: freshSlug(taken, title.en),
        title_ar: title.ar, title_en: title.en, intro_ar: '', intro_en: '',
        image: '', fit: 'contain', sections: [], cta: null, visible: true,
    };
}

/** أفي القسم شيءٌ يُرسم؟ — قسمٌ فارغٌ يُردّ حفظُه */
export function sectionFilled(s: PageSection): boolean {
    return [s.image, s.title_ar, s.title_en, s.body_ar, s.body_en].some((v) => v.trim() !== '');
}

const blankSection = (): PageSection => ({ image: '', fit: 'contain', title_ar: '', title_en: '', body_ar: '', body_en: '' });

export default function CustomPagesField({
    label,
    hint,
    value,
    sources,
    max,
    maxSections,
    onChange,
    onPreview,
    error,
}: {
    label: string;
    hint?: string;
    value: string;
    /** وجهاتُ زرّ الصفحة — كما في الشاشة الآن */
    sources: TargetSources;
    max: number;
    maxSections: number;
    onChange: (value: string) => void;
    /** يعاين الصفحةَ في إطار المعاينة — قبل الحفظ */
    onPreview?: (slug: string) => void;
    error?: string;
}) {
    const t = useTranslate();
    const list = parsePages(value);
    const [open, setOpen] = useState<number | null>(null);
    const [doomed, setDoomed] = useState<number | null>(null);

    const write = (next: CustomPage[]) => onChange(serializePages(next));
    const patch = (at: number, changes: Partial<CustomPage>) => write(list.map((p, k) => (k === at ? { ...p, ...changes } : p)));
    const patchSection = (at: number, si: number, changes: Partial<PageSection>) =>
        patch(at, { sections: list[at].sections.map((s, k) => (k === si ? { ...s, ...changes } : s)) });

    const swap = <T,>(arr: T[], i: number, j: number): T[] => {
        const next = [...arr];
        [next[i], next[j]] = [next[j], next[i]];

        return next;
    };

    const move = (i: number, by: number) => {
        const j = i + by;
        if (j < 0 || j >= list.length) return;

        write(swap(list, i, j));
        setOpen(open === i ? j : open === j ? i : open);
        setDoomed(null);
    };

    const add = () => {
        write([...list, blankPage(list.map((p) => p.slug))]);
        setOpen(list.length);
    };

    const remove = (at: number) => {
        write(list.filter((_, k) => k !== at));
        setDoomed(null);
        setOpen(null);
    };

    const iconButton = 'flex size-10 shrink-0 items-center justify-center rounded-[8px] text-[#6b7280] enabled:hover:bg-[#f3f4f6] disabled:opacity-30';
    // وزرُّ الصفحة لا يفتح الصفحةَ نفسَها
    const targetsFor = (slug: string): TargetSources => ({ ...sources, pages: sources.pages.filter((p) => p.slug !== slug) });

    return (
        <Field label={label} hint={hint} error={error}>
            <div className="space-y-3" data-testid="pages">
                {list.length === 0 ? (
                    <p data-testid="pages-none" className="rounded-[10px] bg-[#f9fafb] px-3 py-3 text-[12px] leading-relaxed text-[#6b7280]">
                        {t('لا صفحات بعد — أنشئ صفحةً بعنوانها وصورها ونصوصها، ثمّ أضفها إلى القائمة الرئيسية.')}
                    </p>
                ) : (
                    <ol className="divide-y divide-[var(--ui-border,#e8e8e8)] overflow-hidden rounded-[12px] bg-white ring-1 ring-[var(--ui-border,#e8e8e8)]">
                        {list.map((p, i) => {
                            const expanded = open === i;
                            const title = p.title_ar || p.title_en;
                            const slugOk = validSlug(p.slug) && list.filter((x) => x.slug === p.slug).length === 1;

                            return (
                                <li key={i} data-testid={`page-${i}`} className="bg-white">
                                    <div className="flex items-center gap-1.5 px-2 py-1.5">
                                        <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-[#111] text-[11px] font-semibold text-white">{i + 1}</span>
                                        <button type="button" onClick={() => setOpen(expanded ? null : i)} aria-expanded={expanded} className="min-w-0 flex-1 px-1 text-start">
                                            <span className={cn('block truncate text-[13px] font-medium', p.visible ? 'text-[#111]' : 'text-[#9ca3af]')}>
                                                {title || t('صفحة بلا عنوان')}
                                            </span>
                                            <span className="block truncate text-[11px] text-[#9ca3af]" dir="ltr">/page/{p.slug}</span>
                                        </button>
                                        <button
                                            type="button"
                                            role="switch"
                                            aria-checked={p.visible}
                                            aria-label={`${t('ظاهرة لزبائنك')} ${title}`}
                                            data-testid={`page-${i}-visible`}
                                            onClick={() => patch(i, { visible: ! p.visible })}
                                            className={iconButton}
                                        >
                                            {p.visible ? <Eye className="size-4" /> : <EyeOff className="size-4" />}
                                        </button>
                                        <button type="button" aria-label={`${t('تحريك لأعلى')} ${title}`} disabled={i === 0} onClick={() => move(i, -1)} className={iconButton}>
                                            <ArrowUp className="size-4" />
                                        </button>
                                        <button type="button" aria-label={`${t('تحريك لأسفل')} ${title}`} disabled={i === list.length - 1} onClick={() => move(i, 1)} className={iconButton}>
                                            <ArrowDown className="size-4" />
                                        </button>
                                        <ChevronDown className={cn('size-4 shrink-0 text-[#d1d5db] transition-transform', expanded && 'rotate-180')} />
                                    </div>

                                    {expanded && (
                                        <div className="space-y-4 border-t border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] px-3 py-4">
                                            <div className="grid gap-3 sm:grid-cols-2">
                                                <Field label="عنوان الصفحة — العربية">
                                                    <Input value={p.title_ar} maxLength={80} onChange={(e) => patch(i, { title_ar: e.target.value })} aria-label={t('عنوان الصفحة — العربية')} data-testid={`page-${i}-title-ar`} />
                                                </Field>
                                                <Field label="Page title — English" hint="وبلا عنوانٍ إنجليزيّ يظهر العنوانُ العربيّ">
                                                    <Input dir="ltr" value={p.title_en} maxLength={80} onChange={(e) => patch(i, { title_en: e.target.value })} aria-label="Page title — English" data-testid={`page-${i}-title-en`} />
                                                </Field>
                                            </div>
                                            <Field label="النبذة — العربية" hint="تحت العنوان — وأسطرُك تبقى أسطرًا">
                                                <Textarea rows={3} value={p.intro_ar} maxLength={800} onChange={(e) => patch(i, { intro_ar: e.target.value })} aria-label={t('النبذة — العربية')} data-testid={`page-${i}-intro-ar`} />
                                            </Field>
                                            <Field label="Intro — English" hint="للصفحة الإنجليزيّة — ولا تُعرض فيها النبذةُ العربيّة">
                                                <Textarea rows={3} dir="ltr" value={p.intro_en} maxLength={800} onChange={(e) => patch(i, { intro_en: e.target.value })} aria-label="Intro — English" data-testid={`page-${i}-intro-en`} />
                                            </Field>
                                            <StoreImageField
                                                name={`page-${i}-image`}
                                                label="الصورة الرئيسية"
                                                hint="اختيارية — تحت العنوان بعرض الصفحة"
                                                value={p.image}
                                                onChange={(url) => patch(i, { image: url })}
                                                fit={p.fit}
                                                onFitChange={(fit) => patch(i, { fit })}
                                                ratio="16 / 9"
                                            />

                                            <div className="space-y-2">
                                                <p className="text-[12px] font-semibold text-[#6b7280]">{t('أقسام الصفحة')}</p>
                                                {p.sections.length === 0 && (
                                                    <p className="text-[12px] text-[#9ca3af]">{t('لا أقسام بعد — كلُّ قسمٍ صورةٌ ونصٌّ، أو أحدُهما.')}</p>
                                                )}
                                                {p.sections.map((s, si) => (
                                                    <div key={si} className="space-y-3 rounded-[12px] bg-white p-3 ring-1 ring-[var(--ui-border,#e8e8e8)]" data-testid={`page-${i}-section-${si}`}>
                                                        <div className="flex items-center gap-1.5">
                                                            <span className="min-w-0 flex-1 truncate text-[12px] font-medium text-[#111]">
                                                                {t('القسم :n', { n: String(si + 1) })}
                                                            </span>
                                                            <button type="button" aria-label={`${t('تحريك لأعلى')} ${t('القسم :n', { n: String(si + 1) })}`} disabled={si === 0} onClick={() => patch(i, { sections: swap(p.sections, si, si - 1) })} className={iconButton}>
                                                                <ArrowUp className="size-4" />
                                                            </button>
                                                            <button type="button" aria-label={`${t('تحريك لأسفل')} ${t('القسم :n', { n: String(si + 1) })}`} disabled={si === p.sections.length - 1} onClick={() => patch(i, { sections: swap(p.sections, si, si + 1) })} className={iconButton}>
                                                                <ArrowDown className="size-4" />
                                                            </button>
                                                            <button type="button" aria-label={`${t('احذف')} ${t('القسم :n', { n: String(si + 1) })}`} data-testid={`page-${i}-section-${si}-delete`} onClick={() => patch(i, { sections: p.sections.filter((_, k) => k !== si) })} className={iconButton}>
                                                                <Trash2 className="size-4" />
                                                            </button>
                                                        </div>
                                                        {! sectionFilled(s) && (
                                                            <p className="flex items-center gap-1 text-[11px] text-[#b45309]" data-testid={`page-${i}-section-${si}-empty`}>
                                                                <CircleAlert className="size-3" />
                                                                {t('قسمٌ فارغ — أضف صورةً أو نصًّا، أو احذفه قبل الحفظ.')}
                                                            </p>
                                                        )}
                                                        <StoreImageField
                                                            name={`page-${i}-section-${si}-image`}
                                                            label="صورة القسم"
                                                            hint="اختيارية — إلى جانب النصّ على الكمبيوتر، وفوقه على الهاتف"
                                                            value={s.image}
                                                            onChange={(url) => patchSection(i, si, { image: url })}
                                                            fit={s.fit}
                                                            onFitChange={(fit) => patchSection(i, si, { fit })}
                                                            ratio="16 / 9"
                                                        />
                                                        <div className="grid gap-3 sm:grid-cols-2">
                                                            <Field label="عنوان القسم — العربية">
                                                                <Input value={s.title_ar} maxLength={80} onChange={(e) => patchSection(i, si, { title_ar: e.target.value })} aria-label={t('عنوان القسم — العربية')} data-testid={`page-${i}-section-${si}-title-ar`} />
                                                            </Field>
                                                            <Field label="Section title — English">
                                                                <Input dir="ltr" value={s.title_en} maxLength={80} onChange={(e) => patchSection(i, si, { title_en: e.target.value })} aria-label="Section title — English" data-testid={`page-${i}-section-${si}-title-en`} />
                                                            </Field>
                                                        </div>
                                                        <Field label="النصّ — العربية">
                                                            <Textarea rows={4} value={s.body_ar} maxLength={2000} onChange={(e) => patchSection(i, si, { body_ar: e.target.value })} aria-label={t('النصّ — العربية')} data-testid={`page-${i}-section-${si}-body-ar`} />
                                                        </Field>
                                                        <Field label="Text — English">
                                                            <Textarea rows={4} dir="ltr" value={s.body_en} maxLength={2000} onChange={(e) => patchSection(i, si, { body_en: e.target.value })} aria-label="Text — English" data-testid={`page-${i}-section-${si}-body-en`} />
                                                        </Field>
                                                    </div>
                                                ))}
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    disabled={p.sections.length >= maxSections}
                                                    onClick={() => patch(i, { sections: [...p.sections, blankSection()] })}
                                                    data-testid={`page-${i}-add-section`}
                                                >
                                                    <Plus />
                                                    {p.sections.length >= maxSections ? t('بلغت الحدّ — :max أقسام', { max: String(maxSections) }) : t('قسم جديد')}
                                                </Button>
                                            </div>

                                            <div className="space-y-3 rounded-[12px] bg-white p-3 ring-1 ring-[var(--ui-border,#e8e8e8)]">
                                                <p className="text-[12px] font-semibold text-[#6b7280]">{t('زرّ في آخر الصفحة — اختياري')}</p>
                                                <div className="grid gap-3 sm:grid-cols-2">
                                                    <Field label="نصّ الزرّ — العربية" hint="اتركه فارغًا فلا زرّ">
                                                        <Input
                                                            value={p.cta?.label_ar ?? ''}
                                                            maxLength={30}
                                                            onChange={(e) => patch(i, { cta: { label_ar: e.target.value, label_en: p.cta?.label_en ?? '', target: p.cta?.target ?? { type: 'shop', value: '' } } })}
                                                            aria-label={t('نصّ الزرّ — العربية')}
                                                            data-testid={`page-${i}-cta-ar`}
                                                        />
                                                    </Field>
                                                    <Field label="Button text — English" hint="اتركه فارغًا فلا زرّ في الصفحة الإنجليزيّة">
                                                        <Input
                                                            dir="ltr"
                                                            value={p.cta?.label_en ?? ''}
                                                            maxLength={30}
                                                            onChange={(e) => patch(i, { cta: { label_ar: p.cta?.label_ar ?? '', label_en: e.target.value, target: p.cta?.target ?? { type: 'shop', value: '' } } })}
                                                            aria-label="Button text — English"
                                                            data-testid={`page-${i}-cta-en`}
                                                        />
                                                    </Field>
                                                </div>
                                                {p.cta && (
                                                    <Field label="ما يفتحه الزرّ">
                                                        <TargetSelect
                                                            value={p.cta.target}
                                                            onChange={(target) => patch(i, { cta: { ...p.cta!, target } })}
                                                            from={targetsFor(p.slug)}
                                                            label="ما يفتحه الزرّ"
                                                            testid={`page-${i}-cta-target`}
                                                        />
                                                    </Field>
                                                )}
                                            </div>

                                            <Field
                                                label="رابط الصفحة"
                                                hint="حروفٌ إنجليزية صغيرة وأرقام وشرطة — وتغييره يُبطل الروابط التي شاركتها"
                                                error={slugOk ? undefined : t('رابطٌ غير صالح أو مكرّر')}
                                            >
                                                <div className="flex items-center gap-2" dir="ltr">
                                                    <span className="shrink-0 text-[12px] text-[#6b7280]">/page/</span>
                                                    <Input
                                                        dir="ltr"
                                                        value={p.slug}
                                                        maxLength={40}
                                                        onChange={(e) => patch(i, { slug: e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '') })}
                                                        aria-label={t('رابط الصفحة')}
                                                        data-testid={`page-${i}-slug`}
                                                    />
                                                </div>
                                            </Field>

                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                {onPreview && (
                                                    <Button type="button" variant="ghost" size="sm" onClick={() => onPreview(p.slug)} data-testid={`page-${i}-preview`}>
                                                        <Eye />
                                                        {t('عاين الصفحة')}
                                                    </Button>
                                                )}
                                                {doomed === i ? (
                                                    <span className="flex items-center gap-2">
                                                        <Button type="button" variant="danger" size="sm" data-testid={`page-${i}-delete-confirm`} onClick={() => remove(i)}>
                                                            {t('تأكيد الحذف')}
                                                        </Button>
                                                        <Button type="button" variant="ghost" size="sm" onClick={() => setDoomed(null)}>
                                                            {t('تراجع')}
                                                        </Button>
                                                    </span>
                                                ) : (
                                                    <Button type="button" variant="ghost" size="sm" data-testid={`page-${i}-delete`} onClick={() => setDoomed(i)}>
                                                        <Trash2 />
                                                        {t('احذف الصفحة')}
                                                    </Button>
                                                )}
                                            </div>
                                            {doomed === i && (
                                                <p className="text-[11px] text-[#9ca3af]">{t('ورابطُها في القائمة يختفي معها — ولا يُحذف منتجٌ ولا مجموعة.')}</p>
                                            )}
                                        </div>
                                    )}
                                </li>
                            );
                        })}
                    </ol>
                )}

                <Button type="button" variant="outline" size="sm" disabled={list.length >= max} onClick={add} data-testid="pages-add">
                    <Plus />
                    {list.length >= max ? t('بلغت الحدّ — :max صفحة', { max: String(max) }) : t('صفحة جديدة')}
                </Button>
            </div>
        </Field>
    );
}
