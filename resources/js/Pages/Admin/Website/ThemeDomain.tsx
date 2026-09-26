import { useForm, usePage } from '@inertiajs/react';

import { SettingsPage } from '@/Components/Settings';
import AdminLayout from '@/Layouts/AdminLayout';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { DomainPath, DomainPricing } from '../Settings/partials/DomainChooser';
import SaveBar from './theme/SaveBar';
import Address from './theme/sections/Address';
import { SCREEN_KEYS, only, seed, type ThemeSeed, type ThemeSettingsData } from './theme/sections/form';
import ThemeHeader, { type ThemeShell } from './theme/Shell';

interface Props extends ThemeShell, ThemeSeed {
    domain: { path: DomainPath; pricing: DomainPricing; suggestion: string | null };
    productCount: number;
}

/**
 * الدومين — عنوانُ متجر الواجهة الخاصّة ونشرُه.
 *
 * ═══ ولمَ العنوانُ والنشرُ في شاشةٍ واحدة ═══
 *
 * لأنّ الخادمَ يرفض فصلَهما: «لا يُنشر متجرٌ بلا عنوان» (انظر
 * `MarketingController::saveStore`). فمفتاحُ نشرٍ في شاشةٍ وعنوانٌ في
 * أخرى يعني تاجرًا يرفع المفتاحَ فيُردّ بخطأٍ عن حقلٍ لا يراه.
 *
 * ═══ وما تحفظه هذه الشاشةُ وحدَها ═══
 *
 * `site_slug` و`store_on` لا غير (`SCREEN_KEYS.domain`). وأخواتُها الخمسُ
 * تكتب في الباب نفسِه، فلولا القصرُ لَمحت كلُّ شاشةٍ ما ضبطته جارتُها.
 */
export default function ThemeDomain() {
    const props = usePage<PageProps<Props>>().props;
    const { site, domain, productCount } = props;
    const t = useTranslate();

    const form = useForm<ThemeSettingsData>(seed(props));

    const save = () => {
        // النداءان منفصلان: `transform` تُثبِّت المحوّلَ على النموذج ولا تردّه
        form.transform((data) => only(data, SCREEN_KEYS.domain));
        form.post(route('admin.marketing.store.save'), { preserveScroll: true });
    };

    return (
        <AdminLayout title="الموقع الإلكتروني">
            <ThemeHeader
                site={site}
                current="admin.website.domain"
                subtitle={t('عنوان متجرك على الإنترنت')}
            />

            <SettingsPage>
                <div className="space-y-6">
                    <Address
                        form={form}
                        site={site}
                        path={domain.path}
                        pricing={domain.pricing}
                        suggestion={domain.suggestion}
                        productCount={productCount}
                    />

                    <SaveBar
                        dirty={form.isDirty}
                        processing={form.processing}
                        onSave={save}
                        onReset={() => form.reset()}
                    />
                </div>
            </SettingsPage>
        </AdminLayout>
    );
}
