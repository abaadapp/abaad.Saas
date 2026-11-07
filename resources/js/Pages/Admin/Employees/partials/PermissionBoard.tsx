import { useMemo, useState } from 'react';
import { ChevronDown, Search } from 'lucide-react';
import { Input } from '@/Components/ui/input';
import { cn } from '@/lib/utils';
import { useTranslate } from '@/lib/i18n';
import { groupPermissions, searchable, type PermissionItem } from './permissionGroups';

type Filter = 'all' | 'selected' | 'sensitive';

interface Props {
    sections: Record<string, string>;
    actions?: Record<string, string>;
    actionHints?: Record<string, string>;
    /** المؤشَّرُ الآن: قائمةُ الموظّف المخصّصة، أو قائمةُ وظيفته في «اتبع» */
    checked: string[];
    /** تُؤشَّر المربّعاتُ في «خصّص» وحده — وفي «اتبع» هي عرضٌ لا مقبض */
    live: boolean;
    mayGrant: (key: string) => boolean;
    onToggle: (key: string) => void;
    /** يضيف المفاتيحَ أو ينزعها وحدها — وما سواها في القائمة يبقى كما هو */
    onSetMany: (keys: string[], on: boolean) => void;
}

/**
 * لوحةُ الصلاحيات: بطاقةٌ لكلّ مجموعة، وبحثٌ، وتصفية.
 *
 * ═══ عرضٌ لا قاعدة ═══
 *
 * لا تعرف ما يُرسَل ولا كيف يُحفظ: تقرأ `checked` وتنادي `onToggle` و
 * `onSetMany`، والنموذجُ يملك القائمة. والبحثُ والتصفيةُ والطيُّ تُخفي ولا
 * تنزع — ما خفي من القائمة باقٍ فيها ويُرسَل كما هو.
 *
 * ═══ و«تحديد الكل» لا يمسّ فعلًا حسّاسًا ═══
 *
 * يشمل أقسامَ المجموعة الظاهرةَ التي يملك الفاعلُ منحَها — لا غير. والفعلُ
 * الحسّاس (`Permissions::ACTIONS`) يُؤشَّر ويُنزع واحدًا واحدًا: «إلغاء فاتورة
 * عميل صادرة» لا يُمنح لأنّ أحدًا أراد أن يفتح «المبيعات».
 */
