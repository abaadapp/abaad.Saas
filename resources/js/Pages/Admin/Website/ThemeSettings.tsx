import { useMemo } from 'react';
import { useForm, usePage } from '@inertiajs/react';

import AdminLayout from '@/Layouts/AdminLayout';
import { SettingsPage } from '@/Components/Settings';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { DomainPath, DomainPricing } from '../Settings/partials/DomainChooser';
import ThemeHeader, { type ThemeShell } from './theme/Shell';
import SaveBar from './theme/SaveBar';
import SettingsRail, { type RailItem } from './theme/SettingsRail';
import Address from './theme/sections/Address';
import Checkout from './theme/sections/Checkout';
import Fields from './theme/sections/Fields';
import Gateway, { type GatewayState } from './theme/sections/Gateway';
import PagesSection, { type PageRow } from './theme/sections/Pages';
import Seo from './theme/sections/Seo';
import { NO_PAGES, type ThemeSettingsData } from './theme/sections/form';

interface Props extends ThemeShell {
    settings: Record<string, string>;
    fieldStates: Record<string, string>;
    fulfilments: string[];
    gateway: GatewayState;
    pages: { rows: PageRow[]; optional: string[]; allowed: string };
    domain: { path: DomainPath; pricing: DomainPricing; suggestion: string | null };
    seo: { title: string; desc: string; index: boolean };
    fallback: { title: string; description: string };
    limits: { title: number; desc: number };
    storeOn: boolean;
    productCount: number;
}

/**
 * ضبطُ متجر الواجهة الخاصّة — صفحةٌ واحدة بعمودٍ يقفز.
 *
 * ═══ ما كان ═══
 *
 * ستُّ شاشاتٍ بشريط تبويبات، وفوقها شاشةُ «عام» التي هي **قائمةٌ ثانية**:
 * سبعُ بطاقاتٍ تقود إلى التبويبات نفسِها. فمن أراد تغيير رسم التوصيل مرّ
 * بقائمتين وحمّل الصفحةَ مرّتين قبل أن يبلغ حقلًا واحدًا.
 *
 * وكان التوزيعُ غيرَ عادل: «الدومين» ثلاثةُ مقابض، و«الظهور في البحث»
 * ثلاثة، و«الصفحات» ثلاثة — و«المتجر والطلبات» **اثنان وثلاثون** ومعها
 * زرّا حفظٍ متجاوران لا يحفظ أحدُهما ما يحفظه الآخر.
 *
 * ═══ وما صار ═══
 *
 * صفحةٌ واحدة، أقسامُها سبعة، وعمودٌ لاصق يقفز إلى القسم بلا تحميل. وزرُّ
 * الحفظ واحدٌ يظهر عند أوّل تغيير — وهو صادقٌ لأنّ الأربعة كانت تكتب في
 * الباب نفسِه (`marketing.store.save`)، فالتفريقُ كان في الشاشة لا في
 * الحفظ.
 *
 * ولم تُنقل «التصميم»: تلك محرّرُ أقسامٍ بمعاينةٍ حيّة لا نموذجُ حقول،
 * وحشرُها في صفحةِ ضبطٍ يُفقدها المعاينةَ التي هي نصفُ عملها.
 *
 * ═══ وقائمةُ الجاهزية انتقلت إلى لوحة التشغيل ═══
 *
 * «أين متجري الآن» سؤالُ لوحةٍ لا سؤالُ نموذج. وكانت في شاشةٍ وسيطةٍ لا
 * تُضبط فيها شيءٌ — فحُذفت الشاشةُ وبقي ما فيها حيث يُقرأ.
 */
