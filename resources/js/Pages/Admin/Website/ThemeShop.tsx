import { useForm, usePage } from '@inertiajs/react';
import { Save } from 'lucide-react';

import AdminLayout from '@/Layouts/AdminLayout';
import Field, { Select } from '@/Components/Field';
import Toggle from '@/Components/Toggle';
import { PageActions, SettingsGroup, SettingsSection } from '@/Components/Settings';
import { Button } from '@/Components/ui/button';
import { Input, Textarea } from '@/Components/ui/input';
import { PasswordInput } from '@/Components/ui/password-input';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import ThemeHeader, { type ThemeShell } from './theme/Shell';

/** حقولُ إتمام الطلب — والاسمُ والهاتفُ ليسا منها: بهما يُعرف الزبون */
const CHECKOUT_FIELDS = [
    { key: 'area', label: 'المنطقة', hint: 'تُسأل عند التوصيل — ومن ضبط قائمة مناطق فالاختيار منها' },
    { key: 'address', label: 'العنوان بالتفصيل', hint: 'تُسأل عند التوصيل وحده' },
    { key: 'date', label: 'موعد التسليم', hint: 'أطفئه إن كنت تسلّم ما هو جاهزٌ الآن' },
    { key: 'slot', label: 'وقت التسليم', hint: 'لا يظهر إلا إن ضبطت فتراتٍ أعلاه' },
    { key: 'recipient', label: 'المستلِم', hint: 'اسمه ورقمه حين يكون الطلب هديةً لغير مشتريه' },
    { key: 'promo', label: 'كود الخصم', hint: 'أطفئه فلا يُطبَّق كوبونٌ من الموقع' },
] as const;

const FIELD_STATES = [
    { value: 'required', label: 'مطلوب' },
    { value: 'optional', label: 'اختياري' },
    { value: 'off', label: 'لا يُعرض' },
];

const FULFILMENTS = [
    { value: 'delivery', label: 'توصيل' },
    { value: 'pickup', label: 'استلام من المحل' },
];

interface Props extends ThemeShell {
    settings: Record<string, string>;
    fieldStates: Record<string, string>;
    fulfilments: string[];
    gateway: {
        active: boolean;
        public_key: string;
        card_integration_id: string;
        has_secret: boolean;
        has_hmac: boolean;
        ready: boolean;
    };
}

/**
 * «المتجر والطلبات» — كيف يدفع زبونه وكيف يستلم وما يُسأل عنه.
 *
 * ═══ ولمَ انتقلت من «الإعدادات» ═══
 *
 * كانت في بطاقةٍ واحدةٍ مع العنوان والنشر وصفحة المتجر: ثلاثون مقبضًا بلا
 * فاصلٍ بين ما يُضبط مرّةً وما يُراجَع كلَّ موسم. وسائرُ متاجر أبعاد لها
 * تبويبٌ بهذا الاسم — فصار له مثلُه على المسار نفسِه.
 *
 * ═══ ونموذجان لا واحد ═══
 *
 * البوّابةُ على حدة لأنّ سرَّيها يُرسلان وحدهما: لو خُلطا بنموذج المتجر
 * لَعبَرا الشبكةَ مع كلّ حفظِ رسمِ توصيل بلا سبب.
 */
