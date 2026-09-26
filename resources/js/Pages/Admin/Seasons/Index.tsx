import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { BellRing, CalendarDays, Globe, Plus, Store, X } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { PRODUCT_TABS } from '@/Components/SectionTabs';
import SmartLink from '@/Components/SmartLink';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import SeasonDialog, { type SeasonFields } from './SeasonDialog';

export interface SeasonRow extends SeasonFields {
    id: number;
    label: string;
    status: 'upcoming' | 'active' | 'ended' | 'inactive';
    statusLabel: string;
    daysUntil: number | null;
    productsCount: number;
    remindersCount: number;
    nextReminder: { at: string; message: string } | null;
}

/** تنبيهٌ حان وقتُه ولم يُقرأ في دورة موسمه */
export interface SeasonAlert {
    id: number;
    seasonId: number;
    season: string;
    message: string;
    /** أيّامٌ إلى بداية الموسم — سالبةٌ إن كان جاريًا */
    days: number;
    startsAt: string;
    dueAt: string | null;
}

interface Props {
    seasons: SeasonRow[];
    filter: string;
    counts: Record<string, number>;
    alerts: SeasonAlert[];
}

/**
 * ما يُقرأ في رأس التنبيه — «باقي ٣٠ يومًا على موسم رمضان».
 *
 * والصفرُ «يبدأ اليوم» لا «باقي ٠ يومًا»، والسالبُ «جارٍ الآن»: موسمٌ بدأ
 * ولمّا يُقرأ تنبيهُه يُقال فيه الحقّ لا عددٌ بالسالب.
 */
export function alertHeadline(
    a: SeasonAlert,
    t: (key: string, replace?: Record<string, string | number>) => string,
): string {
    if (a.days > 0) return t('باقي :n يومًا على موسم :season', { n: a.days, season: a.season });
    if (a.days === 0) return t('موسم :season يبدأ اليوم', { season: a.season });

    return t('موسم :season جارٍ الآن', { season: a.season });
}

export const STATUS_TONE: Record<SeasonRow['status'], 'info' | 'success' | 'neutral' | 'warning'> = {
    upcoming: 'info',
    active: 'success',
    ended: 'neutral',
    inactive: 'warning',
};

/** مدى الموسم بلغة الواجهة — «8 فبراير — 9 مارس» */
export function dateRange(starts: string, ends: string, locale: string): string {
    const tag = locale === 'en' ? 'en-u-nu-latn' : 'ar-u-nu-latn';
    const fmt = (iso: string, withYear: boolean) =>
        new Date(iso + 'T00:00:00').toLocaleDateString(tag, { day: 'numeric', month: 'long', ...(withYear ? { year: 'numeric' } : {}) });
    const sameYear = starts.slice(0, 4) === ends.slice(0, 4);

    return `${fmt(starts, !sameYear)} — ${fmt(ends, true)}`;
}

export function reminderDate(iso: string, locale: string): string {
    return new Date(iso).toLocaleDateString(locale === 'en' ? 'en-u-nu-latn' : 'ar-u-nu-latn', { day: 'numeric', month: 'long' });
}

/**
 * ═══ ما حان وينتظره ═══
 *
 * يُحسب عند كلّ فتحةٍ من بداية الموسم، فلا يُشترط أن تكون الصفحة مفتوحةً
 * وقتَ الموعد: من غاب أسبوعًا وجد تنبيهَه واقفًا.
 *
 * وهنا وحدَه — لا في الجرس ولا في لوحة التحكّم.
 */
