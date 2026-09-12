import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { KeyRound, ShieldCheck } from 'lucide-react';
import Field from '@/Components/Field';
import StatusPill from '@/Components/StatusPill';
import { SettingsSection } from '@/Components/Settings';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { PasswordInput } from '@/Components/ui/password-input';
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

    /*
     * ولا بريدَ على الخادم فلا قسم — سطرٌ واحدٌ مكانه.
     *
     * كان القسم يُعرض كاملًا بتحذيرٍ أصفرَ فوقه: حقلٌ يُكتب فيه، وكلمةُ مرورٍ
     * تُطلب، وزرٌّ يُضغط. ثمّ يردّ الخادم 404 — لأنّ `RecoveryEmailController::start`
     * يرفض حين لا بريد. فيظنّ التاجر العطبَ في كتابته ويعيد المحاولة.
     *
     * وهذه القاعدةُ مطبَّقةٌ في شاشة الدخول منذ زمن: يختفي «نسيت كلمة المرور؟»
     * ويحلّ محلّه «راجع مدير النظام». ونُسيت هنا وحدَها.
     *
     * ولا يُترك المكانُ فارغًا: من نسي كلمته يحتاج جوابًا لا غيابَ سؤال.
     *
     * ويعود القسم وحدَه يوم يُضبط مُرسِلُ بريدٍ حقيقيّ — لا سطرَ يُعدَّل.
     */
    if (! recovery.mail_ready) {
        return (
            <SettingsSection
                title="استعادة كلمة المرور"
                description="نسيت كلمة المرور؟ راجع إدارة أبعاد — الاستعادة الذاتية بالبريد غير مفعّلة بعد."
                icon={KeyRound}
                status={<StatusPill state="off" label="غير مفعّلة" />}
            />
        );
    }

    /*
        والشارةُ من مفردات الحال الموحّدة لا شارةٌ تخصّ هذا القسم.

        كانت ثلاثَ شاراتٍ مكتوبةً بيدها بنصف قطرٍ ٨ بكسل، وبقيّةُ شارات
        الإعدادات دائريّة — فتُقرأ الواحدةُ منها شيئًا آخر غير أخواتها.
        والأسماءُ تبقى أسماءَها: «موثّق» أدقُّ من «جاهز» في بريدٍ يُتحقَّق منه.
    */
    const badge = recovery.verified ? (
        <StatusPill state="ready" label="موثّق" />
    ) : recovery.email ? (
        <StatusPill state="progress" label="بانتظار التحقق" />
    ) : (
        <StatusPill state="action" label="غير مضبوط" />
    );

    /*
        قسمٌ قائمٌ بذاته لا كتلةٌ يفصلها خطّ.

        كان يعيش داخل بطاقة «بيانات النشاط» تحت خطٍّ أفقيّ، وهو موضوعٌ آخر:
        الاسمُ والهاتفُ يُطبعان على الفاتورة، وهذا يُستعاد به الحسابُ يوم
        تُنسى كلمة المرور. فصار له سطحُه كما لبقيّة المواضيع.
    */
    return (
        <SettingsSection
            title="بريد الاستعادة"
            description={
                recovery.verified
                    ? t('إليه وحده يُرسَل رمز استعادة كلمة المرور — ولا يُقبل غيره.')
                    : t('اضبطه الآن لتستعيد حسابك بنفسك يوم تنسى كلمة المرور. وبدونه ستحتاج إلى إدارة أبعاد.')
            }
            icon={KeyRound}
            status={badge}
        >
            {recovery.email && (
                <p className="mb-5 text-[13px] text-[#111]" dir="ltr">
                    {recovery.email}
                    {recovery.verified_at && (
                        <span className="ms-2 text-[12px] text-[#9ca3af]">· {recovery.verified_at}</span>
                    )}
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
                            <PasswordInput
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
        </SettingsSection>
    );
}
