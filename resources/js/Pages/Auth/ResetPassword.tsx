import { type FormEvent } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { Lock, TriangleAlert } from 'lucide-react';
import AuthLayout from '@/Layouts/AuthLayout';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { PasswordInput } from '@/Components/ui/password-input';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Props {
    token: string;
    email: string;
    year: number;
}

/** كلمة المرور الجديدة — تُفتح من رابط الرسالة، ومرّةً واحدة */
export default function ResetPassword() {
    const { token, email, year, errors } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const form = useForm({ token, email, password: '', password_confirmation: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('password.update'), {
            onFinish: () => form.reset('password', 'password_confirmation'),
        });
    };

    const failure = errors.email ?? errors.password ?? errors.token;

    return (
        <AuthLayout title={t('كلمة مرور جديدة')} year={year}>
            <h1 className="text-[26px] font-bold leading-tight text-[#111]">{t('كلمة مرور جديدة')}</h1>
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

            {/* «اذهب» على مفتاح الآيباد تُرسل هنا: حقلان وزرٌّ واحد،
                ومنعُ الإرسال إزعاجٌ لا حماية — انظر lib/enter-key */}
            <form onSubmit={submit} data-enter-submits className="mt-5 space-y-4">
                <Field label="كلمة المرور الجديدة" required htmlFor="password" hint="٨ أحرف على الأقل">
                    <PasswordInput
                        id="password"
                        name="password"
                        autoComplete="new-password"
                        autoFocus
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

                <Field label="تأكيد كلمة المرور" required htmlFor="password_confirmation">
                    <PasswordInput
                        id="password_confirmation"
                        name="password_confirmation"
                        autoComplete="new-password"
                        required
                        placeholder="••••••••"
                        className="ps-10 text-start"
                        leading={
                            <Lock className="pointer-events-none absolute start-3 top-3 z-10 size-4 text-[#9ca3af]" />
                        }
                        value={form.data.password_confirmation}
                        onChange={(e) => form.setData('password_confirmation', e.target.value)}
                    />
                </Field>

                <Button type="submit" size="lg" className="w-full" loading={form.processing}>
                    {t('حفظ كلمة المرور')}
                </Button>
            </form>
        </AuthLayout>
    );
}