export default function ThemeShop() {
    const { site, settings, fieldStates, fulfilments, gateway } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const form = useForm({
        store_allow_orders: (settings.store_allow_orders ?? '1') === '1',
        store_pay_cod: (settings.store_pay_cod ?? '1') === '1',
        store_pay_transfer: (settings.store_pay_transfer ?? '0') === '1',
        store_bank: settings.store_bank ?? '',
        store_delivery_fee: settings.store_delivery_fee ?? '',
        store_free_delivery_over: settings.store_free_delivery_over ?? '',
        store_delivery_areas: settings.store_delivery_areas ?? '',
        store_delivery_slots: settings.store_delivery_slots ?? '',
        store_delivery_note: settings.store_delivery_note ?? '',
        store_image_note: settings.store_image_note ?? '',
        /*
         * وحقولُ الطلب تبدأ بما يعمل به متجره الآن لا بفراغ.
         *
         * `CheckoutFields` تقرأ الفراغَ «ما كان» — فشاشةٌ تعرض فراغًا تقول
         * لصاحبها إنّ حقلًا مطفأٌ وهو يُسأل عنه في متجره.
         */
        store_field_area: fieldStates.area ?? 'optional',
        store_field_address: fieldStates.address ?? 'required',
        store_field_date: fieldStates.date ?? 'required',
        store_field_slot: fieldStates.slot ?? 'optional',
        store_field_recipient: fieldStates.recipient ?? 'optional',
        store_field_promo: fieldStates.promo ?? 'optional',
        store_fulfil: fulfilments.join(','),
        store_max_days: settings.store_max_days ?? '',
        store_gift_card: (settings.store_gift_card ?? '0') === '1',
        store_gift_card_price: settings.store_gift_card_price ?? '',
    });

    const save = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('admin.marketing.store.save'), { preserveScroll: true });
    };

    // وعنوانُ الإشعار من المتصفّح لا من إعدادٍ يُنسى تحديثُه عند تبديل النطاق
    const hookUrl = `${typeof window === 'undefined' ? '' : window.location.origin}/webhooks/paymob`;

    const gatewayForm = useForm({
        active: gateway.active,
        public_key: gateway.public_key,
        card_integration_id: gateway.card_integration_id,
        secret_key: '',
        hmac_secret: '',
    });

    const saveGateway = (e: React.FormEvent) => {
        e.preventDefault();
        gatewayForm.post(route('admin.marketing.store.gateway'), {
            preserveScroll: true,
            // والسرّان لا يبقيان في الشاشة بعد أن حُفظا
            onSuccess: () => gatewayForm.setData((d) => ({ ...d, secret_key: '', hmac_secret: '' })),
        });
    };

    return (
        <AdminLayout title="الموقع الإلكتروني">
            <ThemeHeader
                site={site}
                current="admin.website.shop"
                subtitle={t('كيف يدفع زبونك وكيف يستلم — وما يُسأل عنه قبل أن يؤكّد')}
            />

            <form onSubmit={save}>
                <SettingsSection
                    title="الدفع والاستلام"
                    description="ما يقبضه متجرك من زبونه، وكيف يصله طلبه."
                    divided
                >
                    <SettingsGroup title="قبول الطلبات">
                        <Toggle
                            on={form.data.store_allow_orders}
                            onChange={(v) => form.setData('store_allow_orders', v)}
                            label="اقبل الطلبات من المتجر"
                            hint="أطفئه فيبقى متجرك معرضًا يُتصفَّح بلا سلّة — والأسعار تبقى ظاهرة"
                        />
                    </SettingsGroup>

                    <SettingsGroup title="طرق الدفع">
                        <div className="space-y-3">
                            <Toggle
                                on={form.data.store_pay_cod}
                                onChange={(v) => form.setData('store_pay_cod', v)}
                                label="الدفع عند الاستلام"
                            />
                            <Toggle
                                on={form.data.store_pay_transfer}
                                onChange={(v) => form.setData('store_pay_transfer', v)}
                                label="تحويل بنكي"
                            />
                        </div>

                        {form.data.store_pay_transfer && (
                            <div className="mt-4">
                                <Field
                                    label="بيانات الحساب البنكي"
                                    hint="تظهر للزبون في صفحة إتمام الطلب — اسم البنك ورقم الحساب والآيبان"
                                    error={form.errors.store_bank}
                                >
                                    <Textarea
                                        rows={3}
                                        value={form.data.store_bank}
                                        onChange={(e) => form.setData('store_bank', e.target.value)}
                                        aria-label={t('بيانات الحساب البنكي')}
                                    />
                                </Field>
                            </div>
                        )}

                        {/* ولا طريقةَ دفعٍ يعني متجرًا لا يقبل طلبًا — يُقال قبل أن يُكتشف */}
                        {! form.data.store_pay_cod && ! form.data.store_pay_transfer && ! gateway.ready && (
                            <p className="mt-4 rounded-[10px] bg-[#fffbeb] px-3 py-2 text-[12px] leading-relaxed text-[#b45309]">
                                {t('لا طريقة دفعٍ مفتوحة — متجرك يُتصفَّح ولا يقبل طلبًا.')}
                            </p>
                        )}
                    </SettingsGroup>

                    <SettingsGroup title="التوصيل والاستلام">
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <Field label="رسوم التوصيل" hint="فارغًا يعني توصيلًا بلا رسم" error={form.errors.store_delivery_fee}>
                                <Input type="number" min={0} step="0.001" dir="ltr" value={form.data.store_delivery_fee} onChange={(e) => form.setData('store_delivery_fee', e.target.value)} aria-label={t('رسوم التوصيل')} />
                            </Field>
                            <Field label="توصيل مجاني فوق" hint="مبلغ الطلب الذي يسقط بعده الرسم — فارغًا يعني لا سقف" error={form.errors.store_free_delivery_over}>
                                <Input type="number" min={0} step="0.001" dir="ltr" value={form.data.store_free_delivery_over} onChange={(e) => form.setData('store_free_delivery_over', e.target.value)} aria-label={t('توصيل مجاني فوق')} />
                            </Field>
                        </div>
                        <div className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                            <Field label="مناطق التوصيل" hint="سطرٌ لكل منطقة — فارغًا يعني حقلًا حرًّا للزبون" error={form.errors.store_delivery_areas}>
                                <Textarea rows={3} value={form.data.store_delivery_areas} onChange={(e) => form.setData('store_delivery_areas', e.target.value)} aria-label={t('مناطق التوصيل')} />
                            </Field>
                            <Field label="أوقات التسليم" hint="سطرٌ لكل فترة، مثل: 9 ص – 12 م" error={form.errors.store_delivery_slots}>
                                <Textarea rows={3} value={form.data.store_delivery_slots} onChange={(e) => form.setData('store_delivery_slots', e.target.value)} aria-label={t('أوقات التسليم')} />
                            </Field>
                        </div>

                        {/*
                            وساعاتُ العمل ليست هنا: تُكتب في تذييل كلّ صفحة،
                            فموضعُها صفُّ «التذييل» في محرّر الصفحة. ومقبضان
                            لشيءٍ واحد في شاشتين يفترقان عند أوّل حفظ.
                        */}
                        <div className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                            <Field label="ملاحظة التوصيل" hint="سطرٌ تحت زرّ الإضافة إلى السلّة" error={form.errors.store_delivery_note}>
                                <Input value={form.data.store_delivery_note} onChange={(e) => form.setData('store_delivery_note', e.target.value)} aria-label={t('ملاحظة التوصيل')} />
                            </Field>
                            <Field
                                label="الحجز مقدّمًا (أيام)"
                                hint="أبعد موعدٍ يختاره الزبون — فارغًا يعني ٦٠ يومًا"
                                error={form.errors.store_max_days}
                            >
                                <Input type="number" min={0} max={365} dir="ltr" value={form.data.store_max_days} onChange={(e) => form.setData('store_max_days', e.target.value)} aria-label={t('الحجز مقدّمًا (أيام)')} />
                            </Field>
                        </div>

                        <div className="mt-4">
                            <Field
                                label="طرق الاستلام"
                                hint="واحدة على الأقل — وإلا لم يبقَ للزبون سبيلٌ لاستلام طلبه"
                                error={form.errors.store_fulfil}
                            >
                                <div className="flex flex-wrap gap-4 pt-1">
                                    {FULFILMENTS.map((o) => {
                                        const picked = form.data.store_fulfil.split(',').filter(Boolean);
                                        const on = picked.includes(o.value);

                                        return (
                                            <label key={o.value} className="flex cursor-pointer items-center gap-2 text-[13px]">
                                                <input
                                                    type="checkbox"
                                                    checked={on}
                                                    aria-label={t(o.label)}
                                                    onChange={() =>
                                                        form.setData(
                                                            'store_fulfil',
                                                            (on ? picked.filter((v) => v !== o.value) : [...picked, o.value]).join(','),
                                                        )
                                                    }
                                                    className="size-4 accent-[#111]"
                                                />
                                                {t(o.label)}
                                            </label>
                                        );
                                    })}
                                </div>
                            </Field>
                        </div>
                    </SettingsGroup>

                    {/*
                        ═══ ما يُسأل عنه الزبون في إتمام الطلب ═══

                        الشاشةُ والخادمُ يقرآن من `CheckoutFields`، فحقلٌ
                        أُخفي هنا لا يبقى مشترَطًا هناك.
                    */}
                    <SettingsGroup title="حقول إتمام الطلب">
                        <p className="mb-3 text-[12px] text-[#6b7280]">
                            {t('الاسم والهاتف وطريقة الاستلام تبقى دائمًا — بها يُعرف الزبون ويُسلَّم الطلب.')}
                        </p>

                        <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                            {CHECKOUT_FIELDS.map((f) => (
                                <Field
                                    key={f.key}
                                    label={f.label}
                                    hint={f.hint}
                                    error={form.errors[`store_field_${f.key}` as keyof typeof form.errors]}
                                >
                                    <Select
                                        value={form.data[`store_field_${f.key}` as 'store_field_area']}
                                        onChange={(e) => form.setData(`store_field_${f.key}` as 'store_field_area', e.target.value)}
                                        options={FIELD_STATES}
                                        aria-label={t(f.label)}
                                    />
                                </Field>
                            ))}
                        </div>

                        <div className="mt-4">
                            <Field
                                label="تنبيه الصورة"
                                hint="يظهر تحت صورة المنتج، وفوق زرّ الطلب، وفي صفحة التأكيد. اتركه فارغًا إن لم تحتجه."
                                error={form.errors.store_image_note}
                            >
                                <Input
                                    value={form.data.store_image_note}
                                    onChange={(e) => form.setData('store_image_note', e.target.value)}
                                    aria-label={t('تنبيه الصورة')}
                                    placeholder={t('كل باقة تُنسَّق يدويًّا من ورد اليوم. قد يختلف صنفٌ أو لون حسب المتوفر — ونستبدله بما يساويه أو أفضل، بنفس الشكل والألوان.')}
                                />
                            </Field>
                        </div>
                    </SettingsGroup>

                    {/*
                        كرتُ الهدية — صنفٌ يُباع لا خانةُ نصٍّ مجّانية.
                        يدخل الفاتورةَ بندًا، ويُعدّ في تقرير الأصناف.
                    */}
                    <SettingsGroup title="كرت الهدية">
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <Toggle
                                on={form.data.store_gift_card}
                                onChange={(v) => form.setData('store_gift_card', v)}
                                label="كرت الهدية"
                                hint="يختاره الزبون في إتمام الطلب — نصًّا يرتّبه وملفًّا يرفقه"
                            />
                            <Field label="سعر كرت الهدية" hint="فارغًا يعني ٠٫٥٠٠ — واكتب صفرًا إن أردته مجّانًا" error={form.errors.store_gift_card_price}>
                                <Input type="number" min={0} step="0.001" dir="ltr" value={form.data.store_gift_card_price} onChange={(e) => form.setData('store_gift_card_price', e.target.value)} aria-label={t('سعر كرت الهدية')} />
                            </Field>
                        </div>
                    </SettingsGroup>

                    <SettingsGroup>
                        <PageActions>
                            <Button type="submit" loading={form.processing}>
                                <Save />
                                {t('حفظ')}
                            </Button>
                        </PageActions>
                    </SettingsGroup>
                </SettingsSection>
            </form>

            {/*
                الدفعُ بالبطاقة — بحساب صاحب المحلّ لا بحساب أبعاد.
                ونموذجٌ على حدة: السرّان يُرسلان وحدهما.
            */}
            <form onSubmit={saveGateway} className="mt-6">
                <SettingsSection
                    title="الدفع بالبطاقة"
                    description="مفاتيحُك أنت من لوحة Paymob — والمال يصل حسابك البنكي مباشرةً ولا يمرّ بأبعاد."
                >
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <Field label="المفتاح العامّ" hint="pk_live_… — يظهر للزائر، وهذا موضعه" error={gatewayForm.errors.public_key}>
                            <Input dir="ltr" value={gatewayForm.data.public_key} onChange={(e) => gatewayForm.setData('public_key', e.target.value)} aria-label={t('المفتاح العامّ')} />
                        </Field>
                        <Field label="رقم تكامل البطاقة" hint="Integration ID من لوحة Paymob" error={gatewayForm.errors.card_integration_id}>
                            <Input dir="ltr" value={gatewayForm.data.card_integration_id} onChange={(e) => gatewayForm.setData('card_integration_id', e.target.value)} aria-label={t('رقم تكامل البطاقة')} />
                        </Field>
                        <Field
                            label="المفتاح السرّي"
                            hint={gateway.has_secret ? 'مضبوط — اكتبه من جديد لتبديله' : 'sk_live_…'}
                            error={gatewayForm.errors.secret_key}
                        >
                            {/* والعينُ المشتركة: حقلُ سرٍّ يُرسم بيده يفقد زرَّ الكشف */}
                            <PasswordInput
                                dir="ltr"
                                autoComplete="new-password"
                                value={gatewayForm.data.secret_key}
                                onChange={(e) => gatewayForm.setData('secret_key', e.target.value)}
                                aria-label={t('المفتاح السرّي')}
                            />
                        </Field>
                        <Field
                            label="سرّ التوقيع"
                            hint={gateway.has_hmac ? 'مضبوط — اكتبه من جديد لتبديله' : 'HMAC Secret — به يُصدَّق إشعار الدفع'}
                            error={gatewayForm.errors.hmac_secret}
                        >
                            <PasswordInput
                                dir="ltr"
                                autoComplete="new-password"
                                value={gatewayForm.data.hmac_secret}
                                onChange={(e) => gatewayForm.setData('hmac_secret', e.target.value)}
                                aria-label={t('سرّ التوقيع')}
                            />
                        </Field>
                    </div>

                    <div className="mt-4">
                        <Toggle
                            on={gatewayForm.data.active}
                            onChange={(v) => gatewayForm.setData('active', v)}
                            label="اقبل الدفع بالبطاقة"
                        />
                        {gatewayForm.errors.active && (
                            <p className="mt-2 text-[12px] text-[#b91c1c]">{gatewayForm.errors.active}</p>
                        )}
                    </div>

                    {/* وعنوانُ الإشعار يُلصَق في لوحة Paymob — وبلا لصقه لا يصل طلبٌ أبدًا */}
                    <div className="mt-4">
                        <Field label="عنوان الإشعار (Callback URL)" hint="الصقه في لوحة Paymob — بلا هذا لا يصلك طلبٌ من دفعةٍ ناجحة">
                            <Input dir="ltr" readOnly value={hookUrl} aria-label={t('عنوان الإشعار (Callback URL)')} />
                        </Field>
                    </div>

                    <PageActions>
                        <Button type="submit" loading={gatewayForm.processing}>
                            <Save />
                            {t('حفظ بوّابة الدفع')}
                        </Button>
                    </PageActions>
                </SettingsSection>
            </form>
        </AdminLayout>
    );
}
