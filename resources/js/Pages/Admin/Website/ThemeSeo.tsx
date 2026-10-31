import { useForm, usePage } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';

import { SettingsPage, SettingsSection } from '@/Components/Settings';
import AdminLayout from '@/Layouts/AdminLayout';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import { type Limits, PendingNotice, type Readiness, ReadinessList, SaveState, useSaveFeedback } from './search/SearchParts';
import SaveBar from './theme/SaveBar';
import SeoSection from './theme/sections/Seo';
import { SCREEN_KEYS, only, seed, type ThemeSeed, type ThemeSettingsData } from './theme/sections/form';
import ThemeHeader, { type ThemeShell } from './theme/Shell';

interface Props extends ThemeShell, ThemeSeed {
    fallback: { title: string; description: string };
    fallbackEn?: { title: string; description: string };
    limits: Limits;
    savedAt: string | null;
    /** ما حُفظ في المسوّدة قبل أن يسري البحثُ بالحفظ ولم يُنشر — انظر `StoreSeo::pending` */
    pending: Partial<Record<'store_seo_title' | 'store_seo_desc' | 'store_seo_title_en' | 'store_seo_desc_en', string>>;
    readiness: Readiness;
}

const PENDING_LABELS = {
    store_seo_title: 'عنوان متجرك في البحث',
    store_seo_desc: 'وصفُ متجرك في البحث',
    store_seo_title_en: 'Search title — English',
    store_seo_desc_en: 'Search description — English',
} as const;

/**
 * الظهور في البحث — عنوانُ المتجر في غوغل ووصفُه بلغتيه وإذنُ الفهرسة.
 *
 * ═══ ويسري كلُّه بالحفظ ═══
 *
 * كان النصُّ يمرّ بالمسوّدة وينتظر «نشر»، وإذنُ الفهرسة يسري فورًا — فزرُّ
 * حفظٍ واحد يُطبّق نصفَ الشاشة. فصار الخمسةُ يُكتبون حيّةً معًا من بابهم
 * (`admin.website.seo.save` ← `StoreSeo::save`)، ولا يُنشر بهم تصميمٌ ولا
 * صفحة، ولا يُعيدهم نشرُ مسوّدةٍ قديمة.
 */
export default function ThemeSeo() {
    const props = usePage<PageProps<Props>>().props;
    const { site, fallback, fallbackEn, limits, savedAt, pending, readiness } = props;
    const t = useTranslate();

    const form = useForm<ThemeSettingsData>(seed(props));
    const feedback = useSaveFeedback();

    const save = () => {
        // النداءان منفصلان: `transform` تُثبِّت المحوّلَ على النموذج ولا تردّه
        form.transform((data) => only(data, SCREEN_KEYS.seo));
        form.put(route('admin.website.seo.save'), {
            ...feedback.options,
            // والحالُ تبقى: رسالةُ «حُفظ» تُقرأ في الشاشة نفسِها، والمحفوظُ يصير أساسَ «غير محفوظ»
            preserveState: true,
            onSuccess: () => {
                form.setDefaults();
                feedback.options.onSuccess();
            },
        });
    };

    const pendingRows = (Object.keys(PENDING_LABELS) as (keyof typeof PENDING_LABELS)[])
        .filter((k) => pending?.[k] !== undefined)
        .map((k) => ({ label: t(PENDING_LABELS[k]), value: pending[k] ?? '' }));

    const applyPending = () => {
        for (const k of Object.keys(PENDING_LABELS) as (keyof typeof PENDING_LABELS)[]) {
            if (pending?.[k] !== undefined) form.setData(k, pending[k] ?? '');
        }
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
                    <PendingNotice rows={pendingRows} onUse={applyPending} />

                    <SeoSection form={form} site={site} fallback={fallback} fallbackEn={fallbackEn} limits={limits} />

                    <SaveState dirty={form.isDirty} savedAt={savedAt} justSaved={feedback.justSaved} failure={feedback.failure} />

                    {/* وما يقرؤه غوغل الآن — بما حُفظ، لا بما في الحقول قبل الحفظ */}
                    <SettingsSection
                        icon={ShieldCheck}
                        title="جاهزية الفهرسة"
                        description="ما تقرؤه محرّكات البحث من متجرك كما حُفظ الآن."
                    >
                        <ReadinessList readiness={readiness} />
                    </SettingsSection>

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
