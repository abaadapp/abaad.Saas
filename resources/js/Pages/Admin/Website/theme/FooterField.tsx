import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ChevronDown, ChevronLeft, Eye, EyeOff, Plus, Trash2 } from 'lucide-react';

import Field from '@/Components/Field';
import Toggle from '@/Components/Toggle';
import { Button } from '@/Components/ui/button';
import { Input, Textarea } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { StoreCollection } from './CollectionsField';
import { freshLinkId, type MenuItem } from './MenuField';

/**
 * تذييلُ المتجر كما يرتّبه صاحبُه — مرآةُ `Store\StoreFooter`.
 *
 * الشعارُ أوّلًا دائمًا، وتحته النبذةُ وزرّا التواصل إن شاء. ثمّ ثلاثةُ أعمدةٍ
 * تُرتَّب وتُسمّى وتُخفى ولا تُحذف — «الموقع» و«تسوّق» و«تواصل معنا» — ويختار
 * ما يظهر في كلٍّ منها بمفاتيح لا بكتابة. ويُضاف عمودا نصٍّ قصير. وفي آخره سطرُ
 * الحقوق وسطرُ التذييل، يُظهرهما أو يُخفيهما ويكتبهما بلغتيه.
 *
 * وبياناتُ التواصل لا تُكتب هنا ثانيةً: الهاتفُ والبريدُ والعنوانُ من «بيانات
 * النشاط» تُعرض كما هي ويُشار إلى بابها، وواتساب وإنستغرام والساعاتُ تُكتب في
 * مواضعها من التذييل نفسِه — مفاتيحُها القائمة لا نسخةٌ منها.
 */

export type ContactLine = 'phone' | 'whatsapp' | 'email' | 'address' | 'hours';

export const CONTACT_LINES: ContactLine[] = ['phone', 'whatsapp', 'email', 'address', 'hours'];

interface Titled {
    visible: boolean;
    title_ar: string;
    title_en: string;
}

export type FooterColumn =
    | (Titled & { key: 'pages'; hide: string[] })
    | (Titled & { key: 'shop'; all: boolean; categories: boolean; collections: string[] })
    | (Titled & { key: 'contact' } & Record<ContactLine, boolean>)
    | (Titled & { key: 'text'; id: string; body_ar: string; body_en: string });

export interface FooterConfig {
    about: boolean;
    social: boolean;
    columns: FooterColumn[];
    line: boolean;
    copyright: boolean;
    copyright_ar: string;
    copyright_en: string;
}

const column = (key: 'pages' | 'shop' | 'contact'): FooterColumn => {
    const base = { visible: true, title_ar: '', title_en: '' };

    if (key === 'pages') return { key, ...base, hide: [] };
    if (key === 'shop') return { key, ...base, all: true, categories: true, collections: [] };

    return { key, ...base, phone: true, whatsapp: true, email: true, address: true, hours: true };
};

export function defaultFooter(): FooterConfig {
    return {
        about: true, social: true,
        columns: [column('pages'), column('shop'), column('contact')],
        line: true, copyright: true, copyright_ar: '', copyright_en: '',
    };
}

const strings = (v: unknown) => (Array.isArray(v) ? v.filter((x): x is string => typeof x === 'string') : []);