export default function PermissionBoard({ sections, actions = {}, actionHints = {}, checked, live, mayGrant, onToggle, onSetMany }: Props) {
    const t = useTranslate();
    const [query, setQuery] = useState('');
    const [filter, setFilter] = useState<Filter>('all');
    const [folded, setFolded] = useState<Record<string, boolean>>({});

    const groups = useMemo(() => groupPermissions(sections, actions, actionHints), [sections, actions, actionHints]);
    const all = useMemo(() => groups.flatMap((g) => g.items), [groups]);
    const isChecked = (key: string) => checked.includes(key);

    const needle = searchable(query);
    const passesFilter = (it: PermissionItem) =>
        filter === 'all' || (filter === 'selected' ? isChecked(it.key) : it.sensitive);
    const matches = (it: PermissionItem) =>
        !needle || searchable(`${it.label} ${it.hint ?? ''}`).includes(needle);

    const visible = groups
        .map((g) => {
            // من بحث باسم المجموعة يرى المجموعة كلَّها
            const groupMatches = !!needle && (searchable(t(g.title)).includes(needle) || searchable(g.title).includes(needle));

            return { ...g, items: g.items.filter((it) => passesFilter(it) && (groupMatches || matches(it))) };
        })
        .filter((g) => g.items.length > 0);

    const counts: Record<Filter, number> = {
        all: all.length,
        selected: all.filter((it) => isChecked(it.key)).length,
        sensitive: all.filter((it) => it.sensitive).length,
    };

    const FILTERS: { key: Filter; label: string }[] = [
        { key: 'all', label: 'الكل' },
        { key: 'selected', label: 'المختارة' },
        { key: 'sensitive', label: 'الحساسة' },
    ];

    return (
        <div className="space-y-3" data-testid="permission-board">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                <div className="relative min-w-0 flex-1">
                    <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-[#9ca3af]" />
                    <Input
                        type="search"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder={t('ابحث عن صلاحية أو قسم')}
                        aria-label={t('ابحث عن صلاحية أو قسم')}
                        className="ps-9"
                    />
                </div>

                <div className="flex shrink-0 gap-1 rounded-[10px] bg-[#f3f4f6] p-1" role="group" aria-label={t('تصفية الصلاحيات')}>
                    {FILTERS.map((f) => (
                        <button
                            key={f.key}
                            type="button"
                            aria-pressed={filter === f.key}
                            data-testid={`permission-filter-${f.key}`}
                            onClick={() => setFilter(f.key)}
                            className={cn(
                                'flex-1 rounded-[8px] px-3 py-1.5 text-[13px] whitespace-nowrap transition-colors',
                                filter === f.key ? 'bg-white font-semibold text-[#111] shadow-sm' : 'text-[#6b7280] hover:text-[#111]',
                            )}
                        >
                            {t(f.label)} <span dir="ltr">{counts[f.key]}</span>
                        </button>
                    ))}
                </div>
            </div>

            <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 text-[12px] text-[#6b7280]">
                <p data-testid="permission-count">
                    {t('المختارة :count من :total', { count: counts.selected, total: counts.all })}
                </p>
                {counts.sensitive > 0 && <p>{t('الأفعال الحسّاسة تُمنح واحدًا واحدًا — لا يشملها «تحديد الكل».')}</p>}
            </div>

            {visible.length === 0 ? (
                <p className="rounded-[10px] border border-dashed border-[#e5e7eb] px-3 py-6 text-center text-[13px] text-[#6b7280]">
                    {t('لا صلاحية تطابق بحثك.')}
                </p>
            ) : (
                <div className="grid grid-cols-1 items-start gap-3 md:grid-cols-2">
                    {visible.map((g) => {
                        const whole = groups.find((x) => x.id === g.id)!.items;
                        // والطيُّ لا يُخفي نتيجةَ بحث
                        const open = !folded[g.id] || !!needle;
                        const normal = g.items.filter((it) => !it.sensitive);
                        const sensitive = g.items.filter((it) => it.sensitive);
                        // ما يمسّه «تحديد الكل» و«إلغاء التحديد»: أقسامٌ ظاهرةٌ يملك الفاعلُ منحَها
                        const bulk = normal.filter((it) => mayGrant(it.key)).map((it) => it.key);
                        const title = t(g.title);

                        return (
                            <section
                                key={g.id}
                                // ‏«صلاحيات «العملاء»» لا «العملاء»: اسمُ القسم نفسُه اسمُ مربّعٍ فيه
                                aria-label={t('صلاحيات «:group»', { group: title })}
                                data-testid={`permission-group-${g.id}`}
                                className="rounded-[12px] border border-[#e5e7eb] bg-white p-4"
                            >
                                <button
                                    type="button"
                                    aria-expanded={open}
                                    onClick={() => setFolded((f) => ({ ...f, [g.id]: open }))}
                                    className="flex w-full items-center gap-2 text-start"
                                >
                                    <span className="min-w-0 flex-1 font-bold text-[#111]">{title}</span>
                                    <span className="text-[12px] text-[#9ca3af]" dir="ltr" data-testid={`permission-group-count-${g.id}`}>
                                        {whole.filter((it) => isChecked(it.key)).length}/{whole.length}
                                    </span>
                                    <ChevronDown className={cn('size-4 shrink-0 text-[#9ca3af] transition-transform', open && 'rotate-180')} />
                                </button>

                                {open && (
                                    <>
                                        {live && normal.length > 0 && (
                                            <div className="mt-2 flex gap-3 text-[12px]">
                                                <button
                                                    type="button"
                                                    disabled={!bulk.some((k) => !isChecked(k))}
                                                    aria-label={t('تحديد كل أقسام «:group»', { group: title })}
                                                    onClick={() => onSetMany(bulk, true)}
                                                    className="font-medium text-[#111] underline-offset-2 hover:underline disabled:cursor-not-allowed disabled:text-[#c4c4c4] disabled:no-underline"
                                                >
                                                    {t('تحديد الكل')}
                                                </button>
                                                <button
                                                    type="button"
                                                    disabled={!bulk.some((k) => isChecked(k))}
                                                    aria-label={t('إلغاء تحديد أقسام «:group»', { group: title })}
                                                    onClick={() => onSetMany(bulk, false)}
                                                    className="font-medium text-[#6b7280] underline-offset-2 hover:underline disabled:cursor-not-allowed disabled:text-[#c4c4c4] disabled:no-underline"
                                                >
                                                    {t('إلغاء التحديد')}
                                                </button>
                                            </div>
                                        )}

                                        {normal.length > 0 && (
                                            <div className="mt-3 space-y-3">
                                                {normal.map((it) => (
                                                    <Row key={it.key} item={it} checked={isChecked(it.key)} live={live} mayGrant={mayGrant(it.key)} onToggle={onToggle} />
                                                ))}
                                            </div>
                                        )}

                                        {/*
                                            والحسّاسُ تحت عنوانه في مجموعته: «المبيعات» تفتح شاشة، و«تعديل
                                            فواتير المبيعات» تعيد كتابة مستندٍ ضريبيّ — فلا يقعان في صفٍّ
                                            واحدٍ يُعلَّم سهوًا.
                                        */}
                                        {sensitive.length > 0 && (
                                            <div className="mt-3 space-y-3 rounded-[10px] border border-[#fde68a] bg-[#fffbeb] p-3" data-testid={`permission-sensitive-${g.id}`}>
                                                <p className="text-[12px] font-semibold text-[#92400e]">{t('أفعالٌ حسّاسة')}</p>
                                                {sensitive.map((it) => (
                                                    <Row key={it.key} item={it} checked={isChecked(it.key)} live={live} mayGrant={mayGrant(it.key)} onToggle={onToggle} />
                                                ))}
                                            </div>
                                        )}
                                    </>
                                )}
                            </section>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

function Row({
    item,
    checked,
    live,
    mayGrant,
    onToggle,
}: {
    item: PermissionItem;
    checked: boolean;
    live: boolean;
    mayGrant: boolean;
    onToggle: (key: string) => void;
}) {
    const t = useTranslate();
    const enabled = live && mayGrant;

    return (
        <label
            title={mayGrant ? undefined : t('لا تملك هذه الصلاحية — ولا تُمنح ما لا تملك.')}
            className={cn('flex gap-2.5', item.hint ? 'items-start' : 'items-center', enabled ? 'cursor-pointer' : 'cursor-not-allowed opacity-60')}
        >
            {/* الاسمُ وحده اسمُ المربّع — فلا يدخل الوصفُ فيما يُقرأ له */}
            <input
                type="checkbox"
                aria-label={item.label}
                data-testid={`permission-${item.key}`}
                checked={checked}
                onChange={() => onToggle(item.key)}
                disabled={!enabled}
                className={cn('size-4 shrink-0 rounded border-[#d1d5db] accent-[#111]', item.hint && 'mt-0.5')}
            />
            <span className="min-w-0">
                {/* والحسّاسُ يُعرف بموضعه: تحت «أفعالٌ حسّاسة» في مجموعته — لا وسمٌ يتكرّر على كلّ سطر */}
                <span className="block text-sm text-[#374151]">{item.label}</span>
                {item.hint && (
                    <span className="mt-0.5 block text-[12px] leading-5 text-[#6b7280]" data-testid={`action-hint-${item.key}`}>
                        {item.hint}
                    </span>
                )}
            </span>
        </label>
    );
}
