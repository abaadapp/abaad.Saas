import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { AlertTriangle, KeyRound, ShieldCheck } from 'lucide-react';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';

export interface Recovery {
    email: string | null;
    verified: boolean;
    verified_at: string | null;
    mail_ready: boolean;
}

/**
 * بريد الاستعادة — يُضبط والحساب مفتوح، قبل أن يُحتاج إليه.
 *
 * ومن ضبطه اليوم لا يحتاج إلى أحدٍ يوم ينسى كلمته. ومن لم يضبطه يمرّ بإدارة
 * أبعاد أوّل مرّة — ولهذا يقول له القسم صراحةً إنّه غير مضبوط، لا يتركه
 * يكتشف ذلك يوم ينسى.
 *
 * وقسمٌ داخل بطاقة «بيانات النشاط» لا صفحةٌ جديدة: مكانُه حيث يُضبط اسمُ
 * المتجر وهاتفُه، وهو من جنسها.
 */
export default function RecoveryEmailSection({ recovery }: { recovery: Recovery }) {
    const t = useTranslate();

    /*
     * الخطوة الثانية تُفتح بحالةٍ في الشاشة لا برمزٍ يُمرَّر.
     *
     * والخادم لا يقرأ منها شيئًا: يستخرج المحاولة من الجلسة وحدها (انظر
     * `RecoveryEmailController::confirm`). فهذه راحةُ عينٍ لا حارس.
     */
    const [sent, setSent] = useState(false);

    const start = useForm({ recovery_email: recovery.email ?? '', current_password: '' });
    const confirm = useForm({ code: '' });

    const badge = recovery.verified ? (
        <span className="inline-flex items-center gap-1.5 rounded-[8px] bg-[#f0fdf4] px-2.5 py-1 text-[12px] font-medium text-[#166534]">
            <ShieldCheck className="size-3.5" />
            {t('موثّق')}
        </span>
    ) : recovery.email ? (
        <span className="inline-flex items-center gap-1.5 rounded-[8px] bg-[#fffbeb] px-2.5 py-1 text-[12px] font-medium text-[#b45309]">
            <AlertTriangle className="size-3.5" />
            {t('بانتظار التحقق')}
        </span>
    ) : (
        <span className="inline-flex items-center gap-1.5 rounded-[8px] bg-[#fef2f2] px-2.5 py-1 text-[12px] font-medium text-[#b91c1c]">
            <AlertTriangle className="size-3.5" />
            {t('غير مضبوط')}
        </span>
    );

    return (
        <div className="mt-8 border-t border-[var(--ui-border,#e8e8e8)] pt-6">
            <div className="mb-1 flex flex-wrap items-center gap-3">
                <h3 className="font-bold text-[#111]">{t('بريد الاستعادة')}</h3>
                {badge}
            </div>

            <p className="mb-5 text-[13px] text-[#6b7280]">
                {recovery.verified
                    ? t('إليه وحده يُرسَل رمز استعادة كلمة المرور — ولا يُقبل غيره.')
                    : t('اضبطه الآن لتستعيد حسابك بنفسك يوم تنسى كلمة المرور. وبدونه ستحتاج إلى إدارة أبعاد.')}
            </p>

            {recovery.email && (
                <p className="mb-5 text-[13px] text-[#111]" dir="ltr">
                    {recovery.email}
                    {recovery.verified_at && (
                        <span className="ms-2 text-[12px] text-[#9ca3af]">· {recovery.verified_at}</span>
                    )}
                </p>
            )}

            {!recovery.mail_ready && (
                <p className="mb-5 rounded-[12px] bg-[#fffbeb] px-4 py-3 text-[13px] text-[#b45309]">
                    {t('البريد غير مفعّل على الخادم — لا يمكن إرسال رمز التحقق الآن.')}
                </p>
            )}

            {!sent ? (
                <div className="space-y-4">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field
                            label={recovery.email ? 'بريد استعادة جديد' : 'بريد الاستعادة'}
                            error={start.errors.recovery_email}
                        >
                            <Input
                                type="email"
                                dir="ltr"
                                autoComplete="email"
                                value={start.data.recovery_email}
                                onChange={(e) => start.setData('recovery_email', e.target.value)}
                                placeholder="you@gmail.com"
                            />
                        </Field>

                        {/*
                            كلمة المرور الحالية شرطٌ لا تزيين.

                            جهازٌ تُرك مفتوحًا دقيقتين يكفي لكتابة بريدٍ غريب —
                            ثمّ يملك صاحبُه الحسابَ إلى الأبد بلا كلمة مرورٍ
                            ولا شيء. فالجلسة تُثبت الجهاز، وهذه تُثبت صاحبه.
                        */}
                        <Field label="كلمة المرور الحالية" error={start.errors.current_password}>
                            <Input
                                type="password"
                                dir="ltr"
                                autoComplete="current-password"
                                value={start.data.current_password}
                                onChange={(e) => start.setData('current_password', e.target.value)}
                                placeholder="••••••••"
                            />
                        </Field>
                    </div>

                    <Button
                        type="button"
                        disabled={!recovery.mail_ready}
                        loading={start.processing}
                        onClick={() =>
                            start.post(route('admin.settings.recovery.start'), {
                                preserveScroll: true,
                                onSuccess: () => {
                                    setSent(true);
                                    start.reset('current_password');
                                },
                            })
                        }
                    >
                        <KeyRound />
                        {t('إرسال رمز التحقق')}
                    </Button>
                </div>
            ) : (
                <div className="space-y-4">
                    <p className="text-[13px] text-[#6b7280]">
                        {t('أرسلنا رمزًا من ٦ أرقام إلى البريد الجديد — اكتبه لتوثيقه.')}
                    </p>

                    <div className="max-w-[220px]">
                        <Field label="الرمز" error={confirm.errors.code}>
                            <Input
                                dir="ltr"
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                maxLength={6}
                                value={confirm.data.code}
                                onChange={(e) => confirm.setData('code', e.target.value.replace(/\D/g, ''))}
                                className="text-center font-mono tracking-[0.3em]"
                                placeholder="------"
                            />
                        </Field>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            loading={confirm.processing}
                            onClick={() =>
                                confirm.post(route('admin.settings.recovery.confirm'), {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        setSent(false);
                                        confirm.reset('code');
                                        router.reload({ only: ['recovery'] });
                                    },
                                })
                            }
                        >
                            <ShieldCheck />
                            {t('توثيق')}
                        </Button>
                        <Button type="button" variant="ghost" onClick={() => setSent(false)}>
                            {t('رجوع')}
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}
