import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Bell, ChevronLeft, ChevronRight, Link2Off, MapPin, MessageSquare, RefreshCw, Star, Store } from 'lucide-react';
import { GoogleMapsMark } from '@/Components/BrandMarks';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import Gate from '@/Components/Gate';
import StatusPill from '@/Components/StatusPill';
import { PageActions, SettingsPage, SettingsSection } from '@/Components/Settings';
import { useConfirm } from '@/Components/ConfirmDialog';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { useTranslate } from '@/lib/i18n';
import { csrfHeaders } from '@/lib/csrf';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

/* حدُّ الردّ عند Google — يُقاس في الحقل وفي الخادم معًا */
const REPLY_MAX = 4000;

interface BranchRow {
    id: number;
    name: string;
    location: string | null;
    linked: boolean;
    reviews: number;
    /** معرّفُ الفرع على الخرائط — به يُعلَّم موقعُه في قائمة الاختيار */
    placeId?: string | null;
    syncedAt?: string | null;
    /** سببُ تعثّر سحب هذا الفرع وحده */
    syncError?: string | null;
}

interface ReviewRow {
    id: number;
    branch: string | null;
    rating: number;
    comment: string | null;
    author: string | null;
    photo: string | null;
    at: string | null;
    /** الردُّ **عند Google** — لا ما كُتب في الحقل */
    reply: string | null;
    repliedAt: string | null;
}

interface LocationRow {
    name: string;
    title: string;
    address: string;
    account: string;
    accountTitle: string;
    placeId?: string;
}

