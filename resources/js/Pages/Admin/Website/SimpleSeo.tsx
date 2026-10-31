import { useForm, usePage } from '@inertiajs/react';
import { Check, Search, ShieldCheck } from 'lucide-react';
import Field from '@/Components/Field';
import PageHeader from '@/Components/PageHeader';
import { PageActions, SettingsGroup, SettingsPage, SettingsSection } from '@/Components/Settings';
import Toggle from '@/Components/Toggle';
import { Button } from '@/Components/ui/button';
import { Input, Textarea } from '@/Components/ui/input';
import AdminLayout from '@/Layouts/AdminLayout';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import { LengthNote, type Limits, PendingNotice, type Readiness, ReadinessList, SaveState, SerpPreview, useSaveFeedback } from './search/SearchParts';

interface Props {
    site: { name: string; url: string | null };
    seo: { title: string; desc: string; index: boolean };
    fallback: { title: string; description: string };
    limits: Limits;
    savedAt: string | null;
    pending: Partial<Record<'store_seo_title' | 'store_seo_desc', string>>;
    readiness: Readiness;
}

/**
 * الظهور في البحث لصفحة المتجر البسيطة — لمن نشر متجره ولم يبنِ موقعًا.
 *
 * كانت صفحتُه منشورةً يقرؤها غوغل بعنوانٍ ووصفٍ لا يملك إليهما بابًا: شاشةُ
 * البحث تردّه إلى معالج الإنشاء لأنّه لم يبنِ موقعًا. وهو يملك الموقع في
 * باقته — فاستُثني بلا قصد.
 *
 * والصفحةُ عربيّةٌ وحدها، فالحقولُ عربيّةٌ وحدها. وتُكتب في المفاتيح التي
 * يقرؤها رأسُها (`StoreSeo::simple`) وتسري بالحفظ كأخواتها (`StoreSeo::save`).
 */
export default function SimpleSeo() {
    const { site, seo, fallback, limits, savedAt, pending, readiness } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const form = useForm({
        store_seo_title: seo.title,
        store_seo_desc: seo.desc,
        store_seo_index: seo.index,
    });
    const feedback = useSaveFeedback();

    const save = () =>
        form.put(route('admin.website.seo.save'), {
            ...feedback.options,
            // والحالُ تبقى: رسالةُ «حُفظ» تُقرأ في الشاشة نفسِها، والمحفوظُ يصير أساسَ «غير محفوظ»
            preserveState: true,
            onSuccess: () => {
                form.setDefaults();
                feedback.options.onSuccess();
            },
        });

    const labels = { store_seo_title: t('عنوان متجرك في البحث'), store_seo_desc: t('وصفُ متجرك في البحث') };
    const fields = (Object.keys(labels) as (keyof typeof labels)[]).filter((k) => pending?.[k] !== undefined);

    return (
        <AdminLayout title="ظهور متجرك في البحث">
            {/* واسمُها غيرُ اسم شاشة الموقع المبنيّ — لكلّ شاشةٍ في اللوحة اسمٌ لا يلتبس (`EveryScreenOpensTest`) */}
            <PageHeader title="ظهور متجرك في البحث" subtitle={t('كيف يظهر متجرك في غوغل وحين تُشارك رابطه')} />

            <SettingsPage>
                <PendingNotice
                    rows={fields.map((k) => ({ label: labels[k], value: pending[k] ?? '' }))}
                    onUse={() => fields.forEach((k) => form.setData(k, pending[k] ?? ''))}
                />

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        save();
                    }}
                >
                    <SettingsSection
                        icon={Search}
                        title="الظهور في البحث"
                        description="العنوان والوصف اللذان يظهران في نتائج غوغل — اتركهما فارغين فيبقى ما يُحسب من اسمك ونبذتك."
                        divided
                    >
                        <SettingsGroup title="كما تظهر">
                            <SerpPreview
                                url={site.url}
                                title={form.data.store_seo_title.trim() || fallback.title}
                                description={form.data.store_seo_desc.trim() || fallback.description}
                            />
                        </SettingsGroup>

                        <SettingsGroup title="ما تكتبه">
                            <Field
                                label="عنوان متجرك في البحث"
                                hint="قل ما تبيعه وأين — لا اسمك وحده. وغوغل يقصّ ما بعد الحدّ."
                                error={form.errors.store_seo_title}
                            >
                                <Input
                                    value={form.data.store_seo_title}
                                    onChange={(e) => form.setData('store_seo_title', e.target.value)}
                                    placeholder={fallback.title}
                                    aria-label={t('عنوان متجرك في البحث')}
                                />
                                <LengthNote value={form.data.store_seo_title} kind="title" min={limits.titleMin} max={limits.title} testId="count-title" />
                            </Field>

                            <Field
                                label="وصفُ متجرك في البحث"
                                hint="سطران يقنعان من يقرأهما بأن يضغط — لا وصفُ نشاطك للبنك."
                                error={form.errors.store_seo_desc}
                            >
                                <Textarea
                                    rows={3}
                                    value={form.data.store_seo_desc}
                                    onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => form.setData('store_seo_desc', e.target.value)}
                                    placeholder={fallback.description}
                                    aria-label={t('وصفُ متجرك في البحث')}
                                />
                                <LengthNote value={form.data.store_seo_desc} kind="desc" min={limits.descMin} max={limits.desc} testId="count-desc" />
                            </Field>
                        </SettingsGroup>

                        <SettingsGroup title="الفهرسة">
                            <Toggle
                                on={form.data.store_seo_index}
                                onChange={(v) => form.setData('store_seo_index', v)}
                                label="اظهر في نتائج البحث"
                                hint="أطفئه وأنت تجهّز متجرك — يبقى مفتوحًا لمن يملك رابطه، ولا يفهرسه غوغل"
                            />
                            {! form.data.store_seo_index && (
                                <p data-testid="seo-not-closed" className="mt-3 rounded-[10px] bg-[#fffbeb] px-3 py-2 text-[12px] leading-relaxed text-[#b45309]">
                                    {t('هذا لا يُغلق متجرك — من يملك الرابط يفتحه. الإغلاق من «الإعدادات ‹ متجرك الإلكتروني ‹ نشر المتجر».')}
                                </p>
                            )}
                        </SettingsGroup>

                        <PageActions className="mt-5">
                            <Button type="submit" loading={form.processing} disabled={! form.isDirty}>
                                <Check />
                                {t('حفظ')}
                            </Button>
                        </PageActions>

                        <div className="mt-3">
                            <SaveState dirty={form.isDirty} savedAt={savedAt} justSaved={feedback.justSaved} failure={feedback.failure} />
                        </div>
                    </SettingsSection>
                </form>

                <SettingsSection icon={ShieldCheck} title="جاهزية الفهرسة" description="ما تقرؤه محرّكات البحث من متجرك كما حُفظ الآن.">
                    <ReadinessList readiness={readiness} />
                </SettingsSection>
            </SettingsPage>
        </AdminLayout>
    );
}