export default function ThemeSettings() {
    const {
        site, settings, fieldStates, fulfilments, gateway, pages, domain,
        seo, fallback, limits, storeOn, productCount, context,
    } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const form = useForm<ThemeSettingsData>({
        site_slug: site.slug ?? '',
        store_on: storeOn,

        store_pages: pages.allowed,
        store_about_image: settings.store_about_image ?? '',

        store_allow_orders: (settings.store_allow_orders ?? '1') === '1',
        store_pay_cod: (settings.store_pay_cod ?? '1') === '1',
        store_pay_transfer: (settings.store_pay_transfer ?? '0') === '1',
        store_bank: settings.store_bank ?? '',
        store_delivery_fee: settings.store_delivery_fee ?? '',
        store_free_delivery_over: settings.store_free_delivery_over ?? '',
        store_delivery_areas: settings.store_delivery_areas ?? '',
        store_delivery_slots: settings.store_delivery_slots ?? '',
        store_delivery_note: settings.store_delivery_note ?? '',
        store_max_days: settings.store_max_days ?? '',
        store_fulfil: fulfilments.join(','),

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
        store_image_note: settings.store_image_note ?? '',

        store_gift_card: (settings.store_gift_card ?? '0') === '1',
        store_gift_card_price: settings.store_gift_card_price ?? '',

        store_seo_title: seo.title,
        store_seo_desc: seo.desc,
        store_seo_index: seo.index,
    });

    const save = () => form.post(route('admin.marketing.store.save'), { preserveScroll: true });

    /**
     * ما في كلّ قسمٍ الآن — يُقرأ من العمود قبل القفز إليه.
     *
     * فمن يبحث عن رسم التوصيل يراه في العمود ولا يقفز أصلًا، ومن يريد أن
     * يعرف أمنشورٌ متجره يقرأها بلا أن يفتح قسمًا.
     */
    const rail: RailItem[] = useMemo(() => {
        const shownPages = form.data.store_pages === NO_PAGES
            ? 0
            : form.data.store_pages.split(',').filter(Boolean).length;

        const ways = [
            form.data.store_pay_cod && t('نقد'),
            form.data.store_pay_transfer && t('تحويل'),
            gateway.ready && t('بطاقة'),
        ].filter(Boolean) as string[];

        return [
            {
                id: 'address',
                label: 'العنوان والنشر',
                note: form.data.store_on ? t('منشور') : t('غير منشور'),
            },
            {
                id: 'pages',
                label: 'الصفحات',
                note: t(':n من :all', { n: number(shownPages + 2), all: number(pages.rows.length) }),
            },
            {
                id: 'checkout',
                label: 'الدفع والاستلام',
                note: ways.length ? ways.join(' · ') : t('لا طريقة دفع'),
            },
            {
                id: 'fields',
                label: 'حقول إتمام الطلب',
                note: null,
            },
            {
                id: 'gift',
                label: 'كرت الهدية',
                note: form.data.store_gift_card
                    ? m(Number(form.data.store_gift_card_price || 0.5))
                    : t('مطفأ'),
            },
            {
                id: 'gateway',
                label: 'الدفع بالبطاقة',
                note: gateway.ready ? t('مربوطة') : t('غير مربوطة'),
            },
            {
                id: 'seo',
                label: 'الظهور في البحث',
                note: form.data.store_seo_index ? t('يظهر في غوغل') : t('لا يُفهرس'),
            },
        ];
    }, [form.data, gateway.ready, pages.rows.length, t, m]);

    return (
        <AdminLayout title="الموقع الإلكتروني">
            <ThemeHeader
                site={site}
                current="admin.website.site"
                subtitle={t('ضبطُ متجرك كلُّه في صفحةٍ واحدة — وما تحفظه يصل زبونك في الحال')}
            />

            <SettingsPage width="full">
                <div className="flex flex-col gap-6 lg:flex-row">
                    {/* والعمودُ يُخفى على الجوّال: شاشةٌ ضيّقة تُمرَّر، ولا موضعَ فيها لعمودٍ لاصق */}
                    <aside className="hidden w-56 shrink-0 lg:block">
                        <SettingsRail items={rail} />
                    </aside>

                    <div className="min-w-0 flex-1 space-y-6">
                        <Address
                            form={form}
                            site={site}
                            path={domain.path}
                            pricing={domain.pricing}
                            suggestion={domain.suggestion}
                            productCount={productCount}
                        />

                        <PagesSection form={form} site={site} rows={pages.rows} optional={pages.optional} />

                        <Checkout form={form} gatewayReady={gateway.ready} />

                        <Fields form={form} />

                        <Gateway gateway={gateway} />

                        <Seo form={form} site={site} fallback={fallback} limits={limits} />

                        <SaveBar
                            dirty={form.isDirty}
                            processing={form.processing}
                            onSave={save}
                            onReset={() => form.reset()}
                        />
                    </div>
                </div>
            </SettingsPage>
        </AdminLayout>
    );
}
