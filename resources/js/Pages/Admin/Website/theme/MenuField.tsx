import { useState } from 'react';
import { ArrowDown, ArrowUp, ChevronDown, CircleAlert, Eye, EyeOff, FilePlus, Link2, Plus, RotateCcw, Trash2 } from 'lucide-react';

import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { parseTarget, reaches, type StoreTargetValue, type TargetSources, TargetSelect } from './StoreTargets';

/**
 * القائمةُ الرئيسيّة في محرّر الواجهة — الرئيسية | المتجر | من نحن | تواصل معنا،
 * بترتيب صاحبها، وروابطُه بينها.
 *
 * ═══ والصفحاتُ الأربعُ تُرتَّب ولا تُحذف ═══
 *
 * هي أبوابُ المتجر: «المتجر» بابُ الشراء، و«تواصل معنا» ما يسأل عنه زبونٌ قبل أن
 * يدفع لمن لا يعرفه. فتُحرَّك في القائمة ولا تخرج منها، وإطفاءُ «من نحن» و«تواصل
 * معنا» بابُه شاشةُ «الصفحات» — ولا يُطفئهما بابان.
 *
 * ═══ والروابطُ تُختار وجهتُها ولا تُكتب ═══
 *
 * صفحةٌ مخصّصة، أو مجموعة، أو فئة، أو صفحةٌ من صفحاته (`StoreTargets`). وحذفُ
 * رابطٍ يحذف الرابطَ وحده — لا الصفحةَ ولا المجموعةَ ولا منتجًا فيها.
 *
 * والقيمةُ نصُّ JSON في مفتاحٍ واحد (`store_menu`)، والفراغُ القائمةُ القديمةُ
 * بترتيبها. والخادمُ يقرؤها بصرامة (`Store\StoreNav::validatedMenu`).
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

export type MenuItem = { type: 'page'; key: BuiltIn } | MenuLink;

const BUILT_IN_LABEL: Record<BuiltIn, string> = { home: 'الرئيسية', shop: 'المتجر', about: 'من نحن', contact: 'تواصل معنا' };

const DEFAULT: MenuItem[] = BUILT_INS.map((key) => ({ type: 'page', key }));

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

        const target = parseTarget(i.target);
        const id = String(i.id ?? '');

        if (i.type !== 'link' || ! /^[a-z0-9]{1,16}$/.test(id) || seen.has(`link:${id}`) || ! target) continue;

        seen.add(`link:${id}`);
        out.push({
            type: 'link', id,
            title_ar: String(i.title_ar ?? ''), title_en: String(i.title_en ?? ''),
            target, visible: i.visible !== false,
        });
    }

    for (const key of BUILT_INS) {
        if (! seen.has(`page:${key}`)) out.push({ type: 'page', key });
    }

    return out;
}

/** القائمة ← نصٌّ يُحفظ. والقديمةُ نفسُها بترتيبها وبلا رابطٍ فراغ */
export function serializeMenu(items: MenuItem[]): string {
    const plain = items.length === BUILT_INS.length && items.every((i, k) => i.type === 'page' && i.key === BUILT_INS[k]);

    return plain ? '' : JSON.stringify(items);
}

/** معرّفُ رابطٍ لم يُؤخذ — حروفٌ صغيرةٌ وأرقام */
export function freshLinkId(taken: string[]): string {
    let id = '';
    do id = 'l' + Math.random().toString(36).slice(2, 9); while (taken.includes(id));

    return id;
}

