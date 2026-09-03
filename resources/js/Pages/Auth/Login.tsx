import { type FormEvent, useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Languages, Lock, Mail, TriangleAlert } from 'lucide-react';
import Logo from '@/Components/Logo';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { PasswordInput } from '@/Components/ui/password-input';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Props {
    /**
     * هوية هذا الجهاز — تصل فقط إن سبق أن دخل منه أحد.
     *
     * وجودها هو الإذن بعرض اسم المتجر فوق البطاقة وبابِ نسيانه: على متصفّحٍ
     * لم يُعرف بعد لا شيء من ذلك — انظر LoginController::showLogin.
     */
    device: {
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
 * كان معهما تبويبٌ ثانٍ — أربعة أرقامٍ يدخل بها الكاشير — يُفتح افتراضيًّا على
 * كل جهازٍ سبق أن دخل منه أحد. رُفع الباب كلّه، فبقي بابٌ واحد لا تبويب فوقه.
 */
export default function Login() {
    const { device, year, canRecover, locale, errors } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const [forgetting, setForgetting] = useState(false);

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

    return (
        <div className="flex min-h-dvh flex-col bg-[#f7f8f9] px-4 py-8">
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
                    اسم المتجر فوق البطاقة: الموظف يجب أن يعرف على أي متجرٍ
                    يقف قبل أن يدخل — وجهازٌ رُبط بالمتجر الخطأ يوم التركيب
                    يبقى صامتًا حتى يقف أحدهم أمام شاشةٍ ترفض بياناته الصحيحة
                    ولا يفهم لماذا.
                */}
                {device?.business && (
                    <div className="mb-5 text-center">
                        <p className="text-[15px] font-semibold text-[#111]">{device.business}</p>
                        {device.branch && (
                            <p className="mt-0.5 text-[13px] text-[#6b7280]">
                                {device.branch}
                                {device.device ? ` • ${device.device}` : ''}
                            </p>
                        )}
                    </div>
                )}

                <Card className="p-7">
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

                    {/* «اذهب» على مفتاح الآيباد تُرسل هنا: حقلان وزرٌّ واحد،
                        ومنعُ الإرسال إزعاجٌ لا حماية — انظر lib/enter-key */}
                    <form onSubmit={submit} data-enter-submits className="mt-5 space-y-4">
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
                            <PasswordInput
                                id="password"
                                name="password"
                                autoComplete="current-password"
                                required
                                placeholder="••••••••"
                                className="px-10 text-start"
                                leading={
                                    <Lock className="pointer-events-none absolute start-3 top-3 z-10 size-4 text-[#9ca3af]" />
                                }
                                value={form.data.password}
                                onChange={(e) => form.setData('password', e.target.value)}
                            />
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
                    </Card>

                    {/*
                        المخرج من شاشةٍ صارت مقفلة على متجرٍ واحد.
                        جهازٌ بيع، أو نُقل إلى محلٍّ آخر، أو رُبط يوم التركيب
                        بالمتجر الخطأ — وبلا هذا لا حيلة إلا مسح كوكي المتصفّح
                        يدويًّا، وهو ما لا يعرفه صاحب المحل.
                    */}
                    {device && (
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
                            {device?.activated
                                ? t('سينسى هذا الجهاز فرعَه، ويحتاج مديرًا لإعادة تفعيله من «فتح نقطة البيع». المبيعات والفواتير لا تتأثر.')
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
