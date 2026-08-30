import { router, useForm } from '@inertiajs/react';
import { AlertTriangle, KeyRound, Send, ShieldCheck, Trash2 } from 'lucide-react';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';

export interface BusinessRecovery {
    has_owner: boolean;
    email: string | null;
    verified: boolean;
    verified_at: string | null;
    mail_ready: boolean;
}

/**
 * وسيلة استعادة هذا المتجر — والمرّة الواحدة التي يمرّ فيها بإنسان.
 *
 * المتجر القديم بلا بريدٍ مختوم لا يستعيد نفسه: تتحقّق أنت من صاحبه كما
 * تتحقّق اليوم قبل أن تضع له كلمة مرور، ثمّ تكتب بريده هنا. والفرق أنّ هذا
 * يحدث مرّةً واحدة — بعدها يستعيد نفسه إلى الأبد.
 *
 * ولا زرَّ «توثيق يدويّ» في هذه البطاقة عمدًا: الختم لا يضعه إلا رمزٌ عاد من
 * الصندوق. ولو مَلَكتَ ختمَه بيدك لَصار كلُّ حسابٍ في المنصّة مفتوحًا لمن
 * يجلس على هذه الشاشة — يكتب بريده، يختمه، يطلب استعادة، يدخل.
 */
export default function RecoveryCard({ businessId, data }: { businessId: number; data: BusinessRecovery }) {
    const t = useTranslate();

    const form = useForm({ recovery_email: data.email ?? '' });

    if (!data.has_owner) {
        return null;
    }

    const state = data.verified ? (
        <span className="inline-flex items-center gap-1.5 rounded-[8px] bg-[#f0fdf4] px-2.5 py-1 text-[12px] font-medium text-[#166534]">
            <ShieldCheck className="size-3.5" />
            {t('موثّق')}
        </span>
    ) : data.email ? (
        <span className="inline-flex items-center gap-1.5 rounded-[8px] bg-[#fffbeb] px-2.5 py-1 text-[12px] font-medium text-[#b45309]">
            <AlertTriangle className="size-3.5" />
            {t('بانتظار تأكيد صاحب المتجر')}
        </span>
    ) : (
        <span className="inline-flex items-center gap-1.5 rounded-[8px] bg-[#fef2f2] px-2.5 py-1 text-[12px] font-medium text-[#b91c1c]">
            <AlertTriangle className="size-3.5" />
            {t('غير مضبوط')}
        </span>
    );

    return (
        <Card className="mb-6 p-6">
            <div className="mb-1 flex flex-wrap items-center gap-3">
                <h3 className="flex items-center gap-2 font-bold text-[#111]">
                    <KeyRound className="size-4" />
                    {t('وسيلة استعادة الحساب')}
                </h3>
                {state}
            </div>

            <p className="mb-5 text-[13px] text-[#6b7280]">
                {t('تحقّق من صاحب المتجر أولًا، ثم اكتب بريده هنا. يصله رمز، وبتأكيده وحده يصير البريد موثّقًا — لا تستطيع توثيقه بيدك.')}
            </p>

            {data.verified_at && (
                <p className="mb-5 text-[12px] text-[#9ca3af]">
                    {t('وُثّق في')} {data.verified_at}
                </p>
            )}

            {!data.mail_ready && (
                <p className="mb-5 rounded-[12px] bg-[#fffbeb] px-4 py-3 text-[13px] text-[#b45309]">
                    {t('البريد غير مفعّل على الخادم — لا يمكن إرسال رمز التحقق الآن.')}
                </p>
            )}

            <Field label={t('بريد الاستعادة')} error={form.errors.recovery_email}>
                <Input
                    type="email"
                    dir="ltr"
                    value={form.data.recovery_email}
                    onChange={(e) => form.setData('recovery_email', e.target.value)}
                    placeholder="owner@gmail.com"
                />
            </Field>

            <div className="mt-5 flex flex-wrap justify-end gap-2">
                {data.email && (
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() =>
                            router.delete(route('super-admin.businesses.recovery.clear', businessId), {
                                preserveScroll: true,
                            })
                        }
                    >
                        <Trash2 />
                        {t('إزالة')}
                    </Button>
                )}

                {data.email && !data.verified && (
                    <Button
                        type="button"
                        variant="outline"
                        disabled={!data.mail_ready}
                        onClick={() =>
                            router.post(
                                route('super-admin.businesses.recovery.resend', businessId),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        <Send />
                        {t('إعادة إرسال الرمز')}
                    </Button>
                )}

                <Button
                    type="button"
                    loading={form.processing}
                    onClick={() =>
                        form.post(route('super-admin.businesses.recovery.set', businessId), {
                            preserveScroll: true,
                        })
                    }
                >
                    <Send />
                    {t('حفظ وإرسال رمز التحقق')}
                </Button>
            </div>
        </Card>
    );
}
