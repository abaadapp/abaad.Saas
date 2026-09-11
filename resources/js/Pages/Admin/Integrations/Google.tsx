import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { Check, ExternalLink, KeyRound, Link2Off, MapPin, QrCode, RefreshCw, Search, Star, Store, Trash2 } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import CopyButton from '@/Components/CopyButton';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import SmartLink from '@/Components/SmartLink';
import { Input } from '@/Components/ui/input';
import { PasswordInput } from '@/Components/ui/password-input';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { csrfHeaders } from '@/lib/csrf';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { ConnectGate, ConnectSteps, type Readiness } from '@/Components/Connect';
import type { PageProps } from '@/types';

interface Link {
    place_id: string | null;
    place_name: string | null;
    branch_id: number | null;
    on_receipt: boolean;
    review_url: string | null;
    place_url: string | null;
}

/** فرعٌ وحالُ ربطه — ولا يصل معرّفُ المكان إلى الشاشة: لا يفعل به التاجر شيئًا */
interface BranchLink {
    id: number;
    name: string;
    linked: boolean;
    placeName: string | null;
    rating: number | null;
    reviewCount: number | null;
    mapsUrl: string | null;
    reviewUrl: string | null;
    syncedAt: string | null;
}

/** نتيجةُ بحثٍ كما ردّها خادمُنا عن Google — تُعرض ولا يُحفظ منها إلا المعرّف */
interface PlaceResult {
    place_id: string;
    name: string;
    address: string;
    rating: number | null;
    count: number;
    maps_url: string | null;
}

interface GoogleReview {
    id: string;
    author: string;
    photo: string | null;
    rating: number;
    text: string;
    when: string;
    at: string;
}

interface Pulled {
    /** unlinked | nokey | error | ok — ولكلٍّ منها ما يُفعل، فلا تُجمع في «لا شيء» */
    state: 'unlinked' | 'nokey' | 'error' | 'ok';
    error: string | null;
    fetched_at: string | null;
    place: {
        name: string;
        rating: number | null;
        count: number;
        maps_url: string | null;
        reviews: GoogleReview[];
    } | null;
}

interface Props {
    settings: Record<string, string>;
    link: Link;
    keyHint: string | null;
    /** أَلأبعادَ مفتاحٌ يقرأ به من لم يلصق مفتاحه — نعم أو لا، ولا طرفَ منه */
    platformKey: boolean;
    /** عنوانُ خادمنا ليقيّد به مفتاحه — و`null` حين لا يكون مضبوطًا */
    serverIp: string | null;
    google: Pulled;
    internal: number;
    /** مراحل الربط — شكلُها شكلُ واتساب، انظر App\Support\Integration */
    readiness: Readiness;
    branches: BranchLink[];
    /** أقلُّ ما يُبحث به — من الخادم لا رقمٌ مكتوبٌ هنا أيضًا */
    searchMin: number;
}

/** رابطٌ يُنسخ بضغطة — العنوان طويلٌ ولا يُكتب بيد */
function CopyRow({ label, url, hint }: { label: string; url: string; hint?: string }) {
    const t = useTranslate();

    return (
        <div className="rounded-[12px] border border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] p-3">
            <div className="mb-1.5 flex items-center justify-between gap-2">
                <span className="text-[13px] font-medium text-[#111]">{t(label)}</span>
                <div className="flex items-center gap-1.5">
                    <CopyButton text={url} label="نسخ" />
                    <Button asChild size="sm" variant="outline">
                        <a href={url} target="_blank" rel="noreferrer">
                            <ExternalLink />
                            {t('فتح')}
                        </a>
                    </Button>
                </div>
            </div>
            {/* الرابط لاتينيّ في صفحةٍ عربية: بلا dir ينقلب أوّلُه إلى آخره */}
            <p dir="ltr" className="break-all text-start text-[12px] text-[#6b7280]">{url}</p>
            {hint && <p className="mt-1.5 text-[12px] text-[#9ca3af]">{t(hint)}</p>}
        </div>
    );
}

/** خمسُ نجومٍ ممتلئةٌ بقدر التقييم — والرقم بجوارها لمن لا يعدّ نجومًا */
function Stars({ value, size = 14 }: { value: number; size?: number }) {
    return (
        <span className="flex items-center gap-0.5">
            {[1, 2, 3, 4, 5].map((n) => (
                <Star
                    key={n}
                    style={{ width: size, height: size }}
                    className={cn(
                        n <= Math.round(value) ? 'fill-[#f59e0b] text-[#f59e0b]' : 'text-[#d1d5db]',
                    )}
                />
            ))}
        </span>
    );
}

