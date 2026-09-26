import { useForm } from '@inertiajs/react';
import { CreditCard, Save } from 'lucide-react';

import Field from '@/Components/Field';
import Toggle from '@/Components/Toggle';
import { PageActions, SettingsSection } from '@/Components/Settings';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { PasswordInput } from '@/Components/ui/password-input';
import { useTranslate } from '@/lib/i18n';

export interface GatewayState {
    active: boolean;
    public_key: string;
    card_integration_id: string;
    has_secret: boolean;
    has_hmac: boolean;
    ready: boolean;
}

/**
 * «الدفع بالبطاقة» — بحساب صاحب المحلّ لا بحساب أبعاد.
 *
 * ═══ ونموذجٌ على حدة وحدَه في هذه الصفحة ═══
 *
 * سائرُ الأقسام تُحفظ بشريطٍ واحد. وهذا يبقى بزرّه لأنّ حقلين فيه سرّان:
 * لو جُمعا بنموذج المتجر لَعبَرا الشبكةَ مع كلّ حفظِ رسمِ توصيل بلا سبب،
 * ولَبقيا في ذاكرة الصفحة ما دامت مفتوحة.
 *
 * وهو مكتوبٌ في الشاشة لا مخمَّن: زرٌّ ثانٍ بلا سببٍ مقروء يُقرأ عطبًا.
 */
export default function Gateway({ gateway }: { gateway: GatewayState }) {
    const t = useTranslate();

    // وعنوانُ الإشعار من المتصفّح لا من إعدادٍ يُنسى تحديثُه عند تبديل النطاق
    const hookUrl = `${typeof window === 'undefined' ? '' : window.location.origin}/webhooks/paymob`;

    const form = useForm({
        active: gateway.active,
        public_key: gateway.public_key,
        card_integration_id: gateway.card_integration_id,
        secret_key: '',
        hmac_secret: '',
    });

    const save = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('admin.marketing.store.gateway'), {
            preserveScroll: true,
            // والسرّان لا يبقيان في الشاشة بعد أن حُفظا
            onSuccess: () => form.setData((d) => ({ ...d, secret_key: '', hmac_secret: '' })),
        });
    };

    return (
        <section id="gateway" className="scroll-mt-24">
            <form onSubmit={save}>
                <SettingsSection
                    icon={CreditCard}
                    title="الدفع بالبطاقة"
                    description="مفاتيحُك أنت من لوحة Paymob — والمال يصل حسابك البنكي مباشرةً ولا يمرّ بأبعاد."
                >
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <Field label="المفتاح العامّ" hint="pk_live_… — يظهر للزائر، وهذا موضعه" error={form.errors.public_key}>
                            <Input dir="ltr" value={form.data.public_key} onChange={(e) => form.setData('public_key', e.target.value)} aria-label={t('المفتاح العامّ')} />
                        </Field>
                        <Field label="رقم تكامل البطاقة" hint="Integration ID من لوحة Paymob" error={form.errors.card_integration_id}>
                            <Input dir="ltr" value={form.data.card_integration_id} onChange={(e) => form.setData('card_integration_id', e.target.value)} aria-label={t('رقم تكامل البطاقة')} />
                        </Field>
                        <Field
                            label="المفتاح السرّي"
                            hint={gateway.has_secret ? 'مضبوط — اكتبه من جديد لتبديله' : 'sk_live_…'}
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

                    <div className="mt-4">
                        <Toggle
                            on={form.data.active}
                            onChange={(v) => form.setData('active', v)}
                            label="اقبل الدفع بالبطاقة"
                        />
                        {form.errors.active && <p className="mt-2 text-[12px] text-[#b91c1c]">{form.errors.active}</p>}
                    </div>

                    {/* وعنوانُ الإشعار يُلصَق في لوحة Paymob — وبلا لصقه لا يصل طلبٌ أبدًا */}
                    <div className="mt-4">
                        <Field label="عنوان الإشعار (Callback URL)" hint="الصقه في لوحة Paymob — بلا هذا لا يصلك طلبٌ من دفعةٍ ناجحة">
                            <Input dir="ltr" readOnly value={hookUrl} aria-label={t('عنوان الإشعار (Callback URL)')} />
                        </Field>
                    </div>

                    <PageActions>
                        <Button type="submit" loading={form.processing}>
                            <Save />
                            {t('حفظ بوّابة الدفع')}
                        </Button>
                    </PageActions>
                </SettingsSection>
            </form>
        </section>
    );
}
