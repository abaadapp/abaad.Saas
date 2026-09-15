import { type FormEvent, useEffect, useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowRight, CircleCheck, KeyRound, TriangleAlert } from 'lucide-react';
import AuthLayout from '@/Layouts/AuthLayout';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Props {
    /** رمزٌ مبهم لا يدلّ على صاحبه — الخادم وحده يعرف الحساب خلفه */
    challenge: string;
    /** بريد الوجهة مُقنَّعًا: يطمئن صاحبه أنّ الرمز في صندوقه، ولا يكشف عنوانه */
    masked: string | null;
    cooldown: number;
    year: number;
}

/**
 * إدخال رمز التحقّق.
 *
 * ولا حقلَ بريدٍ في هذه الشاشة البتّة: الوجهة قُرّرت في الخادم من العنوان
 * المختوم المحفوظ، وإتاحةُ تغييرها هنا تُبطل الحماية كلّها.
 *
 * وتستعمل قشرة شاشات الدخول كما هي — الشعار والبطاقة ومبدّل اللغة والحقول:
 * هي صفحةٌ جديدة لأنّ الخطوة جديدة، لا لأنّ لها شكلًا خاصًّا.
 */
export default function VerifyRecoveryCode() {
    const { challenge, masked, cooldown, year, errors, flash } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const form = useForm({ challenge, code: '' });

    /*
     * العدّاد زينةٌ لا حارس.
     *
     * المهلة الحقيقية في الخادم يقيسها من زمن آخر رمزٍ في القاعدة؛ وهذا
     * يمنع النقرة العابثة ويقول للمنتظر كم بقي. ومن يُغلق الصفحة ويفتحها
     * يبدأ عدّادًا جديدًا — والقاعدة لا تنسى.
     */
    const [left, setLeft] = useState(cooldown);

    useEffect(() => {
        if (left <= 0) return;
        const id = setTimeout(() => setLeft((v) => v - 1), 1000);

        return () => clearTimeout(id);
    }, [left]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('recovery.check'), { onFinish: () => form.reset('code') });
    };

    const resend = () =>
        router.post(
            route('recovery.resend'),
            { challenge },
            { preserveScroll: true, onSuccess: () => setLeft(cooldown) },
        );

    return (
        <AuthLayout title={t('رمز التحقق')} year={year}>
            <h1 className="text-[26px] font-bold leading-tight text-[#111]">{t('رمز التحقق')}</h1>
            <p className="mt-1.5 text-[14px] leading-relaxed text-[#6b7280]">
                {masked
                    ? `${t('أرسلنا رمزًا من ٦ أرقام إلى')} ${masked}`
                    : t('أرسلنا رمزًا من ٦ أرقام إلى بريد الاستعادة المسجّل.')}
            </p>

            {flash?.status && (
                <div
                    role="status"
                    className="mt-5 flex items-start gap-2 rounded-[10px] border border-[#bbf7d0] bg-[#f0fdf4] p-3 text-[13px] text-[#15803d]"
                >
                    <CircleCheck className="mt-px size-4 shrink-0" />
                    <span>{flash.status}</span>
                </div>
            )}

            {errors.code && (
                <div
                    role="alert"
                    className="mt-5 flex items-start gap-2 rounded-[10px] border border-[#fecaca] bg-[#fef2f2] p-3 text-[13px] text-[#b91c1c]"
                >
                    <TriangleAlert className="mt-px size-4 shrink-0" />
                    <span>{errors.code}</span>
                </div>
            )}

            <form onSubmit={submit} data-enter-submits className="mt-5 space-y-4">
                <Field label="الرمز" required htmlFor="code">
                    <span className="relative block" dir="ltr">
                        <KeyRound className="pointer-events-none absolute start-3 top-3 size-4 text-[#9ca3af]" />
                        <Input
                            /* «اذهب» لا «تم»: هذا النموذج يُرسَل بالمفتاح — انظر lib/enter-key */
                            enterKeyHint="go"
                            id="code"
                            name="code"
                            // لوحة الأرقام على الهاتف: ستّة أرقامٍ تُكتب بإبهامٍ واحد
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            autoFocus
                            required
                            maxLength={6}
                            placeholder="------"
                            className="ps-10 text-center font-mono text-lg tracking-[0.4em]"
                            value={form.data.code}
                            onChange={(e) => form.setData('code', e.target.value.replace(/\D/g, ''))}
                        />
                    </span>
                </Field>

                <Button type="submit" size="lg" className="w-full" loading={form.processing}>
                    {t('تأكيد')}
                </Button>
            </form>

            <div className="mt-5 flex items-center justify-between gap-2">
                <Link
                    href={route('login')}
                    className="inline-flex items-center gap-1.5 text-[13px] font-medium text-[#6b7280] transition-colors hover:text-[#111]"
                >
                    <ArrowRight className="size-4 rtl:rotate-180" />
                    {t('العودة لتسجيل الدخول')}
                </Link>

                <button
                    type="button"
                    onClick={resend}
                    disabled={left > 0}
                    className="text-[13px] font-medium text-[#6b7280] transition-colors hover:text-[#111] disabled:cursor-not-allowed disabled:text-[#d1d5db]"
                >
                    {left > 0 ? `${t('إعادة الإرسال بعد')} ${left}` : t('إعادة إرسال الرمز')}
                </button>
            </div>
        </AuthLayout>
    );
}
