import { type FormEvent } from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import { ArrowRight, CircleCheck, Mail, TriangleAlert } from 'lucide-react';
import AuthLayout from '@/Layouts/AuthLayout';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Props {
    year: number;
}

/**
 * «نسيت كلمة المرور» — بابٌ يفتحه صاحبه بنفسه.
 *
 * حقلٌ واحد: اسم الدخول. ولا حقلَ لوجهة الإرسال — الرمز يذهب إلى بريد
 * الاستعادة المختوم المحفوظ سلفًا، أو لا يذهب. فمن كتب وجهته بنفسه يُثبت
 * أنّه يملك صندوقًا أنشأه قبل ثانية، لا أنّه يملك المتجر.
 */
export default function ForgotPassword() {
    const { year, errors, flash } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const form = useForm({ email: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('recovery.start'));
    };

    return (
        <AuthLayout title={t('نسيت كلمة المرور')} year={year}>
            <h1 className="text-[26px] font-bold leading-tight text-[#111]">{t('نسيت كلمة المرور')}</h1>
            <p className="mt-1.5 text-[14px] leading-relaxed text-[#6b7280]">
                {t('أدخل بريد الدخول ونرسل رمز التحقق إلى بريد الاستعادة المسجّل')}
            </p>

            {/*
                الجواب واحدٌ في كل حال (انظر PasswordResetController::send)،
                فالنجاح يُعرض ويبقى الحقل — من أخطأ في الكتابة يعيدها بلا رجوع
            */}
            {flash?.status && (
                <div
                    role="status"
                    className="mt-5 flex items-start gap-2 rounded-[10px] border border-[#bbf7d0] bg-[#f0fdf4] p-3 text-[13px] text-[#15803d]"
                >
                    <CircleCheck className="mt-px size-4 shrink-0" />
                    <span>{flash.status}</span>
                </div>
            )}

            {errors.email && (
                <div
                    role="alert"
                    className="mt-5 flex items-start gap-2 rounded-[10px] border border-[#fecaca] bg-[#fef2f2] p-3 text-[13px] text-[#b91c1c]"
                >
                    <TriangleAlert className="mt-px size-4 shrink-0" />
                    <span>{errors.email}</span>
                </div>
            )}

            {/* «اذهب» على مفتاح الآيباد تُرسل هنا: حقلان وزرٌّ واحد،
                ومنعُ الإرسال إزعاجٌ لا حماية — انظر lib/enter-key */}
            <form onSubmit={submit} data-enter-submits className="mt-5 space-y-4">
                <Field label="البريد الإلكتروني" required htmlFor="email">
                    <span className="relative block" dir="ltr">
                        <Mail className="pointer-events-none absolute start-3 top-3 size-4 text-[#9ca3af]" />
                        <Input
                            /* «اذهب» لا «تم»: هذا النموذج يُرسَل بالمفتاح — انظر lib/enter-key */
                            enterKeyHint="go"
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

                <Button type="submit" size="lg" className="w-full" loading={form.processing}>
                    {t('إرسال رمز التحقق')}
                </Button>
            </form>

            <div className="mt-5 text-center">
                <Link
                    href={route('login')}
                    className="inline-flex items-center gap-1.5 text-[13px] font-medium text-[#6b7280] transition-colors hover:text-[#111]"
                >
                    <ArrowRight className="size-4 rtl:rotate-180" />
                    {t('العودة لتسجيل الدخول')}
                </Link>
            </div>
        </AuthLayout>
    );
}
