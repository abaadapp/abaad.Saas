import { useForm, usePage } from '@inertiajs/react';
import { Apple, CreditCard, Info, Save, Webhook } from 'lucide-react';

import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import CopyButton from '@/Components/CopyButton';
import Field from '@/Components/Field';
import StatusPill from '@/Components/StatusPill';
import Toggle from '@/Components/Toggle';
import { PageActions, SettingsPage, SettingsSection } from '@/Components/Settings';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { PasswordInput } from '@/Components/ui/password-input';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

/**
 * ما يصل الشاشةَ عن ربط Paymob — `Store\PaymobSettings::view`.
 *
 * والسرّان حالُهما لا نصُّهما (`has_secret` و`has_hmac`): لا يخرجان من الخادم.
 */
export interface PaymobGateway {
    active: boolean;
    public_key: string;
    card_integration_id: string;
    apple_pay_integration_id: string;
    has_secret: boolean;
    has_hmac: boolean;
    state: 'off' | 'partial' | 'ready';
    card_ready: boolean;
    apple_pay: boolean;
    checkout: boolean;
    online: boolean;
    webhook: string;
}

const PILL = { off: 'idle', partial: 'progress', ready: 'ready' } as const;
const LABEL = { off: 'غير مربوط', partial: 'لم يكتمل', ready: 'مربوط' } as const;

/**
 * التطبيقات التكاملية ← Paymob — بيتُ مفاتيح الدفع الإلكترونيّ الوحيد.
 *
 * ═══ الربطُ غيرُ الاستعمال ═══
 *
 * تُفتح لكلّ متجر ولو لم يكن في موقعه سلّةٌ بعد. فتقول الشاشةُ الأمرين كلًّا
 * على حدة: «مربوط» عن الحساب، و«يُقبض على موقعك» أو «لا سلّة في موقعك» عن
 * الاستعمال — ولا تَعِد بدفعٍ لا يقع.
 *
 * ═══ و Apple Pay «مُضاف» لا «مفعّل» ═══
 *
 * رقمٌ محفوظٌ لا يقول إنّ Paymob فعّلت Apple Pay لهذا الحساب — وذلك لا
 * نعرفه من هنا ولا نملك تفعيله. وله أرقامٌ حيّةٌ فقط عند Paymob.
 */
