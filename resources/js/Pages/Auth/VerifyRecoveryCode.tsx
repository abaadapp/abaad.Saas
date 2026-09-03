import { type FormEvent, useEffect, useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowRight, CircleCheck, KeyRound, Languages, TriangleAlert } from 'lucide-react';
import Logo from '@/Components/Logo';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
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
    const { challenge, masked, cooldown, year, locale, errors, flash } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const switchLocale = () => {
        const next = locale === 'en' ? 'ar' : 'en';
        router.post(route('language.guest'), { locale: next }, { onSuccess: () => window.location.reload() });
    };

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
        <div className="flex min-h-dvh flex-col bg-[#f7f8f9] px-4 py-8">
            <Head title={t('رمز التحقق')} />

            <div className="flex flex-1 items-center justify-center">
                <div className="w-full max-w-[400px]">
                    <div className="mb-8 flex -translate-y-6 justify-center">
                        <Logo className="h-14 w-auto text-[#111]" />
                    </div>

                    <Card className="p-7">
                        <h1 className="text-[17px] font-bold text-[#111]">{t('رمز التحقق')}</h1>
                        <p className="mt-1 text-[13px] text-[#9ca3af]">
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