/**
 * دليلُ المفتاح — ما كان يُطلب من التاجر ولا يُمكَّن منه.
 *
 * ═══ ولمَ يُكتب هنا لا يُترك للدعم ═══
 *
 * البطاقةُ تقول له «قيّد المفتاح بعنوان خادمنا»، وعنوانُ خادمنا لم يكن
 * مكتوبًا في شاشةٍ واحدة. فإمّا أن يسألنا — فما عاد يُتمّها وحده — وإمّا أن
 * يترك مفتاحه بلا قيد، فيُنفِق غيرُه رصيدَه يومَ يُسرَّب، والفاتورةُ فاتورتُه.
 *
 * ═══ والأسماءُ إنجليزيّةٌ عمدًا ═══
 *
 * `Credentials` و`API restrictions` كما تظهر على شاشته حرفًا بحرف. وترجمتُها
 * تجعله يبحث عن كلمةٍ عربيّةٍ لا وجودَ لها عند Google.
 */
function KeyGuide({ serverIp, start }: { serverIp: string | null; start: boolean }) {
    const t = useTranslate();
    const [open, setOpen] = useState(start);

    /* اسمٌ لاتينيٌّ داخل جملةٍ عربيّة: بلا `dir` ينقلب ترتيبُ كلماته */
    const en = (text: string) => (
        <span dir="ltr" className="inline-block font-medium text-[#111]">{text}</span>
    );

    return (
        <div className="mt-3 border-t border-[var(--ui-border,#e8e8e8)] pt-3">
            <Button type="button" size="sm" variant="link" className="px-0" onClick={() => setOpen(!open)}>
                {open ? t('إخفاء الدليل') : t('كيف أحصل على مفتاح؟')}
            </Button>

            {open && (
                <ol className="mt-2 space-y-2.5 text-[12px] leading-relaxed text-[#6b7280]">
                    <li>
                        <span className="font-bold text-[#111]">١. </span>
                        {t('أنشئ مشروعًا في')} {en('console.cloud.google.com')}
                    </li>
                    <li>
                        <span className="font-bold text-[#111]">٢. </span>
                        {t('فعّل')} {en('Places API (New)')} —{' '}
                        {/*
                            وهذا أشيعُ ما يُخطئ فيه: الاسمان متجاوران في القائمة،
                            والقديمةُ تُفعَّل فتُردّ نداءاتُنا بـ403 بلا سببٍ يُفهم.
                        */}
                        <span className="font-medium text-[#b91c1c]">
                            {t('وهي غير «Places API» القديمة — القديمة تُرجع رفضًا بلا سبب مفهوم.')}
                        </span>
                    </li>
                    <li>
                        <span className="font-bold text-[#111]">٣. </span>
                        {t('اربط الفوترة. لكل واجهة ١٠٬٠٠٠ نداء شهريًّا مجانًا، ولا تُخصم بطاقتك قبل أن ترفع الحساب بيدك.')}
                    </li>
                    <li>
                        <span className="font-bold text-[#111]">٤. </span>
                        {t('أنشئ المفتاح من')} {en('APIs and services → Credentials → Create credentials → API key')}
                    </li>
                    <li>
                        <span className="font-bold text-[#111]">٥. </span>
                        {t('قيّده بعنوان خادمنا من')} {en('Application restrictions → IP addresses')}
                        {serverIp ? (
                            <span className="mt-1.5 flex items-center gap-2 rounded-[8px] bg-[#f3f4f6] px-2.5 py-1.5">
                                <span className="text-[11px] text-[#6b7280]">{t('عنوان خادمنا')}</span>
                                <span dir="ltr" className="font-mono text-[12px] font-bold text-[#111]">{serverIp}</span>
                                <CopyButton text={serverIp} label="نسخ" />
                            </span>
                        ) : (
                            /* ولا يُخمَّن عنوان: مفتاحٌ مقيَّدٌ بعنوانٍ خاطئ لا يعمل، ولا يُفهم لماذا */
                            <span className="mt-1.5 block text-[11px] font-medium text-[#b45309]">
                                {t('اطلب عنوان خادمنا من الدعم قبل أن تقيّد المفتاح — ولا تتركه بلا قيد.')}
                            </span>
                        )}
                    </li>
                    <li>
                        <span className="font-bold text-[#111]">٦. </span>
                        {t('وقيّده بالواجهة وحدها من')} {en('API restrictions → Restrict key → Places API (New)')}
                    </li>
                    <li className="border-t border-[var(--ui-border,#e8e8e8)] pt-2.5 text-[#9ca3af]">
                        {t('مفتاح بلا قيد يُسرَّق فيُنفق غيرك رصيدك — والنداءات تُحسب على حسابك أنت.')}
                    </li>
                </ol>
            )}
        </div>
    );
}

