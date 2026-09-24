import Field from '@/Components/Field';
import Toggle from '@/Components/Toggle';
import { SettingsGroup, SettingsSection } from '@/Components/Settings';
import { Input, Textarea } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import type { ThemeForm } from './form';

const FULFILMENTS = [
    { value: 'delivery', label: 'توصيل' },
    { value: 'pickup', label: 'استلام من المحل' },
];

/**
 * «الدفع والاستلام» — ما يقبضه متجره من زبونه، وكيف يصله طلبه.
 *
 * وساعاتُ العمل ليست هنا: تُكتب في تذييل كلّ صفحة، فموضعُها صفُّ «التذييل»
 * في محرّر الصفحة. ومقبضان لشيءٍ واحد في موضعين يفترقان عند أوّل حفظ.
 */
export default function Checkout({ form, gatewayReady }: { form: ThemeForm; gatewayReady: boolean }) {
    const t = useTranslate();

    return (
        <section id="checkout" className="scroll-mt-24">
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
                    {! form.data.store_pay_cod && ! form.data.store_pay_transfer && ! gatewayReady && (
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
            </SettingsSection>
        </section>
    );
}
