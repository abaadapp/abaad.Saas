import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Check, ExternalLink, Info, KeyRound, MapPin, QrCode, RefreshCw, Star, Trash2 } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import CopyButton from '@/Components/CopyButton';
import Field from '@/Components/Field';
import StatusPill, { readinessState } from '@/Components/StatusPill';
import { Advanced, PageActions, SettingsGroup, SettingsPage, SettingsSection } from '@/Components/Settings';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { PasswordInput } from '@/Components/ui/password-input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { ConnectGate, ConnectSteps, type Readiness } from '@/Components/Connect';
import type { PageProps } from '@/types';

interface Link {
    place_id: string | null;
    source: string;
    on_receipt: boolean;
    review_url: string | null;
    place_url: string | null;
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
    google: Pulled;
    internal: number;
    /** مراحل الربط — شكلُها شكلُ واتساب، انظر App\Support\Integration */
    readiness: Readiness;
}

/** رابطٌ يُنسخ بضغطة — العنوان طويلٌ ولا يُكتب بيد */
function CopyRow({ label, url, hint }: { label: string; url: string; hint?: string }) {
    const t = useTranslate();

    return (
        <div className="rounded-[12px] border border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] p-3">
            <div className="mb-1.5 flex flex-wrap items-center justify-between gap-2">
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
 * ربط خرائط Google — وسحبُ تقييماتها.
 *
 * وكان زرًّا في شاشة التقييمات يفتح `business.google.com` في تبويبٍ خارجيّ:
 * اسمُه «ربط تقييمات Google Maps» ولا يربط شيئًا — يُخرج التاجر من لوحته
 * ويتركه هناك، ولا يعود بمعرّفٍ ولا يُحفظ شيء.
 *
 * وما تفعله هذه الصفحة: تحفظ معرّف المكان فيصير للمحلّ رابطُ «اكتب تقييمًا»
 * يفتح ملفَّه بعينه — يُنسخ ليُرسل أو يُطبع رمزًا على الإيصال — وتسحب
 * تقييماتِه ومعدّلَه من Google بمفتاح Places فتُقرأ هنا بلا مغادرة اللوحة.
 *
 * ═══ ترتيبُ الشاشة ═══
 *
 * كانت عمودين: النموذجُ والتقييماتُ في الواسع، وفي الضيّق مفتاحُ Places
 * وبطاقتا شرحٍ — فيقع المفتاحُ الاختياريّ بجوار الخطوة الأولى بالقوّة
 * البصريّة نفسها، ويبقى صاحبُه لا يدري أيَّهما يبدأ به.
 *
 * والترتيب الآن بتبعيّةِ الأشياء لا بملء المساحة: مراحلُ الربط، ثمّ
 * المعرّفُ ورابطاه ورمزُ الإيصال في قسمٍ واحد لأنّها حفظةٌ واحدة، ثمّ ما
 * يُقرأ بعد الربط (التقييمات)، ثمّ ما هو اختياريٌّ أو شرحٌ — مطويًّا.
 */
export default function MarketingGoogle() {
    const { settings, link, keyHint, google, internal, readiness } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const form = useForm({
        google_maps_url: settings.google_maps_url ?? '',
        google_review_on_receipt: (settings.google_review_on_receipt ?? '0') === '1',
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
                    icon={MapPin}
                    title="خرائط Google"
                    description="اربط محلّك بملفّه على الخرائط: يمسح الزبون رمزًا على الإيصال فيكتب تقييمه، وتُقرأ تقييماتك هنا."
                    tool="google"
                    tint="#ea4335"
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
                actions={<StatusPill state={readinessState(readiness)} connected />}
            />

            <SettingsPage>
                <ConnectSteps
                    readiness={readiness}
                    title={t('مراحل الربط')}
                    done={t('تمّ الربط — تُقرأ تقييماتك أدناه، ورابطُ التقييم جاهز.')}
                    waiting={t('لا تُقرأ تقييمةٌ واحدة قبل أن تكتمل هذه المراحل.')}
                />

                {/*
                    المعرّفُ ورابطاه ورمزُ الإيصال في قسمٍ واحد: حفظةٌ واحدة
                    تكتبها كلَّها، وثلاثةُ صناديقَ لزرِّ حفظٍ واحد تجعل صاحبها
                    يضغط الحفظ ثلاثًا ليتأكّد.
                */}
                <form onSubmit={submit}>
                    <SettingsSection
                        title="موقع محلّك على الخرائط"
                        description="الصق «Place ID» الخاصّ بمحلّك — منه يُبنى رابط التقييم، وبه تُقرأ تقييماتك."
                        icon={MapPin}
                        status={linked ? <StatusPill state="ready" label="مربوط" /> : <StatusPill state="action" label="ينقصه المعرّف" />}
                        divided
                    >
                        <SettingsGroup title="معرّف المكان">
                            <Field error={form.errors.google_maps_url}>
                                <Input
                                    dir="ltr"
                                    value={form.data.google_maps_url}
                                    onChange={(e) => form.setData('google_maps_url', e.target.value)}
                                    placeholder="ChIJ… أو https://…place_id:ChIJ…"
                                    aria-label={t('معرّف المكان')}
                                />
                            </Field>

                            {/*
                                من أين يأتي المعرّف يُقال هنا لا يُترك للبحث: رابطُ
                                الخرائط العاديّ لا يحمله، وهو أوّل ما يلصقه التاجر.
                            */}
                            <p className="mt-2 text-[12px] leading-relaxed text-[#9ca3af]">
                                {t('رابط الخرائط العاديّ لا يحمل المعرّف. خُذه من أداة Google:')}{' '}
                                <a
                                    href="https://developers.google.com/maps/documentation/places/web-service/place-id"
                                    target="_blank"
                                    rel="noreferrer"
                                    className="text-[#111] underline"
                                >
                                    Place ID Finder
                                </a>
                            </p>
                        </SettingsGroup>

                        {/*
                            الرابطان ثمرةُ المعرّف لا بابٌ مستقلّ — فموضعهما
                            تحته مباشرةً: من حفظ معرّفًا يريد أن يرى ما صار
                            إليه، لا أن يبحث عنه في عمودٍ آخر.
                        */}
                        {linked ? (
                            <SettingsGroup
                                title="رابطاك بعد الربط"
                                description="أرسل الأوّل لزبونك، وافتح الثاني لتتأكّد أنّه محلّك."
                            >
                                <div className="space-y-3">
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
                                </div>
                            </SettingsGroup>
                        ) : (
                            <SettingsGroup>
                                <p className="rounded-[12px] bg-[#fafafa] px-4 py-3 text-[13px] leading-relaxed text-[#6b7280]">
                                    {t('احفظ معرّف المكان ليظهر رابط طلب التقييم هنا.')}
                                </p>
                            </SettingsGroup>
                        )}

                        <SettingsGroup
                            title="رمز التقييم على الإيصال"
                            description="يمسحه الزبون وهو عند المنضدة — وهي اللحظة الوحيدة التي يكتب فيها أحدٌ تقييمًا."
                        >
                            <label className="flex cursor-pointer items-start gap-2.5">
                                <input
                                    type="checkbox"
                                    checked={form.data.google_review_on_receipt}
                                    onChange={(e) => form.setData('google_review_on_receipt', e.target.checked)}
                                    className="mt-0.5 size-4 rounded border-[#d1d5db] accent-[#111]"
                                />
                                <span className="flex items-center gap-1.5 text-sm font-medium text-[#111]">
                                    <QrCode className="size-4" />
                                    {t('اطبع رمز التقييم على الإيصال')}
                                </span>
                            </label>
                        </SettingsGroup>

                        <SettingsGroup>
                            <PageActions>
                                <Button type="submit" loading={form.processing}>
                                    {t('حفظ')}
                                </Button>
                            </PageActions>
                        </SettingsGroup>
                    </SettingsSection>
                </form>

                {/* ------------------------- تقييمات Google ------------------------- */}
                <SettingsSection
                    title="تقييمات Google"
                    description="تُسحب حيّةً من ملفّ محلّك — ولا تُخزَّن في النظام."
                    icon={Star}
                    action={
                        google.state === 'ok' && (
                            <Button type="button" size="sm" variant="outline" loading={refreshing} onClick={refresh}>
                                <RefreshCw />
                                {t('حدِّث الآن')}
                            </Button>
                        )
                    }
                >
                    {google.state === 'unlinked' && (
                        <p className="rounded-[12px] bg-[#fafafa] p-4 text-[13px] text-[#6b7280]">
                            {t('احفظ معرّف المكان أوّلًا — بلا معرّفٍ لا يُعرف أيُّ محلٍّ تُسحب تقييماته.')}
                        </p>
                    )}

                    {google.state === 'nokey' && (
                        <p className="rounded-[12px] bg-[#fffbeb] p-4 text-[13px] text-[#92400e]">
                            {t('مفتاح الخرائط غير مهيّأ في أبعاد بعد — راجعنا، أو الصق مفتاحك الخاص في «مفتاحك الخاص» أدناه.')}
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
                                            <div className="flex flex-wrap items-center gap-2">
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
                            <p className="mt-4 text-[12px] leading-relaxed text-[#9ca3af]">
                                {t('تُعيد Google خمسة تقييماتٍ بنصوصها كحدٍّ أقصى وتختارها هي — والعدد والمعدّل أعلاه كاملان. وتُحدَّث تلقائيًّا كلّ ٦ ساعات.')}
                            </p>
                        </>
                    )}
                </SettingsSection>

                {/*
                    --------------------------- مفتاح Places ---------------------------

                    مطويٌّ لأنّه اختياريّ: لأبعاد مفتاحُها — انظر
                    GoogleReviews::apiKey — فالتاجر لا يحتاجه ليقرأ تقييماته.
                    وكان يقف بجوار الخطوة الأولى بالقوّة نفسها فيُظنّ شرطًا.
                */}
                <Advanced
                    title="مفتاحك الخاص لـ Places"
                    description="اختياريّ — تُقرأ تقييماتك بمفتاح أبعاد. والصقْ مفتاحك من Google Cloud إن أردت أن تُحتسب النداءات على حسابك."
                    icon={KeyRound}
                    status={keyHint ? <StatusPill state="ready" label="محفوظ" /> : undefined}
                    forceOpen={!! keyForm.errors.google_api_key}
                >
                    {keyHint && (
                        <div className="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-[10px] bg-[#f0fdf4] px-3 py-2">
                            <span className="flex items-center gap-1.5 text-[12px] text-[#047857]">
                                <Check className="size-3.5" />
                                {t('محفوظ')}
                            </span>
                            <span dir="ltr" className="text-[12px] text-[#6b7280]">{keyHint}</span>
                        </div>
                    )}

                    <form onSubmit={saveKey}>
                        <Field error={keyForm.errors.google_api_key}>
                            <PasswordInput
                                dir="ltr"
                                autoComplete="off"
                                value={keyForm.data.google_api_key}
                                onChange={(e) => keyForm.setData('google_api_key', e.target.value)}
                                placeholder={keyHint ? t('الصق مفتاحًا جديدًا لتبديله') : 'AIza…'}
                                aria-label={t('مفتاح Places API')}
                            />
                        </Field>

                        <PageActions className="mt-4">
                            {keyHint && (
                                <Button type="button" size="sm" variant="outline" onClick={forgetKey}>
                                    <Trash2 />
                                    {t('حذف المفتاح')}
                                </Button>
                            )}
                            <Button type="submit" size="sm" loading={keyForm.processing}>
                                {t('حفظ المفتاح')}
                            </Button>
                        </PageActions>
                    </form>

                    {/*
                        وما يجب أن يعرفه قبل أن يلصق: النداءُ مدفوعٌ من
                        حسابه هو، وقيدُ المفتاح مسؤوليّته. وتاجرٌ يلصق
                        مفتاحًا بلا قيدٍ يجده يومًا يُستهلك من غيره.
                    */}
                    <p className="mt-4 border-t border-[var(--ui-border,#e8e8e8)] pt-3 text-[12px] leading-relaxed text-[#9ca3af]">
                        {t('فعّل «Places API (New)» في مشروعك واربط الفوترة — النداء يُحسب على حسابك. وقيّد المفتاح بعنوان خادمنا حتى لا يستعمله غيرك.')}
                    </p>
                </Advanced>

                {/*
                    الفرق بين التقييمين يُقال صراحةً: تقييمات النظام تُكتب
                    داخله وتُنشر بإذنه، وتقييمات Google تُكتب هناك — تُقرأ
                    هنا ولا تُخزَّن، ولا يُردّ عليها من هذه الشاشة.
                */}
                <Advanced title="ما يفعله هذا الربط وما لا يفعله" icon={Info}>
                    <p className="text-[13px] leading-relaxed text-[#6b7280]">
                        {t('يصنع رابطًا يفتح تقييم محلّك على Google، ويقرأ تقييماتِه ومعدّلَه. ولا يُخزّنها في النظام ولا يردّ عليها — الردّ يحتاج موافقة Google على النشاط نفسه في Business Profile.')}
                    </p>
                    <p className="mt-2 text-[13px] leading-relaxed text-[#6b7280]">
                        {t('وتقييمات النظام — :n — تبقى مستقلّةً عنها.', { n: internal })}
                    </p>
                </Advanced>
            </SettingsPage>
        </AdminLayout>
    );
}
