import { type FormEvent, useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Eye, EyeOff, KeyRound, Languages, Lock, Mail, TriangleAlert } from 'lucide-react';
import Logo from '@/Components/Logo';
import Field from '@/Components/Field';
import PinPad from '@/Components/PinPad';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface Props {
    /**
     * هوية هذا الجهاز — تصل فقط إن سبق أن دخل منه أحد.
     *
     * وجودها هو الإذن بعرض تبويب «رمز الموظف»: على متصفّحٍ لم يُعرف بعد لا
     * تبويب أصلًا، فلا يرى الزائرُ بابًا لا يعنيه — انظر LoginController::showLogin.
     */
    pin: {
        business: string | null;
        branch: string | null;
        device: string | null;
        /** جهازٌ مفعَّل (يحتاج مديرًا لإعادة تفعيله) أم متجرٌ متذكَّر وحسب؟ */
        activated: boolean;
    } | null;
    year: number;
    /** هل يستطيع النظام إرسال بريدٍ فعلًا — انظر App\Support\Mailer */
    canRecover: boolean;
}

/**
 * أول ما يراه المستخدم من النظام: بريد وكلمة مرور، لا أكثر.
 *
 * كانت قالب Blade بثيمة بنفسجية مختلفة عن اللوحات، وتصل بحقلَيها معبّأَين
 * سلفًا ببيانات الحساب التجريبي — وهو ما كان سيُشحن إلى العملاء كما هو.
 */