/**
 * ربط خرائط Google — وسحبُ تقييماتها.
 *
 * وكان زرًّا في شاشة التقييمات يفتح `business.google.com` في تبويبٍ خارجيّ:
 * اسمُه «ربط تقييمات Google Maps» ولا يربط شيئًا — يُخرج التاجر من لوحته
 * ويتركه هناك، ولا يعود بمعرّفٍ ولا يُحفظ شيء.
 *
 * وما تفعله هذه الصفحة: تحفظ معرّف المكان فيصير للمحلّ رابطُ «اكتب تقييمًا»
 * يفتح ملفَّه بعينه — يُنسخ ليُرسل أو يُطبع رمزًا على الإيصال — وتسحب
 * تقييماتِه ومعدّلَه من Google بمفتاح Places فتُقرأ هنا بلا مغادرة اللوحة.
 */
export default function MarketingGoogle() {
    const { settings, link, keyHint, platformKey, serverIp, google, internal, readiness, branches, searchMin } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const form = useForm({
        google_review_on_receipt: (settings.google_review_on_receipt ?? '0') === '1',
        google_show_on_site: (settings.google_show_on_site ?? '0') === '1',
    });

    const keyForm = useForm({ google_api_key: '' });
    const [refreshing, setRefreshing] = useState(false);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('admin.integrations.google.save'), { preserveScroll: true });
    };

    const saveKey = (e: React.FormEvent) => {
        e.preventDefault();
        keyForm.post(route('admin.integrations.google.key'), {
            preserveScroll: true,
            // المفتاح لا يبقى في الحقل بعد حفظه — لا يُعرض ولا يُعاد إرساله
            onSuccess: () => keyForm.reset('google_api_key'),
        });
    };

    const refresh = () => {
        setRefreshing(true);
        router.post(route('admin.integrations.google.refresh'), {}, {
            preserveScroll: true,
            onFinish: () => setRefreshing(false),
        });
    };

    const forgetKey = () => {
        router.delete(route('admin.integrations.google.key.forget'), { preserveScroll: true });
    };

    const linked = !! link.place_id;
    const place = google.place;

    /*
        بابٌ قبل الشاشة — لمن لم يربط بعد.

        وكانت تُفتح على حقلِ «معرّف المكان» وبطاقةِ مفتاحِ Places وقائمةِ
        تقييماتٍ فارغة وشرحِ رمزِ الإيصال — كلُّها لمن لم يربط شيئًا. فالخطوة
        الأولى تضيع بين ما لا يعمل قبلها.
    */
    if (! readiness.connected) {
        return (
            <AdminLayout title="ربط خرائط Google">
                <PageHeader
                    title="ربط خرائط Google"
                    subtitle={t('اربط محلّك بملفّه على الخرائط: رابطٌ لطلب التقييم، وتقييماتُ Google تُقرأ هنا')}
                />
                <ConnectGate
                    name={t('خرائط Google')}
                    line={t('اربط محلّك بملفّه على الخرائط: يمسح الزبون رمزًا على الإيصال فيكتب تقييمه، وتُقرأ تقييماتك هنا.')}
                    tool="google"
                    note={readiness.steps[0]?.done ? null : readiness.steps[0]?.fix}
                />
            </AdminLayout>
        );
    }

    return (
        <AdminLayout title="ربط خرائط Google">
            <PageHeader
                title="ربط خرائط Google"
                subtitle={t('اربط محلّك بملفّه على الخرائط: رابطٌ لطلب التقييم، وتقييماتُ Google تُقرأ هنا')}
            />

            <ConnectSteps
                readiness={readiness}
                title={t('مراحل الربط')}
                done={t('تمّ الربط — تُقرأ تقييماتك أدناه، ورابطُ التقييم جاهز.')}
                waiting={t('لا تُقرأ تقييمةٌ واحدة قبل أن تكتمل هذه المراحل.')}
            />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    {/* ---------------------- فروعك على الخرائط ---------------------- */}
                    <Card className="p-6">
                        <div className="mb-5 flex items-start gap-3">
                            <span className="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-[#f3f4f6] text-[#111]">
                                <MapPin className="size-[18px]" />
                            </span>
                            <div>
                                <h3 className="font-bold text-[#111]">{t('موقع المتجر على خرائط Google')}</h3>
                                <p className="mt-0.5 text-[13px] text-[#6b7280]">
                                    {/*
                                        ولمَ لكلّ فرعٍ ربطُه — يُقال هنا لا يُترك للاكتشاف:
                                        رمزٌ على إيصال فرعٍ يفتح ملفَّ فرعٍ آخر عطبٌ لا يراه صاحبه.
                                    */}
                                    {t('لكلّ فرعٍ ملفُّه على Google بتقييماته. اربط كلّ فرعٍ بملفّه ليصل تقييم الزبون إلى الفرع الذي اشترى منه.')}
                                </p>
                            </div>
                        </div>

                        <ul className="space-y-3">
                            {branches.map((b) => (
                                <BranchRow key={b.id} branch={b} min={searchMin} />
                            ))}
                        </ul>
                    </Card>

                    {/*
                        وبابُ الإدارة الكاملة — بابٌ آخرُ غيرُ هذا.

                        هذه الشاشةُ تقرأ الملفَّ العامّ: معدّلٌ وعددٌ وخمسةُ نصوصٍ
                        تختارها Google. وقراءةُ التقييمات كلِّها والردُّ عليها
                        باسم المتجر تحتاج إذنَ صاحبه — فتُذكر ولا تُخلط بهذه.
                    */}
                    <Card className="p-6">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div className="min-w-0">
                                <h3 className="font-bold text-[#111]">{t('الرد على التقييمات')}</h3>
                                <p className="mt-0.5 text-[13px] leading-relaxed text-[#6b7280]">
                                    {t('اقرأ تقييماتك كلّها وردّ عليها باسم متجرك — يحتاج ربط حساب Google بإذنك.')}
                                </p>
                            </div>
                            <SmartLink
                                routeName="admin.integrations.googleBusiness"
                                href={route('admin.integrations.googleBusiness')}
                                className="shrink-0 rounded-[10px] border border-[var(--ui-border,#e8e8e8)] px-3 py-1.5 text-[13px] text-[#111] hover:bg-[#fafafa]"
                            >
                                {t('إدارة التقييمات')}
                            </SmartLink>
                        </div>
                    </Card>

                    {/* ------------------------- رمز الإيصال ------------------------- */}
                    <form onSubmit={submit}>
                        <Card className="p-6">
                            <label className="flex cursor-pointer items-start gap-2.5">
                                <input
                                    type="checkbox"
                                    checked={form.data.google_review_on_receipt}
                                    onChange={(e) => form.setData('google_review_on_receipt', e.target.checked)}
                                    className="mt-0.5 size-4 rounded border-[#d1d5db] accent-[#111]"
                                />
                                <span>
                                    <span className="flex items-center gap-1.5 text-sm font-medium text-[#111]">
                                        <QrCode className="size-4" />
                                        {t('اطبع رمز التقييم على الإيصال')}
                                    </span>
                                    <span className="mt-0.5 block text-[12px] text-[#6b7280]">
                                        {t('يمسحه الزبون وهو عند المنضدة — وهي اللحظة الوحيدة التي يكتب فيها أحدٌ تقييمًا.')}
                                    </span>
                                    {/*
                                        وحدُّه يُقال: الرمزُ يُطبع بملفّ الفرع الذي طُبعت منه
                                        الورقة، وفرعٌ غيرُ مربوطٍ لا يُطبع على إيصاله شيء.
                                    */}
                                    <span className="mt-1 block text-[12px] text-[#9ca3af]">
                                        {t('يُطبع بملفّ الفرع الذي صدرت منه الورقة — والفرع غير المربوط لا يُطبع على إيصاله رمز.')}
                                    </span>
                                </span>
                            </label>

                            <label className="mt-5 flex cursor-pointer items-start gap-2.5 border-t border-[var(--ui-border,#e8e8e8)] pt-5">
                                <input
                                    type="checkbox"
                                    checked={form.data.google_show_on_site}
                                    onChange={(e) => form.setData('google_show_on_site', e.target.checked)}
                                    className="mt-0.5 size-4 rounded border-[#d1d5db] accent-[#111]"
                                />
                                <span>
                                    <span className="flex items-center gap-1.5 text-sm font-medium text-[#111]">
                                        <Star className="size-4" />
                                        {t('عرض تقييم Google في الموقع')}
                                    </span>
                                    <span className="mt-0.5 block text-[12px] text-[#6b7280]">
                                        {/*
                                            وحدُّه يُقال: رقمان وإسناد، ولا نصوص.
                                            شروطُ Google تمنع الاحتفاظ بمحتوى الأماكن.
                                        */}
                                        {t('يُعرض المعدّل وعدد التقييمات مع الإسناد إلى Google — ولا تُعرض نصوص التقييمات.')}
                                    </span>
                                </span>
                            </label>

                            <div className="mt-6 flex items-center gap-3">
                                <Button type="submit" loading={form.processing}>
                                    {t('حفظ')}
                                </Button>
                                {linked && (
                                    <span className="flex items-center gap-1.5 text-[13px] text-[#047857]">
                                        <Check className="size-4" />
                                        {t('مرتبط')}
                                    </span>
                                )}
                            </div>
                        </Card>
                    </form>

                    {/* ------------------------- تقييمات Google ------------------------- */}
                    <Card className="p-6">
                        <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 className="font-bold text-[#111]">{t('تقييمات Google')}</h3>
                                <p className="mt-0.5 text-[13px] text-[#6b7280]">
                                    {t('تُسحب حيّةً من ملفّ محلّك — ولا تُخزَّن في النظام.')}
                                </p>
                            </div>
                            {google.state === 'ok' && (
                                <Button type="button" size="sm" variant="outline" loading={refreshing} onClick={refresh}>
                                    <RefreshCw />
                                    {t('حدِّث الآن')}
                                </Button>
                            )}
                        </div>

                        {google.state === 'unlinked' && (
                            <p className="rounded-[12px] bg-[#fafafa] p-4 text-[13px] text-[#6b7280]">
                                {t('احفظ معرّف المكان أوّلًا — بلا معرّفٍ لا يُعرف أيُّ محلٍّ تُسحب تقييماته.')}
                            </p>
                        )}

                        {google.state === 'nokey' && (
                            <p className="rounded-[12px] bg-[#fffbeb] p-4 text-[13px] text-[#92400e]">
                                {t('مفتاح الخرائط غير مهيّأ في أبعاد بعد — راجعنا، أو الصق مفتاحك الخاص في البطاقة المجاورة.')}
                            </p>
                        )}

                        {google.state === 'error' && (
                            <p className="rounded-[12px] bg-[#fef2f2] p-4 text-[13px] text-[#b91c1c]">{google.error}</p>
                        )}

                        {google.state === 'ok' && place && (
                            <>
                                <div className="flex flex-wrap items-center gap-4 rounded-[12px] border border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] p-4">
                                    <div>
                                        <p className="text-[13px] font-medium text-[#111]">{place.name || t('محلّك')}</p>
                                        <div className="mt-1 flex items-center gap-2">
                                            <Stars value={place.rating ?? 0} size={16} />
                                            <span className="text-[15px] font-bold text-[#111]">
                                                {place.rating ?? '—'}
                                            </span>
                                        </div>
                                    </div>
                                    <div className="ms-auto text-end">
                                        <p className="text-[20px] font-bold text-[#111]">{place.count}</p>
                                        <p className="text-[12px] text-[#9ca3af]">{t('تقييمًا على Google')}</p>
                                    </div>
                                </div>

                                {place.reviews.length === 0 ? (
                                    <p className="mt-4 text-[13px] text-[#9ca3af]">
                                        {t('لم تُعِد Google نصوص تقييمات لهذا المكان بعد.')}
                                    </p>
                                ) : (
                                    <ul className="mt-4 space-y-3">
                                        {place.reviews.map((review) => (
                                            <li
                                                key={review.id}
                                                className="rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-4"
                                            >
                                                <div className="flex items-center gap-2">
                                                    {review.photo && (
                                                        <img
                                                            src={review.photo}
                                                            alt=""
                                                            className="size-7 rounded-full object-cover"
                                                        />
                                                    )}
                                                    <span className="text-[13px] font-medium text-[#111]">
                                                        {review.author}
                                                    </span>
                                                    <Stars value={review.rating} />
                                                    <span className="ms-auto text-[12px] text-[#9ca3af]">
                                                        {review.when}
                                                    </span>
                                                </div>
                                                {review.text && (
                                                    <p className="mt-2 whitespace-pre-line text-[13px] leading-relaxed text-[#374151]">
                                                        {review.text}
                                                    </p>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                )}

                                {/*
                                    الحدُّ يُقال ولا يُترك للاستنتاج: تاجرٌ عنده مئةُ
                                    تقييمٍ يرى خمسةً فيظنّ أنّ الباقي ضاع أو أنّ
                                    الربط ناقص — والخمسة حدُّ Google لا حدُّنا.
                                */}
                                <p className="mt-4 text-[12px] text-[#9ca3af]">
                                    {t('تُعيد Google خمسة تقييماتٍ بنصوصها كحدٍّ أقصى وتختارها هي — والعدد والمعدّل أعلاه كاملان. وتُحدَّث تلقائيًّا كلّ ٦ ساعات.')}
                                </p>
                            </>
                        )}
                    </Card>
                </div>

                <div className="space-y-4">
                    {linked ? (
                        <>
                            <CopyRow
                                label="رابط طلب التقييم"
                                url={link.review_url!}
                                hint="أرسله للعميل أو اطبعه — يفتح نافذة «اكتب تقييمًا» على محلّك."
                            />
                            <CopyRow
                                label="ملفّك على الخرائط"
                                url={link.place_url!}
                                hint="افتحه وتأكّد أنّه محلّك — معرّفٌ خاطئ يرسل زبائنك إلى محلٍّ آخر."
                            />
                        </>
                    ) : (
                        <Card className="p-5 text-center">
                            <Star className="mx-auto size-6 text-[#9ca3af]" />
                            <p className="mt-2 text-[13px] font-medium text-[#111]">{t('لم يُربط بعد')}</p>
                            <p className="mt-1 text-[12px] text-[#9ca3af]">
                                {t('احفظ معرّف المكان ليظهر رابط طلب التقييم هنا.')}
                            </p>
                        </Card>
                    )}

                    {/* --------------------------- مفتاح Places --------------------------- */}
                    <Card className="p-5">
                        <div className="mb-3 flex items-start gap-2.5">
                            <KeyRound className="mt-0.5 size-[18px] shrink-0 text-[#111]" />
                            <div>
                                <h3 className="text-[14px] font-bold text-[#111]">
                                    {platformKey ? t('مفتاحك الخاص') : t('مفتاح Google Maps')}
                                </h3>
                                {/*
                                    والنصُّ يتبع الواقع لا العكس.

                                    فحين يكون لأبعادَ مفتاحٌ فهذا الحقل اختياريّ حقًّا: من
                                    لم يلصق شيئًا تُقرأ تقييماته. وحين لا يكون، فقولُ
                                    «اختياريّ» يجعل التاجر ينتظر قراءةً لا تأتي أبدًا —
                                    ولا يشكو، لأنّه صدَّق ما قرأ.
                                */}
                                <p className="mt-0.5 text-[12px] text-[#6b7280]">
                                    {platformKey
                                        ? t('اختياريّ — تُقرأ تقييماتك بمفتاح أبعاد. والصقْ مفتاحك من Google Cloud إن أردت أن تُحتسب النداءات على حسابك.')
                                        : t('مطلوب — لا تُقرأ تقييماتك قبل أن تلصق مفتاحك من Google Cloud.')}
                                </p>
                            </div>
                        </div>

                        {keyHint && (
                            <div className="mb-3 flex items-center justify-between gap-2 rounded-[10px] bg-[#f0fdf4] px-3 py-2">
                                <span className="flex items-center gap-1.5 text-[12px] text-[#047857]">
                                    <Check className="size-3.5" />
                                    {t('محفوظ')}
                                </span>
                                <span dir="ltr" className="text-[12px] text-[#6b7280]">{keyHint}</span>
                            </div>
                        )}

                        <form onSubmit={saveKey}>
                            <PasswordInput
                                dir="ltr"
                                autoComplete="off"
                                value={keyForm.data.google_api_key}
                                onChange={(e) => keyForm.setData('google_api_key', e.target.value)}
                                placeholder={keyHint ? t('الصق مفتاحًا جديدًا لتبديله') : 'AIza…'}
                                aria-label={t('مفتاح Places API')}
                            />
                            {keyForm.errors.google_api_key && (
                                <p className="mt-2 text-[12px] text-[#b91c1c]">{keyForm.errors.google_api_key}</p>
                            )}

                            <div className="mt-3 flex items-center gap-2">
                                <Button type="submit" size="sm" loading={keyForm.processing}>
                                    {t('حفظ المفتاح')}
                                </Button>
                                {keyHint && (
                                    <Button type="button" size="sm" variant="danger" onClick={forgetKey}>
                                        <Trash2 />
                                        {t('حذف المفتاح')}
                                    </Button>
                                )}
                            </div>
                        </form>

                        {/*
                            والدليلُ مفتوحٌ من أوّله حين لا يكون له ولا لأبعادَ مفتاح:
                            تلك وحدها الحالُ التي لا تُقرأ فيها تقييمةٌ قبل أن يتحرّك.
                        */}
                        <KeyGuide serverIp={serverIp} start={!keyHint && !platformKey} />
                    </Card>

                    {/*
                        الفرق بين التقييمين يُقال صراحةً: تقييمات النظام تُكتب
                        داخله وتُنشر بإذنه، وتقييمات Google تُكتب هناك — تُقرأ
                        هنا ولا تُخزَّن، ولا يُردّ عليها من هذه الشاشة.
                    */}
                    <Card className="p-4 text-[12px] leading-relaxed text-[#6b7280]">
                        <p className="mb-1 text-[13px] font-medium text-[#111]">{t('ما يفعله هذا الربط وما لا يفعله')}</p>
                        <p>
                            {t('يصنع رابطًا يفتح تقييم محلّك على Google، ويقرأ تقييماتِه ومعدّلَه. ولا يُخزّنها في النظام ولا يردّ عليها — الردّ يحتاج موافقة Google على النشاط نفسه في Business Profile.')}
                        </p>
                        <p className="mt-2">
                            {t('وتقييمات النظام — :n — تبقى مستقلّةً عنها.', { n: internal })}
                        </p>
                    </Card>
                </div>
            </div>
        </AdminLayout>
    );
}

/* ═══════════════════ فرعٌ وربطُه ═══════════════════ */

function BranchRow({ branch, min }: { branch: BranchLink; min: number }) {
    const t = useTranslate();
    const [searching, setSearching] = useState(false);
    const [busy, setBusy] = useState(false);

    const act = (run: () => void) => {
        setBusy(true);
        run();
    };

    return (
        <li className="rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="flex items-center gap-1.5 font-medium text-[#111]">
                        <Store className="size-4 shrink-0 text-[#9ca3af]" />
                        {branch.name}
                    </p>

                    {branch.linked ? (
                        <>
                            <p className="mt-1 truncate text-[13px] text-[#6b7280]">{branch.placeName}</p>
                            <p className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px]">
                                {branch.rating === null ? (
                                    /* لا تقييمَ بعد — وهي ليست صفرًا، والفرقُ يراه صاحبُ المحلّ */
                                    <span className="text-[#9ca3af]">{t('لا تقييمات بعد')}</span>
                                ) : (
                                    <>
                                        <span className="flex items-center gap-1 font-medium text-[#111]">
                                            <Star className="size-3.5 fill-[#f59e0b] text-[#f59e0b]" />
                                            {branch.rating.toFixed(1)}
                                        </span>
                                        <span className="text-[#6b7280]">
                                            {t(':n تقييم', { n: branch.reviewCount ?? 0 })}
                                        </span>
                                    </>
                                )}
                                {/* والإسنادُ شرطُ Google لعرض معدّلها — لا يُحذف */}
                                <span className="text-[11px] text-[#9ca3af]">{t('المصدر: Google')}</span>
                            </p>
                        </>
                    ) : (
                        <p className="mt-1 text-[13px] text-[#9ca3af]">{t('غير مربوط')}</p>
                    )}
                </div>

                <div className="flex shrink-0 flex-wrap items-center gap-2">
                    {branch.linked && branch.mapsUrl && (
                        <a
                            href={branch.mapsUrl}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1.5 rounded-[10px] border border-[var(--ui-border,#e8e8e8)] px-2.5 py-1.5 text-[12px] text-[#111] hover:bg-[#fafafa]"
                        >
                            <ExternalLink className="size-3.5" />
                            {t('فتح في Google Maps')}
                        </a>
                    )}

                    {branch.linked && (
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={busy}
                            onClick={() =>
                                act(() =>
                                    router.post(
                                        route('admin.integrations.google.branch.refresh', branch.id),
                                        {},
                                        { preserveScroll: true, onFinish: () => setBusy(false) },
                                    ),
                                )
                            }
                        >
                            <RefreshCw />
                            {t('حدِّث الآن')}
                        </Button>
                    )}

                    <Button type="button" size="sm" variant={branch.linked ? 'outline' : 'primary'} onClick={() => setSearching(true)}>
                        <Search />
                        {branch.linked ? t('تغيير المتجر') : t('ربط Google Maps')}
                    </Button>

                    {branch.linked && (
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={busy}
                            onClick={() => {
                                /* الفكُّ يُؤكَّد: ضغطةٌ واحدةٌ تُطفئ رمزَ الإيصال في الفرع كلِّه */
                                if (! window.confirm(t('إلغاء ربط هذا الفرع بخرائط Google؟'))) return;
                                act(() =>
                                    router.delete(route('admin.integrations.google.branch.unlink', branch.id), {
                                        preserveScroll: true,
                                        onFinish: () => setBusy(false),
                                    }),
                                );
                            }}
                        >
                            <Link2Off />
                            {t('إلغاء الربط')}
                        </Button>
                    )}
                </div>
            </div>

            <PlaceSearchDialog
                open={searching}
                onClose={() => setSearching(false)}
                branch={branch}
                min={min}
            />
        </li>
    );
}

/* ═══════════════════ ابحث عن متجرك ═══════════════════ */

function PlaceSearchDialog({
    open,
    onClose,
    branch,
    min,
}: {
    open: boolean;
    onClose: () => void;
    branch: BranchLink;
    min: number;
}) {
    const t = useTranslate();
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<PlaceResult[]>([]);
    const [state, setState] = useState<'idle' | 'loading' | 'empty' | 'error'>('idle');
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState<string | null>(null);

    /* آخرُ طلبٍ هو الذي يُعرض — وردٌّ متأخّرٌ لكلمةٍ قديمة لا يدهس الأحدث */
    const seq = useRef(0);

    useEffect(() => {
        if (! open) return;

        const q = query.trim();

        if (q.length < min) {
            setResults([]);
            setState('idle');
            setError(null);

            return;
        }

        /*
            تمهُّلٌ قبل النداء — والنداءُ مدفوع.

            بلا هذا يصير «محل الورد» تسعةَ نداءاتٍ على Google، ثمانيةٌ منها
            لكلماتٍ ناقصةٍ لا يقرأ أحدٌ نتيجتها.
        */
        const timer = window.setTimeout(async () => {
            const mine = ++seq.current;
            setState('loading');
            setError(null);

            try {
                const res = await fetch(route('admin.integrations.google.search'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeaders() },
                    body: JSON.stringify({ q }),
                });

                if (mine !== seq.current) return;

                if (res.status === 429) {
                    setState('error');
                    setError(t('تجاوزتَ حدّ البحث — انتظر قليلًا ثم حاول.'));

                    return;
                }

                const body = await res.json();

                if (mine !== seq.current) return;

                if (! body.ok) {
                    setState('error');
                    setError(body.error ?? t('تعذر الاتصال بـ Google حاليًا. حاول مرة أخرى.'));

                    return;
                }

                setResults(body.results ?? []);
                setState((body.results ?? []).length === 0 ? 'empty' : 'idle');
            } catch {
                if (mine !== seq.current) return;
                setState('error');
                setError(t('تعذر الاتصال بـ Google حاليًا. حاول مرة أخرى.'));
            }
        }, 400);

        return () => window.clearTimeout(timer);
    }, [query, open, min, t]);

    const choose = (placeId: string) => {
        setSaving(placeId);
        router.post(
            route('admin.integrations.google.branch.link', branch.id),
            { place_id: placeId },
            {
                preserveScroll: true,
                onSuccess: () => {
                    onClose();
                    setQuery('');
                    setResults([]);
                },
                onError: (errors) => setError(errors.place_id ?? null),
                onFinish: () => setSaving(null),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={(o) => ! o && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('ابحث عن متجرك في Google')}</DialogTitle>
                </DialogHeader>

                <div className="px-5 pb-5">
                    <p className="mb-3 text-[13px] text-[#6b7280]">
                        {t('الفرع: :name', { name: branch.name })}
                    </p>

                    <Input
                        autoFocus
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder={t('ابحث باسم المتجر أو رقم الهاتف')}
                        aria-label={t('ابحث باسم المتجر أو رقم الهاتف')}
                    />

                    {query.trim().length > 0 && query.trim().length < min && (
                        <p className="mt-2 text-[12px] text-[#9ca3af]">
                            {t('اكتب :n أحرف على الأقل.', { n: min })}
                        </p>
                    )}

                    {state === 'loading' && (
                        <p className="mt-3 text-[13px] text-[#6b7280]">{t('جارٍ البحث…')}</p>
                    )}

                    {state === 'empty' && (
                        <p className="mt-3 rounded-[10px] bg-[#fafafa] p-3 text-[13px] text-[#6b7280]">
                            {t('لم نجد متجرًا مطابقًا. جرّب الاسم أو رقم الهاتف.')}
                        </p>
                    )}

                    {error && (
                        <p className="mt-3 rounded-[10px] bg-[#fef2f2] p-3 text-[13px] text-[#b91c1c]">{error}</p>
                    )}

                    <ul className="mt-3 max-h-[46svh] space-y-2 overflow-y-auto">
                        {results.map((r) => (
                            <li
                                key={r.place_id}
                                className="rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-3"
                            >
                                <p className="font-medium text-[#111]">{r.name}</p>
                                {r.address && (
                                    <p className="mt-0.5 text-[12px] text-[#6b7280]">{r.address}</p>
                                )}

                                <div className="mt-2 flex flex-wrap items-center justify-between gap-2">
                                    <span className="flex items-center gap-2 text-[12px]">
                                        {r.rating === null ? (
                                            <span className="text-[#9ca3af]">{t('لا تقييمات بعد')}</span>
                                        ) : (
                                            <>
                                                <span className="flex items-center gap-1 font-medium text-[#111]">
                                                    <Star className="size-3 fill-[#f59e0b] text-[#f59e0b]" />
                                                    {r.rating.toFixed(1)}
                                                </span>
                                                <span className="text-[#6b7280]">
                                                    {t(':n تقييم', { n: r.count })}
                                                </span>
                                            </>
                                        )}
                                        <span className="text-[11px] text-[#9ca3af]">{t('المصدر: Google')}</span>
                                    </span>

                                    <Button
                                        type="button"
                                        size="sm"
                                        loading={saving === r.place_id}
                                        disabled={saving !== null}
                                        onClick={() => choose(r.place_id)}
                                    >
                                        {t('اختيار هذا المتجر')}
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