/** النصُّ المحفوظ ← تذييل — والفراغُ الأصليّ، والعمودُ الثابتُ الغائبُ يُلحق آخرَه */
export function parseFooter(raw: string | undefined): FooterConfig {
    const cfg = defaultFooter();
    let o: Record<string, unknown> | null = null;

    try {
        o = raw ? JSON.parse(raw) : null;
    } catch {
        o = null;
    }

    if (! o || typeof o !== 'object' || Array.isArray(o)) return cfg;

    for (const k of ['about', 'social', 'line', 'copyright'] as const) {
        if (k in o) cfg[k] = o[k] !== false;
    }

    cfg.copyright_ar = String(o.copyright_ar ?? '');
    cfg.copyright_en = String(o.copyright_en ?? '');

    const columns: FooterColumn[] = [];
    const seen = new Set<string>();

    for (const raw of Array.isArray(o.columns) ? o.columns : []) {
        if (! raw || typeof raw !== 'object') continue;

        const c = raw as Record<string, unknown>;
        const titled = { visible: c.visible !== false, title_ar: String(c.title_ar ?? ''), title_en: String(c.title_en ?? '') };

        if (c.key === 'text') {
            const id = String(c.id ?? '');

            if (! /^[a-z0-9]{1,16}$/.test(id) || seen.has(`text:${id}`)) continue;

            seen.add(`text:${id}`);
            columns.push({ key: 'text', id, ...titled, body_ar: String(c.body_ar ?? ''), body_en: String(c.body_en ?? '') });
            continue;
        }

        if ((c.key !== 'pages' && c.key !== 'shop' && c.key !== 'contact') || seen.has(c.key)) continue;

        seen.add(c.key);
        const col = { ...column(c.key), ...titled } as FooterColumn;

        if (col.key === 'pages') col.hide = strings(c.hide);
        if (col.key === 'shop') {
            col.all = c.all !== false;
            col.categories = c.categories !== false;
            col.collections = strings(c.collections);
        }
        if (col.key === 'contact') CONTACT_LINES.forEach((l) => { col[l] = c[l] !== false; });

        columns.push(col);
    }

    for (const key of ['pages', 'shop', 'contact'] as const) {
        if (! seen.has(key)) columns.push(column(key));
    }

    cfg.columns = columns;

    return cfg;
}

/** التذييل ← نصٌّ يُحفظ. والأصليُّ نفسُه فراغ */
export function serializeFooter(cfg: FooterConfig): string {
    return JSON.stringify(cfg) === JSON.stringify(defaultFooter()) ? '' : JSON.stringify(cfg);
}

const DEFAULT_TITLE: Record<'pages' | 'shop' | 'contact', string> = { pages: 'الموقع', shop: 'تسوّق', contact: 'تواصل معنا' };

const CONTACT_LABEL: Record<ContactLine, string> = {
    phone: 'الهاتف', whatsapp: 'واتساب', email: 'البريد', address: 'العنوان', hours: 'ساعات العمل',
};

