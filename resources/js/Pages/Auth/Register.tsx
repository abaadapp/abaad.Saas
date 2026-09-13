import { type FormEvent, useEffect, useState } from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Check, MapPin, TriangleAlert } from 'lucide-react';

import AuthLayout from '@/Layouts/AuthLayout';
import OnboardingProgress from '@/Components/auth/OnboardingProgress';
import PhoneInput from '@/Components/auth/PhoneInput';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { PasswordInput } from '@/Components/ui/password-input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface Activity {
    value: string;
    label: string;
    icon: string;
    hint: string;
}

interface Props {
    activities: Activity[];
    teamSizes: { value: string; label: string }[];
    year: number;
}

/** ما يُحفظ بين تحديثٍ وآخر — ولا كلمةَ مرورٍ فيه، انظر `KEEP` أدناه */
interface Draft {
    name: string;
    dial: string;
    phone: string;
    type: string;
    shop: string;
    team_size: string;
    address: string;
    email: string;
}

const STORE_KEY = 'abaad.signup.draft';

/**
 * فتحُ متجرٍ جديد — سؤالٌ في كلّ شاشة.
 *
 * ═══ ولا شيءَ يُكتب في القاعدة قبل الخطوة الأخيرة ═══
 *
 * الخطواتُ كلُّها حالةٌ في المتصفّح، والطلبُ واحدٌ عند «ابدأ إدارة متجرك».
 * فمن تركَ المعالجَ في منتصفه لا يترك خلفه متجرًا نصفَ مبنيٍّ بلا مالك —
 * وهو ما يقع في المعالجات التي تحفظ كلَّ خطوة، فتمتلئ القاعدةُ بمستأجرين
 * لا أحدَ يدخلهم ولا أحد يحذفهم.
 *
 * ═══ والرجوعُ لا يمحو شيئًا ═══
 *
 * الحقولُ كلُّها في كائنٍ واحد (`useForm`)، والخطوةُ رقمٌ إلى جانبه. فالرجوعُ
 * تغييرُ رقمٍ لا هدمُ نموذج.
 *
 * ═══ والتحديثُ لا يُضيّع ما كُتب — ولا يحفظ كلمة المرور ═══
 *
 * `sessionStorage` لا `localStorage`: تموت بإغلاق اللسان، فلا يجد من يفتح
 * الحاسوبَ بعده اسمَ متجرٍ ورقمَ هاتفٍ لأحد. وكلمةُ المرور لا تُكتب فيها
 * إطلاقًا — `KEEP` تعدّ ما يُحفظ اسمًا اسمًا، فحقلٌ يُضاف لا يتسلّل إليها.
 */
