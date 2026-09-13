import { type FormEvent, useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { Lock, Mail, TriangleAlert } from 'lucide-react';

import AuthLayout from '@/Layouts/AuthLayout';
import AuthShowcase from '@/Components/auth/AuthShowcase';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { PasswordInput } from '@/Components/ui/password-input';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Props {
    /**
     * هوية هذا الجهاز — تصل فقط إن سبق أن دخل منه أحد.
     *
     * وجودها هو الإذن بعرض اسم المتجر فوق النموذج وبابِ نسيانه: على متصفّحٍ
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
    /** هل بابُ التسجيل الذاتيّ مفتوح — انظر RegisterController */
    canRegister: boolean;
}

/**
 * أول ما يراه المستخدم من النظام: بريد وكلمة مرور، لا أكثر.
 *
 * ═══ وما تغيّر في هذه النسخة ═══
 *
 * كانت بطاقةً في وسط صفحةٍ رماديّة، تحمل خلفيّتَها وشعارَها ومبدّلَ لغتها
 * بيدها. فصارت على `AuthLayout` المشترك — هو نفسُه غلافُ التسجيل والتهيئة —
 * وإلى جانبها عرضٌ للمنتج على الشاشة الواسعة.
 *
 * ولم يُمسّ شيءٌ من منطقها: الجهازُ المتذكَّر، ونسيانُه، و«تذكّرني»، وبابُ
 * الاستعادة الذي يظهر بحسب وجود مُرسِل بريد.
 */
export default function Login() {
    const { device, year, canRecover, canRegister, errors } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const [forgetting, setForgetting] = useState(false);

    const form = useForm({ email: '', password: '', remember: false });

    const submit = (e: FormEvent) => {
        e.preventDefault();

        /* ومنعُ الإرسال المزدوج: الطلبُ قائمٌ فلا يُرسَل ثانٍ فوقه */
        if (form.processing) {
            return;
        }

        form.post(route('login.attempt'), { onFinish: () => form.reset('password') });
    };

    // التحقق يردّ الخطأ على حقل البريد؛ نعرضه شريطًا واحدًا فوق النموذج
    const failure = errors.email ?? errors.password;

    return (
        <AuthLayout title={t('تسجيل الدخول')} year={year} showcase={<AuthShowcase />}>
            {/*
                اسم المتجر فوق النموذج: الموظف يجب أن يعرف على أي متجرٍ يقف
                قبل أن يدخل — وجهازٌ رُبط بالمتجر الخطأ يوم التركيب يبقى
                صامتًا حتى يقف أحدهم أمام شاشةٍ ترفض بياناته الصحيحة ولا يفهم
                لماذا.
            */}
            {device?.business && (
                <div className="mb-6">
                    <p className="text-[13px] text-[#9ca3af]">{t('تدخل إلى')}</p>
                    <p className="mt-0.5 text-[15px] font-semibold text-[#111]">{device.business}</p>
                    {device.branch && (
                        <p className="mt-0.5 text-[13px] text-[#6b7280]">
                            {device.branch}
                            {device.device ? ` • ${device.device}` : ''}
                        </p>
                    )}
                </div>
            )}

            <h1 className="text-[26px] font-bold leading-tight text-[#111]">
                {t('مرحبًا بك مجددًا')} <span aria-hidden>👋</span>
            </h1>
            <p className="mt-1.5 text-[14px] text-[#6b7280]">{t('أدخل بريدك وكلمة المرور للمتابعة')}</p>

            {failure && (
                <div
                    role="alert"
                    className="mt-6 flex items-start gap-2 rounded-[10px] border border-[#fecaca] bg-[#fef2f2] p-3 text-[13px] text-[#b91c1c]"
                >
                    <TriangleAlert className="mt-px size-4 shrink-0" />
                    <span>{failure}</span>
                </div>
            )}

            {/* «اذهب» على مفتاح الآيباد تُرسل هنا: حقلان وزرٌّ واحد،
                ومنعُ الإرسال إزعاجٌ لا حماية — انظر lib/enter-key */}
            <form onSubmit={submit} data-enter-submits className="mt-6 space-y-4">
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
                    «نسيت كلمة المرور» صار مسارًا حقيقيًّا لا رابط بريدٍ إلى
                    الدعم. ويختفي حين لا يكون البريد مضبوطًا — ولا يُترك مكانه
                    فارغًا: من نسي كلمته يحتاج جوابًا، لا غيابَ سؤال. بابٌ يقول
                    «أرسلنا الرابط» ولا يُرسل أسوأ من بابٍ مكتوبٍ عليه «اطلب من
                    المدير».
                */}
                {/* ويلتفّان عند الضيق بدل أن ينضغط أحدهما سطرين */}
                <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
                    <label className="flex w-fit shrink-0 cursor-pointer items-center gap-2 text-[13px] text-[#4b4b4b]">
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

            {/*
                وبابُ الحساب الجديد — ويظهر بحسب فتحه فعلًا.

                `canRegister` يقرأ إعدادَ المنصّة: بابٌ مقفلٌ لا يُعرض رابطُه،
                فمن يضغطه لا يجد صفحةً تقول «مغلق» بعد أن ملأ اسمَه.
            */}
            {canRegister && (
                <p className="mt-6 text-center text-[13px] text-[#6b7280]">
                    {t('ليس لديك حساب؟')}{' '}
                    <Link
                        href={route('register')}
                        className="font-semibold text-[#111] underline-offset-4 hover:underline"
                    >
                        {t('إنشاء حساب')}
                    </Link>
                </p>
            )}

            {/*
                المخرج من شاشةٍ صارت مقفلة على متجرٍ واحد.
                جهازٌ بيع، أو نُقل إلى محلٍّ آخر، أو رُبط يوم التركيب بالمتجر
                الخطأ — وبلا هذا لا حيلة إلا مسح كوكي المتصفّح يدويًّا، وهو ما
                لا يعرفه صاحب المحل.
            */}
            {device && (
                <div className="mt-6 text-center">
                    <button
                        type="button"
                        onClick={() => setForgetting(true)}
                        className="text-[13px] text-[#9ca3af] underline-offset-4 transition-colors hover:text-[#4b4b4b] hover:underline"
                    >
                        {t('ليس هذا متجرك؟')}
                    </button>
                </div>
            )}

            {/*
                تأكيدٌ لا زرٌّ مباشر — والنصّ يفترق بحسب ما يُنسى.
                الجهاز المفعَّل يحتاج مديرًا يعيد تفعيله، فضغطةٌ عابرة عليه
                توقف الصندوق حتى يحضر أحد. أما المتجر المتذكَّر فيُكتب من جديد
                عند أي دخولٍ بالبريد، ونسيانه لا يكلّف شيئًا.
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
        </AuthLayout>
    );
}
