import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { ExternalLink, Save } from 'lucide-react';

import AdminLayout from '@/Layouts/AdminLayout';
import Field from '@/Components/Field';
import Toggle from '@/Components/Toggle';
import { PageActions, SettingsGroup, SettingsSection } from '@/Components/Settings';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import DomainChooser, {
    DomainPathStrip,
    NewDomainCard,
    type DomainPath,
    type DomainPricing,
} from '../Settings/partials/DomainChooser';
import ThemeHeader, { ThemeState, type ThemeShell } from './theme/Shell';

interface Props extends ThemeShell {
    path: DomainPath;
    pricing: DomainPricing;
    suggestion: string | null;
    storeOn: boolean;
    productCount: number;
}

/**
 * «العنوان والنشر» — أين يُفتح متجره، واسمُه عليه، وأمفتوحٌ هو.
 *
 * ═══ وثلاثةٌ في شاشةٍ واحدة لأنّها سؤالٌ واحد ═══
 *
 * الطريقُ والاسمُ والمفتاح. ومن فرّقها شاشتين نشر متجرًا بلا عنوان — وهي
 * حالٌ لا معنى لها: المفتاح مرفوع والصفحة لا تُفتح من أيّ رابط. و`saveStore`
 * ترفضها بكلمة، والأفضلُ ألّا تُتاح أصلًا.
 */
export default function ThemeDomain() {
    const { site, path, pricing, suggestion, storeOn, productCount } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const [choosing, setChoosing] = useState(path === '');
    const [busy, setBusy] = useState(false);

    const pickPath = (next: Exclude<DomainPath, ''>) => {
        setBusy(true);
        router.post(
            route('admin.marketing.domain.path'),
            { site_path: next },
            {
                preserveScroll: true,
                onSuccess: () => setChoosing(false),
                onFinish: () => setBusy(false),
            },
        );
    };

    const form = useForm({
        site_slug: site.slug ?? '',
        store_on: storeOn,
    });

    const save = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('admin.marketing.store.save'), { preserveScroll: true });
    };

    /** العنوان كما سيقرؤه الزبون — يُبنى بقاعدة الخادم نفسها */
    const slug = form.data.site_slug
        .toLowerCase()
        .replace(/[\s_]+/g, '-')
        .replace(/[^a-z0-9-]/g, '')
        .replace(/-{2,}/g, '-')
        .replace(/^-|-$/g, '');

    return (
        <AdminLayout title="الموقع الإلكتروني">
            <ThemeHeader
                site={site}
                current="admin.website.domain"
                subtitle={t('أين يُفتح متجرك، واسمُه على العنوان، ومفتاحُ نشره')}
            />

            {choosing ? (
                <DomainChooser
                    pricing={pricing}
                    domain={site.host}
                    current={path}
                    onPick={pickPath}
                    onCancel={path === '' ? undefined : () => setChoosing(false)}
                    busy={busy}
                />
            ) : (
                <>
                    <DomainPathStrip
                        path={path as Exclude<DomainPath, ''>}
                        pricing={pricing}
                        onChange={() => setChoosing(true)}
                    />

                    {path === 'new' && (
                        <NewDomainCard pricing={pricing} domain={site.host} onPick={pickPath} busy={busy} />
                    )}

                    <form onSubmit={save}>
                        <SettingsSection
                            title="عنوان متجرك"
                            description="الاسم الذي يُكتب قبل النطاق — يُوزَّع على زبائنك فيصعب تغييره بعدها."
                            status={<ThemeState published={site.published} />}
                            action={
                                site.url && (
                                    <Button variant="outline" size="sm" asChild>
                                        <a href={site.url} target="_blank" rel="noreferrer">
                                            <ExternalLink />
                                            {t('افتح متجري')}
                                        </a>
                                    </Button>
                                )
                            }
                            divided
                        >
                            <SettingsGroup title="الاسم على العنوان">
                                <Field
                                    label="عنوان متجرك"
                                    hint="حروفٌ إنجليزية صغيرة وأرقام وشرطة — يُكتب مرّةً ويصعب تغييره بعد أن يوزّعه زبائنك"
                                    error={form.errors.site_slug}
                                >
                                    <div className="flex items-center gap-2">
                                        <Input
                                            dir="ltr"
                                            value={form.data.site_slug}
                                            onChange={(e) => form.setData('site_slug', e.target.value)}
                                            placeholder={suggestion ?? 'my-store'}
                                            className="max-w-[16rem]"
                                            aria-label={t('عنوان متجرك')}
                                        />
                                        <span dir="ltr" className="shrink-0 text-[13px] text-[#9ca3af]">
                                            .{site.host}
                                        </span>
                                    </div>
                                </Field>

                                {slug && (
                                    <p dir="ltr" className="mt-2 text-[13px] font-medium text-[#111]">
                                        https://{slug}.{site.host}
                                    </p>
                                )}
                            </SettingsGroup>

                            <SettingsGroup title="النشر">
                                <Toggle
                                    on={form.data.store_on}
                                    onChange={(v) => form.setData('store_on', v)}
                                    label="نشر المتجر"
                                    hint="حتى يُنشر لا يفتحه أحد — والعنوان يردّ «غير موجود» لا صفحةً فارغة"
                                />

                                <p className="mt-3 rounded-[10px] bg-[#eff6ff] px-3 py-2 text-[12px] leading-relaxed text-[#1d4ed8]">
                                    {t('زبونك يفتح واجهتك على هذا العنوان — وهذا المفتاح يفتحها ويُغلقها.')}
                                </p>

                                {/*
                                    ولا يُنشر متجرٌ بلا بضاعة: صفحةٌ فارغة
                                    تُفقد الزبون ثقته، ولا يعود إليها بعد أن
                                    رآها خالية.
                                */}
                                {form.data.store_on && productCount === 0 && (
                                    <p
                                        data-testid="empty-shelf"
                                        className="mt-3 rounded-[10px] bg-[#fffbeb] px-3 py-2 text-[12px] text-[#b45309]"
                                    >
                                        {t('لا صنف معروضًا في متجرك — ستُفتح الصفحة خالية. اعرض صنفًا قبل نشرها.')}
                                    </p>
                                )}
                            </SettingsGroup>

                            <SettingsGroup>
                                <PageActions>
                                    <Button type="submit" loading={form.processing}>
                                        <Save />
                                        {t('حفظ')}
                                    </Button>
                                </PageActions>
                            </SettingsGroup>
                        </SettingsSection>
                    </form>
                </>
            )}
        </AdminLayout>
    );
}
