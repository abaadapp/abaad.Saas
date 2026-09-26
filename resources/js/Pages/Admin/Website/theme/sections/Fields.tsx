import { ClipboardList, Gift } from 'lucide-react';
import Field, { Select } from '@/Components/Field';
import Toggle from '@/Components/Toggle';
import { SettingsGroup, SettingsSection } from '@/Components/Settings';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import type { ThemeForm } from './form';

/** حقولُ إتمام الطلب — والاسمُ والهاتفُ ليسا منها: بهما يُعرف الزبون */
const CHECKOUT_FIELDS = [
    { key: 'area', label: 'المنطقة', hint: 'تُسأل عند التوصيل — ومن ضبط قائمة مناطق فالاختيار منها' },
    { key: 'address', label: 'العنوان بالتفصيل', hint: 'تُسأل عند التوصيل وحده' },
    { key: 'date', label: 'موعد التسليم', hint: 'أطفئه إن كنت تسلّم ما هو جاهزٌ الآن' },
    { key: 'slot', label: 'وقت التسليم', hint: 'لا يظهر إلا إن ضبطت فتراتٍ في «الدفع والاستلام»' },
    { key: 'recipient', label: 'المستلِم', hint: 'اسمه ورقمه حين يكون الطلب هديةً لغير مشتريه' },
    { key: 'promo', label: 'كود الخصم', hint: 'أطفئه فلا يُطبَّق كوبونٌ من الموقع' },
] as const;

const FIELD_STATES = [
    { value: 'required', label: 'مطلوب' },
    { value: 'optional', label: 'اختياري' },
    { value: 'off', label: 'لا يُعرض' },
];

/**
 * «ما يُسأل عنه الزبون» و«كرت الهدية» — قسمان متجاوران لأنّ كليهما يقع في
 * شاشة إتمام الطلب نفسِها.
 *
 * والشاشةُ والخادمُ يقرآن من `CheckoutFields`، فحقلٌ أُخفي هنا لا يبقى
 * مشترَطًا هناك.
 */
export default function Fields({ form }: { form: ThemeForm }) {
    const t = useTranslate();

    return (
        <>
            <section id="fields" className="scroll-mt-24">
                <SettingsSection
                    icon={ClipboardList}
                    title="حقول إتمام الطلب"
                    description="ما يُسأل عنه الزبون قبل أن يؤكّد طلبه."
                    divided
                >
                    <SettingsGroup>
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
                    </SettingsGroup>

                    <SettingsGroup title="تنبيه الصورة">
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
                    </SettingsGroup>
                </SettingsSection>
            </section>

            {/*
                كرتُ الهدية — صنفٌ يُباع لا خانةُ نصٍّ مجّانية.
                يدخل الفاتورةَ بندًا، ويُعدّ في تقرير الأصناف.
            */}
            <section id="gift" className="scroll-mt-24">
                <SettingsSection icon={Gift} title="كرت الهدية" description="بطاقةٌ يضيفها الزبون إلى طلبه — تُباع بندًا في فاتورته.">
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
                </SettingsSection>
            </section>
        </>
    );
}
