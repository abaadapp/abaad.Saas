import { useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, Check, CreditCard, Eye, Package, Settings } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import Gate from '@/Components/Gate';
import SectionTabs, { WEBSITE_TABS } from '@/Components/SectionTabs';
import SmartLink from '@/Components/SmartLink';
import Toggle from '@/Components/Toggle';
import { PageActions, SettingsGroup, SettingsPage, SettingsSection } from '@/Components/Settings';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { SiteShell } from './shell';

interface Props extends SiteShell {
    settings: { show_prices: boolean; allow_orders: boolean };
    sells: boolean;
    hasCatalogue: boolean;
    payments: { label: string; on: boolean }[];
    counts: { products: number; categories: number };
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
    const { settings, sells, hasCatalogue, payments, counts } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const form = useForm({
        show_prices: settings.show_prices,
        allow_orders: settings.allow_orders,
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.put(route('admin.website.shop.save'), { preserveScroll: true });
    };

    if (!hasCatalogue) {
        return (
            <AdminLayout title="المتجر">
                <PageHeader title="المتجر" subtitle={t('إعدادات ما يعرضه موقعك من منتجاتك')} />
                <SectionTabs tabs={WEBSITE_TABS} current="admin.website.shop" />

                <Gate
                    icon={Package}
                    title="موقعك تعريفيّ — لا يعرض منتجات"
                    description="لا إعدادات متجرٍ هنا لأنّ موقعك لا يعرض متجرًا. تبدّل ذلك من إعدادات الموقع متى شئت."
                />
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
                                    <Toggle
                                        on={form.data.allow_orders}
                                        label="السماح بالطلب من الموقع"
                                        hint="يظهر على كلّ منتج زرُّ طلبٍ يفتح محادثة واتساب باسمه وسعره"
                                        onChange={(v) => form.setData('allow_orders', v)}
                                    />
                                </div>
                            )}
                        </div>

                        {!form.data.show_prices && (
                            <p className="mt-4 flex items-center gap-2 rounded-[12px] bg-[#fffbeb] px-4 py-3 text-[13px] leading-6 text-[#b45309]">
                                <AlertTriangle className="size-4 shrink-0" />
                                {t('الطلب مغلقٌ ما دامت الأسعار مخفيّة — لا يُطلب ما لا يُعرف ثمنه.')}
                            </p>
                        )}

                        {!sells && (
                            <p className="mt-4 rounded-[12px] bg-[#f5f5f5] px-4 py-3 text-[13px] leading-6 text-[#6b7280]">
                                {t('موقعك يعرض منتجاتك ولا يستقبل طلبات — يتواصل معك الزبون على واتساب. تبدّل ذلك من إعدادات الموقع.')}
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
                                <li key={p.label} className="flex items-center justify-between gap-2 py-2.5 text-[13px] first:pt-0 last:pb-0">
                                    <span className="flex items-center gap-2 text-[#374151]">
                                        <CreditCard className="size-4 shrink-0 text-[#9ca3af]" />
                                        {p.label}
                                    </span>
                                    <Badge variant={p.on ? 'success' : 'neutral'}>
                                        {t(p.on ? 'مفعّل' : 'مطفأ')}
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
