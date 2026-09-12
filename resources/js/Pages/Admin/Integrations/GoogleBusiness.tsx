import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Bell, Link2Off, MapPin, MessageSquare, RefreshCw, Star, Store } from 'lucide-react';
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
}

interface Props {
    configured: boolean;
    connected: boolean;
    account: {
        email: string | null;
        accountName: string | null;
        linkedAt: string | null;
        syncedAt: string | null;
        error: string | null;
    } | null;
    branches: BranchRow[];
    reviews: ReviewRow[];
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
    const { configured, connected, account, branches, reviews, alerts } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    // نافذةُ التأكيد من النظام لا من المتصفّح — انظر ConfirmDialog
    const [ask, confirmDialog] = useConfirm();

    const [replying, setReplying] = useState<ReviewRow | null>(null);
    const [picking, setPicking] = useState<BranchRow | null>(null);

    const alertForm = useForm({
        gbp_alerts_enabled: alerts.enabled,
        gbp_low_rating: alerts.threshold,
    });

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
                    actions={<StatusPill state="progress" label="عند أبعاد" />}
                />
                <Gate
                    mark={<GoogleMapsMark size={80} />}
                    title="إدارة تقييمات Google غير مهيّأة في أبعاد بعد"
                    /*
                        والفرقُ بينها وبين «ربط خرائط Google» يُقال هنا: اسمان
                        متقاربان، ومن ظنّهما واحدًا يعود يبحث عمّا ربطه.
                    */
                    description="وهي غير «ربط خرائط Google»: تلك تقرأ المعدّل وعدد التقييمات، وهذه تفتح ملفّ متجرك لتقرأ تقييماته كلّها وتردّ عليها باسمك."
                    note={t('راجعنا لتفعيلها — ولا شيء عليك أن تفعله حتى ذلك.')}
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
                    actions={<StatusPill state="idle" label="غير مربوط" />}
                />
                <Gate
                    mark={<GoogleMapsMark size={80} />}
                    title="لم يُربط حساب Google بعد"
                    description="ولا تُقرأ تقييمة واحدة قبله — الربط بإذنك على حسابك، ويُلغى متى شئت."
                    action={
                        <Button asChild size="lg">
                            <a href={route('admin.integrations.googleBusiness.connect')}>
                                {t('ربط حساب Google Business')}
                            </a>
                        </Button>
                    }
                    /* وخطأُ Google الأخير يُقال بنصّه: «لم تُسحب» لا تقول ما يُصلَح */
                    note={account?.error}
                />
            </AdminLayout>
        );
    }

    return (
        <AdminLayout title={t('تقييمات Google')}>
            <PageHeader
                title={t('تقييمات Google')}
                subtitle={t('اقرأ تقييماتك كلّها وردّ عليها باسم متجرك')}
                actions={<StatusPill state={account?.error ? 'error' : 'ready'} connected />}
            />

            <SettingsPage width="wide">
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
                    {account?.error && (
                        <p className="mt-3 rounded-[10px] bg-[#fef2f2] p-3 text-[13px] text-[#b91c1c]">
                            {account.error}
                        </p>
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
                                        </p>
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
                                {reviews.length}
                            </span>
                        }
                    >
                        {reviews.length === 0 ? (
                            <p className="rounded-[12px] bg-[#fafafa] p-4 text-[13px] text-[#6b7280]">
                                {t('لا تقييمات مسحوبة بعد — اربط موقع الفرع ثم اضغط «سحب التقييمات».')}
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

    /*
        المواقعُ تُقرأ عند فتح النافذة لا في كلّ فتحةِ شاشة.
        نداءان لكلّ حسابٍ ولكلّ موقع، وهي لا تتبدّل في اليوم مرّتين.
    */
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
                        {locations.map((l) => (
                            <li key={l.name} className="rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-3">
                                <p className="font-medium text-[#111]">{l.title}</p>
                                {l.address && <p className="mt-0.5 text-[12px] text-[#6b7280]">{l.address}</p>}

                                <div className="mt-2 flex justify-end">
                                    <Button
                                        size="sm"
                                        loading={saving === l.name}
                                        disabled={saving !== null}
                                        onClick={() => {
                                            setSaving(l.name);
                                            router.post(
                                                route('admin.integrations.googleBusiness.branch.link', branch.id),
                                                { location: l.name, account: l.account },
                                                {
                                                    preserveScroll: true,
                                                    onSuccess: onClose,
                                                    onError: (e) => setError(e.location ?? null),
                                                    onFinish: () => setSaving(null),
                                                },
                                            );
                                        }}
                                    >
                                        {t('اختيار هذا الموقع')}
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            </DialogContent>
        </Dialog>
    );
}