export default function Login() {
    const { pin, year, canRecover, locale, errors } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const [reveal, setReveal] = useState(false);
    const [forgetting, setForgetting] = useState(false);

    /*
     * التبويب يبدأ على «رمز الموظف» متى وُجد.
     *
     * الجهاز في المحل واحد، ومن يقف أمامه طوال اليوم هو الكاشير لا المالك:
     * فتحُه على البريد يجعل الأغلبيةَ تضغط تبويبًا قبل كل دخول.
     *
     * والخطأ يسبق ذلك كلّه: الرفض يعيد تحميل الصفحة، فلو بدأ التبويب على
     * الرمز دائمًا لابتلع خطأَ «بيانات الدخول غير صحيحة» — يضغط المالك
     * «دخول» فترتدّ الشاشة إلى لوحة أرقام، ولا يعرف أنه أخطأ كلمته.
     */
    const [tab, setTab] = useState<'email' | 'pin'>(
        errors.email || errors.password ? 'email' : pin ? 'pin' : 'email',
    );

    /**
     * اتجاه الصفحة (dir) يُحسم في قالب الجذر عند تحميلها، فلا يكفي تحديث
     * Inertia الجزئي لقلبه — نعيد التحميل بعد الحفظ كما تفعل بقية اللوحات.
     */
    const switchLocale = () => {
        const next = locale === 'en' ? 'ar' : 'en';
        router.post(
            route('language.guest'),
            { locale: next },
            { onSuccess: () => window.location.reload() },
        );
    };

    const form = useForm({ email: '', password: '', remember: false });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('login.attempt'), { onFinish: () => form.reset('password') });
    };

    // التحقق يردّ الخطأ على حقل البريد؛ نعرضه شريطًا واحدًا فوق النموذج
    const failure = errors.email ?? errors.password;

    const tabClass =
        'flex items-center justify-center gap-1.5 rounded-[10px] py-2 text-[13px] font-medium transition-colors';
    const tabOn = 'bg-white text-[#111] shadow-[0_1px_2px_rgba(0,0,0,0.06)]';
    const tabOff = 'text-[#6b7280] hover:text-[#111]';

    return (
        <div className="flex min-h-screen flex-col bg-[#f7f8f9] px-4 py-8">
            <Head title={t('تسجيل الدخول')} />

            {/* flex-1 يبتلع الفراغ فيبقى النموذج في وسط الشاشة والتذييل ملتصقًا بأسفلها */}
            <div className="flex flex-1 items-center justify-center">
                <div className="w-full max-w-[400px]">
                {/* الإزاحة بـtransform لا بهامش: الهامش يزيد ارتفاع الكتلة المتمركزة
                    فينزل معه موضع البطاقة، والتحويل يرفع الشعار وحده */}
                <div className="mb-8 flex -translate-y-6 justify-center">
                    <Logo className="h-14 w-auto text-[#111]" />
                </div>

                {/*
                    اسم المتجر فوق البطاقة لا داخل تبويب الرمز وحده.
                    الموظف يجب أن يعرف على أي متجرٍ يقف قبل أن يمدّ يده إلى
                    الأرقام — وجهازٌ رُبط بالمتجر الخطأ يوم التركيب يبقى صامتًا
                    حتى يقف أحدهم أمام شاشةٍ ترفض رمزه الصحيح ولا يفهم لماذا.
                */}
                {pin?.business && (
                    <div className="mb-5 text-center">
                        <p className="text-[15px] font-semibold text-[#111]">{pin.business}</p>
                        {pin.branch && (
                            <p className="mt-0.5 text-[13px] text-[#6b7280]">
                                {pin.branch}
                                {pin.device ? ` • ${pin.device}` : ''}
                            </p>
                        )}
                    </div>
                )}

                <Card className="p-7">
                    {/*
                        تبويبان لا زرٌّ ينقل إلى شاشةٍ أخرى: البابان متساويان
                        على جهاز المحل — المالك ببريده والكاشير برمزه — والانتقال
                        بينهما لا يستحقّ تحميل صفحة.
                    */}
                    {/*
                        النصّان في نداءَي t() مباشرةً لا في مصفوفةٍ تُمرّ عليها.
                        فاحصُ الترجمة يقرأ ما بين قوسَي t('...') وحدها، فنصٌّ
                        يصل إليها في متغيّر يمرّ صامتًا: يقول الفاحص «الترجمة
                        كاملة» ويبقى التبويبان عربيَّين في الشاشة الإنجليزية.
                    */}
                    {pin && (
                        <div className="mb-6 grid grid-cols-2 gap-1 rounded-[12px] bg-[#f2f2f0] p-1">
                            <button
                                type="button"
                                onClick={() => setTab('pin')}
                                aria-pressed={tab === 'pin'}
                                className={cn(tabClass, tab === 'pin' ? tabOn : tabOff)}
                            >
                                <KeyRound className="size-4" />
                                {t('رمز الموظف')}
                            </button>
                            <button
                                type="button"
                                onClick={() => setTab('email')}
                                aria-pressed={tab === 'email'}
                                className={cn(tabClass, tab === 'email' ? tabOn : tabOff)}
                            >
                                <Mail className="size-4" />
                                {t('البريد وكلمة المرور')}
                            </button>
                        </div>
                    )}

                    {pin && tab === 'pin' ? (
                        <>
                            <h1 className="text-[17px] font-bold text-[#111]">{t('دخول الموظف')}</h1>
                            <p className="mt-1 mb-5 text-[13px] text-[#9ca3af]">
                                {t('أدخل رمز الدخول المكوّن من 4 أرقام')}
                            </p>
                            <PinPad from="login" />
                        </>
                    ) : (
                        <>
                    <h1 className="text-[17px] font-bold text-[#111]">{t('تسجيل الدخول')}</h1>
                    <p className="mt-1 text-[13px] text-[#9ca3af]">
                        {t('أدخل بريدك وكلمة المرور للمتابعة')}
                    </p>

                    {failure && (
                        <div
                            role="alert"
                            className="mt-5 flex items-start gap-2 rounded-[10px] border border-[#fecaca] bg-[#fef2f2] p-3 text-[13px] text-[#b91c1c]"
                        >
                            <TriangleAlert className="mt-px size-4 shrink-0" />
                            <span>{failure}</span>
                        </div>
                    )}

                    <form onSubmit={submit} className="mt-5 space-y-4">
                        <Field label="البريد الإلكتروني" required htmlFor="email">
                            {/*
                             * الغلاف بالاتجاه نفسه لا الصفحة: البريد قيمة لاتينية دائمًا،
                             * وكان الحقل dir="ltr" بينما غلافه يتبع الصفحة — فتُحسب حشوة
                             * الأيقونة (ps) يسارًا وتُرسم الأيقونة (start) يمينًا في العربية،
                             * فيمرّ البريد الطويل تحتها. توحيد الاتجاه يجعلهما جهة واحدة.
                             */}
                            <span className="relative block" dir="ltr">
                                <Mail className="pointer-events-none absolute start-3 top-3 size-4 text-[#9ca3af]" />
                                <Input
                                    id="email"
                                    name="email"
                                    type="email"
                                    autoComplete="username"
                                    autoFocus
                                    required
                                    placeholder="you@example.com"
                                    className="ps-10 text-start"
                                    value={form.data.email}
                                    onChange={(e) => form.setData('email', e.target.value)}
                                />
                            </span>
                        </Field>

                        <Field label="كلمة المرور" required htmlFor="password">
                            {/* نفس السبب، وليبقى القفل في جهة أيقونة البريد لا مقابلها */}
                            <span className="relative block" dir="ltr">
                                <Lock className="pointer-events-none absolute start-3 top-3 size-4 text-[#9ca3af]" />
                                <Input
                                    id="password"
                                    name="password"
                                    type={reveal ? 'text' : 'password'}
                                    autoComplete="current-password"
                                    required
                                    placeholder="••••••••"
                                    className="px-10 text-start"
                                    value={form.data.password}
                                    onChange={(e) => form.setData('password', e.target.value)}
                                />
                                <button
                                    type="button"
                                    onClick={() => setReveal((v) => !v)}
                                    aria-label={reveal ? t('إخفاء كلمة المرور') : t('إظهار كلمة المرور')}
                                    className="absolute end-2 top-1.5 flex size-7 items-center justify-center rounded-[8px] text-[#9ca3af] transition-colors hover:bg-[#f2f2f0] hover:text-[#4b4b4b]"
                                >
                                    {reveal ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                                </button>
                            </span>
                        </Field>

                        {/*
                            «نسيت كلمة المرور» صار مسارًا حقيقيًّا لا رابط بريدٍ
                            إلى الدعم. الموظف يطلب رمزه من صاحب النشاط كما كان،
                            وصاحب النشاط نفسه لم يكن له باب إلا الاتصال بالدعم.

                            ويختفي حين لا يكون البريد مضبوطًا — ولا يُترك مكانه
                            فارغًا: من نسي كلمته يحتاج جوابًا، لا غيابَ سؤال.
                            بابٌ يقول «أرسلنا الرابط» ولا يُرسل أسوأ من بابٍ
                            مكتوبٍ عليه «اطلب من المدير».
                        */}
                        <div className="flex items-center justify-between gap-3">
                            <label className="flex w-fit cursor-pointer items-center gap-2 text-[13px] text-[#4b4b4b]">
                                <input
                                    type="checkbox"
                                    checked={form.data.remember}
                                    onChange={(e) => form.setData('remember', e.target.checked)}
                                    className="size-4 rounded-[4px] border-[#d1d5db] text-[#111] accent-[#111] focus:ring-0"
                                />
                                {t('تذكرني')}
                            </label>

                            {canRecover ? (
                                <Link
                                    href={route('password.request')}
                                    className="text-[13px] font-medium text-[#6b7280] transition-colors hover:text-[#111]"
                                >
                                    {t('نسيت كلمة المرور؟')}
                                </Link>
                            ) : (
                                <span className="text-[13px] text-[#9ca3af]">
                                    {t('نسيت كلمتك؟ راجع مدير النظام')}
                                </span>
                            )}
                        </div>

                        <Button type="submit" size="lg" className="w-full" loading={form.processing}>
                            {t('تسجيل الدخول')}
                        </Button>
                    </form>
                        </>
                    )}
                    </Card>

                    {/*
                        المخرج من شاشةٍ صارت مقفلة على متجرٍ واحد.
                        جهازٌ بيع، أو نُقل إلى محلٍّ آخر، أو رُبط يوم التركيب
                        بالمتجر الخطأ — وبلا هذا لا حيلة إلا مسح كوكي المتصفّح
                        يدويًّا، وهو ما لا يعرفه صاحب المحل.
                    */}
                    {pin && (
                        <div className="mt-5 text-center">
                            <button
                                type="button"
                                onClick={() => setForgetting(true)}
                                className="text-[13px] text-[#9ca3af] underline-offset-4 transition-colors hover:text-[#4b4b4b] hover:underline"
                            >
                                {t('ليس هذا متجرك؟')}
                            </button>
                        </div>
                    )}
                </div>
            </div>

            {/*
                تأكيدٌ لا زرٌّ مباشر — والنصّ يفترق بحسب ما يُنسى.
                الجهاز المفعَّل يحتاج مديرًا يعيد تفعيله، فضغطةٌ عابرة عليه
                توقف الصندوق حتى يحضر أحد. أما المتجر المتذكَّر فيُكتب من
                جديد عند أي دخولٍ بالبريد، ونسيانه لا يكلّف شيئًا.
            */}
            <Dialog open={forgetting} onOpenChange={setForgetting}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('نسيان هذا الجهاز')}</DialogTitle>
                    </DialogHeader>

                    <div className="px-5 pb-5">
                        {/* نداءان لا نداءٌ بعاملٍ ثلاثي: الفاحص يقرأ ما يلي t( مباشرةً */}
                        <p className="text-[13px] leading-6 text-[#4b4b4b]">
                            {pin?.activated
                                ? t('سيتوقف الدخول بالرمز على هذا الجهاز، ويحتاج مديرًا لإعادة تفعيله من «فتح نقطة البيع». المبيعات والفواتير لا تتأثر.')
                                : t('سيُنسى المتجر المرتبط بهذا الجهاز. يعود الربط تلقائيًا عند أول دخول بالبريد وكلمة المرور.')}
                        </p>

                        <div className="mt-5 flex justify-end gap-2">
                            <Button type="button" variant="outline" onClick={() => setForgetting(false)}>
                                {t('إلغاء')}
                            </Button>
                            <Button
                                type="button"
                                variant="danger"
                                onClick={() => router.post(route('device.forget'))}
                            >
                                {t('نسيان الجهاز')}
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>

            {/* التذييل خارج الكتلة المتمركزة، فيثبت في أسفل الشاشة */}
            <div className="flex shrink-0 flex-col items-center gap-2">
                <button
                    type="button"
                    onClick={switchLocale}
                    lang={locale === 'en' ? 'ar' : 'en'}
                    className="flex items-center gap-1.5 rounded-[10px] px-3 py-1.5 text-[13px] font-medium text-[#6b7280] transition-colors hover:bg-white hover:text-[#111]"
                >
                    <Languages className="size-4" />
                    {/* اسم اللغة الأخرى بلغتها: زر واحد لا قائمة، والخياران اثنان */}
                    {locale === 'en' ? 'العربية' : 'English'}
                </button>

                <p className="text-[12px] text-[#9ca3af]">
                    © {year} Abaad — {t('جميع الحقوق محفوظة')}
                </p>
            </div>
        </div>
    );
}
