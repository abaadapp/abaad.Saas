import { useForm } from '@inertiajs/react';
import { Copy, KeyRound, Link2 } from 'lucide-react';
import { toast } from 'sonner';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { PasswordInput } from '@/Components/ui/password-input';
import { useTranslate } from '@/lib/i18n';

/** ما يقوله الخادم عن ربط التاجر بتطبيقه — انظر App\Support\WhatsAppOwnApp::view */
export interface OwnAppView {
    enabled: boolean;
    connection: {
        app_id: string | null;
        waba_id: string | null;
        phone_number_id: string | null;
        callback_url: string;
        /** لصاحب الحساب وحده — يكتبه في لوحة تطبيقه */
        verify_token: string | null;
        webhook_verified_at: string | null;
        last_webhook_at: string | null;
        app_webhook: string | null;
        billing: {
            state: string | null;
            owner: string | null;
            checked_at: string | null;
            /** متى راجع فريقُ أبعاد دليلَها — ولا يُرسل الربط قبله */
            reviewed_at?: string | null;
            /** ما يُرسله التاجرُ دليلًا — من ميتا نفسها */
            evidence?: string[];
            /** سببُ وقوف إرسالٍ رُوجع دليلُه ثمّ نقضته ميتا */
            stopped?: string | null;
        };
    } | null;
}

/*
 * خطواتُ الإعداد كما يمشيها التاجر في لوحات ميتا — بترتيبها.
 *
 * ونصٌّ لا رابطٌ وحده: لوحاتُ ميتا تتبدّل أسماءُ قوائمها، والتاجرُ يحتاج أن
 * يعرف ما يبحث عنه لا أين كان أمس.
 */
const GUIDE: { title: string; body: string }[] = [
    {
        title: 'جهّز حسابك عند Meta',
        body: 'حساب Meta Business باسم نشاطك (يُستحسن توثيقه — بلا توثيق تُرسل إلى 250 عميلًا في اليوم)، ورقم هاتف ليس على تطبيق واتساب في الهاتف، ووسيلة دفع في «الفوترة» بحسابك.',
    },
    {
        title: 'أنشئ تطبيقك',
        body: 'من developers.facebook.com أنشئ تطبيقًا من نوع Business باسم نشاطك، وأضف إليه منتج WhatsApp، واربطه بحساب أعمالك.',
    },
    {
        title: 'أضف رقمك',
        body: 'في WhatsApp Manager أضف رقم متجرك وتحقّق منه برسالة SMS أو مكالمة. أرقام التجربة لا تصلح لعملائك.',
    },
    {
        title: 'أنشئ رمزًا دائمًا',
        body: 'في إعدادات الأعمال ‹ مستخدمو النظام: أنشئ مستخدمًا، وامنحه تطبيقك وحساب واتساب، ثمّ «إنشاء رمز» بلا انتهاء، بصلاحيّتَي whatsapp_business_messaging وwhatsapp_business_management.',
    },
    {
        title: 'انسخ بيانات تطبيقك',
        body: 'من إعدادات التطبيق ‹ أساسيّ: معرّف التطبيق (App ID) وسرّ التطبيق (App Secret). ومن WhatsApp ‹ إعداد API: معرّف حساب واتساب (WABA ID) ومعرّف الرقم (Phone number ID).',
    },
    {
        title: 'الصقها هنا واضغط «تحقّق وحفظ»',
        body: 'نتحقّق أنّ الرمز صادرٌ من تطبيقك ودائم، وأنّ الرقم في حسابك، وأنّ حسابك ليس لأبعاد ولا مشارَكًا معها.',
    },
    {
        title: 'اضبط الإشعارات في تطبيقك',
        body: 'في لوحة تطبيقك ‹ WhatsApp ‹ Configuration: الصق عنوان الإشعارات ورمز التحقّق الظاهرين هنا، واشترك في حقل messages، وحوّل التطبيق إلى Live. ثمّ «فحص الربط».',
    },
    {
        title: 'أرسل دليل الفوترة',
        body: 'ميتا لا تُطلعنا على وسيلة الدفع، وإقرارك وحده لا يكفي. أرسل لفريق أبعاد فاتورة ميتا لهذا الحساب، أو صورة صفحة «الفوترة والمدفوعات» في حساب أعمالك تُظهر وسيلة دفعك عليه. لا تخرج رسالة قبل مراجعته.',
    },
    {
        title: 'جرّب',
        body: 'أرسل رسالة من هاتفك إلى رقم متجرك: تظهر في «محادثات واتساب»، وتقول البطاقة «يستقبل». ثمّ ردّ عليها لترى «وصلت» و«قُرئت».',
    },
];

