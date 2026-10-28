import { useState } from 'react';
import { ChevronDown } from 'lucide-react';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** قسمٌ وما يُفعل فيه — `SectionActions::groups()` */
export interface PermissionGroup {
    key: string;
    label: string;
    /** القسمُ نفسُه: «عرض» يفتح شاشاته. و`null` لمجموعةٍ يفتحها قسمٌ آخر */
    view: string | null;
    /** القسمُ الذي يفتح شاشات مجموعةٍ بلا قسمٍ لها — «فواتير العملاء» يفتحها «المبيعات» */
    opens_with: string | null;
    actions: string[];
}

interface Props {
    groups: PermissionGroup[];
    sections: Record<string, string>;
    actions: Record<string, string>;
    hints?: Record<string, string>;
    /** ما يملكه فعلًا: قائمتُه إن خُصّصت، وقائمةُ وظيفته إن اتّبعها */
    shown: string[];
    /** ما تمنحه وظيفتُه — يُقاس عليه ما أُضيف باليد وما نُزع */
    roleGrants: string[];
    manual: boolean;
    /** المربّعاتُ مقبضٌ لا عرض — «خصّص» والباقةُ تبيعه */
    live: boolean;
    mayGrant: (key: string) => boolean;
    onChange: (next: string[]) => void;
}

/**
 * شاشةُ الصلاحيات: كلُّ قسمٍ وتحته ما يُفعل فيه.
 *
 * ═══ ولماذا مجموعاتٌ لا قائمتان ═══
 *
 * كانت الأقسامُ قائمةً والأفعالُ قائمةً تحتها: «العملاء» في الأعلى، و«حذف
 * عميل» — لو وُجد — بين ثلاثين فعلًا في الأسفل. فلا يرى المالكُ ما يفتحه
 * القسمُ وما يبقى مغلقًا فيه. وصار كلُّ قسمٍ بطاقةً: «عرض» أوّلًا، ثمّ ما
 * يُفعل فيه، ومعها «الكل» و«لا شيء».
 *
 * ═══ ومن أين جاءت الصلاحية ═══
 *
 * في «اتبع الوظيفة» كلُّ مؤشَّرٍ «من الوظيفة». وفي «خصّص» يُعلَّم ما يختلف
 * عنها: «أُضيفت» لما لا تمنحه الوظيفة، و«نُزعت» لما تمنحه وسُحب. فيُقرأ
 * التخصيصُ فرقًا عن الوظيفة لا قائمةً تُحفظ عن ظهر قلب.
 *
 * والخادمُ هو الحارس (`CheckAbility` و`refuseGrantingMoreThanIHave`) — وهذه
 * تقول قبل الضغط ما سيقوله.
 */
