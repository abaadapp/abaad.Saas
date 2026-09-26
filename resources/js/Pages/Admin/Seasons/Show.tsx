import { useEffect, useMemo, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Bell, CalendarClock, CalendarDays, Globe, Package, Pencil, Plus, Search, Store, Trash2, X } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import BackLink from '@/Components/BackLink';
import { useConfirm } from '@/Components/ConfirmDialog';
import Field from '@/Components/Field';
import PageHeader from '@/Components/PageHeader';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input, Textarea } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import Performance, { type SeasonPerformance } from './Performance';
import SeasonDialog from './SeasonDialog';
import { STATUS_TONE, dateRange, type SeasonRow } from './Index';

interface SeasonProduct {
    id: number;
    name: string;
    label: string;
    sku: string | null;
    image: string | null;
    category: string | null;
    active: boolean;
    stock_status: string;
}

interface Reminder {
    id: number;
    type: 'relative' | 'fixed';
    days_before: number | null;
    remind_at: string | null;
    due_at: string | null;
    message: string;
    active: boolean;
    /** حان وقتُه ولم يُقرأ في هذه الدورة */
    due: boolean;
    acknowledged: boolean;
}

interface Props {
    season: SeasonRow & { products: SeasonProduct[]; reminders: Reminder[] };
    maxReminders: number;
    /** الاختصاراتُ الجاهزة بالأيّام — أسبوعٌ وأسبوعان وشهرٌ وشهران */
    presets: number[];
    /** أداءُ الموسم — `null` لمن لا يقرأ التقارير، فلا يصل رقمٌ إلى شاشته */
    performance: SeasonPerformance | null;
}

const when = (iso: string | null, locale: string) =>
    iso
        ? new Date(iso).toLocaleString(locale === 'en' ? 'en-u-nu-latn' : 'ar-u-nu-latn', {
              day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit',
          })
        : '—';