export default function Register() {
    const { activities, teamSizes, year, errors } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const form = useForm({
        name: '',
        dial: '968',
        phone: '',
        type: activities[0]?.value ?? '',
        shop: '',
        team_size: '',
        address: '',
        email: '',
        password: '',
    });

    const [step, setStep] = useState(0);
    const [tried, setTried] = useState(false);

    /* ما يُحفظ بين تحديثٍ وآخر — كلُّ حقلٍ إلّا كلمة المرور */
    const KEEP: (keyof Draft)[] = ['name', 'dial', 'phone', 'type', 'shop', 'team_size', 'address', 'email'];

    useEffect(() => {
        try {
            const raw = sessionStorage.getItem(STORE_KEY);

            if (raw) {
                const draft = JSON.parse(raw) as Partial<Draft>;
                form.setData((d) => ({ ...d, ...draft }));
            }
        } catch {
            /* لسانٌ خاصٌّ أو تخزينٌ مقفل — يُبدأ من الصفر، ولا تسقط الشاشة */
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        try {
            const draft = Object.fromEntries(KEEP.map((k) => [k, form.data[k]]));
            sessionStorage.setItem(STORE_KEY, JSON.stringify(draft));
        } catch {
            /* لا يُعطَّل المعالجُ لأنّ التخزين مقفل */
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [form.data]);

    /*
     * الخطواتُ قائمةٌ تُقرأ — لا سلسلةُ `if` في الرسم.
     *
     * `done` تقول متى يُفتح «التالي»، و`optional` تقول أيُّها يُتخطّى.
     * وشريطُ التقدّم يأخذ عددَه منها، فخطوةٌ تُضاف أو تُرفع لا تترك الشريطَ
     * يعدّ ما ليس فيه.
     */
    const steps = [
        {
            key: 'you',
            title: 'خلّنا نتعرّف عليك',
            emoji: '👋',
            body: 'اكتب اسمك ورقم جوّالك، وبعدها نجهّز أبعاد لنشاطك.',
            done: form.data.name.trim().length >= 2 && form.data.phone.replace(/\D/g, '').length >= 7,
        },
        {
            key: 'activity',
            title: 'وش نوع نشاطك؟',
            emoji: '',
            body: 'نجهّز تصنيفات البداية بحسب نشاطك — وتقدر تغيّرها بعدين.',
            done: form.data.type !== '',
        },
        {
            key: 'shop',
            title: 'وش اسم متجرك؟',
            emoji: '🌷',
            body: 'هذا الاسم يظهر على فواتيرك وأوراقك ومتجرك الإلكتروني.',
            done: form.data.shop.trim().length >= 2,
        },
        {
            key: 'team',
            title: 'كم شخص يعمل معك؟',
            emoji: '',
            body: 'يساعدنا نعرف حجم نشاطك — وتقدر تتخطّاها.',
            done: true,
            optional: true,
        },
        {
            key: 'place',
            title: 'وين موقع متجرك؟',
            emoji: '📍',
            body: 'أضف عنوان متجرك ليظهر على أوراقك وفي بيانات نشاطك.',
            done: true,
            optional: true,
        },
        {
            key: 'account',
            title: 'بيانات الدخول لإدارة متجرك',
            emoji: '',
            body: 'بهذا البريد تدخل إلى أبعاد، وبه تستعيد كلمتك إن نسيتها.',
            done: form.data.email.trim() !== '' && form.data.password.length >= 8,
        },
    ];

    const here = steps[step];
    const last = step === steps.length - 1;

    const back = () => {
        setTried(false);
        setStep((s) => Math.max(0, s - 1));
    };

    const next = () => {
        if (!here.done) {
            setTried(true);

            return;
        }

        setTried(false);
        setStep((s) => Math.min(steps.length - 1, s + 1));
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();

        if (!last) {
            next();

            return;
        }

        /* ومنعُ الإرسال المزدوج — ضغطتان على «ابدأ» لا تفتحان متجرين */
        if (form.processing || !here.done) {
            setTried(true);

            return;
        }

        // المفتاحُ والرقمُ يُجمعان هنا — والخادمُ يحفظ نصًّا واحدًا
        form.transform((d) => ({
            ...d,
            phone: `+${d.dial}${d.phone.replace(/\D/g, '').replace(/^0+/, '')}`,
        }));

        form.post(route('register.store'), {
            onSuccess: () => {
                try {
                    sessionStorage.removeItem(STORE_KEY);
                } catch {
                    /* لا شيء */
                }
            },
            onFinish: () => form.reset('password'),
        });
    };

    /* أخطاءُ الخادم تقع كلُّها على الخطوة الأخيرة — وهي وحدها التي تُرسِل */
    const failure = errors.email ?? errors.password ?? errors.shop ?? errors.type ?? errors.phone ?? errors.name;

    useEffect(() => {
        if (failure) {
            setStep(steps.length - 1);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [failure]);

    const rules = [
        { label: '8 أحرف على الأقل', ok: form.data.password.length >= 8 },
        { label: 'حرف واحد على الأقل', ok: /[A-Za-z]/.test(form.data.password) },
        { label: 'رقم واحد على الأقل', ok: /[0-9]/.test(form.data.password) },
    ];

    return (
        <AuthLayout title={t('إنشاء حساب')} year={year}>
            <OnboardingProgress current={step} total={steps.length} className="mb-8" />

            <h1 className="text-[26px] font-bold leading-tight text-[#111]">
                {t(here.title)} {here.emoji && <span aria-hidden>{here.emoji}</span>}
            </h1>
            <p className="mt-1.5 text-[14px] leading-relaxed text-[#6b7280]">{t(here.body)}</p>

            {failure && (
                <div
                    role="alert"
                    className="mt-6 flex items-start gap-2 rounded-[10px] border border-[#fecaca] bg-[#fef2f2] p-3 text-[13px] text-[#b91c1c]"
                >
                    <TriangleAlert className="mt-px size-4 shrink-0" />
                    <span>{failure}</span>
                </div>
            )}

            {/*
                نموذجٌ واحدٌ لكلّ الخطوات — و«التالي» زرُّ إرسالِه.

                فمفتاحُ Enter ينقل الخطوةَ كما ينقلها الزرّ، ولا يحتاج
                المستخدم أن يرفع يدَه عن لوحة المفاتيح ليضغط بالفأرة.
            */}
            {/*
                و`noValidate`: الرسائلُ من الشاشة لا من المتصفّح.

                حقولٌ عليها `required` تجعل المتصفّحَ يعترض بفقاعته هو قبل أن
                يبلغ الطلبُ شيفرتَنا — بخطٍّ غير خطّ النظام وموضعٍ لا نضبطه،
                وعلى أوّل حقلٍ ناقصٍ وحده. فتُترك السمةُ لقارئ الشاشة ويُترك
                الاعتراضُ لنا: سطرٌ واحد بلغة الشاشة تحت الخطوة كلِّها.
            */}
            <form onSubmit={submit} noValidate className="mt-6">
                {here.key === 'you' && (
                    <div className="space-y-4">
                        <Field label="الاسم" required htmlFor="name">
                            <Input
                                id="name"
                                name="name"
                                autoComplete="name"
                                autoFocus
                                required
                                placeholder={t('اسمك الكامل')}
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                            />
                        </Field>

                        <Field label="رقم الجوال" required htmlFor="phone">
                            <PhoneInput
                                id="phone"
                                dial={form.data.dial}
                                onDialChange={(v) => form.setData('dial', v)}
                                value={form.data.phone}
                                onChange={(v) => form.setData('phone', v)}
                            />
                        </Field>
                    </div>
                )}

                {here.key === 'activity' && (
                    <div className="space-y-2.5" role="radiogroup" aria-label={t('نوع النشاط')}>
                        {activities.map((a) => {
                            const picked = form.data.type === a.value;

                            return (
                                <button
                                    key={a.value}
                                    type="button"
                                    role="radio"
                                    aria-checked={picked}
                                    onClick={() => form.setData('type', a.value)}
                                    className={cn(
                                        'flex w-full items-start gap-3 rounded-[12px] border p-3.5 text-start transition-all',
                                        'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#111]',
                                        picked
                                            ? 'border-[#111] bg-white shadow-[0_10px_28px_-18px_rgba(17,17,17,0.5)]'
                                            : 'border-[#e6e6e6] bg-white/60 hover:border-[#c9c9c9] hover:bg-white',
                                    )}
                                >
                                    <span className="text-[22px] leading-none" aria-hidden>
                                        {a.icon}
                                    </span>

                                    <span className="min-w-0 flex-1">
                                        <span className="block text-[14px] font-semibold text-[#111]">{a.label}</span>
                                        <span className="mt-0.5 block text-[12.5px] leading-relaxed text-[#9ca3af]">
                                            {a.hint}
                                        </span>
                                    </span>

                                    {/*
                                        والعلامةُ شكلٌ لا لون: من لا يفرّق الدرجات
                                        يرى «✓» ولا يرى حدًّا أدكنَ بدرجتين.
                                    */}
                                    <span
                                        aria-hidden
                                        className={cn(
                                            'mt-0.5 grid size-5 shrink-0 place-items-center rounded-full border transition-colors',
                                            picked ? 'border-[#111] bg-[#111] text-white' : 'border-[#d8d8d8]',
                                        )}
                                    >
                                        {picked && <Check className="size-3" strokeWidth={3} />}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                )}

                {here.key === 'shop' && (
                    <Field label="اسم المتجر" required htmlFor="shop">
                        <Input
                            id="shop"
                            name="shop"
                            autoFocus
                            required
                            placeholder={t('اكتب اسم متجرك')}
                            value={form.data.shop}
                            onChange={(e) => form.setData('shop', e.target.value)}
                        />
                    </Field>
                )}

                {here.key === 'team' && (
                    <div className="space-y-2.5" role="radiogroup" aria-label={t('حجم الفريق')}>
                        {teamSizes.map((s) => {
                            const picked = form.data.team_size === s.value;

                            return (
                                <button
                                    key={s.value}
                                    type="button"
                                    role="radio"
                                    aria-checked={picked}
                                    onClick={() => form.setData('team_size', picked ? '' : s.value)}
                                    className={cn(
                                        'flex w-full items-center justify-between gap-3 rounded-[12px] border px-4 py-3 text-start text-[14px] transition-all',
                                        'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#111]',
                                        picked
                                            ? 'border-[#111] bg-white font-semibold text-[#111] shadow-[0_10px_28px_-18px_rgba(17,17,17,0.5)]'
                                            : 'border-[#e6e6e6] bg-white/60 text-[#4b4b4b] hover:border-[#c9c9c9] hover:bg-white',
                                    )}
                                >
                                    {s.label}
                                    <span
                                        aria-hidden
                                        className={cn(
                                            'grid size-5 shrink-0 place-items-center rounded-full border transition-colors',
                                            picked ? 'border-[#111] bg-[#111] text-white' : 'border-[#d8d8d8]',
                                        )}
                                    >
                                        {picked && <Check className="size-3" strokeWidth={3} />}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                )}

                {here.key === 'place' && (
                    <Field label="عنوان المتجر" htmlFor="address">
                        {/*
                            عنوانٌ نصٌّ لا خريطة.

                            لا مفتاحَ خرائطَ في النظام (`GOOGLE_BUSINESS_*` هو
                            ملفُّ النشاط التجاريّ لا الخرائط)، وإضافةُ تبعيّةٍ
                            وحسابٍ ومفتاحٍ لأجل خطوةٍ اختياريّةٍ ثمنٌ لا مقابلَ
                            له. والعنوانُ يُكتب في `businesses.address` وهو
                            العمودُ نفسُه الذي تقرؤه أوراقُ المتجر.
                        */}
                        <span className="relative block">
                            <MapPin className="pointer-events-none absolute start-3 top-3 size-4 text-[#9ca3af]" />
                            <Input
                                id="address"
                                name="address"
                                autoFocus
                                placeholder={t('مثال: مسقط — الخوير، شارع 42')}
                                className="ps-10"
                                value={form.data.address}
                                onChange={(e) => form.setData('address', e.target.value)}
                            />
                        </span>
                    </Field>
                )}

                {here.key === 'account' && (
                    <div className="space-y-4">
                        <Field label="البريد الإلكتروني" required htmlFor="email" error={errors.email}>
                            {/* الغلافُ بالاتّجاه نفسه لا الصفحة — انظر شاشة الدخول */}
                            <span className="relative block" dir="ltr">
                                <Input
                                    id="email"
                                    name="email"
                                    type="email"
                                    autoComplete="email"
                                    autoFocus
                                    required
                                    placeholder="you@example.com"
                                    className="text-start"
                                    value={form.data.email}
                                    onChange={(e) => form.setData('email', e.target.value)}
                                />
                            </span>
                        </Field>

                        <Field label="كلمة المرور" required htmlFor="password">
                            <PasswordInput
                                id="password"
                                name="password"
                                autoComplete="new-password"
                                required
                                placeholder="••••••••"
                                className="text-start"
                                value={form.data.password}
                                onChange={(e) => form.setData('password', e.target.value)}
                            />
                        </Field>

                        {/*
                            والشروطُ تُقال قبل أن تُفرض — ولا تُلوَّن حمراءَ قبل
                            أن يبدأ الكتابة.

                            حقلٌ يصرخ «قصيرة» عند أوّل حرفٍ يُكتب فيه يُقرأ
                            خطأً وقع، لا شرطًا لم يُستوفَ بعد. فتبقى رماديّةً
                            حتى يُكتب أوّلُ حرف، ثمّ تخضرّ واحدةً واحدة.
                        */}
                        <ul className="space-y-1.5">
                            {rules.map((r) => (
                                <li
                                    key={r.label}
                                    className={cn(
                                        'flex items-center gap-2 text-[12.5px] transition-colors',
                                        form.data.password === ''
                                            ? 'text-[#9ca3af]'
                                            : r.ok
                                              ? 'text-[#047857]'
                                              : 'text-[#6b7280]',
                                    )}
                                >
                                    <span
                                        aria-hidden
                                        className={cn(
                                            'grid size-4 shrink-0 place-items-center rounded-full border transition-colors',
                                            r.ok && form.data.password !== ''
                                                ? 'border-[#047857] bg-[#047857] text-white'
                                                : 'border-[#d8d8d8]',
                                        )}
                                    >
                                        {r.ok && form.data.password !== '' && (
                                            <Check className="size-2.5" strokeWidth={4} />
                                        )}
                                    </span>
                                    {t(r.label)}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {/*
                    وتنبيهُ «أكمل الحقول» لا يظهر إلّا بعد محاولةِ متابعة.

                    رسالةٌ تسبق المحاولةَ تُقرأ اتّهامًا لمن لم يفعل شيئًا بعد.
                */}
                {tried && !here.done && (
                    <p role="alert" className="mt-3 text-[12.5px] text-[#b91c1c]">
                        {t('أكمل هذه الخطوة للمتابعة.')}
                    </p>
                )}

                <div className="mt-7 flex items-center gap-3">
                    {step > 0 && (
                        <Button type="button" variant="outline" size="lg" onClick={back}>
                            {/*
                                والسهمُ يتبع اللغة: `rtl:rotate-180` تقلبه في
                                العربيّة. وسهمٌ ثابتٌ يشير إلى «السابق» ويتّجه
                                إلى حيث يقع «التالي».
                            */}
                            <ArrowLeft className="rtl:rotate-180" />
                            {t('السابق')}
                        </Button>
                    )}

                    <Button type="submit" size="lg" className="flex-1" loading={form.processing}>
                        {last ? t('ابدأ إدارة متجرك') : t('متابعة')}
                        {!last && <ArrowRight className="rtl:rotate-180" />}
                    </Button>
                </div>

                {/* والاختياريّةُ تُتخطّى صراحةً — لا بتركِ الحقل فارغًا وتخمين النيّة */}
                {here.optional && (
                    <button
                        type="button"
                        onClick={() => setStep((s) => Math.min(steps.length - 1, s + 1))}
                        className="mt-4 w-full text-center text-[13px] text-[#9ca3af] underline-offset-4 transition-colors hover:text-[#4b4b4b] hover:underline"
                    >
                        {t('تخطّي الآن')}
                    </button>
                )}
            </form>

            <p className="mt-8 text-center text-[13px] text-[#6b7280]">
                {t('لديك حساب؟')}{' '}
                <Link
                    href={route('login.form')}
                    className="font-semibold text-[#111] underline-offset-4 hover:underline"
                >
                    {t('تسجيل الدخول')}
                </Link>
            </p>
        </AuthLayout>
    );
}