interface ReviewsPage {
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

interface ReviewFilters {
    branch: number | null;
    stars: number | null;
    unanswered: boolean;
}

interface Props {
    configured: boolean;
    /** الحالُ كما يقرؤها الخادم (`GoogleBusiness::state`) */
    state?: string;
    connected: boolean;
    /** انقطع الإذنُ من جهة Google — يُعيده التاجر بنفسه */
    reconnect?: boolean;
    lastError?: string | null;
    account: {
        email: string | null;
        accountName: string | null;
        linkedAt: string | null;
        syncedAt: string | null;
        error: string | null;
    } | null;
    branches: BranchRow[];
    reviews: ReviewRow[];
    pagination?: ReviewsPage;
    filters?: ReviewFilters;
    alerts: { enabled: boolean; threshold: number };
}

function Stars({ value }: { value: number }) {
    return (
        <span className="inline-flex items-center gap-0.5" aria-label={`${value}/5`}>
            {[1, 2, 3, 4, 5].map((i) => (
                <Star
                    key={i}
                    className={cn('size-3.5', i <= value ? 'fill-[#f59e0b] text-[#f59e0b]' : 'text-[#d4d4d8]')}
                />
            ))}
        </span>
    );
}

export default function GoogleBusinessPage() {
    const {
        configured,
        state = 'ready',
        connected,
        reconnect = false,
        lastError = null,
        account,
        branches,
        reviews,
        pagination,
        filters = { branch: null, stars: null, unanswered: false },
        alerts,
    } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    // نافذةُ التأكيد من النظام لا من المتصفّح — انظر ConfirmDialog
    const [ask, confirmDialog] = useConfirm();

    const [replying, setReplying] = useState<ReviewRow | null>(null);
    const [picking, setPicking] = useState<BranchRow | null>(null);

    const alertForm = useForm({
        gbp_alerts_enabled: alerts.enabled,
        gbp_low_rating: alerts.threshold,
    });

    const blocked = state === 'api_blocked';

    /* والمرشِّحُ في الخادم: التقييماتُ صفحاتٌ، وما في الصفحة بعضُ ما عنده */
    const filter = (next: Partial<ReviewFilters>) => {
        const merged = { ...filters, ...next };

        router.get(
            route('admin.integrations.googleBusiness'),
            {
                ...(merged.branch ? { branch: merged.branch } : {}),
                ...(merged.stars ? { stars: merged.stars } : {}),
                ...(merged.unanswered ? { unanswered: 1 } : {}),
            },
            { preserveScroll: true, preserveState: true },
        );
    };

    /*
        بابٌ قبل الشاشة.

        وثلاثةٌ يحتاجها التكامل: عميلُ OAuth وسرُّه ووصولٌ **معتمَدٌ** من
        Google. وغيابُ أيٍّ منها يعني زرًّا يقود إلى صفحة خطأٍ عندهم لا يفهم
        منها التاجر شيئًا — فيُقال الحالُ ولا يُعرض الزرّ.
    */
    if (! configured) {
        return (
            <AdminLayout title={t('تقييمات Google')}>
                <PageHeader
                    title={t('تقييمات Google')}
                    subtitle={t('اقرأ تقييماتك كلّها وردّ عليها باسم متجرك')}
                    actions={<StatusPill state="progress" label="غير متاحة بعد" />}
                />
                <Gate
                    mark={<GoogleMapsMark size={80} />}
                    title="إدارة تقييمات Google غير متاحة على المنصّة بعد"
                    /*
                        والفرقُ بينها وبين «ربط خرائط Google» يُقال هنا: اسمان
                        متقاربان، ومن ظنّهما واحدًا يعود يبحث عمّا ربطه.
                    */
                    description="وهي غير «ربط خرائط Google»: تلك تقرأ المعدّل وعدد التقييمات، وهذه تفتح ملفّ متجرك لتقرأ تقييماته كلّها وتردّ عليها باسمك."
                    /*
                        وهذه حالُ المنصّة لا حالُ المتجر: عميلُ OAuth لأبعاد لم يُهيَّأ.
                        فلا يُطلب من التاجر أن يراسل أحدًا — يظهر زرُّ الربط هنا
                        وحده متى صارت متاحة، ويربط حسابه بنفسه.
                    */
                    note={t('متى أُتيحت سيظهر هنا زر «ربط حساب Google» لتربط حسابك بنفسك — لا شيء عليك أن تفعله الآن.')}
                />
            </AdminLayout>
        );
    }

    /*
        بابٌ قبل الشاشة — لمن لم يربط حسابه.

        وكان بانرًا أحمرَ فوق بطاقةٍ فيها زرُّ ربط، وتحتها فراغ: لا فروعَ
        ولا تنبيهاتٍ ولا تقييمات. فيقرأ التاجر «لا تُقرأ تقييمة» ثمّ يمرّ
        بصفحةٍ خاويةٍ ليتأكّد.
    */
    if (! connected) {
        return (
            <AdminLayout title={t('تقييمات Google')}>
                <PageHeader
                    title={t('تقييمات Google')}
                    subtitle={t('اقرأ تقييماتك كلّها وردّ عليها باسم متجرك')}
                    actions={<StatusPill state={reconnect ? 'error' : 'idle'} label={reconnect ? 'انقطع الربط' : 'غير مربوط'} />}
                />
                {/*
                    وانقطاعُ الإذن غيرُ «لم يُربط بعد»: سحبته Google أو انتهى، والتاجر
                    يُعيده بنفسه من الزرّ نفسِه — لا يراسل أحدًا ليُعيده له.
                */}
                <Gate
                    mark={<GoogleMapsMark size={80} />}
                    title={reconnect ? 'انقطع ربط حساب Google' : 'لم يُربط حساب Google بعد'}
                    description={
                        reconnect
                            ? 'انتهى الإذن أو سُحب من حساب Google. أعِد الربط بحسابك لتعود قراءة التقييمات والرد عليها.'
                            : 'مفتاح الخرائط لا يكفي: قراءة تقييماتك كلّها والرد عليها يحتاجان إذنك الصريح من حساب Google الذي يملك النشاط أو يديره، ويُلغى متى شئت.'
                    }
                    action={
                        <Button asChild size="lg">
                            <a href={route('admin.integrations.googleBusiness.connect')} data-testid="gbp-connect">
                                {t(reconnect ? 'إعادة ربط حساب Google' : 'ربط حساب Google Business')}
                            </a>
                        </Button>
                    }
                    /* وخطأُ Google الأخير يُقال بنصّه: «لم تُسحب» لا تقول ما يُصلَح */
                    note={lastError ?? account?.error}
                />
            </AdminLayout>
        );
    }

    return (
        <AdminLayout title={t('تقييمات Google')}>
            <PageHeader
                title={t('تقييمات Google')}
                subtitle={t('اقرأ تقييماتك كلّها وردّ عليها باسم متجرك')}
                actions={
                    blocked
                        ? <StatusPill state="progress" label="بانتظار اعتماد Google" />
                        : <StatusPill state={account?.error ? 'error' : 'ready'} connected />
                }
            />

            <SettingsPage width="wide">
                {/*
                    وعدمُ الاعتماد عند أبعاد لا عند التاجر: حسابُه مربوطٌ ولا شيء عليه،
                    فلا يُعرض له «أعد الربط» ولا يُقال له راجع حسابك.
                */}
                {blocked && (
                    <div
                        data-testid="gbp-api-blocked"
                        className="rounded-[12px] border border-[#fde68a] bg-[#fffbeb] p-4 text-[13px] leading-relaxed text-[#92400e]"
                    >
                        {t('حسابك مربوط، لكنّ Google لم تعتمد واجهات Business Profile لأبعاد بعد. ليس عليك شيء، ويعود العمل تلقائيًّا بعد اعتمادها.')}
                    </div>
                )}

                {/* ═══════════ حال الربط ═══════════ */}
                <SettingsSection
                    title="حساب Google المربوط"
                    description="منه تُقرأ التقييمات، وباسمه تُنشر الردود."
                    icon={Star}
                    status={<StatusPill state={account?.error ? 'error' : 'ready'} connected />}
                    action={
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={async () => {
                                const go = await ask({
                                    message: 'إلغاء ربط حساب Google؟ ستتوقف قراءة التقييمات والرد عليها.',
                                    danger: true,
                                    action: 'إلغاء الربط',
                                });

                                if (! go) return;

                                router.delete(route('admin.integrations.googleBusiness.disconnect'), {
                                    preserveScroll: true,
                                });
                            }}
                        >
                            <Link2Off />
                            {t('إلغاء الربط')}
                        </Button>
                    }
                >
                    <dl className="space-y-2 rounded-[12px] bg-[#fafafa] p-4 text-[13px]">
                        <div className="flex flex-wrap justify-between gap-3">
                            <dt className="text-[#6b7280]">{t('الحساب')}</dt>
                            <dd dir="ltr" className="font-medium text-[#111]">{account?.email ?? '—'}</dd>
                        </div>
                        {account?.syncedAt && (
                            <div className="flex flex-wrap justify-between gap-3">
                                <dt className="text-[#6b7280]">{t('آخر سحب')}</dt>
                                <dd className="text-[#111]">{account.syncedAt}</dd>
                            </div>
                        )}
                    </dl>

                    {/* وخطأُ Google الأخير يُقال بنصّه: «لم تُسحب» لا تقول ما يُصلَح */}
                    {account?.error && ! blocked && (
                        <div className="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-[10px] bg-[#fef2f2] p-3">
                            <p className="text-[13px] text-[#b91c1c]">{account.error}</p>
                            {/* والإصلاحُ بيده: إعادةُ الإذن من حسابه هو */}
                            <Button asChild size="sm" variant="outline">
                                <a href={route('admin.integrations.googleBusiness.connect')} data-testid="gbp-reconnect">
                                    <RefreshCw />
                                    {t('إعادة ربط حساب Google')}
                                </a>
                            </Button>
                        </div>
                    )}
                </SettingsSection>

                <>
                    {/* ═══════════ الفروع ومواقعها ═══════════ */}
                    <SettingsSection
                        title="الفروع ومواقعها"
                        description="لكلّ فرعٍ موقعٌ في ملفّ أعمالك — وتقييماته تُسحب إليه وحده."
                        icon={MapPin}
                        status={
                            <span className="text-[13px] font-medium tabular-nums text-[#6b7280]" dir="ltr">
                                {branches.filter((b) => b.linked).length} / {branches.length}
                            </span>
                        }
                    >
                        <ul className="space-y-3">
                            {branches.map((b) => (
                                <li
                                    key={b.id}
                                    className="flex flex-wrap items-center justify-between gap-3 rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-4"
                                >
                                    <div className="min-w-0">
                                        <p className="flex items-center gap-1.5 font-medium text-[#111]">
                                            <Store className="size-4 shrink-0 text-[#9ca3af]" />
                                            {b.name}
                                        </p>
                                        <p className="mt-1 text-[13px] text-[#6b7280]">
                                            {b.linked
                                                ? t(':n تقييم', { n: b.reviews })
                                                : t('غير مربوط')}
                                            {b.syncedAt && (
                                                <span className="ms-2 text-[#9ca3af]">
                                                    {t('آخر سحب')}{' '}
                                                    <span dir="ltr">{new Date(b.syncedAt).toLocaleString()}</span>
                                                </span>
                                            )}
                                        </p>
                                        {/* وتعثُّرُ فرعٍ يُقال عليه وحده: جارُه سليم */}
                                        {b.syncError && (
                                            <p data-testid="gbp-branch-error" className="mt-1 text-[12px] text-[#b91c1c]">
                                                {b.syncError}
                                            </p>
                                        )}
                                    </div>

                                    <div className="flex shrink-0 flex-wrap gap-2">
                                        {b.linked && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    router.post(
                                                        route('admin.integrations.googleBusiness.branch.sync', b.id),
                                                        {},
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            >
                                                <RefreshCw />
                                                {t('سحب التقييمات')}
                                            </Button>
                                        )}
                                        <Button
                                            size="sm"
                                            variant={b.linked ? 'outline' : 'primary'}
                                            onClick={() => setPicking(b)}
                                        >
                                            {b.linked ? t('تغيير الموقع') : t('ربط الموقع')}
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </SettingsSection>

                    {/* ═══════════ التنبيهات ═══════════ */}
                    <SettingsSection
                        title="تنبيه عند تقييم منخفض"
                        description="تقييمٌ منخفض يُترك يومين يقرؤه كلّ من يفتح ملفّ محلّك — والردّ في ساعته يقلب أثره."
                        icon={Bell}
                        status={
                            alerts.enabled
                                ? <StatusPill state="ready" label="مفعّل" />
                                : <StatusPill state="off" />
                        }
                    >
                        <label className="flex cursor-pointer items-start gap-2.5">
                            <input
                                type="checkbox"
                                checked={alertForm.data.gbp_alerts_enabled}
                                onChange={(e) => alertForm.setData('gbp_alerts_enabled', e.target.checked)}
                                className="mt-0.5 size-4 rounded border-[#d1d5db] accent-[#111]"
                            />
                            <span className="text-sm text-[#111]">
                                {t('نبّهني في الجرس بالتقييمات الجديدة')}
                            </span>
                        </label>

                        <div className="mt-4 flex flex-wrap items-center gap-2">
                            <span className="text-[13px] text-[#6b7280]">{t('يُعدّ منخفضًا عند')}</span>
                            {[1, 2, 3].map((n) => (
                                <button
                                    key={n}
                                    type="button"
                                    onClick={() => alertForm.setData('gbp_low_rating', n)}
                                    className={cn(
                                        'rounded-[10px] border px-3 py-1.5 text-[13px]',
                                        alertForm.data.gbp_low_rating === n
                                            ? 'border-[#111] bg-[#111] text-white'
                                            : 'border-[var(--ui-border,#e8e8e8)] text-[#4b4b4b] hover:bg-[#fafafa]',
                                    )}
                                >
                                    {t('⭐ :n فأقل', { n })}
                                </button>
                            ))}
                        </div>

                        <PageActions className="mt-5">
                            <Button
                                loading={alertForm.processing}
                                onClick={() =>
                                    alertForm.post(route('admin.integrations.googleBusiness.alerts'), {
                                        preserveScroll: true,
                                    })
                                }
                            >
                                {t('حفظ')}
                            </Button>
                        </PageActions>
                    </SettingsSection>

                    {/* ═══════════ التقييمات ═══════════ */}
                    <SettingsSection
                        title="تقييمات Google"
                        description="ما سُحب من ملفّ أعمالك — والردُّ عليه يُنشر باسم متجرك."
                        icon={MessageSquare}
                        status={
                            <span className="text-[13px] font-medium tabular-nums text-[#6b7280]" dir="ltr">
                                {pagination?.total ?? reviews.length}
                            </span>
                        }
                    >
                        <div className="mb-3 flex flex-wrap items-center gap-2">
                            {branches.length > 1 && (
                                <select
                                    aria-label={t('الفرع')}
                                    value={filters.branch ?? ''}
                                    onChange={(e) => filter({ branch: e.target.value ? Number(e.target.value) : null })}
                                    className="rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white px-3 py-1.5 text-[13px]"
                                >
                                    <option value="">{t('كل الفروع')}</option>
                                    {branches.map((b) => (
                                        <option key={b.id} value={b.id}>{b.name}</option>
                                    ))}
                                </select>
                            )}
                            <select
                                aria-label={t('التقييم')}
                                value={filters.stars ?? ''}
                                onChange={(e) => filter({ stars: e.target.value ? Number(e.target.value) : null })}
                                className="rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white px-3 py-1.5 text-[13px]"
                            >
                                <option value="">{t('كل التقييمات')}</option>
                                {[5, 4, 3, 2, 1].map((n) => (
                                    <option key={n} value={n}>{t('⭐ :n', { n })}</option>
                                ))}
                            </select>
                            <label className="flex cursor-pointer items-center gap-2 text-[13px] text-[#111]">
                                <input
                                    type="checkbox"
                                    checked={filters.unanswered}
                                    onChange={(e) => filter({ unanswered: e.target.checked })}
                                    className="size-4 rounded border-[#d1d5db] accent-[#111]"
                                />
                                {t('بلا ردّ فقط')}
                            </label>
                        </div>

                        {reviews.length === 0 ? (
                            <p className="rounded-[12px] bg-[#fafafa] p-4 text-[13px] text-[#6b7280]">
                                {filters.branch || filters.stars || filters.unanswered
                                    ? t('لا تقييمات تطابق هذا الاختيار.')
                                    : t('لا تقييمات مسحوبة بعد — اربط موقع الفرع ثم اضغط «سحب التقييمات».')}
                            </p>
                        ) : (
                            <ul className="space-y-3">
                                {reviews.map((r) => (
                                    <li
                                        key={r.id}
                                        className="rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-4"
                                    >
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <div className="min-w-0">
                                                <p className="flex flex-wrap items-center gap-2">
                                                    <Stars value={r.rating} />
                                                    <span className="text-[13px] font-medium text-[#111]">
                                                        {r.author ?? t('زائر')}
                                                    </span>
                                                    {r.branch && (
                                                        <span className="text-[12px] text-[#9ca3af]">· {r.branch}</span>
                                                    )}
                                                    {r.at && (
                                                        <span className="text-[12px] text-[#9ca3af]" dir="ltr">
                                                            {new Date(r.at).toLocaleDateString()}
                                                        </span>
                                                    )}
                                                </p>

                                                {r.comment && (
                                                    <p className="mt-2 whitespace-pre-wrap break-words text-[13px] leading-relaxed text-[#4b4b4b]">
                                                        {r.comment}
                                                    </p>
                                                )}
                                            </div>

                                            <Button size="sm" variant="outline" onClick={() => setReplying(r)}>
                                                <MessageSquare />
                                                {r.reply ? t('تعديل الردّ') : t('الرد على التقييم')}
                                            </Button>
                                        </div>

                                        {/*
                                            والردُّ المعروضُ هو ما عند Google.
                                            لا يُكتب هنا حرفٌ قبل أن تقبله — وردٌّ يُعرض
                                            «منشورًا» ولم يُنشر يجعل التاجر يظنّ أنّه أجاب
                                            زبونًا لم يصله شيء.
                                        */}
                                        {r.reply && (
                                            <div className="mt-3 rounded-[10px] bg-[#fafafa] p-3">
                                                <p className="mb-1 text-[11px] font-bold text-[#6b7280]">
                                                    {t('ردّك المنشور على Google')}
                                                    {r.repliedAt && (
                                                        <span className="ms-1 font-normal" dir="ltr">
                                                            {new Date(r.repliedAt).toLocaleDateString()}
                                                        </span>
                                                    )}
                                                </p>
                                                <p className="whitespace-pre-wrap break-words text-[13px] text-[#111]">
                                                    {r.reply}
                                                </p>
                                            </div>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}

                        {pagination && pagination.last_page > 1 && (
                            <div className="mt-4 flex items-center justify-between gap-3">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={! pagination.prev_page_url}
                                    onClick={() => pagination.prev_page_url && router.get(pagination.prev_page_url, {}, { preserveScroll: true })}
                                >
                                    <ChevronRight className="rtl:rotate-0 ltr:rotate-180" />
                                    {t('السابق')}
                                </Button>
                                <span className="text-[12px] text-[#6b7280]">
                                    {t('صفحة :p من :l', { p: pagination.current_page, l: pagination.last_page })}
                                </span>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={! pagination.next_page_url}
                                    onClick={() => pagination.next_page_url && router.get(pagination.next_page_url, {}, { preserveScroll: true })}
                                >
                                    {t('التالي')}
                                    <ChevronLeft className="rtl:rotate-0 ltr:rotate-180" />
                                </Button>
                            </div>
                        )}
                    </SettingsSection>
                </>
            </SettingsPage>

            {replying && <ReplyDialog review={replying} onClose={() => setReplying(null)} />}
            {picking && <LocationDialog branch={picking} onClose={() => setPicking(null)} />}
            {confirmDialog}
        </AdminLayout>
    );
}

/* ═══════════════════ الردّ ═══════════════════ */

function ReplyDialog({ review, onClose }: { review: ReviewRow; onClose: () => void }) {
    const t = useTranslate();
    const form = useForm({ comment: review.reply ?? '' });

    const left = REPLY_MAX - form.data.comment.length;

    return (
        <Dialog open onOpenChange={(o) => ! o && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('الرد على التقييم')}</DialogTitle>
                </DialogHeader>

                <div className="px-5 pb-5">
                    <div className="mb-3 rounded-[10px] bg-[#fafafa] p-3">
                        <Stars value={review.rating} />
                        {review.comment && (
                            <p className="mt-2 whitespace-pre-wrap text-[13px] text-[#4b4b4b]">{review.comment}</p>
                        )}
                    </div>

                    <textarea
                        rows={5}
                        autoFocus
                        maxLength={REPLY_MAX}
                        value={form.data.comment}
                        onChange={(e) => form.setData('comment', e.target.value)}
                        placeholder={t('اكتب ردّك — يظهر باسم متجرك على Google.')}
                        className="w-full resize-none rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-3 text-[13px] outline-none focus:border-[#111]"
                    />

                    {/* والحدُّ يُقرأ وهو يكتب لا بعد أن يُردّ نصُّه */}
                    <p className="mt-1 text-[11px] text-[#9ca3af]" dir="ltr">
                        {left} / {REPLY_MAX}
                    </p>

                    {form.errors.comment && (
                        <p className="mt-2 rounded-[10px] bg-[#fef2f2] p-3 text-[12px] text-[#b91c1c]">
                            {form.errors.comment}
                        </p>
                    )}

                    <div className="mt-4 flex justify-end gap-2">
                        <Button variant="outline" onClick={onClose}>
                            {t('إلغاء')}
                        </Button>
                        <Button
                            loading={form.processing}
                            disabled={form.data.comment.trim() === ''}
                            onClick={() =>
                                form.post(route('admin.integrations.googleBusiness.reply', review.id), {
                                    preserveScroll: true,
                                    onSuccess: onClose,
                                })
                            }
                        >
                            {t('نشر الردّ على Google')}
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}

/* ═══════════════════ اختيار الموقع ═══════════════════ */

function LocationDialog({ branch, onClose }: { branch: BranchRow; onClose: () => void }) {
    const t = useTranslate();
    const [state, setState] = useState<'idle' | 'loading' | 'error'>('idle');
    const [error, setError] = useState<string | null>(null);
    const [locations, setLocations] = useState<LocationRow[]>([]);
    const [saving, setSaving] = useState<string | null>(null);
    /* موقعٌ ليس مكانَ الفرع على الخرائط: يُسأل عنه قبل الربط */
    const [confirming, setConfirming] = useState<string | null>(null);

    const link = (l: LocationRow, confirmed: boolean) => {
        setSaving(l.name);
        router.post(
            route('admin.integrations.googleBusiness.branch.link', branch.id),
            { location: l.name, account: l.account, confirmed },
            {
                preserveScroll: true,
                onSuccess: onClose,
                onError: (e) => setError(e.location ?? null),
                onFinish: () => setSaving(null),
            },
        );
    };

    /*
        المواقعُ تُقرأ عند فتح النافذة لا في كلّ فتحةِ شاشة.
        نداءان لكلّ حسابٍ ولكلّ موقع، وهي لا تتبدّل في اليوم مرّتين.
    */
    const same = (l: LocationRow) => !! branch.placeId && l.placeId === branch.placeId;

    const load = async () => {
        setState('loading');
        setError(null);

        try {
            const res = await fetch(route('admin.integrations.googleBusiness.locations'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeaders() },
            });
            const body = await res.json();

            if (! body.ok) {
                setState('error');
                setError(body.error ?? t('تعذّر الوصول إلى Google. حاول بعد قليل.'));

                return;
            }

            setLocations(body.locations ?? []);
            setState('idle');
        } catch {
            setState('error');
            setError(t('تعذّر الوصول إلى Google. حاول بعد قليل.'));
        }
    };

    return (
        <Dialog
            open
            onOpenChange={(o) => {
                if (! o) onClose();
            }}
        >
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('اختر موقع الفرع')}</DialogTitle>
                </DialogHeader>

                <div className="px-5 pb-5">
                    <p className="mb-3 text-[13px] text-[#6b7280]">{t('الفرع: :name', { name: branch.name })}</p>

                    {locations.length === 0 && state !== 'loading' && (
                        <Button variant="outline" onClick={load}>
                            <RefreshCw />
                            {t('اقرأ مواقعي من Google')}
                        </Button>
                    )}

                    {state === 'loading' && <p className="text-[13px] text-[#6b7280]">{t('جارٍ القراءة…')}</p>}

                    {error && (
                        <p className="mt-3 rounded-[10px] bg-[#fef2f2] p-3 text-[13px] text-[#b91c1c]">{error}</p>
                    )}

                    <ul className="mt-3 max-h-[46svh] space-y-2 overflow-y-auto">
                        {[...locations].sort((a, b) => Number(same(b)) - Number(same(a))).map((l) => {
                            const differs = !! branch.placeId && !! l.placeId && branch.placeId !== l.placeId;

                            return (
                                <li key={l.name} className="rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-3">
                                    <p className="flex flex-wrap items-center gap-2 font-medium text-[#111]">
                                        {l.title}
                                        {same(l) && (
                                            <span
                                                data-testid="gbp-location-match"
                                                className="rounded-full bg-[#f0fdf4] px-2 py-0.5 text-[11px] font-medium text-[#166534]"
                                            >
                                                {t('يطابق هذا الفرع على الخرائط')}
                                            </span>
                                        )}
                                    </p>
                                    {l.address && <p className="mt-0.5 text-[12px] text-[#6b7280]">{l.address}</p>}
                                    {/* وحسابُ الموقع يُسمّى: قد تتفرّق الفروع بين أكثر من حساب */}
                                    {l.accountTitle && <p className="mt-0.5 text-[12px] text-[#9ca3af]">{l.accountTitle}</p>}

                                    {confirming === l.name && (
                                        <p className="mt-2 rounded-[10px] bg-[#fffbeb] p-2.5 text-[12px] text-[#92400e]">
                                            {t('هذا الموقع ليس مكان هذا الفرع على الخرائط. تأكّد منه قبل ربطه.')}
                                        </p>
                                    )}

                                    <div className="mt-2 flex justify-end">
                                        <Button
                                            size="sm"
                                            loading={saving === l.name}
                                            disabled={saving !== null}
                                            onClick={() => {
                                                if (differs && confirming !== l.name) {
                                                    setConfirming(l.name);

                                                    return;
                                                }

                                                link(l, differs);
                                            }}
                                        >
                                            {t(confirming === l.name ? 'ربطه رغم ذلك' : 'اختيار هذا الموقع')}
                                        </Button>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            </DialogContent>
        </Dialog>
    );
}
