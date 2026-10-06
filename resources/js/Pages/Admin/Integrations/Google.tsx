import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { Check, CheckCircle2, Circle, ExternalLink, Info, KeyRound, Link2Off, MapPin, QrCode, RefreshCw, Search, Star, Store, Trash2 } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import CopyButton from '@/Components/CopyButton';
import Field from '@/Components/Field';
import StatusPill, { readinessState } from '@/Components/StatusPill';
import { Advanced, PageActions, SettingsGroup, SettingsPage, SettingsSection } from '@/Components/Settings';
import Toggle from '@/Components/Toggle';
import { useConfirm } from '@/Components/ConfirmDialog';
import { Button } from '@/Components/ui/button';
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
    /** أخرائطُ Google مفعّلةٌ لهذا المتجر — بمفتاحه هو وحده، ولا مفتاحَ لأبعاد */
    enabled: boolean;
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

/** روابطُ Google الرسميّة — يفتحها التاجر من حسابه، ولا يمرّ شيءٌ منها بنا */
const GOOGLE_LINKS = {
    project: 'https://console.cloud.google.com/projectcreate',
    billing: 'https://console.cloud.google.com/billing',
    pricing: 'https://mapsplatform.google.com/pricing/',
    placesApi: 'https://console.cloud.google.com/apis/library/places.googleapis.com',
    credentials: 'https://console.cloud.google.com/apis/credentials',
    security: 'https://developers.google.com/maps/api-security-best-practices',
};

/**
 * «كيف أحصل على مفتاح Google؟» — الطريقُ كلُّه، ليُتمّه التاجر وحده.
 *
 * ═══ ولمَ يُكتب هنا لا يُترك للدعم ═══
 *
 * المفتاحُ مفتاحُه والفاتورةُ فاتورتُه، فلا يصحّ أن يحتاجنا ليُنشئه. والخطواتُ
 * سبعٌ بترتيب ما يراه في Google Cloud: مشروع، فوترة، واجهة، مفتاح، قيد، لصق،
 * ثمّ ربطُ الفروع — ومفتاحٌ واحدٌ لكلّها.
 *
 * ═══ والأسماءُ إنجليزيّةٌ عمدًا ═══
 *
 * `Credentials` و`API restrictions` كما تظهر على شاشته حرفًا بحرف. وترجمتُها
 * تجعله يبحث عن كلمةٍ عربيّةٍ لا وجودَ لها عند Google.
 *
 * ═══ ولا سعرَ مكتوب ═══
 *
 * أسعارُ Google تتبدّل، ورقمٌ محفورٌ هنا يبقى يُعرض بعد أن يصير خطأً. فيُحال
 * إلى صفحة أسعارها الرسميّة، ويُقال من يدفع — لا كم.
 */