export default function SeasonShow() {
    const { season, maxReminders, presets, performance, locale, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const [edit, setEdit] = useState(false);
    const [picker, setPicker] = useState(false);
    const [ask, confirmDialog] = useConfirm();

    const remove = async () => {
        const ok = await ask({
            title: t('حذف الموسم'),
            message: t('يُحذف الموسم وتذكيراته وصِلاته بالمنتجات. المنتجات نفسها لا تُمسّ ولا تُحذف.'),
            action: t('حذف الموسم'),
            danger: true,
        });
        if (ok) router.delete(route('admin.seasons.destroy', season.id));
    };

    const detach = async (p: SeasonProduct) => {
        const ok = await ask({
            title: t('إزالة من الموسم'),
            message: t('يُزال «:name» من هذا الموسم فقط — المنتج يبقى كما هو في متجرك.', { name: p.label }),
            action: t('إزالة'),
        });
        if (ok) router.delete(route('admin.seasons.detach', [season.id, p.id]), { preserveScroll: true });
    };

    return (
        <AdminLayout title="الموسم">
            {confirmDialog}
            <BackLink routeName="admin.seasons.index" href={route('admin.seasons.index')} label={t('المواسم')} />

            <PageHeader
                title={season.label}
                subtitle={dateRange(season.starts_at, season.ends_at, locale)}
                actions={
                    <>
                        <Badge variant={STATUS_TONE[season.status]}>{season.statusLabel}</Badge>
                        <Button variant="outline" onClick={() => setEdit(true)}>
                            {t('تعديل')}
                        </Button>
                        <Button variant="outline" onClick={remove} aria-label={t('حذف الموسم')} title={t('حذف الموسم')}>
                            <Trash2 className="text-[#b91c1c]" />
                        </Button>
                    </>
                }
            />

            <div className="mb-4 flex flex-wrap gap-1.5 text-[12px]">
                <span className={cn('inline-flex items-center gap-1 rounded-full px-2.5 py-1', season.show_in_pos ? 'bg-[#f0fdf4] text-[#15803d]' : 'bg-[#f4f4f5] text-[#a1a1aa] line-through')}>
                    <Store className="size-3.5" /> {t('نقطة البيع')}
                </span>
                <span className={cn('inline-flex items-center gap-1 rounded-full px-2.5 py-1', season.show_on_website ? 'bg-[#f0fdf4] text-[#15803d]' : 'bg-[#f4f4f5] text-[#a1a1aa] line-through')}>
                    <Globe className="size-3.5" /> {t('الموقع الإلكتروني')}
                </span>
                {season.daysUntil !== null && (
                    <span className="inline-flex items-center gap-1 rounded-full bg-[#eff6ff] px-2.5 py-1 text-[#1d4ed8]">
                        <CalendarDays className="size-3.5" /> {t('يبدأ بعد :n يومًا', { n: season.daysUntil })}
                    </span>
                )}
            </div>

            {/* ═══ الأداء — لمن يقرأ التقارير؛ والخادمُ لا يرسله لسواه ═══ */}
            {performance && context && (
                <div className="mb-4">
                    <Performance seasonId={season.id} data={performance} currency={context.currency} />
                </div>
            )}

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_380px]">
                {/* ═══ منتجات الموسم ═══ */}
                <Card className="p-4">
                    <div className="mb-3 flex items-center justify-between gap-2">
                        <h2 className="flex items-center gap-1.5 text-[14px] font-bold text-[#111]">
                            <Package className="size-4" /> {t('منتجات الموسم')}
                            <span className="text-[12px] font-normal text-[#9ca3af]">({season.products.length})</span>
                        </h2>
                        <Button size="sm" onClick={() => setPicker(true)}>
                            <Plus /> {t('إضافة منتجات')}
                        </Button>
                    </div>

                    {season.products.length === 0 ? (
                        <p className="rounded-[12px] border border-dashed border-[var(--ui-border,#e8e8e8)] p-8 text-center text-[13px] text-[#9ca3af]">
                            {t('لا منتجات في هذا الموسم بعد — أضف منتجاتك القائمة إليه.')}
                        </p>
                    ) : (
                        <ul className="divide-y divide-[#f1f1f0]">
                            {season.products.map((p) => (
                                <li key={p.id} className="flex items-center gap-3 py-2.5">
                                    <span className="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-[10px] bg-[#f4f4f5] text-lg">
                                        {p.image ? <img src={p.image} alt="" className="size-full object-cover" /> : '📦'}
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-[13.5px] font-medium text-[#111]">{p.label}</span>
                                        <span className="block truncate text-[11.5px] text-[#9ca3af]">
                                            {[p.sku, p.category].filter(Boolean).join(' · ')}
                                        </span>
                                    </span>
                                    {!p.active && <Badge variant="neutral">{t('غير مفعّل')}</Badge>}
                                    <Badge status={p.stock_status} />
                                    <button
                                        type="button"
                                        onClick={() => detach(p)}
                                        aria-label={t('إزالة :name من الموسم', { name: p.label })}
                                        className="rounded-full p-1.5 text-[#9ca3af] hover:bg-[#fee2e2] hover:text-[#b91c1c]"
                                    >
                                        <X className="size-4" />
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>

                {/* ═══ التذكيرات ═══ */}
                <Reminders season={season} maxReminders={maxReminders} presets={presets} locale={locale} />
            </div>

            <SeasonDialog open={edit} season={season} onClose={() => setEdit(false)} />
            <ProductPicker open={picker} seasonId={season.id} onClose={() => setPicker(false)} />
        </AdminLayout>
    );
}

/* ═══════════════════ مُنتقي الأصناف ═══════════════════ */

function ProductPicker({ open, seasonId, onClose }: { open: boolean; seasonId: number; onClose: () => void }) {
    const t = useTranslate();
    const [q, setQ] = useState('');
    const [rows, setRows] = useState<SeasonProduct[]>([]);
    const [loading, setLoading] = useState(false);
    const [picked, setPicked] = useState<Set<number>>(new Set());
    const form = useForm<{ product_ids: number[] }>({ product_ids: [] });

    /*
     * بحثٌ في الخادم لا تحميلٌ للكتالوج كلِّه: متجرٌ بعشرة آلاف صنف لا يُحمَّل
     * في نافذة. والطلبُ يُؤجَّل ربعَ ثانيةٍ بعد آخر حرف.
     */
    useEffect(() => {
        if (!open) return;
        const id = setTimeout(async () => {
            setLoading(true);
            try {
                const res = await fetch(`${route('admin.seasons.products', seasonId)}?q=${encodeURIComponent(q)}`, {
                    headers: { Accept: 'application/json' },
                });
                if (res.ok) setRows(((await res.json()).products ?? []) as SeasonProduct[]);
            } finally {
                setLoading(false);
            }
        }, 250);
        return () => clearTimeout(id);
    }, [q, open, seasonId]);

    useEffect(() => {
        if (open) {
            setQ('');
            setPicked(new Set());
        }
    }, [open]);

    const toggle = (id: number) =>
        setPicked((s) => {
            const n = new Set(s);
            if (n.has(id)) n.delete(id);
            else n.add(id);
            return n;
        });

    const submit = () => {
        form.transform(() => ({ product_ids: Array.from(picked) }));
        form.post(route('admin.seasons.attach', seasonId), { preserveScroll: true, onSuccess: () => onClose() });
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('إضافة منتجات إلى الموسم')}</DialogTitle>
                </DialogHeader>

                <div className="space-y-3 px-5 pb-5">
                <div className="relative">
                    <Search className="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-[#9ca3af]" />
                    <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('ابحث بالاسم أو الرمز أو الباركود...')} className="ps-9" autoFocus />
                </div>

                <ul className="max-h-[50dvh] divide-y divide-[#f1f1f0] overflow-y-auto rounded-[12px] border border-[var(--ui-border,#e8e8e8)]">
                    {rows.length === 0 && (
                        <li className="p-6 text-center text-[13px] text-[#9ca3af]">{loading ? t('جارٍ البحث…') : t('لا نتائج')}</li>
                    )}
                    {rows.map((p) => (
                        <li key={p.id}>
                            <label className="flex cursor-pointer items-center gap-3 px-3 py-2 hover:bg-[#fafaf9]">
                                <input type="checkbox" checked={picked.has(p.id)} onChange={() => toggle(p.id)} className="size-4 accent-[#111]" />
                                <span className="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-[8px] bg-[#f4f4f5]">
                                    {p.image ? <img src={p.image} alt="" className="size-full object-cover" /> : '📦'}
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-[13px] font-medium text-[#111]">{p.label}</span>
                                    <span className="block truncate text-[11px] text-[#9ca3af]">{[p.sku, p.category].filter(Boolean).join(' · ')}</span>
                                </span>
                                <Badge status={p.stock_status} />
                            </label>
                        </li>
                    ))}
                </ul>

                <div className="flex items-center justify-between gap-2">
                    <span className="text-[12px] text-[#71717a]">{t(':n مختار', { n: picked.size })}</span>
                    <div className="flex gap-2">
                        <Button variant="outline" onClick={onClose}>{t('إلغاء')}</Button>
                        <Button onClick={submit} disabled={picked.size === 0} loading={form.processing}>
                            {t('إضافة إلى الموسم')}
                        </Button>
                    </div>
                </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}

/* ═══════════════════ التذكيرات ═══════════════════ */

/** اسمُ المدّة بالعربيّة — والشهرُ ثلاثون يومًا لا شهرٌ تقويميّ */
export function presetLabel(days: number, t: (k: string, v?: Record<string, string | number>) => string): string {
    return (
        {
            7: t('قبل أسبوع'),
            14: t('قبل أسبوعين'),
            30: t('قبل شهر'),
            60: t('قبل شهرين'),
        }[days] ?? t('قبل :n يومًا', { n: days })
    );
}

/**
 * مُنتقي المدّة — أربعةُ اختصاراتٍ ومخصّص.
 *
 * والمخصّصُ يظهر حقلُه متى كان العددُ خارجَ الاختصارات، فمن فتح تذكيرًا
 * عنده ٤٥ يومًا وجد حقلَه مفتوحًا على رقمه لا صفرًا يُعيد كتابتَه.
 */
export function DaysPicker({
    presets,
    value,
    onChange,
    error,
}: {
    presets: number[];
    value: string;
    onChange: (v: string) => void;
    error?: string;
}) {
    const t = useTranslate();
    const n = Number(value);
    const isPreset = presets.includes(n);
    const [custom, setCustom] = useState(! isPreset);

    return (
        <Field label="متى يصلك التنبيه" error={error}>
            <div className="flex flex-wrap gap-1.5" role="group" aria-label={t('متى يصلك التنبيه')}>
                {presets.map((d) => (
                    <button
                        key={d}
                        type="button"
                        aria-pressed={! custom && n === d}
                        data-testid={'preset-' + d}
                        onClick={() => {
                            setCustom(false);
                            onChange(String(d));
                        }}
                        className={cn(
                            'rounded-full px-3 py-1.5 text-[12.5px] font-medium transition-colors',
                            ! custom && n === d ? 'bg-[#111] text-white' : 'bg-[#f2f2f0] text-[#4b4b4b] hover:bg-[#e9e9e6]',
                        )}
                    >
                        {presetLabel(d, t)}
                    </button>
                ))}
                <button
                    type="button"
                    aria-pressed={custom}
                    data-testid="preset-custom"
                    onClick={() => setCustom(true)}
                    className={cn(
                        'rounded-full px-3 py-1.5 text-[12.5px] font-medium transition-colors',
                        custom ? 'bg-[#111] text-white' : 'bg-[#f2f2f0] text-[#4b4b4b] hover:bg-[#e9e9e6]',
                    )}
                >
                    {t('عدد أيام مخصص')}
                </button>
            </div>

            {custom && (
                <Input
                    type="number"
                    min={0}
                    max={365}
                    dir="ltr"
                    className="mt-2 max-w-[10rem]"
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    aria-label={t('عدد الأيام قبل البداية')}
                />
            )}
        </Field>
    );
}

function Reminders({ season, maxReminders, presets, locale }: { season: Props['season']; maxReminders: number; presets: number[]; locale: string }) {
    const t = useTranslate();
    const form = useForm({ type: 'relative' as 'relative' | 'fixed', days_before: '14', remind_at: '', message: '' });
    const [editing, setEditing] = useState<number | null>(null);
    const full = season.reminders.length >= maxReminders;

    const submit = () => {
        form.post(route('admin.seasons.reminders.store', season.id), {
            preserveScroll: true,
            onSuccess: () => form.reset('message', 'remind_at'),
        });
    };

    const sorted = useMemo(
        () => [...season.reminders].sort((a, b) => (a.due_at ?? '').localeCompare(b.due_at ?? '')),
        [season.reminders],
    );

    return (
        <Card className="p-4">
            <h2 className="mb-3 flex items-center gap-1.5 text-[14px] font-bold text-[#111]">
                <Bell className="size-4" /> {t('التذكيرات')}
                <span className="text-[12px] font-normal text-[#9ca3af]">({season.reminders.length})</span>
            </h2>

            {sorted.length > 0 && (
                <ul className="mb-4 space-y-2">
                    {sorted.map((r) => (
                        <li key={r.id} className={cn('rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-3', !r.active && 'opacity-60')}>
                            <div className="flex items-start gap-2">
                                <CalendarClock className="mt-0.5 size-4 shrink-0 text-[#71717a]" />
                                <div className="min-w-0 flex-1">
                                    <p className="text-[13px] font-medium text-[#111]" dir="auto">{r.message}</p>
                                    <p className="mt-0.5 flex flex-wrap items-center gap-1 text-[11.5px] text-[#71717a]">
                                        <span>
                                            {r.type === 'relative'
                                                ? presetLabel(r.days_before ?? 0, t)
                                                : t('تاريخ محدد')}
                                        </span>
                                        <span className="text-[#d4d4d8]">·</span>
                                        <span>{when(r.due_at, locale)}</span>
                                        {/* وما حان يُقال إنّه حان — ومكانُه في قائمة المواسم */}
                                        {r.due && <Badge variant="warning">{t('حان')}</Badge>}
                                        {r.acknowledged && <span className="text-[#9ca3af]">{t('قُرئ')}</span>}
                                    </p>

                                    {/* وتعديلُ الموعد في موضعه — لا حذفٌ وإعادةُ إنشاء */}
                                    {r.type === 'relative' && editing === r.id && (
                                        <div className="mt-2">
                                            <DaysPicker
                                                presets={presets}
                                                value={String(r.days_before ?? 0)}
                                                onChange={(v) =>
                                                    router.patch(
                                                        route('admin.seasons.reminders.update', [season.id, r.id]),
                                                        { type: 'relative', days_before: v },
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            />
                                        </div>
                                    )}
                                </div>

                                {r.type === 'relative' && (
                                    <button
                                        type="button"
                                        aria-label={t('عدّل الموعد')}
                                        data-testid={'edit-reminder-' + r.id}
                                        onClick={() => setEditing(editing === r.id ? null : r.id)}
                                        className="rounded-full p-1 text-[#9ca3af] hover:bg-[#f3f4f6] hover:text-[#111]"
                                    >
                                        <Pencil className="size-4" />
                                    </button>
                                )}
                                <button
                                    type="button"
                                    role="switch"
                                    aria-checked={r.active}
                                    aria-label={t('تفعيل التذكير')}
                                    onClick={() => router.patch(route('admin.seasons.reminders.update', [season.id, r.id]), { active: !r.active }, { preserveScroll: true })}
                                    className={cn('relative h-5 w-9 shrink-0 rounded-full transition-colors', r.active ? 'bg-[#111]' : 'bg-[#d1d5db]')}
                                >
                                    <span className={cn('absolute top-0.5 size-4 rounded-full bg-white shadow transition-[inset-inline-start]', r.active ? 'start-[18px]' : 'start-0.5')} />
                                </button>
                                <button
                                    type="button"
                                    aria-label={t('حذف التذكير')}
                                    onClick={() => router.delete(route('admin.seasons.reminders.destroy', [season.id, r.id]), { preserveScroll: true })}
                                    className="rounded-full p-1 text-[#9ca3af] hover:bg-[#fee2e2] hover:text-[#b91c1c]"
                                >
                                    <X className="size-4" />
                                </button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            {full ? (
                <p className="text-[12px] text-[#b45309]">{t('بلغ الموسم أقصى عدد من التذكيرات (:n).', { n: maxReminders })}</p>
            ) : (
                <form
                    className="space-y-3 rounded-[12px] bg-[#fafaf9] p-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        submit();
                    }}
                >
                    <div role="tablist" aria-label={t('نوع التذكير')} className="inline-flex overflow-hidden rounded-[9px] border border-[var(--ui-border,#e8e8e8)] bg-white">
                        {[
                            { key: 'relative' as const, label: t('قبل بداية الموسم') },
                            { key: 'fixed' as const, label: t('تاريخ محدد') },
                        ].map((k) => (
                            <button
                                key={k.key}
                                type="button"
                                role="tab"
                                aria-selected={form.data.type === k.key}
                                onClick={() => form.setData('type', k.key)}
                                className={cn('px-3 py-1.5 text-[12px] font-medium', form.data.type === k.key ? 'bg-[#111] text-white' : 'text-[#4b4b4b] hover:bg-[#fafafa]')}
                            >
                                {k.label}
                            </button>
                        ))}
                    </div>

                    {form.data.type === 'relative' ? (
                        <DaysPicker
                            presets={presets}
                            value={form.data.days_before}
                            onChange={(v) => form.setData('days_before', v)}
                            error={form.errors.days_before}
                        />
                    ) : (
                        <Field label="التاريخ والوقت" error={form.errors.remind_at}>
                            <Input type="datetime-local" value={form.data.remind_at} onChange={(e) => form.setData('remind_at', e.target.value)} aria-label={t('التاريخ والوقت')} />
                        </Field>
                    )}

                    <Field label="نص التذكير" error={form.errors.message}>
                        <Textarea rows={2} value={form.data.message} onChange={(e) => form.setData('message', e.target.value)} placeholder={t('مثال: مراجعة المنتجات والمخزون')} aria-label={t('نص التذكير')} />
                    </Field>

                    <Button type="submit" size="sm" loading={form.processing} disabled={form.data.message.trim() === ''}>
                        <Plus /> {t('إضافة تذكير')}
                    </Button>
                </form>
            )}
        </Card>
    );
}