export default function FooterField({
    label,
    hint,
    value,
    onChange,
    owned,
    onOwned,
    menu,
    collections,
    contact,
    maxText,
    error,
}: {
    label: string;
    hint?: string;
    value: string;
    onChange: (value: string) => void;
    /** المفاتيحُ القائمةُ التي يكتبها التذييلُ في مواضعها — السطرُ وواتساب وإنستغرام والساعات */
    owned: Record<string, string>;
    onOwned: (key: string, value: string) => void;
    /** القائمةُ الرئيسيّة كما في الشاشة الآن — منها روابطُ عمود «الموقع» */
    menu: MenuItem[];
    collections: StoreCollection[];
    /** من «بيانات النشاط» — يُعرض ولا يُكتب هنا */
    contact: { phone?: string; email?: string; address?: string };
    maxText: number;
    error?: string;
}) {
    const t = useTranslate();
    const cfg = parseFooter(value);
    const [open, setOpen] = useState<string | null>(null);

    const write = (next: FooterConfig) => onChange(serializeFooter(next));
    const keyOf = (c: FooterColumn) => (c.key === 'text' ? `text:${c.id}` : c.key);
    const patchCol = (k: string, changes: Record<string, unknown>) =>
        write({ ...cfg, columns: cfg.columns.map((c) => (keyOf(c) === k ? ({ ...c, ...changes } as FooterColumn) : c)) });

    const move = (i: number, by: number) => {
        const j = i + by;
        if (j < 0 || j >= cfg.columns.length) return;

        const next = [...cfg.columns];
        [next[i], next[j]] = [next[j], next[i]];
        write({ ...cfg, columns: next });
    };

    const texts = cfg.columns.filter((c) => c.key === 'text');
    const addText = () => {
        const id = freshLinkId(texts.map((c) => (c.key === 'text' ? c.id : '')));
        write({ ...cfg, columns: [...cfg.columns, { key: 'text', id, visible: true, title_ar: '', title_en: '', body_ar: '', body_en: '' }] });
        setOpen(`text:${id}`);
    };

    // روابطُ عمود «الموقع» — ما يُضغط من القائمة، لا العناوينُ التي لا تُضغط
    const links = menu.flatMap((i) => {
        if (i.type === 'page') return [{ key: i.key, label: t(({ home: 'الرئيسية', shop: 'المتجر', about: 'من نحن', contact: 'تواصل معنا' } as const)[i.key]) }];
        if (i.type === 'link') return [{ key: `link-${i.id}`, label: i.title_ar || i.title_en }];

        return [];
    });

    const check = (on: boolean, set: (on: boolean) => void, text: string, id: string, note?: string) => (
        <label className="flex min-h-11 cursor-pointer items-center gap-3 rounded-[10px] px-2 hover:bg-white" data-testid={id}>
            <input type="checkbox" checked={on} onChange={(e) => set(e.target.checked)} className="size-5 accent-[#111]" />
            <span className="min-w-0 flex-1">
                <span className="block text-[13px] text-[#111]">{text}</span>
                {note && <span className="block truncate text-[11px] text-[#9ca3af]" dir="auto">{note}</span>}
            </span>
        </label>
    );

    const iconButton = 'flex size-10 shrink-0 items-center justify-center rounded-[8px] text-[#6b7280] enabled:hover:bg-[#f3f4f6] disabled:opacity-30';
    const card = 'space-y-3 rounded-[12px] bg-white p-3 ring-1 ring-[var(--ui-border,#e8e8e8)]';

    return (
        <Field label={label} hint={hint} error={error}>
            <div className="space-y-4" data-testid="footer">
                {/* الشعارُ أوّلًا دائمًا — ويُختار ما تحته */}
                <div className={card} data-testid="footer-brand">
                    <p className="text-[12px] font-semibold text-[#6b7280]">{t('الشعار — أوّلُ التذييل دائمًا')}</p>
                    <Toggle on={cfg.about} onChange={(v) => write({ ...cfg, about: v })} label="النبذة تحت الشعار" hint="نبذتُك نفسُها التي في «عنّا»" />
                    <Toggle on={cfg.social} onChange={(v) => write({ ...cfg, social: v })} label="زرّا إنستغرام وواتساب" />
                    {cfg.social && (
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="إنستغرام" hint="اسمُ الحساب أو رابطُه">
                                <Input dir="ltr" value={owned.store_instagram ?? ''} maxLength={100} onChange={(e) => onOwned('store_instagram', e.target.value)} aria-label={t('إنستغرام')} data-testid="footer-instagram" />
                            </Field>
                            <Field label="واتساب" hint="ورقمُ متجرك إن تركته فارغًا">
                                <Input dir="ltr" value={owned.store_whatsapp ?? ''} maxLength={30} onChange={(e) => onOwned('store_whatsapp', e.target.value)} aria-label={t('واتساب')} data-testid="footer-whatsapp" />
                            </Field>
                        </div>
                    )}
                </div>

                {/* ثمّ الأعمدةُ بترتيبه */}
                <ol className="divide-y divide-[var(--ui-border,#e8e8e8)] overflow-hidden rounded-[12px] bg-white ring-1 ring-[var(--ui-border,#e8e8e8)]">
                    {cfg.columns.map((c, i) => {
                        const k = keyOf(c);
                        const expanded = open === k;
                        const fallback = c.key === 'text' ? t('عمود نص') : t(DEFAULT_TITLE[c.key]);
                        const title = c.title_ar || fallback;

                        return (
                            <li key={k} data-testid={`footer-col-${i}`} data-key={c.key}>
                                <div className="flex items-center gap-1.5 px-2 py-1.5">
                                    <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-[#111] text-[11px] font-semibold text-white">{i + 1}</span>
                                    <button type="button" onClick={() => setOpen(expanded ? null : k)} aria-expanded={expanded} className="min-w-0 flex-1 px-1 text-start" data-testid={`footer-col-${i}-open`}>
                                        <span className={cn('block truncate text-[13px] font-medium', c.visible ? 'text-[#111]' : 'text-[#9ca3af]')}>{title}</span>
                                        {! c.visible && <span className="block text-[11px] text-[#9ca3af]">{t('مخفيّ')}</span>}
                                    </button>
                                    <button type="button" role="switch" aria-checked={c.visible} aria-label={`${t('ظاهر لزبائنك')} ${title}`} onClick={() => patchCol(k, { visible: ! c.visible })} className={iconButton} data-testid={`footer-col-${i}-visible`}>
                                        {c.visible ? <Eye className="size-4" /> : <EyeOff className="size-4" />}
                                    </button>
                                    <button type="button" aria-label={`${t('تحريك لأعلى')} ${title}`} disabled={i === 0} onClick={() => move(i, -1)} className={iconButton}>
                                        <ArrowUp className="size-4" />
                                    </button>
                                    <button type="button" aria-label={`${t('تحريك لأسفل')} ${title}`} disabled={i === cfg.columns.length - 1} onClick={() => move(i, 1)} className={iconButton}>
                                        <ArrowDown className="size-4" />
                                    </button>
                                    <ChevronDown className={cn('size-4 shrink-0 text-[#d1d5db] transition-transform', expanded && 'rotate-180')} />
                                </div>

                                {expanded && (
                                    <div className="space-y-3 border-t border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] px-3 py-4">
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <Field label="عنوان العمود">
                                                <Input value={c.title_ar} maxLength={40} placeholder={fallback} onChange={(e) => patchCol(k, { title_ar: e.target.value })} aria-label={t('عنوان العمود')} data-testid={`footer-col-${i}-title-ar`} />
                                            </Field>
                                            <Field label="Column title — English" hint="اختياري">
                                                <Input dir="ltr" value={c.title_en} maxLength={40} onChange={(e) => patchCol(k, { title_en: e.target.value })} aria-label="Column title — English" data-testid={`footer-col-${i}-title-en`} />
                                            </Field>
                                        </div>

                                        {c.key === 'pages' && (
                                            <div className="space-y-1">
                                                <p className="text-[12px] font-semibold text-[#6b7280]">{t('الروابط المعروضة — من القائمة الرئيسية')}</p>
                                                {links.map((l) => check(
                                                    ! c.hide.includes(l.key),
                                                    (on) => patchCol(k, { hide: on ? c.hide.filter((x) => x !== l.key) : [...c.hide, l.key] }),
                                                    l.label || t('بلا اسم'),
                                                    `footer-col-${i}-link-${l.key}`,
                                                ))}
                                            </div>
                                        )}

                                        {c.key === 'shop' && (
                                            <div className="space-y-1">
                                                {check(c.all, (on) => patchCol(k, { all: on }), t('كل المنتجات'), `footer-col-${i}-all`)}
                                                {check(c.categories, (on) => patchCol(k, { categories: on }), t('الفئات'), `footer-col-${i}-categories`, t('أوّلُ ستّ فئاتٍ فيها منتجٌ معروض'))}
                                                {collections.length > 0 && <p className="pt-2 text-[12px] font-semibold text-[#6b7280]">{t('مجموعاتك')}</p>}
                                                {collections.map((col) => check(
                                                    c.collections.includes(col.slug),
                                                    (on) => patchCol(k, { collections: on ? [...c.collections, col.slug] : c.collections.filter((x) => x !== col.slug) }),
                                                    col.title_ar || col.title_en,
                                                    `footer-col-${i}-collection-${col.slug}`,
                                                    col.visible ? undefined : t('مخفيّة — لا تظهر حتى تُظهرها'),
                                                ))}
                                            </div>
                                        )}

                                        {c.key === 'contact' && (
                                            <div className="space-y-1">
                                                {CONTACT_LINES.map((l) => {
                                                    const shown = l === 'whatsapp' ? (owned.store_whatsapp || contact.phone || '')
                                                        : l === 'hours' ? (owned.store_hours ?? '')
                                                            : (contact[l] ?? '');

                                                    return check(c[l], (on) => patchCol(k, { [l]: on }), t(CONTACT_LABEL[l]), `footer-col-${i}-${l}`, shown || t('غير مكتوب بعد'));
                                                })}
                                                <div className="grid gap-3 pt-2 sm:grid-cols-2">
                                                    <Field label="ساعات العمل">
                                                        <Input value={owned.store_hours ?? ''} maxLength={120} onChange={(e) => onOwned('store_hours', e.target.value)} aria-label={t('ساعات العمل')} data-testid="footer-hours" />
                                                    </Field>
                                                    <Field label="Working hours — English" hint="مثل: Daily 10 AM – 10 PM">
                                                        <Input dir="ltr" value={owned.store_hours_en ?? ''} maxLength={120} onChange={(e) => onOwned('store_hours_en', e.target.value)} aria-label="Working hours — English" data-testid="footer-hours-en" />
                                                    </Field>
                                                </div>
                                                <p className="flex flex-wrap items-center gap-1 pt-1 text-[11px] text-[#6b7280]">
                                                    {t('الهاتف والبريد والعنوان من «بيانات النشاط» — تُعدَّل من')}
                                                    <Link href={route('admin.settings.index')} className="inline-flex items-center font-medium text-[#111] underline">
                                                        {t('الإعدادات')}
                                                        <ChevronLeft className="size-3" />
                                                    </Link>
                                                </p>
                                            </div>
                                        )}

                                        {c.key === 'text' && (
                                            <div className="space-y-3">
                                                <Field label="النص">
                                                    <Textarea rows={3} value={c.body_ar} maxLength={400} onChange={(e) => patchCol(k, { body_ar: e.target.value })} aria-label={t('النص')} data-testid={`footer-col-${i}-body-ar`} />
                                                </Field>
                                                <Field label="Text — English" hint="اختياري — ولا يُعرض النصُّ العربيّ في الصفحة الإنجليزيّة">
                                                    <Textarea rows={3} dir="ltr" value={c.body_en} maxLength={400} onChange={(e) => patchCol(k, { body_en: e.target.value })} aria-label="Text — English" data-testid={`footer-col-${i}-body-en`} />
                                                </Field>
                                                <div className="flex justify-end">
                                                    <Button type="button" variant="ghost" size="sm" onClick={() => write({ ...cfg, columns: cfg.columns.filter((x) => keyOf(x) !== k) })} data-testid={`footer-col-${i}-delete`}>
                                                        <Trash2 />
                                                        {t('احذف العمود')}
                                                    </Button>
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </li>
                        );
                    })}
                </ol>

                <Button type="button" variant="outline" size="sm" disabled={texts.length >= maxText} onClick={addText} data-testid="footer-add-text">
                    <Plus />
                    {texts.length >= maxText ? t('بلغت الحدّ — :max عمودا نصّ', { max: String(maxText) }) : t('عمود نص')}
                </Button>

                {/* وفي آخره سطرُ الحقوق وسطرُ التذييل */}
                <div className={card} data-testid="footer-bottom">
                    <p className="text-[12px] font-semibold text-[#6b7280]">{t('آخر التذييل')}</p>
                    <Toggle on={cfg.copyright} onChange={(v) => write({ ...cfg, copyright: v })} label="سطر الحقوق" hint="وبلا نصٍّ تكتبه: السنةُ واسمُ متجرك" />
                    {cfg.copyright && (
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="نص الحقوق — اختياري">
                                <Input value={cfg.copyright_ar} maxLength={80} placeholder={`© ${new Date().getFullYear()}`} onChange={(e) => write({ ...cfg, copyright_ar: e.target.value })} aria-label={t('نص الحقوق — اختياري')} data-testid="footer-copyright-ar" />
                            </Field>
                            <Field label="Copyright — English" hint="اختياري">
                                <Input dir="ltr" value={cfg.copyright_en} maxLength={80} onChange={(e) => write({ ...cfg, copyright_en: e.target.value })} aria-label="Copyright — English" data-testid="footer-copyright-en" />
                            </Field>
                        </div>
                    )}
                    <Toggle on={cfg.line} onChange={(v) => write({ ...cfg, line: v })} label="سطر التذييل" hint="جملةٌ قصيرةٌ تكتبها بجانب الحقوق" />
                    {cfg.line && (
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="سطر التذييل">
                                <Input value={owned.store_tagline ?? ''} maxLength={60} onChange={(e) => onOwned('store_tagline', e.target.value)} aria-label={t('سطر التذييل')} data-testid="footer-tagline" />
                            </Field>
                            <Field label="Footer line — English" hint="اختياري">
                                <Input dir="ltr" value={owned.store_tagline_en ?? ''} maxLength={60} onChange={(e) => onOwned('store_tagline_en', e.target.value)} aria-label="Footer line — English" data-testid="footer-tagline-en" />
                            </Field>
                        </div>
                    )}
                </div>
            </div>
        </Field>
    );
}
