import { Search } from 'lucide-react';
import Field from '@/Components/Field';
import Toggle from '@/Components/Toggle';
import { SettingsGroup, SettingsSection } from '@/Components/Settings';
import { Input, Textarea } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { LengthNote, SerpPreview } from '../../search/SearchParts';
import type { ThemeSite } from '../Shell';
import type { ThemeForm } from './form';

/**
 * «الظهور في البحث» — ما يراه من يبحث عن متجره في غوغل قبل أن يفتحه.
 *
 * والمعاينةُ تُرى قبل أن تُحفظ: من كتب عنوانًا لا يعرف أطويلٌ هو أم قصير
 * حتى يراه مقصوصًا في نتيجة. والحدُّ إرشادٌ — ما تجاوزه يُحفظ ويُقصّ في
 * غوغل، ولا يُردّ حفظُه برسالة.
 */
export default function Seo({
    form,
    site,
    fallback,
    fallbackEn,
    limits,
}: {
    form: ThemeForm;
    site: ThemeSite;
    /** ما يُكتب في `<head>` حين لا يكتب شيئًا — يُحسب في الخادم لا هنا */
    fallback: { title: string; description: string };
    /** وما يُكتب في الصفحة الإنجليزيّة حين لا يكتب لها شيئًا */
    fallbackEn?: { title: string; description: string };
    /** ما يُعرض في نتيجة غوغل — إرشادٌ لا شرطُ حفظ، وأدناه ما يُعدّ قصيرًا */
    limits: { title: number; desc: number; titleMin?: number; descMin?: number };
}) {
    const t = useTranslate();

    /* وما سيُكتب فعلًا — الفراغُ يُبقي المحسوب، فالمعاينةُ تقول الحقّ */
    const title = form.data.store_seo_title.trim() || fallback.title;
    const desc = form.data.store_seo_desc.trim() || fallback.description;
    const titleEn = form.data.store_seo_title_en.trim() || (fallbackEn ?? fallback).title;
    const descEn = form.data.store_seo_desc_en.trim() || (fallbackEn ?? fallback).description;

    /** عدّادٌ يقول «كم بقي» وتنبيهٌ يقول «ناقص» أو «قصير» أو «طويل» — ولا يمنع */
    const note = (value: string, kind: 'title' | 'desc', testId: string) => (
        <LengthNote
            value={value}
            kind={kind}
            min={(kind === 'title' ? limits.titleMin : limits.descMin) ?? 0}
            max={kind === 'title' ? limits.title : limits.desc}
            testId={testId}
        />
    );

    return (
        <section id="seo" className="scroll-mt-24">
            <SettingsSection
                icon={Search}
                title="الظهور في البحث"
                description="العنوان والوصف اللذان يظهران في نتائج غوغل — اتركهما فارغين فيبقى ما يُحسب من اسمك ونبذتك."
                divided
            >
                {/* ولكلّ لغةٍ نتيجتُها — الإنجليزيّةُ على عنوانها `?lang=en` لا على العربيّ */}
                <SettingsGroup title="كما تظهر">
                    <div className="grid gap-3 lg:grid-cols-2">
                        <SerpPreview url={site.url} title={title} description={desc} />
                        <SerpPreview
                            url={site.url ? `${site.url}?lang=en` : null}
                            title={titleEn}
                            description={descEn}
                            lang="en"
                            testId="seo-preview-en"
                        />
                    </div>
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
                        {note(form.data.store_seo_title, 'title', 'count-title')}
                    </Field>

                    <Field
                        label="وصفُ متجرك في البحث"
                        hint="سطران يقنعان من يقرأهما بأن يضغط — لا وصفُ نشاطك للبنك."
                        error={form.errors.store_seo_desc}
                    >
                        <Textarea
                            rows={3}
                            value={form.data.store_seo_desc}
                            onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) =>
                                form.setData('store_seo_desc', e.target.value)
                            }
                            placeholder={fallback.description}
                            aria-label={t('وصفُ متجرك في البحث')}
                        />
                        {note(form.data.store_seo_desc, 'desc', 'count-desc')}
                    </Field>
                </SettingsGroup>

                {/*
                    وللصفحة الإنجليزيّة ما يُكتب لها — ولا يُعرض فيها ما كُتب للعربيّة.
                    وفارغُها يُحسب كما كان: اسمُ متجرك ونبذتُه.
                */}
                <SettingsGroup title="للصفحة الإنجليزيّة">
                    <Field
                        label="Search title — English"
                        hint="للصفحة الإنجليزيّة — واتركه فارغًا فيبقى ما يُحسب من اسمك"
                        error={form.errors.store_seo_title_en}
                    >
                        <Input
                            dir="ltr"
                            value={form.data.store_seo_title_en}
                            onChange={(e) => form.setData('store_seo_title_en', e.target.value)}
                            placeholder={fallbackEn?.title ?? fallback.title}
                            aria-label={t('Search title — English')}
                        />
                        {note(form.data.store_seo_title_en, 'title', 'count-title-en')}
                    </Field>

                    <Field
                        label="Search description — English"
                        hint="للصفحة الإنجليزيّة — واتركه فارغًا فيبقى ما يُحسب من نبذتك"
                        error={form.errors.store_seo_desc_en}
                    >
                        <Textarea
                            rows={3}
                            dir="ltr"
                            value={form.data.store_seo_desc_en}
                            onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) =>
                                form.setData('store_seo_desc_en', e.target.value)
                            }
                            placeholder={fallbackEn?.description ?? fallback.description}
                            aria-label={t('Search description — English')}
                        />
                        {note(form.data.store_seo_desc_en, 'desc', 'count-desc-en')}
                    </Field>
                </SettingsGroup>

                <SettingsGroup title="الفهرسة">
                    <Toggle
                        on={form.data.store_seo_index}
                        onChange={(v) => form.setData('store_seo_index', v)}
                        label="اظهر في نتائج البحث"
                        hint="أطفئه وأنت تجهّز متجرك — يبقى مفتوحًا لمن يملك رابطه، ولا يفهرسه غوغل"
                    />

                    {/*
                        وإطفاؤها ليس إغلاقًا — ولا يُترك يُخمَّن.

                        من ظنّها مفتاحَ نشرٍ أطفأها ليُغلق متجره، وهو مفتوحٌ لكلّ
                        من يملك الرابط. والمفتاحُ الذي يُغلق في «العنوان والنشر».
                    */}
                    {! form.data.store_seo_index && (
                        <p
                            data-testid="seo-not-closed"
                            className="mt-3 rounded-[10px] bg-[#fffbeb] px-3 py-2 text-[12px] leading-relaxed text-[#b45309]"
                        >
                            {t('هذا لا يُغلق متجرك — من يملك الرابط يفتحه. الإغلاق من «العنوان والنشر ‹ نشر المتجر».')}
                        </p>
                    )}
                </SettingsGroup>
            </SettingsSection>
        </section>
    );
}