export function SeasonAlerts({ alerts }: { alerts: SeasonAlert[] }) {
    const t = useTranslate();

    if (alerts.length === 0) {
        return null;
    }

    return (
        <div data-testid="season-alerts" className="mb-4 space-y-2">
            {alerts.map((a) => (
                <div
                    key={a.id}
                    data-testid={'season-alert-' + a.id}
                    className="flex flex-wrap items-start gap-3 rounded-[14px] border border-[#fcd34d] bg-[#fffbeb] px-4 py-3"
                >
                    <BellRing className="mt-0.5 size-4 shrink-0 text-[#b45309]" />
                    <div className="min-w-0 flex-1">
                        <p className="text-[13.5px] font-semibold text-[#92400e]" dir="auto">
                            {alertHeadline(a, t)}
                        </p>
                        <p className="mt-0.5 text-[12.5px] leading-relaxed text-[#b45309]" dir="auto">
                            {a.message}
                        </p>
                    </div>
                    <div className="flex shrink-0 items-center gap-1">
                        <Button variant="ghost" size="sm" asChild>
                            <SmartLink routeName="admin.seasons.show" href={route('admin.seasons.show', a.seasonId)}>
                                {t('افتح الموسم')}
                            </SmartLink>
                        </Button>
                        <button
                            type="button"
                            aria-label={t('أخفِ التنبيه')}
                            data-testid={'season-alert-read-' + a.id}
                            onClick={() =>
                                router.post(
                                    route('admin.seasons.reminders.read', [a.seasonId, a.id]),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                            className="rounded-full p-1 text-[#b45309] hover:bg-[#fde68a]"
                        >
                            <X className="size-4" />
                        </button>
                    </div>
                </div>
            ))}
        </div>
    );
}

export default function SeasonsIndex() {
    const { seasons, filter, counts, alerts, locale } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const [dialog, setDialog] = useState<{ open: boolean; season: SeasonRow | null }>({ open: false, season: null });

    const filters = [
        { key: 'all', label: t('الكل') },
        { key: 'upcoming', label: t('قادمة') },
        { key: 'active', label: t('نشطة') },
        { key: 'ended', label: t('منتهية') },
        { key: 'inactive', label: t('غير فعالة') },
    ];

    return (
        <AdminLayout title="المواسم">
            <PageHeader
                title="المواسم"
                subtitle={t('نظّم منتجاتك حسب المواسم والمناسبات وحدد مواعيد الاستعداد لها.')}
                actions={
                    <Button onClick={() => setDialog({ open: true, season: null })}>
                        <Plus />
                        {t('موسم جديد')}
                    </Button>
                }
            />

            <SectionTabs tabs={PRODUCT_TABS} current="admin.seasons.index" />

            <SeasonAlerts alerts={alerts} />

            <div className="mb-4 flex flex-wrap gap-1.5" role="group" aria-label={t('الحالة')}>
                {filters.map((f) => (
                    <button
                        key={f.key}
                        type="button"
                        aria-pressed={filter === f.key}
                        onClick={() => router.get(route('admin.seasons.index'), f.key === 'all' ? {} : { status: f.key }, { preserveState: true, replace: true })}
                        className={cn(
                            'rounded-full px-3 py-1.5 text-[12.5px] font-medium transition-colors',
                            filter === f.key ? 'bg-[#111] text-white' : 'bg-[#f2f2f0] text-[#4b4b4b] hover:bg-[#e9e9e6]',
                        )}
                    >
                        {f.label}
                        {(counts[f.key] ?? 0) > 0 && <span className="ms-1.5 tabular-nums opacity-70">{counts[f.key]}</span>}
                    </button>
                ))}
            </div>

            {seasons.length === 0 ? (
                <Card className="p-10 text-center">
                    <CalendarDays className="mx-auto size-9 text-[#d4d4d8]" />
                    <p className="mt-3 text-[14px] font-medium text-[#4b4b4b]">{t('لا مواسم بعد')}</p>
                    <p className="mt-1 text-[12.5px] text-[#9ca3af]">
                        {t('أنشئ موسمًا مثل رمضان أو العيد، واربط به منتجاتك، وضع تذكيرات للاستعداد له.')}
                    </p>
                </Card>
            ) : (
                <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                    {seasons.map((s) => (
                        <SeasonCard key={s.id} season={s} locale={locale} onEdit={() => setDialog({ open: true, season: s })} />
                    ))}
                </div>
            )}

            <SeasonDialog open={dialog.open} season={dialog.season} onClose={() => setDialog({ open: false, season: null })} />
        </AdminLayout>
    );
}

export function SeasonCard({ season: s, locale, onEdit }: { season: SeasonRow; locale: string; onEdit: () => void }) {
    const t = useTranslate();

    return (
        <Card className={cn('flex flex-col gap-3 p-4', s.status === 'inactive' && 'opacity-70')}>
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <h3 className="truncate text-[15px] font-bold text-[#111]">{s.label}</h3>
                    <p className="mt-0.5 text-[12.5px] text-[#71717a]">{dateRange(s.starts_at, s.ends_at, locale)}</p>
                </div>
                <Badge variant={STATUS_TONE[s.status]}>{s.statusLabel}</Badge>
            </div>

            <div className="flex flex-wrap gap-x-4 gap-y-1 text-[12.5px] text-[#4b4b4b]">
                <span>{t(':n منتج', { n: s.productsCount })}</span>
                <span>{t(':n تذكيرات', { n: s.remindersCount })}</span>
            </div>

            <div className="flex flex-wrap gap-1.5 text-[11.5px]">
                <span className={cn('inline-flex items-center gap-1 rounded-full px-2 py-0.5', s.show_in_pos ? 'bg-[#f0fdf4] text-[#15803d]' : 'bg-[#f4f4f5] text-[#a1a1aa] line-through')}>
                    <Store className="size-3" /> {t('نقطة البيع')}
                </span>
                <span className={cn('inline-flex items-center gap-1 rounded-full px-2 py-0.5', s.show_on_website ? 'bg-[#f0fdf4] text-[#15803d]' : 'bg-[#f4f4f5] text-[#a1a1aa] line-through')}>
                    <Globe className="size-3" /> {t('الموقع الإلكتروني')}
                </span>
            </div>

            {s.nextReminder && (
                <p className="rounded-[10px] bg-[#fafaf9] px-3 py-2 text-[12px] text-[#4b4b4b]">
                    <span className="text-[#9ca3af]">{t('التذكير القادم')}: </span>
                    {reminderDate(s.nextReminder.at, locale)} · {s.nextReminder.message}
                </p>
            )}

            <div className="mt-auto flex gap-2">
                <Button asChild size="sm" className="flex-1">
                    <SmartLink routeName="admin.seasons.show" href={route('admin.seasons.show', s.id)}>
                        {t('فتح')}
                    </SmartLink>
                </Button>
                <Button size="sm" variant="outline" onClick={onEdit}>
                    {t('تعديل')}
                </Button>
            </div>
        </Card>
    );
}
