import { type FormEvent, useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Eye, EyeOff, Languages, Lock, TriangleAlert } from 'lucide-react';
import Logo from '@/Components/Logo';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Props {
    token: string;
    email: string;
    year: number;
}

/** كلمة المرور الجديدة — تُفتح من رابط الرسالة، ومرّةً واحدة */
export default function ResetPassword() {
    const { token, email, year, locale, errors } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const [reveal, setReveal] = useState(false);

    const switchLocale = () => {
        const next = locale === 'en' ? 'ar' : 'en';
        router.post(route('language.guest'), { locale: next }, { onSuccess: () => window.location.reload() });
    };

    const form = useForm({ token, email, password: '', password_confirmation: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('password.update'), {
            onFinish: () => form.reset('password', 'password_confirmation'),
        });
    };

    const failure = errors.email ?? errors.password ?? errors.token;

    return (
        <div className="flex min-h-screen flex-col bg-[#f7f8f9] px-4 py-8">
            <Head title={t('كلمة مرور جديدة')} />

            <div className="flex flex-1 items-center justify-center">
                <div className="w-full max-w-[400px]">
                    <div className="mb-8 flex -translate-y-6 justify-center">
                        <Logo className="h-14 w-auto text-[#111]" />
                    </div>

                    <Card className="p-7">
                        <h1 className="text-[17px] font-bold text-[#111]">{t('كلمة مرور جديدة')}</h1>
                        {/* البريد يُعرض ولا يُعدَّل: الرمز مرتبط به، وتغييرُه يجعل الرابط يُرفض بلا سبب مفهوم */}
                        <p className="mt-1 text-[13px] text-[#9ca3af]" dir="ltr">
                            {email}
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
                            <Field label="كلمة المرور الجديدة" required htmlFor="password" hint="٨ أحرف على الأقل">
                                <span className="relative block" dir="ltr">
                                    <Lock className="pointer-events-none absolute start-3 top-3 size-4 text-[#9ca3af]" />
                                    <Input
                                        id="password"
                                        name="password"
                                        type={reveal ? 'text' : 'password'}
                                        autoComplete="new-password"
                                        autoFocus
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

                            <Field label="تأكيد كلمة المرور" required htmlFor="password_confirmation">
                                <span className="relative block" dir="ltr">
                                    <Lock className="pointer-events-none absolute start-3 top-3 size-4 text-[#9ca3af]" />
                                    <Input
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        type={reveal ? 'text' : 'password'}
                                        autoComplete="new-password"
                                        required
                                        placeholder="••••••••"
                                        className="ps-10 text-start"
                                        value={form.data.password_confirmation}
                                        onChange={(e) => form.setData('password_confirmation', e.target.value)}
                                    />
                                </span>
                            </Field>

                            <Button type="submit" size="lg" className="w-full" loading={form.processing}>
                                {t('حفظ كلمة المرور')}
                            </Button>
                        </form>
                    </Card>
                </div>
            </div>

            <div className="flex shrink-0 flex-col items-center gap-2">
                <button
                    type="button"
                    onClick={switchLocale}
                    lang={locale === 'en' ? 'ar' : 'en'}
                    className="flex items-center gap-1.5 rounded-[10px] px-3 py-1.5 text-[13px] font-medium text-[#6b7280] transition-colors hover:bg-white hover:text-[#111]"
                >
                    <Languages className="size-4" />
                    {locale === 'en' ? 'العربية' : 'English'}
                </button>

                <p className="text-[12px] text-[#9ca3af]">
                    © {year} Abaad — {t('جميع الحقوق محفوظة')}
                </p>
            </div>
        </div>
    );
}
