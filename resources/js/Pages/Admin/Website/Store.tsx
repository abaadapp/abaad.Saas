import { useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, Check, CreditCard, Eye, Globe, Minus, Package, Settings } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import Gate from '@/Components/Gate';
import SectionTabs, { WEBSITE_TABS } from '@/Components/SectionTabs';
import SmartLink from '@/Components/SmartLink';
import Toggle from '@/Components/Toggle';
import Field, { Select } from '@/Components/Field';
import { PageActions, SettingsGroup, SettingsPage, SettingsSection } from '@/Components/Settings';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { number } from '@/lib/format';
import { cn } from '@/lib/utils';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { SiteShell } from './shell';

interface Props extends SiteShell {
    settings: { show_prices: boolean; allow_orders: boolean };
    sells: boolean;
    hasCatalogue: boolean;
    /*
     * وسائلُ الدفع كما هي **على الموقع** لا كما هي على المنضدة.
     *
     * `pos` أنّ نقطة البيع تقبلها، و`online` أنّ الموقع يقبضها فعلًا —
     * وبينهما بوّابةُ دفعٍ مربوطة. انظر `App\Support\Website\Commerce`.
     */
    payments: { label: string; pos: boolean; online: boolean; note: string }[];
    channel: string;
    readiness: { key: string; label: string; ok: boolean; optional: boolean; detail: string }[];
    counts: { products: number; categories: number };
    goal: string;
    goals: { key: string; label: string; hint: string; icon: string }[];
    name: string;
}

/**
 * المتجر — ما يراه الزائر، وما يستطيع فعله.
 *
 * ولا يُعرض إعدادٌ لا يعني شيئًا لهذا الموقع: من اختار «تعريفيّ» لا يرى هذه
 * الشاشة إلا بسطرٍ يقول لماذا. وهذا هو الفرق بين إخفاءٍ في الشاشة وغيابٍ من
 * البنية — الوجهة تحكم لا مفتاحٌ مطفأ.
 *
 * والدفع يُقرأ ولا يُضبط هنا: مصدره «الضرائب والعملة والدفع». ومصدران
 * لإعدادٍ واحد يعنيان تاجرًا يُطفئ البطاقة في أحدهما وتبقى تعمل في الآخر.
 */