export default function Paymob() {
    const { gateway } = usePage<PageProps<{ gateway: PaymobGateway }>>().props;
    const t = useTranslate();

    const form = useForm({
        active: gateway.active,
        public_key: gateway.public_key,
        card_integration_id: gateway.card_integration_id,
        apple_pay_integration_id: gateway.apple_pay_integration_id,
        secret_key: '',
        hmac_secret: '',
    });

    const save = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('admin.integrations.paymob.save'), {
            preserveScroll: true,
            // والسرّان لا يبقيان في الشاشة بعد أن حُفظا
            onSuccess: () => form.setData((d) => ({ ...d, secret_key: '', hmac_secret: '' })),
        });
    };

    return (
        <AdminLayout title="Paymob">
            <PageHeader
                title="Paymob"
                subtitle={t('مفاتيحك أنت من حساب Paymob الخاص بك، والمال يصل إلى حسابك ولا يمر عبر أبعاد.')}
                actions={<StatusPill state={PILL[gateway.state]} label={LABEL[gateway.state]} connected />}
            />

            <SettingsPage>
                {/* والاستعمالُ على الموقع سؤالٌ غيرُ الربط — يُقال وحده */}
                <div
                    className="mb-5 flex items-start gap-2.5 rounded-[12px] border border-[#e8e8e8] bg-[#fafafa] p-4 text-[13px] leading-relaxed text-[#374151]"
                    data-testid="paymob-usage"
                >
                    <Info className="mt-0.5 size-4 shrink-0 text-[#6b7280]" />
                    <p className="m-0">
                        {gateway.online
                            ? t('يدفع زبونُك على موقعك بالبطاقة الآن.')
                            : gateway.checkout
                              ? t('في موقعك سلّةٌ وإتمامُ طلب — يدفع زبونُك بالبطاقة حين يكتمل الربط ويُشغَّل.')
                              : t('لا سلّة في موقعك بعد — الربطُ يُحفظ هنا، والدفعُ الإلكترونيّ يعمل في المواقع ذات السلّة وإتمام الطلب.')}
                    </p>
                </div>

                <form onSubmit={save} className="space-y-6">
                    <SettingsSection
                        icon={CreditCard}
                        title="مفاتيح الحساب"
                        description="من لوحة Paymob ← Settings ← Developers (أو Payment Integrations). اختر الوضع (Live أو Test) قبل النسخ."
                    >
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <Field label="المفتاح العامّ" hint="Public Key — يظهر للزائر في رابط صفحة الدفع، وهذا موضعه" error={form.errors.public_key}>
                                <Input
                                    dir="ltr"
                                    value={form.data.public_key}
                                    onChange={(e) => form.setData('public_key', e.target.value)}
                                    aria-label={t('المفتاح العامّ')}
                                />
                            </Field>
                            <Field label="رقم تكامل البطاقة" hint="Card Integration ID من حسابك في Paymob" error={form.errors.card_integration_id}>
                                <Input
                                    dir="ltr"
                                    inputMode="numeric"
                                    value={form.data.card_integration_id}
                                    onChange={(e) => form.setData('card_integration_id', e.target.value)}
                                    aria-label={t('رقم تكامل البطاقة')}
                                />
                            </Field>
                            <Field
                                label="المفتاح السرّي"
                                hint={gateway.has_secret ? 'مضبوط — اكتبه من جديد لتبديله' : 'Secret Key'}
                                error={form.errors.secret_key}
                            >
                                {/* والعينُ المشتركة: حقلُ سرٍّ يُرسم بيده يفقد زرَّ الكشف */}
                                <PasswordInput
                                    dir="ltr"
                                    autoComplete="new-password"
                                    value={form.data.secret_key}
                                    onChange={(e) => form.setData('secret_key', e.target.value)}
                                    aria-label={t('المفتاح السرّي')}
                                />
                            </Field>
                            <Field
                                label="سرّ التوقيع"
                                hint={gateway.has_hmac ? 'مضبوط — اكتبه من جديد لتبديله' : 'HMAC Secret — به يُصدَّق إشعار الدفع'}
                                error={form.errors.hmac_secret}
                            >
                                <PasswordInput
                                    dir="ltr"
                                    autoComplete="new-password"
                                    value={form.data.hmac_secret}
                                    onChange={(e) => form.setData('hmac_secret', e.target.value)}
                                    aria-label={t('سرّ التوقيع')}
                                />
                            </Field>
                        </div>
                    </SettingsSection>

                    <SettingsSection
                        icon={Apple}
                        title="Apple Pay"
                        description="يجب أن يكون Apple Pay مفعّلًا أولًا في حساب Paymob الخاص بك."
                        status={
                            <StatusPill
                                state={gateway.apple_pay ? 'ready' : 'idle'}
                                label={gateway.apple_pay ? 'مُضاف' : 'غير مُضاف'}
                            />
                        }
                    >
                        <Field
                            label="رقم تكامل Apple Pay"
                            hint="اختياري — استخدم Live Integration ID الخاص بـ Apple Pay من حسابك في Paymob. Apple Pay لدى Paymob لا يملك Test Integration ID."
                            error={form.errors.apple_pay_integration_id}
                        >
                            <Input
                                dir="ltr"
                                inputMode="numeric"
                                value={form.data.apple_pay_integration_id}
                                onChange={(e) => form.setData('apple_pay_integration_id', e.target.value)}
                                aria-label={t('رقم تكامل Apple Pay')}
                            />
                        </Field>
                        <p className="mt-3 text-[12px] leading-relaxed text-[#b45309]" data-testid="paymob-test-warning">
                            {t('إن كانت مفاتيحك تجريبية (Test) فاترك هذا الحقل فارغًا — رقم Apple Pay حيّ فقط، وإرساله مع مفاتيح تجريبية يمنع فتح صفحة الدفع.')}
                        </p>
                    </SettingsSection>

                    <SettingsSection
                        icon={Webhook}
                        title="عنوان الإشعار"
                        description="الصقه في لوحة Paymob ← Payment Integrations (في الوضع Live) على تكامل البطاقة وعلى تكامل Apple Pay كليهما — بلا هذا لا يصلك طلبٌ من دفعةٍ ناجحة."
                    >
                        <div className="flex items-center gap-2">
                            <Input dir="ltr" readOnly value={gateway.webhook} aria-label={t('عنوان الإشعار (Callback URL)')} />
                            <CopyButton text={gateway.webhook} />
                        </div>
                    </SettingsSection>

                    <SettingsSection icon={CreditCard} title="التشغيل">
                        <Toggle
                            on={form.data.active}
                            onChange={(v) => form.setData('active', v)}
                            label="اقبل الدفع الإلكتروني عبر Paymob"
                        />
                        {form.errors.active && <p className="mt-2 text-[12px] text-[#b91c1c]">{form.errors.active}</p>}

                        <PageActions>
                            <Button type="submit" loading={form.processing}>
                                <Save />
                                {t('حفظ ربط Paymob')}
                            </Button>
                        </PageActions>
                    </SettingsSection>
                </form>
            </SettingsPage>
        </AdminLayout>
    );
}
