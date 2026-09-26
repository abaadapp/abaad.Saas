import { useForm, usePage } from '@inertiajs/react';

import { SettingsPage } from '@/Components/Settings';
import AdminLayout from '@/Layouts/AdminLayout';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import SaveBar from './theme/SaveBar';
import PagesSection, { type PageRow } from './theme/sections/Pages';
import { SCREEN_KEYS, only, seed, type ThemeSeed, type ThemeSettingsData } from './theme/sections/form';
import ThemeHeader, { type ThemeShell } from './theme/Shell';

interface Props extends ThemeShell, ThemeSeed {
    pageRows: PageRow[];
    optional: string[];
}

/**
 * الصفحات — أربعٌ مكتوبةٌ في القالب، تُفتح وتُغلق ولا تُخلَق.
 *
 * وهي تفترق عن صفحات البانِي عن حقّ: تلك تُضاف وتُحذف ويُكتب لها عنوان،
 * وهذه أربعٌ يرسمها قالبُ الواجهة — «الرئيسية» و«المتجر» ثابتتان،
 * و«من نحن» و«تواصل معنا» يُؤذن بهما أو يُمنعان (`StoreNav::OPTIONAL`).
 *
 * فلا يُعرض زرُّ «صفحة جديدة»: زرٌّ لا يعمل أسوأ من غيابه.
 */
export default function ThemePages() {
    const props = usePage<PageProps<Props>>().props;
    const { site, pageRows, optional } = props;
    const t = useTranslate();

    const form = useForm<ThemeSettingsData>(seed(props));

    const save = () => {
        // النداءان منفصلان: `transform` تُثبِّت المحوّلَ على النموذج ولا تردّه
        form.transform((data) => only(data, SCREEN_KEYS.pages));
        form.post(route('admin.marketing.store.save'), { preserveScroll: true });
    };

    return (
        <AdminLayout title="الموقع الإلكتروني">
            <ThemeHeader
                site={site}
                current="admin.website.pages"
                subtitle={t('صفحاتُ متجرك — ما يُفتح منها، وأين يُكتب ما فيها')}
            />

            <SettingsPage>
                <div className="space-y-6">
                    <PagesSection form={form} site={site} rows={pageRows} optional={optional} />

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