function KeyGuide({ serverIp, start }: { serverIp: string | null; start: boolean }) {
    const t = useTranslate();
    const [open, setOpen] = useState(start);

    /* اسمٌ لاتينيٌّ داخل جملةٍ عربيّة: بلا `dir` ينقلب ترتيبُ كلماته */
    const en = (text: string) => (
        <span dir="ltr" className="inline-block font-medium text-[#111]">{text}</span>
    );

    const go = (href: string, label: string) => (
        <a
            href={href}
            target="_blank"
            rel="noreferrer"
            className="mt-1 inline-flex items-center gap-1 text-[12px] font-medium text-[#2563eb] hover:underline"
        >
            <ExternalLink className="size-3" />
            {t(label)}
        </a>
    );

    const step = (n: string, title: string, body: React.ReactNode) => (
        <li className="flex gap-2.5">
            <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-[#f3f4f6] text-[11px] font-bold text-[#111]">
                {n}
            </span>
            <div className="min-w-0">
                <p className="font-medium text-[#111]">{t(title)}</p>
                <div className="mt-0.5">{body}</div>
            </div>
        </li>
    );

    return (
        <div className="mt-4 rounded-[12px] border border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] p-3" data-testid="google-key-guide">
            <button
                type="button"
                className="flex w-full items-center justify-between gap-2 text-start text-[13px] font-bold text-[#111]"
                aria-expanded={open}
                onClick={() => setOpen(!open)}
            >
                <span className="flex items-center gap-1.5">
                    <Info className="size-4 text-[#2563eb]" />
                    {t('كيف أحصل على مفتاح Google؟')}
                </span>
                <span className="text-[12px] font-medium text-[#2563eb]">{open ? t('إخفاء') : t('عرض الخطوات')}</span>
            </button>

            {open && (
                <ol className="mt-3 space-y-3.5 text-[12px] leading-relaxed text-[#6b7280]">
                    {step('١', 'مشروع في Google Cloud', (
                        <>
                            <p>{t('افتح Google Cloud وأنشئ مشروعًا جديدًا، أو اختر مشروعًا موجودًا خاصًا بنشاطك.')} {en('console.cloud.google.com')}</p>
                            {go(GOOGLE_LINKS.project, 'إنشاء مشروع في Google Cloud')}
                        </>
                    ))}

                    {step('٢', 'الفوترة', (
                        <>
                            <p>{t('اربط مشروعك بحساب فوترة في Google. رسوم Google — إن وجدت — تُخصم من حسابك في Google مباشرة، ولا تدفعها أبعاد نيابةً عنك.')}</p>
                            <p className="mt-1">{t('الأسعار والحصص المجانية تحددها Google وقد تتغير — راجعها في صفحتها الرسمية.')}</p>
                            <span className="flex flex-wrap gap-x-4">
                                {go(GOOGLE_LINKS.billing, 'حساب الفوترة في Google Cloud')}
                                {go(GOOGLE_LINKS.pricing, 'أسعار Google Maps Platform')}
                            </span>
                        </>
                    ))}

                    {step('٣', 'تفعيل الواجهة', (
                        <>
                            <p>
                                {t('فعّل')} {en('Places API (New)')} — {t('وهي وحدها ما يحتاجه أبعاد.')}{' '}
                                {/*
                                    وهذا أشيعُ ما يُخطئ فيه: الاسمان متجاوران في القائمة،
                                    والقديمةُ تُفعَّل فتُردّ نداءاتُنا بـ403 بلا سببٍ يُفهم.
                                */}
                                <span className="font-medium text-[#b91c1c]">
                                    {t('وهي غير «Places API» القديمة — القديمة تُرجع رفضًا بلا سبب مفهوم.')}
                                </span>
                            </p>
                            {go(GOOGLE_LINKS.placesApi, 'فتح Places API (New)')}
                        </>
                    ))}

                    {step('٤', 'إنشاء المفتاح', (
                        <>
                            <p>{t('من قسم Credentials أنشئ API Key جديدًا:')} {en('APIs and services → Credentials → Create credentials → API key')}</p>
                            <p className="mt-1 font-medium text-[#b91c1c]">{t('لا تشارك مفتاحك مع أي شخص ولا ترسله في المحادثات أو البريد.')}</p>
                            {go(GOOGLE_LINKS.credentials, 'فتح Credentials')}
                        </>
                    ))}

                    {step('٥', 'تقييد المفتاح', (
                        <>
                            <p>
                                {t('لحماية المفتاح، قيّد استخدامه بعنوان IP الخاص بخادم أبعاد الموضح هنا، واجعل API restrictions مقتصرة على Places API (New).')}
                            </p>
                            <p className="mt-1">{en('Application restrictions → IP addresses')}</p>
                            {serverIp ? (
                                <span className="mt-1.5 flex flex-wrap items-center gap-2 rounded-[8px] bg-white px-2.5 py-1.5" data-testid="google-server-ip">
                                    <span className="text-[11px] text-[#6b7280]">{t('عنوان خادم أبعاد')}</span>
                                    <span dir="ltr" className="font-mono text-[12px] font-bold text-[#111]">{serverIp}</span>
                                    <CopyButton text={serverIp} label="نسخ" />
                                </span>
                            ) : (
                                /*
                                    ولا يُخمَّن عنوان: مفتاحٌ مقيَّدٌ بعنوانٍ خاطئ يُرفض عند كلّ
                                    نداء ولا يُفهم لماذا. فيُقال الواقعُ كما هو.
                                */
                                <span className="mt-1.5 block rounded-[8px] bg-[#fffbeb] px-2.5 py-1.5 text-[11px] font-medium text-[#92400e]" data-testid="google-server-ip-missing">
                                    {t('عنوان خادم أبعاد غير مُعلن في هذه الصفحة بعد، فلا تقيّد المفتاح بعنوان IP تخمينًا — مفتاحٌ مقيّد بعنوان خاطئ يُرفض. اكتفِ الآن بتقييده بالواجهة (الخطوة التالية).')}
                                </span>
                            )}
                            <p className="mt-1.5">{en('API restrictions → Restrict key → Places API (New)')}</p>
                            {/*
                                ولا تقييدَ بنطاق الموقع: المفتاحُ يُستعمل من خادم أبعاد وحده
                                ولا يصل المتصفّح، لا في لوحتك ولا في متجرك ولا على نطاقك
                                الخاصّ إن كان لك. ومفتاحٌ مقيّدٌ بـ«HTTP referrers» يُرفض
                                عند كلّ نداءٍ من الخادم — فيُقال قبل أن يُجرَّب.
                            */}
                            <p className="mt-1.5 font-medium text-[#111]" data-testid="google-no-referrer">
                                {t('لا تقيّد المفتاح بنطاق موقعك (HTTP referrers): أبعاد يستعمله من الخادم فقط ولا يصل إلى المتصفح، لا في لوحتك ولا في متجرك ولا على نطاقك الخاص — والتقييد بالنطاق يجعل Google ترفضه.')}
                            </p>
                            {go(GOOGLE_LINKS.security, 'إرشادات Google لحماية المفتاح')}
                        </>
                    ))}

                    {step('٦', 'الصق المفتاح في أبعاد', (
                        <p>{t('الصق مفتاح Google هنا واضغط حفظ. بعد الحفظ لا يُعرض المفتاح مرة أخرى — تظهر آخر أربعة أحرف منه فقط.')}</p>
                    ))}

                    {step('٧', 'اربط فروعك', (
                        <>
                            <p>{t('بعد حفظ المفتاح، اربط كل فرع بالموقع الصحيح له في Google.')}</p>
                            <p className="mt-1 font-medium text-[#111]">{t('مفتاح واحد يكفي لجميع فروع متجرك. لا تحتاج إلى إنشاء مفتاح جديد لكل فرع.')}</p>
                        </>
                    ))}
                </ol>
            )}
        </div>
    );
}

/**
 * نموذجُ المفتاح — مفتاحُ التاجر وحده، ومفتاحٌ واحدٌ لكلّ فروعه.
 *
 * والحالُ تُقال بجملتها لا بلونٍ وحده: غير مربوط، مطفأ، مفعّل — ولكلٍّ منها
 * ما يحدث لمتجره وما لا يحدث.
 */
function KeyForm({
    keyHint,
    serverIp,
    enabled,
}: {
    keyHint: string | null;
    serverIp: string | null;
    enabled: boolean;
}) {
    const t = useTranslate();
    const keyForm = useForm({ google_api_key: '' });
    const [test, setTest] = useState<{ state: 'idle' | 'loading' | 'ok' | 'error'; message: string | null }>({
        state: 'idle',
        message: null,
    });

    /*
        اختبارُ الاتّصال — بالمفتاح الملصوق إن وُجد، وإلّا بالمحفوظ.

        والنداءُ من خادمنا لا من المتصفّح، والردُّ نعم أو سببُ الرفض — ولا
        يعود المفتاح فيه. فيعرف التاجر قبل أن يربط فرعًا أنّ مفتاحه يعمل.
    */
    const runTest = async () => {
        setTest({ state: 'loading', message: null });

        try {
            const res = await fetch(route('admin.integrations.google.key.test'), {
                method: 'POST',
                headers: { ...csrfHeaders(), 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ google_api_key: keyForm.data.google_api_key }),
            });
            const body = (await res.json()) as { ok?: boolean; message?: string };

            setTest({
                state: body.ok ? 'ok' : 'error',
                message: body.message ?? t('تعذّر الوصول إلى Google. حاول بعد قليل.'),
            });
        } catch {
            setTest({ state: 'error', message: t('تعذّر الوصول إلى Google. حاول بعد قليل.') });
        }
    };

    return (
        <>
            {/*
                والتشغيلُ بيد التاجر: يُطفئ فتتوقّف النداءات ويبقى مفتاحُه وأماكنُ
                فروعه ورابطُ التقييم في الإيصال. ولا يُعرض قبل أن يُحفظ مفتاح —
                لصقُ المفتاح تفعيل، ولا شيء يُفعَّل بلا مفتاح.
            */}
            {/* الحالُ بجملتها — ولا يُفهم من «مطفأ» أنّ شيئًا مُحي */}
            <p
                data-testid="google-state"
                className={cn(
                    'mb-4 rounded-[10px] px-3 py-2.5 text-[13px] leading-relaxed',
                    !keyHint ? 'bg-[#fafafa] text-[#374151]' : enabled ? 'bg-[#f0fdf4] text-[#166534]' : 'bg-[#fffbeb] text-[#92400e]',
                )}
            >
                {!keyHint
                    ? t('لم تربط Google بعد. متجرك وفروعك ومبيعاتك تعمل بشكل طبيعي، لكن ميزات Google ستبقى متوقفة.')
                    : enabled
                      ? t('Google Maps مفعّلة بمفتاحك. مفتاح واحد يكفي لجميع فروع متجرك.')
                      : t('Google Maps متوقفة لهذا المتجر. لن تُرسل طلبات جديدة إلى Google، وستبقى بيانات الفروع المحفوظة وروابط التقييم كما هي.')}
            </p>

            {keyHint && (
                <div className="mb-4" data-testid="google-enabled">
                    <Toggle
                        on={enabled}
                        onChange={(v) => router.post(route('admin.integrations.google.enabled'), { enabled: v }, { preserveScroll: true })}
                        label="تفعيل خرائط Google"
                        hint={enabled ? 'الفوترة واستهلاك Google على حسابك في Google، وليس على أبعاد.' : 'مطفأة — لا يُنادى Google عن متجرك، وبياناتك المحفوظة باقية.'}
                    />
                </div>
            )}

            {keyHint && (
                <div className="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-[10px] bg-[#f0fdf4] px-3 py-2">
                    <span className="flex items-center gap-1.5 text-[12px] text-[#047857]">
                        <Check className="size-3.5" />
                        {t('محفوظ')}
                    </span>
                    <span dir="ltr" className="text-[12px] text-[#6b7280]">{keyHint}</span>
                </div>
            )}

            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    keyForm.post(route('admin.integrations.google.key'), {
                        preserveScroll: true,
                        // المفتاح لا يبقى في الحقل بعد حفظه — لا يُعرض ولا يُعاد إرساله
                        onSuccess: () => keyForm.reset('google_api_key'),
                    });
                }}
            >
                <Field error={keyForm.errors.google_api_key}>
                    <PasswordInput
                        dir="ltr"
                        autoComplete="off"
                        value={keyForm.data.google_api_key}
                        onChange={(e) => keyForm.setData('google_api_key', e.target.value)}
                        placeholder={keyHint ? t('الصق مفتاحًا جديدًا لتبديله') : 'AIza…'}
                        aria-label={t('مفتاح Google')}
                    />
                </Field>

                {test.message && (
                    <p
                        data-testid="google-key-test"
                        data-state={test.state}
                        className={cn(
                            'mt-3 rounded-[10px] px-3 py-2 text-[13px]',
                            test.state === 'ok' ? 'bg-[#f0fdf4] text-[#166534]' : 'bg-[#fef2f2] text-[#b91c1c]',
                        )}
                    >
                        {test.message}
                    </p>
                )}

                <PageActions className="mt-4">
                    {(keyHint || keyForm.data.google_api_key.trim() !== '') && (
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            loading={test.state === 'loading'}
                            onClick={runTest}
                            data-testid="google-key-test-button"
                        >
                            <RefreshCw />
                            {t('اختبار الاتصال')}
                        </Button>
                    )}
                    {keyHint && (
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() => router.delete(route('admin.integrations.google.key.forget'), { preserveScroll: true })}
                        >
                            <Trash2 />
                            {t('حذف المفتاح')}
                        </Button>
                    )}
                    <Button type="submit" size="sm" loading={keyForm.processing}>
                        {t('حفظ المفتاح')}
                    </Button>
                </PageActions>
            </form>

            {/* والدليلُ مفتوحٌ من أوّله حين لا مفتاحَ له — ليُتمّه وحده بلا دعم */}
            <KeyGuide serverIp={serverIp} start={!keyHint} />
        </>
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
    const { settings, link, keyHint, enabled, serverIp, google, internal, readiness, branches, searchMin } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const form = useForm({
        google_review_on_receipt: (settings.google_review_on_receipt ?? '0') === '1',
        google_show_on_site: (settings.google_show_on_site ?? '0') === '1',
    });

    const [refreshing, setRefreshing] = useState(false);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('admin.integrations.google.save'), { preserveScroll: true });
    };

    const refresh = () => {
        setRefreshing(true);
        router.post(route('admin.integrations.google.refresh'), {}, {
            preserveScroll: true,
            onFinish: () => setRefreshing(false),
        });
    };

    const linked = !! link.place_id;
    const place = google.place;
    const linkedBranches = branches.filter((b) => b.linked).length;

    /*
        بابٌ قبل الشاشة — لمن لم يربط بعد.

        وكانت تُفتح على حقلِ «معرّف المكان» وبطاقةِ مفتاحِ Places وقائمةِ
        تقييماتٍ فارغة وشرحِ رمزِ الإيصال — كلُّها لمن لم يربط شيئًا. فالخطوة
        الأولى تضيع بين ما لا يعمل قبلها.
    */
    if (! readiness.connected) {
        return (
            <AdminLayout title="ربط Google Maps">
                <PageHeader
                    title="ربط Google Maps"
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
        <AdminLayout title="ربط Google Maps">
            <PageHeader
                title="ربط Google Maps"
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
                    والمفتاحُ حين يكون شرطًا يعلو، وحين يكون اختيارًا يُطوى آخرَها.

                    كان في العمود الضيّق بجوار الخطوة الأولى بالقوّة البصريّة
                    نفسها في الحالين — فمن كان اختياريًّا عنده ظنّه شرطًا فوقف
                    عنده، ومن كان شرطًا عنده ظنّه اختيارًا فمضى ينتظر قراءةً
                    لا تأتي.
                */}
                {/*
                    ═══ مفتاحُ التاجر — ولا مفتاحَ لأبعاد ═══

                    خرائطُ Google اختياريّة: يربطها التاجر بمفتاحه من مشروعه في
                    Google Cloud، ونداءاتُها على حسابه هو. ومن لم يربطها يعمل
                    متجرُه كلُّه كما هو — هذه الصفحةُ وحدها تنتظره.
                */}
                <SettingsSection
                    title="ربط Google Maps"
                    description="ربط Google Maps اختياري. إذا أردت قراءة بيانات موقعك وتقييمات Google داخل أبعاد، اربط مفتاحك من مشروعك في Google Cloud. استخدام Google وفوترته يكونان على حسابك في Google مباشرة."
                    icon={KeyRound}
                    status={
                        !keyHint
                            ? <StatusPill state="action" label="غير مربوط" />
                            : enabled
                              ? <StatusPill state="ready" label="مفعّل" />
                              : <StatusPill state="idle" label="مطفأ" />
                    }
                >
                    <KeyForm keyHint={keyHint} serverIp={serverIp} enabled={enabled} />
                </SettingsSection>

                {/* ---------------------- فروعك على الخرائط ---------------------- */}
                <SettingsSection
                    title="الفروع المرتبطة"
                    /*
                        ولمَ لكلّ فرعٍ ربطُه — يُقال هنا لا يُترك للاكتشاف:
                        رمزٌ على إيصال فرعٍ يفتح ملفَّ فرعٍ آخر عطبٌ لا يراه صاحبه.
                    */
                    description="مفتاح واحد يكفي لجميع فروع متجرك. بعد حفظ المفتاح، اربط كل فرع بالموقع الصحيح له في Google — ليصل تقييم الزبون إلى الفرع الذي اشترى منه."
                    icon={MapPin}
                    status={
                        <span className="text-[13px] font-medium tabular-nums text-[#6b7280]" dir="ltr">
                            {linkedBranches} / {branches.length}
                        </span>
                    }
                    divided
                >
                    <SettingsGroup>
                        <ul className="space-y-3">
                            {branches.map((b) => (
                                <BranchRow key={b.id} branch={b} min={searchMin} />
                            ))}
                        </ul>
                    </SettingsGroup>

                    {/*
                        الرابطان ثمرةُ الربط لا بابٌ مستقلّ — فموضعهما تحته
                        مباشرةً: من ربط يريد أن يرى ما صار إليه، لا أن يبحث
                        عنه في عمودٍ آخر.
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
                                {t('اربط فرعًا بملفّه ليظهر رابط طلب التقييم هنا.')}
                            </p>
                        </SettingsGroup>
                    )}
                </SettingsSection>

                {/* ------------------------- ما يُطبع وما يُعرض ------------------------- */}
                <form onSubmit={submit}>
                    <SettingsSection
                        title="أين يظهر تقييمك"
                        description="مقبضان: رمزٌ على الورقة يمسحه الزبون، ومعدّلٌ يُعرض في موقعك."
                        icon={QrCode}
                        divided
                    >
                        <SettingsGroup title="رمز التقييم على الإيصال">
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
                        </SettingsGroup>

                        <SettingsGroup title="تقييم Google في موقعك">
                            <label className="flex cursor-pointer items-start gap-2.5">
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
                        </SettingsGroup>

                        <SettingsGroup>
                            <PageActions
                                note={
                                    linked && (
                                        <span className="flex items-center gap-1.5 text-[13px] text-[#047857]">
                                            <Check className="size-4" />
                                            {t('مرتبط')}
                                        </span>
                                    )
                                }
                            >
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
                            {t('اربط فرعًا بملفّه أوّلًا — بلا ملفٍّ لا يُعرف أيُّ محلٍّ تُسحب تقييماته.')}
                        </p>
                    )}

                    {google.state === 'nokey' && (
                        <p className="rounded-[12px] bg-[#fffbeb] p-4 text-[13px] text-[#92400e]">
                            {keyHint
                                ? t('Google Maps متوقفة لهذا المتجر. لن تُرسل طلبات جديدة إلى Google، وستبقى بيانات الفروع المحفوظة وروابط التقييم كما هي.')
                                : t('لم تربط Google بعد. متجرك وفروعك ومبيعاتك تعمل بشكل طبيعي، لكن ميزات Google ستبقى متوقفة.')}
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
                    وبابُ الإدارة الكاملة — بابٌ آخرُ غيرُ هذا.

                    هذه الشاشةُ تقرأ الملفَّ العامّ: معدّلٌ وعددٌ وخمسةُ نصوصٍ
                    تختارها Google. وقراءةُ التقييمات كلِّها والردُّ عليها
                    باسم المتجر تحتاج إذنَ صاحبه — فتُذكر ولا تُخلط بهذه.
                */}
                <SettingsSection
                    title="الرد على التقييمات"
                    description="اقرأ تقييماتك كلّها وردّ عليها باسم متجرك — يحتاج ربط حساب Google بإذنك."
                    icon={Star}
                    action={
                        <Button asChild variant="outline" size="sm">
                            <SmartLink
                                routeName="admin.integrations.googleBusiness"
                                href={route('admin.integrations.googleBusiness')}
                            >
                                {t('إدارة التقييمات')}
                            </SmartLink>
                        </Button>
                    }
                />

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

/* ═══════════════════ فرعٌ وربطُه ═══════════════════ */

function BranchRow({ branch, min }: { branch: BranchLink; min: number }) {
    const t = useTranslate();
    // نافذةُ التأكيد من النظام لا من المتصفّح — انظر ConfirmDialog
    const [ask, confirmDialog] = useConfirm();
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
                    {/* ✓ مربوط · ○ يحتاج ربط — لكلّ فرعٍ حالُه، وربطُ فرعٍ لا يمسّ غيره */}
                    <p className="flex items-center gap-1.5 font-medium text-[#111]" data-testid="branch-google-status">
                        {branch.linked ? (
                            <CheckCircle2 className="size-4 shrink-0 text-[#047857]" aria-hidden />
                        ) : (
                            <Circle className="size-4 shrink-0 text-[#9ca3af]" aria-hidden />
                        )}
                        <Store className="size-4 shrink-0 text-[#9ca3af]" />
                        {branch.name}
                        <span className={cn('text-[12px] font-normal', branch.linked ? 'text-[#047857]' : 'text-[#b45309]')}>
                            — {branch.linked ? t('مربوط') : t('يحتاج ربط')}
                        </span>
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
                        <p className="mt-1 text-[13px] text-[#9ca3af]">{t('ابحث عن هذا الفرع في Google واختر موقعه الصحيح — بالمفتاح نفسه.')}</p>
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
                            onClick={async () => {
                                /* الفكُّ يُؤكَّد: ضغطةٌ واحدةٌ تُطفئ رمزَ الإيصال في الفرع كلِّه */
                                const go = await ask({
                                    message: 'إلغاء ربط هذا الفرع بخرائط Google؟',
                                    danger: true,
                                    action: 'إلغاء الربط',
                                });

                                if (! go) return;

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

            {confirmDialog}
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