function Copyable({ label, value, testid }: { label: string; value: string; testid: string }) {
    const t = useTranslate();

    return (
        <div className="space-y-1">
            <dt className="text-[12px] text-[#6b7280]">{t(label)}</dt>
            <dd className="flex items-center gap-2">
                <code dir="ltr" className="min-w-0 flex-1 truncate rounded-[8px] bg-[#f3f4f6] px-2 py-1 text-[12px] text-[#111]" data-testid={testid}>
                    {value}
                </code>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() => {
                        void navigator.clipboard?.writeText(value);
                        toast.success(t('نُسخ'));
                    }}
                >
                    <Copy />
                    {t('نسخ')}
                </Button>
            </dd>
        </div>
    );
}

/**
 * «اربط تطبيقك على Meta» — التاجر يملك تطبيقه وحسابه ورقمه ورمزه وفوترته.
 *
 * ولا يعود السرُّ ولا الرمز إلى الشاشة بعد الحفظ: حقلاهما فارغان دائمًا،
 * وتركُهما فارغين يُبقي المحفوظ لوصلةٍ قائمةٍ على الرقم نفسه.
 */
export default function OwnAppLink({ ownApp, mayManage }: { ownApp: OwnAppView; mayManage: boolean }) {
    const t = useTranslate();
    const linked = ownApp.connection;
    const form = useForm({
        app_id: linked?.app_id ?? '',
        app_secret: '',
        waba_id: linked?.waba_id ?? '',
        phone_number_id: linked?.phone_number_id ?? '',
        access_token: '',
        billing_ack: false,
    });

    const billing = linked?.billing.state;
    const billed = billing === 'confirmed';

    return (
        <div className="mt-4 space-y-4" data-testid="own-app">
            <details className="rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-4" open={! linked} data-testid="own-app-guide">
                <summary className="cursor-pointer text-[13px] font-medium text-[#111]">{t('خطوات ربط تطبيقك على Meta')}</summary>
                <ol className="mt-3 space-y-3">
                    {GUIDE.map((step, i) => (
                        <li key={step.title} className="flex gap-3 text-[12px] leading-relaxed">
                            <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-[#f5f3ff] font-medium text-[#6d28d9]">{i + 1}</span>
                            <span>
                                <span className="block font-medium text-[#111]">{t(step.title)}</span>
                                <span className="block text-[#6b7280]">{t(step.body)}</span>
                            </span>
                        </li>
                    ))}
                </ol>
                <p className="mt-3 text-[12px] leading-relaxed text-[#6b7280]">
                    {t('رسوم ميتا ووسيلة دفعها عليك مباشرة عند ميتا — أبعاد تتقاضى اشتراك البرنامج وحده. ورقمٌ على تطبيق واتساب في الهاتف لا يُربط بهذه الطريقة: التعايش لا تتيحه ميتا إلّا عبر شريكٍ تقنيّ.')}
                </p>
            </details>

            {linked && (
                <dl className="space-y-3 rounded-[12px] bg-[#fafafa] p-4" data-testid="own-app-facts">
                    <Copyable label="عنوان الإشعارات (Callback URL)" value={linked.callback_url} testid="own-app-callback" />
                    {linked.verify_token && <Copyable label="رمز التحقّق (Verify token)" value={linked.verify_token} testid="own-app-verify" />}
                    <div className="flex flex-wrap justify-between gap-3 text-[12px]" data-testid="own-app-webhook">
                        <dt className="text-[#6b7280]">{t('إشعارات تطبيقك')}</dt>
                        <dd className="font-medium text-[#111]">
                            {linked.last_webhook_at
                                ? t('تصلنا — آخرها :at', { at: linked.last_webhook_at })
                                : linked.webhook_verified_at
                                  ? t('طابقت ميتا العنوان — ننتظر أوّل إشعار')
                                  : t('لم تُضبط بعد في لوحة تطبيقك')}
                        </dd>
                    </div>
                    <div className="flex flex-wrap justify-between gap-3 text-[12px]" data-testid="own-app-billing" data-state={billing ?? ''}>
                        <dt className="text-[#6b7280]">{t('فوترة ميتا')}</dt>
                        <dd className={billed ? 'font-medium text-[#047857]' : 'font-medium text-[#b45309]'}>
                            {billed
                                ? `${t('راجع فريق أبعاد دليلها — على حسابك')}${linked.billing.owner ? ` — ${linked.billing.owner}` : ''}`
                                : t('بانتظار مراجعة دليلٍ من ميتا — لا تخرج رسالة قبلها')}
                        </dd>
                    </div>
                    {! billed && (
                        <div className="rounded-[10px] bg-[#fffbeb] p-3 text-[12px] leading-relaxed text-[#92400e]" data-testid="own-app-billing-evidence">
                            {linked.billing.stopped && <p className="mb-1 font-medium">{linked.billing.stopped}</p>}
                            <p>{t('ميتا لا تُطلعنا على وسيلة الدفع، وإقرارك وحده لا يكفي. أرسل لفريق أبعاد واحدًا من:')}</p>
                            <ul className="mt-1 list-disc ps-5">
                                {(linked.billing.evidence ?? []).map((e) => (
                                    <li key={e}>{e}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                </dl>
            )}

            {mayManage && (
                <form
                    className="space-y-4"
                    data-testid="own-app-form"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(route('admin.integrations.whatsapp.connect'), {
                            preserveScroll: true,
                            onSuccess: () => form.reset('app_secret', 'access_token'),
                        });
                    }}
                >
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="معرّف التطبيق (App ID)" error={form.errors.app_id}>
                            <Input dir="ltr" inputMode="numeric" value={form.data.app_id} onChange={(e) => form.setData('app_id', e.target.value.trim())} data-testid="own-app-id" />
                        </Field>
                        <Field label="سرّ التطبيق (App Secret)" hint={linked ? 'اتركه فارغًا لإبقاء المحفوظ' : 'يُخزَّن مشفَّرًا ولا يُعرض بعد الحفظ'} error={form.errors.app_secret}>
                            <PasswordInput dir="ltr" value={form.data.app_secret} onChange={(e) => form.setData('app_secret', e.target.value.trim())} data-testid="own-app-secret" />
                        </Field>
                        <Field label="معرّف حساب واتساب (WABA ID)" error={form.errors.waba_id}>
                            <Input dir="ltr" inputMode="numeric" value={form.data.waba_id} onChange={(e) => form.setData('waba_id', e.target.value.trim())} />
                        </Field>
                        <Field label="معرّف الرقم (Phone number ID)" error={form.errors.phone_number_id}>
                            <Input dir="ltr" inputMode="numeric" value={form.data.phone_number_id} onChange={(e) => form.setData('phone_number_id', e.target.value.trim())} />
                        </Field>
                    </div>
                    <Field label="رمز الوصول الدائم (System user token)" hint={linked ? 'اتركه فارغًا لإبقاء المحفوظ' : 'يُخزَّن مشفَّرًا ولا يُعرض بعد الحفظ'} error={form.errors.access_token}>
                        <PasswordInput dir="ltr" value={form.data.access_token} onChange={(e) => form.setData('access_token', e.target.value.trim())} data-testid="own-app-token" />
                    </Field>
                    <label className="flex items-start gap-2 text-[12px] leading-relaxed text-[#374151]">
                        <input
                            type="checkbox"
                            className="mt-1"
                            checked={form.data.billing_ack}
                            onChange={(e) => form.setData('billing_ack', e.target.checked)}
                            data-testid="own-app-billing-ack"
                        />
                        <span>{t('أُقرّ أنّ رسوم ميتا للرسائل والقوالب ووسيلة دفعها على حسابي أنا عند ميتا، لا على أبعاد.')}</span>
                    </label>
                    {form.errors.billing_ack && <p className="text-[12px] text-[#b91c1c]">{form.errors.billing_ack}</p>}
                    <Button type="submit" loading={form.processing} disabled={! form.data.billing_ack} data-testid="own-app-submit">
                        {linked ? <KeyRound /> : <Link2 />}
                        {t(linked ? 'تحديث بيانات الربط' : 'تحقّق وحفظ')}
                    </Button>
                </form>
            )}
        </div>
    );
}
