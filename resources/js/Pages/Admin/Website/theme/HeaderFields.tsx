import { X } from 'lucide-react';

import Field from '@/Components/Field';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * حقلا «رأس المتجر» في محرّر الواجهة — المحاذاةُ واختصاراتُ صفّ «المتجر».
 *
 * والقيمُ نصوصٌ كسائر حقول المحرّر: المحاذاةُ `left`/`center`/`right`،
 * والاختصاراتُ معرّفاتُ فئاتٍ مفصولةٌ بفاصلة بترتيبها. ويُحفظان بزرّ الصفّ
 * كأخواتهما — والخادمُ يقرأ ما يصل بصرامة (`StoreHeader`).
 */

/** المحاذاةُ مكانٌ في الشاشة — بترتيبها كما تُقرأ يمينًا إلى يسار */
export const ALIGN_OPTIONS = [
    { value: 'right', label: 'يمين' },
    { value: 'center', label: 'في الوسط' },
    { value: 'left', label: 'يسار' },
] as const;

/** فراغُ الحقل يُقرأ الوسطَ — كما يقرؤه الخادم */
export function alignOf(raw: string | undefined): string {
    return ALIGN_OPTIONS.some((o) => o.value === raw) ? (raw as string) : 'center';
}

export function AlignField({
    label,
    value,
    onChange,
    error,
}: {
    label: string;
    value: string;
    onChange: (v: string) => void;
    error?: string;
}) {
    const t = useTranslate();
    const current = alignOf(value);

    return (
        <Field label={label} error={error}>
            <div role="radiogroup" aria-label={t(label)} className="flex gap-2" data-testid="align-field">
                {ALIGN_OPTIONS.map((o) => (
                    <button
                        key={o.value}
                        type="button"
                        role="radio"
                        aria-checked={current === o.value}
                        data-testid={`align-${o.value}`}
                        onClick={() => onChange(o.value)}
                        className={cn(
                            'min-h-[40px] flex-1 rounded-[10px] border px-3 text-[13px] transition-colors',
                            current === o.value
                                ? 'border-[#111] bg-[#111] text-white'
                                : 'border-[var(--ui-border,#e8e8e8)] bg-white text-[#111] hover:bg-[#f3f4f6]',
                        )}
                    >
                        {t(o.label)}
                    </button>
                ))}
            </div>
        </Field>
    );
}

export interface ShortcutCategory {
    id: number;
    name: string;
    /** كم صنفًا معروضًا فيها — والفارغةُ لا تظهر في الواجهة */
    shown: number;
}

/** قيمةٌ مخزّنة ← معرّفاتٌ موجبةٌ فريدةٌ بترتيبها */
export function shortcutIds(raw: string | undefined): number[] {
    const out: number[] = [];

    for (const token of (raw ?? '').split(',')) {
        const id = Number(token.trim());

        if (Number.isInteger(id) && id > 0 && ! out.includes(id)) out.push(id);
    }

    return out;
}

/**
 * اختصاراتُ صفّ «المتجر» — فئاتٌ من متجره، يختارها ويرتّبها ويُزيلها.
 *
 * ولا رابطَ يُكتب: الفئةُ تُختار من قائمته فتُحفظ بمعرّفها، واسمُها في
 * الواجهة اسمُ الفئة بلغة الزائر. والمختارُ الذي حُذفت فئتُه يُعرض ليُزال —
 * لا يختفي من القائمة ويبقى في الإعداد.
 */
export function ShortcutsField({
    label,
    hint,
    value,
    categories,
    max,
    onChange,
    error,
}: {
    label: string;
    hint?: string;
    value: string;
    categories: ShortcutCategory[];
    max: number;
    onChange: (v: string) => void;
    error?: string;
}) {
    const t = useTranslate();
    const ids = shortcutIds(value);
    const byId = new Map(categories.map((c) => [c.id, c]));
    const rest = categories.filter((c) => ! ids.includes(c.id));
    const full = ids.length >= max;

    const write = (next: number[]) => onChange(next.join(','));

    const move = (i: number, by: number) => {
        const j = i + by;

        if (j < 0 || j >= ids.length) return;

        const next = [...ids];
        [next[i], next[j]] = [next[j], next[i]];
        write(next);
    };

    return (
        <Field label={label} hint={hint} error={error}>
            <div className="space-y-2" data-testid="shortcuts-field">
                {/* الثابتان أوّلًا — يُريان ولا يُحرَّكان */}
                <p className="text-[12px] text-[#6b7280]">
                    {t('كل المنتجات')} · {t('الأكثر مبيعًا')}
                </p>

                {ids.length > 0 && (
                    <ol className="divide-y divide-[var(--ui-border,#e8e8e8)] overflow-hidden rounded-[12px] border border-[var(--ui-border,#e8e8e8)] bg-white">
                        {ids.map((id, i) => {
                            const c = byId.get(id);

                            return (
                                <li key={id} className="flex items-center gap-2 px-3 py-2" data-testid={`shortcut-${id}`}>
                                    <span className="w-5 shrink-0 text-[11px] text-[#9ca3af]">{i + 1}</span>
                                    <span className={cn('min-w-0 flex-1 truncate text-[13px]', c ? 'text-[#111]' : 'text-[#b91c1c]')}>
                                        {c ? c.name : t('فئةٌ لم تعد موجودة')}
                                    </span>
                                    {c && c.shown === 0 && (
                                        <span className="shrink-0 text-[11px] text-[#b45309]">{t('لا تظهر — بلا منتجٍ معروض')}</span>
                                    )}
                                    <button
                                        type="button"
                                        aria-label={`${t('ارفع')} ${c?.name ?? ''}`}
                                        disabled={i === 0}
                                        onClick={() => move(i, -1)}
                                        className="rounded-md px-2 py-1 text-[13px] text-[#6b7280] enabled:hover:bg-[#f3f4f6] disabled:opacity-30"
                                    >
                                        ↑
                                    </button>
                                    <button
                                        type="button"
                                        aria-label={`${t('أنزل')} ${c?.name ?? ''}`}
                                        disabled={i === ids.length - 1}
                                        onClick={() => move(i, 1)}
                                        className="rounded-md px-2 py-1 text-[13px] text-[#6b7280] enabled:hover:bg-[#f3f4f6] disabled:opacity-30"
                                    >
                                        ↓
                                    </button>
                                    <button
                                        type="button"
                                        aria-label={`${t('أزل')} ${c?.name ?? ''}`}
                                        onClick={() => write(ids.filter((v) => v !== id))}
                                        className="rounded-md p-1 text-[#6b7280] hover:bg-[#f3f4f6]"
                                    >
                                        <X className="size-3.5" />
                                    </button>
                                </li>
                            );
                        })}
                    </ol>
                )}

                {categories.length === 0 ? (
                    <p className="text-[13px] text-[#6b7280]">{t('لا فئاتٍ في متجرك بعد.')}</p>
                ) : (
                    <select
                        data-testid="shortcut-add"
                        aria-label={t('أضف فئة')}
                        value=""
                        disabled={full || rest.length === 0}
                        onChange={(e) => {
                            const id = Number(e.target.value);

                            if (id > 0) write([...ids, id].slice(0, max));
                        }}
                        className="w-full rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white px-3 py-2 text-[13px] text-[#111] disabled:opacity-50"
                    >
                        <option value="">{full ? t('اكتملت الاختصارات') : t('أضف فئة…')}</option>
                        {rest.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.name}
                            </option>
                        ))}
                    </select>
                )}
            </div>
        </Field>
    );
}
