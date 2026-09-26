import { useState } from 'react';
import { router } from '@inertiajs/react';
import { ExternalLink, Globe } from 'lucide-react';

import Field from '@/Components/Field';
import Toggle from '@/Components/Toggle';
import { SettingsGroup, SettingsSection } from '@/Components/Settings';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import DomainChooser, {
    DomainPathStrip,
    NewDomainCard,
    type DomainPath,
    type DomainPricing,
} from '../../../Settings/partials/DomainChooser';
import { ThemeState, type ThemeSite } from '../Shell';
import type { ThemeForm } from './form';

/**
 * «العنوان والنشر» — أين يُفتح متجره، واسمُه عليه، وأمفتوحٌ هو.
 *
 * ثلاثةٌ في موضعٍ واحد لأنّها سؤالٌ واحد. ومن فرّقها نشر متجرًا بلا عنوان —
 * وهي حالٌ لا معنى لها: المفتاح مرفوع والصفحة لا تُفتح من أيّ رابط.
 *
 * وطريقُ العنوان يُبدَّل بحفظٍ مستقلّ (`marketing.domain.path`): هو قرارٌ
 * يُغيّر الفاتورة، فلا يُخبَّأ في شريط حفظٍ مع رسمِ توصيل.
 */
export default function Address({
    form,
    site,
    path,
    pricing,
    suggestion,
    productCount,
}: {
    form: ThemeForm;
    site: ThemeSite;
    path: DomainPath;
    pricing: DomainPricing;
    suggestion: string | null;
    productCount: number;
}) {
    const t = useTranslate();
    const [choosing, setChoosing] = useState(path === '');
    const [busy, setBusy] = useState(false);

    const pickPath = (next: Exclude<DomainPath, ''>) => {
        setBusy(true);
        router.post(
            route('admin.marketing.domain.path'),
            { site_path: next },
            { preserveScroll: true, onSuccess: () => setChoosing(false), onFinish: () => setBusy(false) },
        );
    };

    /** العنوان كما سيقرؤه الزبون — يُبنى بقاعدة الخادم نفسها */
    const slug = form.data.site_slug
        .toLowerCase()
        .replace(/[\s_]+/g, '-')
        .replace(/[^a-z0-9-]/g, '')
        .replace(/-{2,}/g, '-')
        .replace(/^-|-$/g, '');

    if (choosing) {
        return (
            <section id="address" className="scroll-mt-24">
                <DomainChooser
                    pricing={pricing}
                    domain={site.host}
                    current={path}
                    onPick={pickPath}
                    onCancel={path === '' ? undefined : () => setChoosing(false)}
                    busy={busy}
                />
            </section>
        );
    }

    return (
        <section id="address" className="scroll-mt-24 space-y-4">
            <DomainPathStrip
                path={path as Exclude<DomainPath, ''>}
                pricing={pricing}
                onChange={() => setChoosing(true)}
            />

            {path === 'new' && <NewDomainCard pricing={pricing} domain={site.host} onPick={pickPath} busy={busy} />}

            <SettingsSection
                icon={Globe}
                title="العنوان والنشر"
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
                        /*
                            و`w-fit` ليست زينة: الفقرةُ بلا عرضٍ تملأ الصفَّ،
                            و`dir="ltr"` يُلصق نصَّها بيسارها — فيقف العنوانُ
                            على بُعد ثلاثِ مئةٍ وسبعين بكسلًا من الحقل الذي
                            يصفه. والعرضُ بقدر النصّ يُعيده إلى بداية السطر.
                        */
                        <p dir="ltr" className="mt-2 w-fit text-[13px] font-medium text-[#111]">
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

                    {/*
                        ولا يُنشر متجرٌ بلا بضاعة: صفحةٌ فارغة تُفقد الزبون
                        ثقته، ولا يعود إليها بعد أن رآها خالية.
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
            </SettingsSection>
        </section>
    );
}
