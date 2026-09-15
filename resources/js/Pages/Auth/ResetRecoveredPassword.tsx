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
    /** رمزٌ مبهم — الخادم وحده يعرف الحساب خلفه وأنّه اجتاز التحقّق */
    challenge: string;
    year: number;
}

/**
 * كلمة المرور الجديدة — بعد اجتياز الرمز، ومرّةً واحدة.
 *
 * ولا بريدَ في الشاشة ولا معرّف حساب: الخادم يقرأ صاحب المحاولة من رمزها.
 * وهي نسخةٌ من شاشة الرابط القديم بالحقول والقشرة نفسها — اختلافها في مدخلٍ
 * واحد لا في شكل.
 */
export default function ResetRecoveredPassword() {
    const { challenge, year, errors } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const form = useForm({ challenge, password: '', password_confirmation: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('recovery.password.store'), {
            onFinish: () => form.reset('password', 'password_confirmation'),
        });
    };

    const failure = errors.password ?? errors.challenge;

    return (
        <AuthLayout title={t('كلمة مرور جديدة')} year={year}>
            <h1 className="text-[26px] font-bold leading-tight text-[#111]">{t('كلمة مرور جديدة')}</h1>
            <p className="mt-1.5 text-[14px] leading-relaxed text-[#6b7280]">
                {t('اختر كلمة مرور جديدة لحسابك')}
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
                        /* «اذهب» لا «تم»: هذا النموذج يُرسَل بالمفتاح — انظر lib/enter-key */
                        enterKeyHint="go"
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
                        /* «اذهب» لا «تم»: هذا النموذج يُرسَل بالمفتاح — انظر lib/enter-key */
                        enterKeyHint="go"
                        id="password_confirmation"
                        name="password_confirmation"
                        autoComplete="new-password"
                        required
                        placeholder="••••••••"
                        className="px-10 text-start"
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