export default function MenuField({
    label,
    hint,
    value,
    sources,
    max,
    onChange,
    onCreatePage,
    onOpenPages,
    error,
}: {
    label: string;
    hint?: string;
    value: string;
    /** ما يُختار وجهةً — كما في الشاشة الآن، ولو لم يُحفظ بعد */
    sources: TargetSources;
    max: number;
    onChange: (value: string) => void;
    /** يُنشئ صفحةً مخصّصةً فارغةً بهذا الاسم ويردّ اسمَها المختصر — أو `null` إن بلغت الحدّ */
    onCreatePage?: (title: { ar: string; en: string }) => string | null;
    /** يفتح صفَّ «الصفحات المخصّصة» ليُكتب محتواها */
    onOpenPages?: () => void;
    error?: string;
}) {
    const t = useTranslate();
    const items = parseMenu(value);
    const links = items.filter((i): i is MenuLink => i.type === 'link');
    const [open, setOpen] = useState<string | null>(null);
    const [doomed, setDoomed] = useState<string | null>(null);
    const [created, setCreated] = useState<string | null>(null);

    const write = (next: MenuItem[]) => onChange(serializeMenu(next));
    const keyOf = (i: MenuItem) => (i.type === 'page' ? `page:${i.key}` : `link:${i.id}`);
    const patch = (id: string, changes: Partial<MenuLink>) =>
        write(items.map((i) => (i.type === 'link' && i.id === id ? { ...i, ...changes } : i)));

    const move = (at: number, by: number) => {
        const j = at + by;
        if (j < 0 || j >= items.length) return;

        const next = [...items];
        [next[at], next[j]] = [next[j], next[at]];
        write(next);
        setDoomed(null);
    };

    const addLink = (target: StoreTargetValue, title = { ar: '', en: '' }) => {
        const id = freshLinkId(links.map((l) => l.id));
        // والرابطُ الجديدُ قبل «من نحن» — بعد الرئيسية والمتجر حيث يُتوقَّع
        const at = items.findIndex((i) => i.type === 'page' && i.key === 'about');
        const link: MenuLink = { type: 'link', id, title_ar: title.ar, title_en: title.en, target, visible: true };
        const next = [...items];
        next.splice(at < 0 ? next.length : at, 0, link);
        write(next);
        setOpen(id);

        return id;
    };

    const addPage = () => {
        const slug = onCreatePage?.({ ar: 'صفحة جديدة', en: 'New page' });

        if (! slug) return;

        addLink({ type: 'page', value: slug }, { ar: 'صفحة جديدة', en: 'New page' });
        setCreated(slug);
    };

    const firstTarget = (): StoreTargetValue =>
        sources.pages[0] ? { type: 'page', value: sources.pages[0].slug }
            : sources.collections[0] ? { type: 'collection', value: sources.collections[0].slug }
                : { type: 'shop', value: '' };

    const remove = (id: string) => {
        write(items.filter((i) => ! (i.type === 'link' && i.id === id)));
        setDoomed(null);
        setOpen(null);
    };

    const pageState = (key: BuiltIn): string | null => {
        const p = sources.storePages.find((x) => x.key === key);

        if (! p) return null;
        if (! p.on) return t('مطفأة — تُشغَّل من شاشة «الصفحات»');

        return p.blocked ? t(p.blocked) : null;
    };

    const full = links.length >= max;
    const iconButton = 'flex size-10 shrink-0 items-center justify-center rounded-[8px] text-[#6b7280] enabled:hover:bg-[#f3f4f6] disabled:opacity-30';

    return (
        <Field label={label} hint={hint} error={error}>
            <div className="space-y-3" data-testid="menu">
                <ol className="divide-y divide-[var(--ui-border,#e8e8e8)] overflow-hidden rounded-[12px] bg-white ring-1 ring-[var(--ui-border,#e8e8e8)]">
                    {items.map((item, at) => {
                        const k = keyOf(item);

                        if (item.type === 'page') {
                            const state = pageState(item.key);

                            return (
                                <li key={k} className="flex items-center gap-1.5 px-2 py-1.5" data-testid={`menu-page-${item.key}`}>
                                    <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-[#f3f4f6] text-[11px] font-semibold text-[#111]">{at + 1}</span>
                                    <span className="min-w-0 flex-1 px-1">
                                        <span className={cn('block truncate text-[13px] font-medium', state ? 'text-[#9ca3af]' : 'text-[#111]')}>{t(BUILT_IN_LABEL[item.key])}</span>
                                        {state && (
                                            <span className="block truncate text-[11px] text-[#b45309]" data-testid={`menu-page-${item.key}-state`}>{state}</span>
                                        )}
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
                        const ok = reaches(item.target, sources);

                        return (
                            <li key={k} className="bg-white" data-testid={`menu-link-${at}`}>
                                <div className="flex items-center gap-1.5 px-2 py-1.5">
                                    <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-[#111] text-[11px] font-semibold text-white">{at + 1}</span>
                                    <button type="button" onClick={() => setOpen(expanded ? null : item.id)} aria-expanded={expanded} className="min-w-0 flex-1 px-1 text-start">
                                        <span className={cn('flex items-center gap-1 truncate text-[13px] font-medium', item.visible ? 'text-[#111]' : 'text-[#9ca3af]')}>
                                            <Link2 className="size-3.5 shrink-0 text-[#9ca3af]" />
                                            {title || t('رابط بلا اسم')}
                                        </span>
                                        {! ok && (
                                            <span className="flex items-center gap-1 text-[11px] text-[#b45309]" data-testid={`menu-link-${at}-unreachable`}>
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
                                        data-testid={`menu-link-${at}-visible`}
                                        onClick={() => patch(item.id, { visible: ! item.visible })}
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
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <Field label="اسم الرابط — العربية">
                                                <Input
                                                    value={item.title_ar}
                                                    maxLength={30}
                                                    onChange={(e) => patch(item.id, { title_ar: e.target.value })}
                                                    aria-label={t('اسم الرابط — العربية')}
                                                    data-testid={`menu-link-${at}-title-ar`}
                                                />
                                            </Field>
                                            <Field label="Link name — English" hint="وبلا اسمٍ إنجليزيّ يظهر الاسمُ العربيّ">
                                                <Input
                                                    dir="ltr"
                                                    value={item.title_en}
                                                    maxLength={30}
                                                    onChange={(e) => patch(item.id, { title_en: e.target.value })}
                                                    aria-label="Link name — English"
                                                    data-testid={`menu-link-${at}-title-en`}
                                                />
                                            </Field>
                                        </div>
                                        <Field label="ما يفتحه الرابط">
                                            <TargetSelect
                                                value={item.target}
                                                onChange={(target) => patch(item.id, { target })}
                                                from={sources}
                                                label="ما يفتحه الرابط"
                                                testid={`menu-link-${at}-target`}
                                            />
                                        </Field>
                                        {created !== null && item.target.type === 'page' && item.target.value === created && (
                                            <p className="flex flex-wrap items-center gap-2 rounded-[10px] bg-[#eff6ff] px-3 py-2 text-[12px] text-[#1d4ed8]" data-testid="menu-page-created">
                                                {t('أُنشئت صفحةٌ فارغة لهذا الرابط — اكتب محتواها وصورها.')}
                                                {onOpenPages && (
                                                    <button type="button" className="font-medium underline" onClick={onOpenPages}>
                                                        {t('افتح «الصفحات المخصّصة»')}
                                                    </button>
                                                )}
                                            </p>
                                        )}
                                        <div className="flex justify-end">
                                            {doomed === item.id ? (
                                                <span className="flex items-center gap-2">
                                                    <Button type="button" variant="danger" size="sm" data-testid={`menu-link-${at}-delete-confirm`} onClick={() => remove(item.id)}>
                                                        {t('تأكيد الحذف')}
                                                    </Button>
                                                    <Button type="button" variant="ghost" size="sm" onClick={() => setDoomed(null)}>
                                                        {t('تراجع')}
                                                    </Button>
                                                </span>
                                            ) : (
                                                <Button type="button" variant="ghost" size="sm" data-testid={`menu-link-${at}-delete`} onClick={() => setDoomed(item.id)}>
                                                    <Trash2 />
                                                    {t('احذف الرابط')}
                                                </Button>
                                            )}
                                        </div>
                                        <p className="text-[11px] leading-relaxed text-[#9ca3af]">
                                            {t('حذفُ الرابط لا يحذف الصفحةَ ولا المجموعةَ ولا أيَّ منتجٍ فيها.')}
                                        </p>
                                    </div>
                                )}
                            </li>
                        );
                    })}
                </ol>

                <div className="flex flex-wrap gap-2">
                    <Button type="button" variant="outline" size="sm" disabled={full} onClick={() => addLink(firstTarget())} data-testid="menu-add-link">
                        <Plus />
                        {full ? t('بلغت الحدّ — :max روابط', { max: String(max) }) : t('رابط جديد')}
                    </Button>
                    {onCreatePage && (
                        <Button type="button" variant="outline" size="sm" disabled={full} onClick={addPage} data-testid="menu-add-page">
                            <FilePlus />
                            {t('صفحة جديدة في القائمة')}
                        </Button>
                    )}
                    {value !== '' && (
                        <Button type="button" variant="ghost" size="sm" onClick={() => { setOpen(null); onChange(''); }} data-testid="menu-reset">
                            <RotateCcw />
                            {t('عُد إلى القائمة الأصليّة')}
                        </Button>
                    )}
                </div>
                {value !== '' && (
                    <p className="text-[11px] leading-relaxed text-[#9ca3af]">
                        {t('والعودةُ إلى القائمة الأصليّة تحذف روابطك منها وحدها — صفحاتُك ومجموعاتُك تبقى.')}
                    </p>
                )}
            </div>
        </Field>
    );
}
