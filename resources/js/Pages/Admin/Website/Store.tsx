import { useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, Check, CreditCard, Package, Settings } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { WEBSITE_TABS } from '@/Components/SectionTabs';
import SmartLink from '@/Components/SmartLink';
import Toggle from '@/Components/Toggle';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
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

                <Card className="p-12 text-center">
                    <Package className="mx-auto mb-3 size-8 text-[#d1d5db]" />
                    <p className="font-medium text-[#374151]">{t('موقعك تعريفيّ — لا يعرض منتجات')}</p>
                    <p className="mx-auto mt-1 max-w-md text-[13px] leading-7 text-[#9ca3af]">
                        {t('لا إعدادات متجرٍ هنا لأنّ موقعك لا يعرض متجرًا. تبدّل ذلك من إعدادات الموقع متى شئت.')}
                    </p>
                </Card>
            </AdminLayout>
        );
    }

    return (
        <AdminLayout title="المتجر">
            <PageHeader title="المتجر" subtitle={t('ما يعرضه موقعك من منتجاتك، وما يستطيع الزائر فعله')} />

            <SectionTabs tabs={WEBSITE_TABS} current="admin.website.shop" />

            <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
                <form onSubmit={submit} className="space-y-4 lg:col-span-2">
                    <Card className="p-5">
                        <h3 className="font-bold text-[#111]">{t('ما يراه الزائر')}</h3>

                        <div className="mt-3 divide-y divide-[var(--ui-border,#e8e8e8)]">
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

                            {sells && (
                                <Toggle
                                    on={form.data.allow_orders}
                                    label="السماح بالطلب من الموقع"
                                    hint="يضيف الزائر إلى السلّة ويُتمّ الطلب"
                                    onChange={(v) => form.setData('allow_orders', v)}
                                />
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

                        <div className="mt-5 flex justify-end">
                            <Button type="submit" loading={form.processing}>
                                <Check />
                                {t('حفظ')}
                            </Button>
                        </div>
                    </Card>
                </form>

                <div className="space-y-4">
                    <Card className="p-5">
                        <h3 className="flex items-center gap-2 font-bold text-[#111]">
                            <Package className="size-4 text-[#9ca3af]" />
                            {t('ما سيُعرض')}
                        </h3>
                        <ul className="mt-3 space-y-2 text-[13px] text-[#374151]">
                            <li>
                                {number(counts.products)} {t('منتجًا مفعّلًا')}
                            </li>
                            <li>
                                {number(counts.categories)} {t('تصنيفًا')}
                            </li>
                        </ul>
                        <p className="mt-3 text-[12px] leading-6 text-[#9ca3af]">
                            {t('يُقرأ من منتجاتك مباشرةً — ما تغيّره في المنتجات يظهر في موقعك.')}
                        </p>
                        <Button variant="outline" size="sm" className="mt-3" asChild>
                            <SmartLink routeName="admin.products.index" href={route('admin.products.index')}>
                                {t('إدارة المنتجات')}
                            </SmartLink>
                        </Button>
                    </Card>

                    <Card className="p-5">
                        <h3 className="flex items-center gap-2 font-bold text-[#111]">
                            <CreditCard className="size-4 text-[#9ca3af]" />
                            {t('طرق الدفع')}
                        </h3>
                        <ul className="mt-3 space-y-2">
                            {payments.map((p) => (
                                <li key={p.label} className="flex items-center justify-between gap-2 text-[13px]">
                                    <span className="text-[#374151]">{p.label}</span>
                                    <Badge variant={p.on ? 'success' : 'neutral'}>
                                        {t(p.on ? 'مفعّل' : 'مطفأ')}
                                    </Badge>
                                </li>
                            ))}
                        </ul>
                        <p className="mt-3 text-[12px] leading-6 text-[#9ca3af]">
                            {t('تُضبط في إعدادات الدفع — والموقع يعرض ما فُعّل هناك.')}
                        </p>
                        <Button variant="outline" size="sm" className="mt-3" asChild>
                            <SmartLink
                                routeName="admin.settings.index"
                                href={route('admin.settings.index', { section: 'finance' })}
                            >
                                <Settings />
                                {t('إعدادات الدفع')}
                            </SmartLink>
                        </Button>
                    </Card>
                </div>
            </div>
        </AdminLayout>
    );
}
