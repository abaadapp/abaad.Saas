import { useForm, usePage } from '@inertiajs/react';

import { SettingsPage } from '@/Components/Settings';
import AdminLayout from '@/Layouts/AdminLayout';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import SaveBar from './theme/SaveBar';
import SeoSection from './theme/sections/Seo';
import { SCREEN_KEYS, only, seed, type ThemeSeed, type ThemeSettingsData } from './theme/sections/form';
import ThemeHeader, { type ThemeShell } from './theme/Shell';

interface Props extends ThemeShell, ThemeSeed {
    fallback: { title: string; description: string };
    limits: { title: number; desc: number };
}

/**
 * الظهور في البحث — عنوانُ المتجر في غوغل ووصفُه وإذنُ الفهرسة.
 *
 * ونصُّه يمرّ بالمسوّدة إن كان النظامُ مفتوحًا (`StoreContent::VERSIONED`
 * يحمل `store_seo_title` و`store_seo_desc`)، أمّا إذنُ الفهرسة فيسري
 * فورًا: مفتاحٌ يمنع غوغل لا يُؤجَّل إلى نشرة.
 */
export default function ThemeSeo() {
    const props = usePage<PageProps<Props>>().props;
    const { site, fallback, limits } = props;
    const t = useTranslate();

    const form = useForm<ThemeSettingsData>(seed(props));

    const save = () => {
        // النداءان منفصلان: `transform` تُثبِّت المحوّلَ على النموذج ولا تردّه
        form.transform((data) => only(data, SCREEN_KEYS.seo));
        form.post(route('admin.marketing.store.save'), { preserveScroll: true });
    };

    return (
        <AdminLayout title="الموقع الإلكتروني">
            <ThemeHeader
                site={site}
                current="admin.website.seo"
                subtitle={t('كيف يظهر متجرك في غوغل وحين تُشارك رابطه')}
            />

            <SettingsPage>
                <div className="space-y-6">
                    <SeoSection form={form} site={site} fallback={fallback} limits={limits} />

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
