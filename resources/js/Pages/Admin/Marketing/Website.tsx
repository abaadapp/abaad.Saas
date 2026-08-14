import { useForm, usePage } from '@inertiajs/react';
import { ExternalLink, Globe, Save } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import Toggle from '@/Components/Toggle';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Props {
    settings: Record<string, string>;
    store: { name: string | null; phone: string | null; address: string | null };
    /** كم منتجًا سيجده الزائر — رقمٌ يقول إن كان الموقع سيبدو فارغًا */
    published: number;
}

export default function Website() {
    const { settings, store, published } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const form = useForm({
        site_enabled: settings.site_enabled === '1',
        site_domain: settings.site_domain ?? '',
        site_tagline: settings.site_tagline ?? '',
        site_about: settings.site_about ?? '',
        site_whatsapp: settings.site_whatsapp ?? '',
        site_instagram: settings.site_instagram ?? '',
        site_show_prices: settings.site_show_prices === '1',
        site_allow_orders: settings.site_allow_orders === '1',
    });

    return (
        <AdminLayout title="الموقع الإلكتروني">
            <PageHeader
                title="الموقع الإلكتروني"
                subtitle={t('واجهة متجرك على الإنترنت — ما يراه من يبحث عنك')}
                actions={
                    form.data.site_enabled &&
                    form.data.site_domain && (
                        <Button variant="outline" asChild>
                            <a
                                href={`https://${form.data.site_domain}`}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <ExternalLink />
                                {t('فتح الموقع')}
                            </a>
                        </Button>
                    )
                }
            />

            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(route('admin.marketing.website.save'), { preserveScroll: true });
                }}
                className="max-w-3xl space-y-6"
            >
                <Card className="p-6">
                    <div className="mb-5">
                        <Toggle
                            label="نشر الموقع"
                            hint="حتى تُفعّله يبقى الموقع مغلقًا على الزوّار."
                            on={form.data.site_enabled}
                            onChange={(v) => form.setData('site_enabled', v)}
                        />
                    </div>

                    {/*
                        رقمٌ قبل النشر لا بعده: موقعٌ يُفتح على متجرٍ بلا منتجات
                        يبدو مهجورًا لأوّل زائر، وأوّل انطباعٍ لا يتكرّر.
                    */}
                    {form.data.site_enabled && published === 0 && (
                        <p className="mb-4 rounded-[12px] bg-[#fffbeb] px-4 py-3 text-[13px] text-[#b45309]">
                            {t('لا منتجات في متجرك بعد — الموقع سيُفتح على صفحةٍ فارغة.')}
                        </p>
                    )}

                    <div className="space-y-4">
                        <Field
                            label="النطاق"
                            hint="اكتب النطاق وحده بلا https:// — مثل: mystore.om"
                            error={form.errors.site_domain}
                        >
                            <Input
                                dir="ltr"
                                value={form.data.site_domain}
                                onChange={(e) => form.setData('site_domain', e.target.value)}
                                placeholder="mystore.om"
                            />
                        </Field>

                        <Field label="الجملة التعريفية" error={form.errors.site_tagline}>
                            <Input
                                value={form.data.site_tagline}
                                onChange={(e) => form.setData('site_tagline', e.target.value)}
                                placeholder={t('أجود المنتجات بأفضل الأسعار')}
                            />
                        </Field>

                        <Field label="نبذة عن المتجر" error={form.errors.site_about}>
                            <textarea
                                rows={4}
                                value={form.data.site_about}
                                onChange={(e) => form.setData('site_about', e.target.value)}
                                className="w-full rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white px-3 py-2 text-sm transition-[border-color,box-shadow] focus:border-[#d1d5db] focus:shadow-[0_0_0_3px_rgba(0,0,0,0.05)] focus:outline-none"
                            />
                        </Field>
                    </div>
                </Card>

                <Card className="p-6">
                    <h3 className="mb-5 font-bold text-[#111]">{t('التواصل')}</h3>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="واتساب" hint="بصيغة دولية بلا +" error={form.errors.site_whatsapp}>
                            <Input
                                dir="ltr"
                                value={form.data.site_whatsapp}
                                onChange={(e) => form.setData('site_whatsapp', e.target.value)}
                                placeholder="96890000000"
                            />
                        </Field>
                        <Field label="إنستغرام" error={form.errors.site_instagram}>
                            <Input
                                dir="ltr"
                                value={form.data.site_instagram}
                                onChange={(e) => form.setData('site_instagram', e.target.value)}
                                placeholder="mystore"
                            />
                        </Field>
                    </div>

                    {/* بيانات المتجر مصدرها الإعدادات — تُعرض هنا ولا تُكرَّر إدخالًا */}
                    <p className="mt-4 text-[12px] text-[#9ca3af]">
                        {t('الاسم والهاتف والعنوان تُقرأ من إعدادات المتجر')}: {store.name ?? '—'}
                        {store.phone ? ` · ${store.phone}` : ''}
                    </p>
                </Card>

                <Card className="p-6">
                    <h3 className="mb-5 font-bold text-[#111]">{t('ما يراه الزائر')}</h3>

                    <div className="divide-y divide-[var(--ui-border,#e8e8e8)]">
                        <Toggle
                            label="عرض الأسعار"
                            hint="بإطفائه يُعرض المنتج بلا سعر ويُطلب السعر بالتواصل."
                            on={form.data.site_show_prices}
                            onChange={(v) => form.setData('site_show_prices', v)}
                        />
                        <Toggle
                            label="قبول الطلبات"
                            hint="الطلبات الواردة من الموقع تدخل قائمة المبيعات كغيرها."
                            on={form.data.site_allow_orders}
                            onChange={(v) => form.setData('site_allow_orders', v)}
                        />
                    </div>
                </Card>

                <div className="flex items-center justify-between">
                    <p className="flex items-center gap-1.5 text-[12px] text-[#9ca3af]">
                        <Globe className="size-3.5" />
                        {t(':n منتجًا يظهر في الموقع', { n: number(published) })}
                    </p>
                    <Button type="submit" loading={form.processing}>
                        <Save />
                        {t('حفظ')}
                    </Button>
                </div>
            </form>
        </AdminLayout>
    );
}
