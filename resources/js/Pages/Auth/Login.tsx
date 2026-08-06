import { type FormEvent, useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Eye, EyeOff, KeyRound, Languages, Lock, Mail, TriangleAlert } from 'lucide-react';
import Logo from '@/Components/Logo';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Props {
    /** رابط شاشة رمز الموظف — تُخفى إن لم تكن متاحة */
    pinUrl: string | null;
    year: number;
}

/**
 * أول ما يراه المستخدم من النظام: بريد وكلمة مرور، لا أكثر.
 *
 * كانت قالب Blade بثيمة بنفسجية مختلفة عن اللوحات، وتصل بحقلَيها معبّأَين
 * سلفًا ببيانات الحساب التجريبي — وهو ما كان سيُشحن إلى العملاء كما هو.
 */
export default function Login() {
    const { pinUrl, year, locale, errors } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const [reveal, setReveal] = useState(false);

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
                                    aria-label={t(reveal ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور')}
                                    className="absolute end-2 top-1.5 flex size-7 items-center justify-center rounded-[8px] text-[#9ca3af] transition-colors hover:bg-[#f2f2f0] hover:text-[#4b4b4b]"
                                >
                                    {reveal ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                                </button>
                            </span>
                        </Field>

                        {/*
                            «نسيت كلمة المرور» حُذف: كان رابط بريدٍ إلى الدعم لا
                            استعادةً فعلية — وعدٌ بباب لا يفتحه إلا إنسان. ومن
                            نسي كلمته يطلبها من صاحب النشاط، وهو يعيد تعيينها من
                            صفحة الموظف.
                        */}
                        <label className="flex w-fit cursor-pointer items-center gap-2 text-[13px] text-[#4b4b4b]">
                            <input
                                type="checkbox"
                                checked={form.data.remember}
                                onChange={(e) => form.setData('remember', e.target.checked)}
                                className="size-4 rounded-[4px] border-[#d1d5db] text-[#111] accent-[#111] focus:ring-0"
                            />
                            {t('تذكرني')}
                        </label>

                        <Button type="submit" size="lg" className="w-full" loading={form.processing}>
                            {t('تسجيل الدخول')}
                        </Button>
                    </form>

                    {pinUrl && (
                        <>
                            <div className="my-5 flex items-center gap-3">
                                <span className="h-px flex-1 bg-[var(--ui-border,#e8e8e8)]" />
                                <span className="text-[12px] text-[#9ca3af]">{t('أو')}</span>
                                <span className="h-px flex-1 bg-[var(--ui-border,#e8e8e8)]" />
                            </div>
                            <Button variant="outline" size="lg" className="w-full" asChild>
                                <a href={pinUrl}>
                                    <KeyRound />
                                    {t('دخول الموظف بالرمز')}
                                </a>
                            </Button>
                        </>
                    )}
                    </Card>
                </div>
            </div>

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