export default function PermissionGroups({
    groups,
    sections,
    actions,
    hints = {},
    shown,
    roleGrants,
    manual,
    live,
    mayGrant,
    onChange,
}: Props) {
    const t = useTranslate();
    const [onlyGranted, setOnlyGranted] = useState(false);
    const [closed, setClosed] = useState<string[]>([]);

    const has = (key: string) => shown.includes(key);
    const keysOf = (g: PermissionGroup) => [...(g.view ? [g.view] : []), ...g.actions];
    const all = groups.flatMap(keysOf);
    const granted = all.filter(has).length;

    const toggle = (key: string) => onChange(has(key) ? shown.filter((k) => k !== key) : [...shown, key]);

    // «الكل» يمنح ما يملك الفاعلُ منحَه — ومجموعةٌ يفتحها قسمٌ آخر تفتحه معها
    const selectAll = (g: PermissionGroup) => {
        const wanted = [...keysOf(g), ...(g.opens_with ? [g.opens_with] : [])].filter(mayGrant);
        onChange([...shown, ...wanted.filter((k) => !has(k))]);
    };
    // و«لا شيء» يرفع ما في المجموعة وحدها — لا القسمَ الذي يفتحها مع غيرها
    const clearAll = (g: PermissionGroup) => {
        const mine = keysOf(g);
        onChange(shown.filter((k) => !mine.includes(k)));
    };

    const badge = (key: string) => {
        if (!manual) {
            return has(key) ? { text: t('من الوظيفة'), tone: 'bg-[#f3f4f6] text-[#4b5563]' } : null;
        }
        if (has(key) && !roleGrants.includes(key)) {
            return { text: t('أُضيفت يدويًا'), tone: 'bg-[#eff6ff] text-[#1d4ed8]' };
        }
        if (!has(key) && roleGrants.includes(key)) {
            return { text: t('نُزعت عن الوظيفة'), tone: 'bg-[#fffbeb] text-[#92400e]' };
        }
        return null;
    };

    const row = (key: string, label: string, hint?: string) => {
        const b = badge(key);
        const locked = !live || !mayGrant(key);

        return (
            <label
                key={key}
                title={mayGrant(key) ? undefined : t('لا تملك هذه الصلاحية — ولا تُمنح ما لا تملك.')}
                className={cn('flex gap-2.5 rounded-[8px] p-1.5', hint ? 'items-start' : 'items-center', locked ? 'cursor-not-allowed opacity-70' : 'cursor-pointer hover:bg-[#f9fafb]')}
                data-testid={`perm-row-${key}`}
            >
                <input
                    type="checkbox"
                    checked={has(key)}
                    onChange={() => toggle(key)}
                    disabled={locked}
                    className={cn('size-4 shrink-0 rounded border-[#d1d5db] accent-[#111]', hint && 'mt-0.5')}
                    data-testid={`perm-${key}`}
                />
                <span className="min-w-0 flex-1">
                    <span className="flex flex-wrap items-center gap-1.5">
                        <span className="text-sm text-[#374151]">{label}</span>
                        {b && (
                            <span className={cn('rounded-full px-2 py-0.5 text-[11px] font-medium', b.tone)} data-testid={`perm-badge-${key}`}>
                                {b.text}
                            </span>
                        )}
                    </span>
                    {hint && (
                        <span className="mt-0.5 block text-[12px] leading-5 text-[#6b7280]" data-testid={`action-hint-${key}`}>
                            {hint}
                        </span>
                    )}
                </span>
            </label>
        );
    };

    return (
        <div className="space-y-3" data-testid="permission-groups">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-[13px] text-[#374151]" data-testid="perm-summary">
                    {t('يملك :n من :total صلاحية', { n: String(granted), total: String(all.length) })}
                </p>
                <label className="flex cursor-pointer items-center gap-2 text-[12px] text-[#4b5563]">
                    <input
                        type="checkbox"
                        checked={onlyGranted}
                        onChange={() => setOnlyGranted((v) => !v)}
                        className="size-4 rounded border-[#d1d5db] accent-[#111]"
                        data-testid="perm-only-granted"
                    />
                    {t('اعرض الصلاحيات الفعلية وحدها')}
                </label>
            </div>

            <p className="text-[12px] text-[#6b7280]">
                {t('«عرض» يفتح شاشات القسم ويقرأ ما فيها، ولا يبيح الإضافة أو التعديل أو الحذف أو الاعتماد أو التصدير — كلٌّ منها يُمنح باسمه.')}
            </p>

            {groups.map((g) => {
                const keys = keysOf(g);
                const mine = keys.filter(has).length;
                const visible = onlyGranted ? keys.filter(has) : keys;
                const open = !closed.includes(g.key);
                const opener = g.view ?? g.opens_with;
                // فعلٌ مؤشَّرٌ وقسمُه مغلق: لا يصل صاحبُه إلى شاشته أصلًا
                const stranded = opener !== null && !has(opener) && g.actions.some(has);

                if (onlyGranted && visible.length === 0) {
                    return null;
                }

                return (
                    <section key={g.key} className="rounded-[12px] border border-[var(--ui-border,#e5e7eb)]" data-testid={`perm-group-${g.key}`}>
                        <div className="flex flex-wrap items-center gap-2 p-3">
                            <button
                                type="button"
                                onClick={() => setClosed((c) => (open ? [...c, g.key] : c.filter((k) => k !== g.key)))}
                                aria-expanded={open}
                                className="flex min-w-0 flex-1 items-center gap-2 text-start"
                            >
                                <ChevronDown className={cn('size-4 shrink-0 text-[#6b7280] transition-transform', !open && '-rotate-90 rtl:rotate-90')} />
                                <span className="truncate text-sm font-semibold text-[#111]">{g.label}</span>
                                <span className="shrink-0 rounded-full bg-[#f3f4f6] px-2 py-0.5 text-[11px] tabular-nums text-[#4b5563]" dir="ltr">
                                    {mine}/{keys.length}
                                </span>
                            </button>
                            {live && (
                                <span className="flex shrink-0 gap-1">
                                    <button
                                        type="button"
                                        onClick={() => selectAll(g)}
                                        className="rounded-[8px] border border-[#e5e7eb] px-2.5 py-1 text-[12px] hover:bg-[#f9fafb]"
                                        data-testid={`perm-all-${g.key}`}
                                    >
                                        {t('الكل')}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => clearAll(g)}
                                        className="rounded-[8px] border border-[#e5e7eb] px-2.5 py-1 text-[12px] hover:bg-[#f9fafb]"
                                        data-testid={`perm-none-${g.key}`}
                                    >
                                        {t('لا شيء')}
                                    </button>
                                </span>
                            )}
                        </div>

                        {open && (
                            <div className="space-y-1 border-t border-[var(--ui-border,#e5e7eb)] p-2">
                                {g.view === null && g.opens_with && (
                                    <p className="px-1.5 pb-1 text-[12px] text-[#6b7280]">
                                        {t('تُفتح شاشاتها بقسم «:s».', { s: sections[g.opens_with] ?? g.opens_with })}
                                    </p>
                                )}
                                {stranded && (
                                    <p className="rounded-[8px] bg-[#fffbeb] px-2 py-1.5 text-[12px] text-[#92400e]" data-testid={`perm-stranded-${g.key}`}>
                                        {t('لا تعمل هذه الصلاحيات دون «عرض» قسم «:s».', { s: sections[opener] ?? opener })}
                                    </p>
                                )}
                                <div className="grid grid-cols-1 gap-x-3 sm:grid-cols-2">
                                    {g.view && visible.includes(g.view) && row(g.view, t('عرض: :s', { s: sections[g.view] ?? g.label }))}
                                    {g.actions.filter((a) => visible.includes(a)).map((a) => row(a, actions[a] ?? a, hints[a]))}
                                </div>
                            </div>
                        )}
                    </section>
                );
            })}

            {manual && (
                <p className="text-[12px] text-[#6b7280]">
                    {t('«أُضيفت يدويًا» ما لا تمنحه الوظيفة، و«نُزعت عن الوظيفة» ما تمنحه وسحبته. والتخصيص لا يتبدّل مع الوظيفة.')}
                </p>
            )}
        </div>
    );
}