export default function Store() {
    const { settings, sells, hasCatalogue, payments, counts, goal, goals, name, channel, readiness } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const form = useForm({
        show_prices: settings.show_prices,
        allow_orders: settings.allow_orders,
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.put(route('admin.website.shop.save'), { preserveScroll: true });
    };

    /*
     * نوعُ الموقع — القرارُ الذي كان يُوعَد به ولا بابَ له.
     *
     * وهو أوّلُ قسمٍ لأنّه يحكم ما تحته: من يجعل موقعه تعريفيًّا لا معنى
     * لمقبضَي «إظهار الأسعار» و«السماح بالطلب» عنده أصلًا. ولأنّه يُبدّل ما
     * يعرضه الموقع، يُقال أثرُه قبل أن يُحفظ لا بعده.
     *
     * والاسمُ يُرسل معه كما هو: `saveSite` تحفظ الاثنين في نداءٍ واحد، وهذه
     * الشاشة لا تعرض الاسم — فيُعاد إليها ما جاء منها.
     */
    const type = useForm({ name, goal });

    const saveType = (e: React.FormEvent) => {
        e.preventDefault();
        type.put(route('admin.website.settings.save'), { preserveScroll: true });
    };

    const siteType = (
        <form onSubmit={saveType}>
            <SettingsSection
                title="نوع الموقع"
                description="يحدّد ما يُبنى في موقعك: متجرٌ يستقبل الطلبات، أو عرضٌ بلا طلب، أو تعريفٌ بلا منتجات."
                icon={Globe}
            >
                <Field
                    label="ماذا تريد من موقعك؟"
                    hint={goals.find((g) => g.key === type.data.goal)?.hint}
                    error={type.errors.goal}
                >
                    <Select
                        value={type.data.goal}
                        options={goals.map((g) => ({ value: g.key, label: g.label }))}
                        onChange={(e) => type.setData('goal', e.target.value)}
                    />
                </Field>

                {type.data.goal !== goal && (
                    <p className="mt-4 flex items-center gap-2 rounded-[12px] bg-[#fffbeb] px-4 py-3 text-[13px] leading-6 text-[#b45309]">
                        <AlertTriangle className="size-4 shrink-0" />
                        {t('صفحاتك ومحتواك تبقى كما هي — ويظهر من الأقسام ما يصلح للنوع الجديد وحده.')}
                    </p>
                )}

                <PageActions className="mt-5">
                    <Button type="submit" variant="outline" loading={type.processing}>
                        <Check />
                        {t('حفظ')}
                    </Button>
                </PageActions>
            </SettingsSection>
        </form>
    );

    if (!hasCatalogue) {
        return (
            <AdminLayout title="المتجر">
                <PageHeader title="المتجر" subtitle={t('إعدادات ما يعرضه موقعك من منتجاتك')} />
                <SectionTabs tabs={WEBSITE_TABS} current="admin.website.shop" />

                <SettingsPage>
                    {siteType}

                    <Gate
                        icon={Package}
                        title="موقعك تعريفيّ — لا يعرض منتجات"
                        description="لا إعدادات متجرٍ هنا لأنّ موقعك لا يعرض متجرًا. تبدّله من «نوع الموقع» أعلاه متى شئت."
                    />
                </SettingsPage>
            </AdminLayout>
        );
    }

    return (
        <AdminLayout title="المتجر">
            <PageHeader title="المتجر" subtitle={t('ما يعرضه موقعك من منتجاتك، وما يستطيع الزائر فعله')} />

            <SectionTabs tabs={WEBSITE_TABS} current="admin.website.shop" />

            {/*
                عمودٌ واحد: المقبضان أوّلًا، ثمّ ما يُقرأ ولا يُضبط هنا.

                وكان المقبضان في ثلثَي الشاشة وإلى جانبهما عمودُ «ما سيُعرض»
                و«طرق الدفع» — وهما بيانان يُقرآن لا إعدادان يُغيَّران، فيقفان
                بالقوّة البصريّة نفسها إلى جانب ما يُضبط فعلًا.
            */}
            <SettingsPage>
                {siteType}

                <form onSubmit={submit}>
                    <SettingsSection
                        title="ما يراه الزائر"
                        description="مقبضان يحكمان ما يعرضه موقعك وما يستطيع الزائر فعله."
                        icon={Eye}
                    >
                        <div className="divide-y divide-[var(--ui-border,#e8e8e8)]">
                            <div className="py-2 first:pt-0">
                                <Toggle
                                    on={form.data.show_prices}
                                    label="إظهار الأسعار"
                                    hint="بدونها يرى الزائر منتجاتك بلا أسعار ويسأل عنها"
                                    onChange={(v) =>
                                        form.setData((d) => ({
                                            ...d,
                                            show_prices: v,
                                            // سعرٌ مخفيّ لا يُطلب معه — والقاعدة في الخادم أيضًا
                                            allow_orders: v ? d.allow_orders : false,
                                        }))
                                    }
                                />
                            </div>

                            {sells && (
                                <div className="py-2 last:pb-0">
                                    {/*
                                        واسمُ المفتاح يقول ما يفعله.

                                        كان «السماح بالطلب من الموقع» — وهي
                                        جملةٌ تَعِد بسلّةٍ ودفعٍ وطلبٍ يصل
                                        لوحةَ المبيعات. ولا شيء من ذلك يقع:
                                        الزرُّ يفتح محادثةَ واتساب، والطلبُ
                                        يكتبه الزبون بيده ويقرؤه التاجر بيده.
                                    */}
                                    <Toggle
                                        on={form.data.allow_orders}
                                        label="السماح بالطلب عبر واتساب"
                                        hint="يظهر على كل منتج زرٌّ يفتح محادثة واتساب باسمه وسعره — لا سلّة ولا دفع إلكتروني"
                                        onChange={(v) => form.setData('allow_orders', v)}
                                    />
                                </div>
                            )}
                        </div>

                        {form.data.allow_orders && channel === 'none' && (
                            <p className="mt-4 flex items-center gap-2 rounded-[12px] bg-[#fffbeb] px-4 py-3 text-[13px] leading-6 text-[#b45309]">
                                <AlertTriangle className="size-4 shrink-0" />
                                {t('لا رقم واتساب في بيانات متجرك — فلا يظهر زرّ الطلب أصلًا.')}
                            </p>
                        )}

                        {!form.data.show_prices && (
                            <p className="mt-4 flex items-center gap-2 rounded-[12px] bg-[#fffbeb] px-4 py-3 text-[13px] leading-6 text-[#b45309]">
                                <AlertTriangle className="size-4 shrink-0" />
                                {t('الطلب مغلقٌ ما دامت الأسعار مخفيّة — لا يُطلب ما لا يُعرف ثمنه.')}
                            </p>
                        )}

                        {!sells && (
                            <p className="mt-4 rounded-[12px] bg-[#f5f5f5] px-4 py-3 text-[13px] leading-6 text-[#6b7280]">
                                {t('موقعك يعرض منتجاتك ولا يستقبل طلبات — يتواصل معك الزبون على واتساب. تبدّل ذلك من «نوع الموقع» أعلاه.')}
                            </p>
                        )}

                        <PageActions className="mt-5">
                            <Button type="submit" loading={form.processing}>
                                <Check />
                                {t('حفظ')}
                            </Button>
                        </PageActions>
                    </SettingsSection>
                </form>

                {/*
                    جاهزيةُ المتجر — حقائقُ تُقاس قبل النشر لا بعده.

                    صاحبُ المتجر يضغط «انشر» ثمّ يوزّع الرابط، وما يكتشفه بعد
                    ذلك يكتشفه من زبونٍ خذلَه: سعرٌ نسي إظهاره، أو رقمُ واتساب
                    لم يُكتب فلا زرَّ طلب، أو كلُّ صنفٍ نفد فالصفحةُ فارغة.
                    وكلُّها معلومةٌ عندنا قبل أن يضغط.

                    ولا تمنع النشر ولا تُسمّي شيئًا خطأً: «اختياريّ» يُقال
                    اختياريًّا — نطاقٌ خاصٌّ ليس نقصًا، ولأبعادَ عنوانٌ يعمل.
                */}
                <SettingsSection
                    title="جاهزية متجرك"
                    description="ما يعمل فعلًا اليوم — لا ما ينقصك أن تفعله."
                    icon={Check}
                >
                    <ul className="divide-y divide-[var(--ui-border,#e8e8e8)]">
                        {readiness.map((f) => (
                            <li key={f.key} className="flex items-start gap-3 py-2.5 text-[13px] first:pt-0 last:pb-0">
                                <span
                                    className={cn(
                                        'mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full',
                                        f.ok
                                            ? 'bg-[#dcfce7] text-[#15803d]'
                                            : f.optional
                                              ? 'bg-[#f3f4f6] text-[#9ca3af]'
                                              : 'bg-[#fef3c7] text-[#b45309]',
                                    )}
                                    aria-hidden
                                >
                                    {f.ok ? <Check className="size-3" /> : <Minus className="size-3" />}
                                </span>
                                <span className="min-w-0">
                                    <span className="flex flex-wrap items-center gap-1.5 font-medium text-[#111]">
                                        {f.label}
                                        {! f.ok && f.optional && (
                                            <Badge variant="neutral">{t('اختياري')}</Badge>
                                        )}
                                    </span>
                                    <span className="mt-0.5 block text-[12px] leading-6 text-[#6b7280]">
                                        {f.detail}
                                    </span>
                                </span>
                            </li>
                        ))}
                    </ul>
                </SettingsSection>

                {/*
                    ما يُقرأ ولا يُضبط هنا — ومصدرُ كلٍّ منهما مكتوبٌ تحته.

                    والدفع يُقرأ ولا يُضبط هنا: مصدره «الضرائب والعملة والدفع».
                    ومصدران لإعدادٍ واحد يعنيان تاجرًا يُطفئ البطاقة في أحدهما
                    وتبقى تعمل في الآخر.
                */}
                <SettingsSection title="ما يعرضه موقعك — ومن أين يُضبط" divided>
                    <SettingsGroup
                        title="ما سيُعرض"
                        description="يُقرأ من منتجاتك مباشرةً — ما تغيّره في المنتجات يظهر في موقعك."
                        action={
                            <Button variant="outline" size="sm" asChild>
                                <SmartLink routeName="admin.products.index" href={route('admin.products.index')}>
                                    <Package />
                                    {t('إدارة المنتجات')}
                                </SmartLink>
                            </Button>
                        }
                    >
                        <dl className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div className="flex items-center justify-between gap-2 rounded-[12px] bg-[#fafafa] px-4 py-3 text-[13px]">
                                <dt className="text-[#6b7280]">{t('منتجات مفعّلة')}</dt>
                                <dd className="font-medium tabular-nums text-[#111]">{number(counts.products)}</dd>
                            </div>
                            <div className="flex items-center justify-between gap-2 rounded-[12px] bg-[#fafafa] px-4 py-3 text-[13px]">
                                <dt className="text-[#6b7280]">{t('تصنيفات')}</dt>
                                <dd className="font-medium tabular-nums text-[#111]">{number(counts.categories)}</dd>
                            </div>
                        </dl>
                    </SettingsGroup>

                    <SettingsGroup
                        title="طرق الدفع"
                        description="تُضبط في إعدادات الدفع — والموقع يعرض ما فُعّل هناك."
                        action={
                            <Button variant="outline" size="sm" asChild>
                                <SmartLink
                                    routeName="admin.settings.index"
                                    href={route('admin.settings.index', { section: 'finance' })}
                                >
                                    <Settings />
                                    {t('إعدادات الدفع')}
                                </SmartLink>
                            </Button>
                        }
                    >
                        <ul className="divide-y divide-[var(--ui-border,#e8e8e8)]">
                            {payments.map((p) => (
                                <li key={p.label} className="flex flex-wrap items-center justify-between gap-2 py-2.5 text-[13px] first:pt-0 last:pb-0">
                                    <span className="min-w-0">
                                        <span className="flex items-center gap-2 text-[#374151]">
                                            <CreditCard className="size-4 shrink-0 text-[#9ca3af]" />
                                            {p.label}
                                        </span>
                                        <span className="mt-0.5 block ps-6 text-[12px] leading-6 text-[#9ca3af]">
                                            {p.note}
                                        </span>
                                    </span>
                                    <Badge variant={p.online ? 'success' : p.pos ? 'neutral' : 'warning'}>
                                        {t(p.online ? 'على الموقع' : p.pos ? 'نقطة البيع' : 'مطفأة')}
                                    </Badge>
                                </li>
                            ))}
                        </ul>
                    </SettingsGroup>
                </SettingsSection>
            </SettingsPage>

        </AdminLayout>
    );
}
