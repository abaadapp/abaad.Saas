import { Search } from 'lucide-react';
import Field from '@/Components/Field';
import Toggle from '@/Components/Toggle';
import { SettingsGroup, SettingsSection } from '@/Components/Settings';
import { Input, Textarea } from '@/Components/ui/input';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
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
    limits,
}: {
    form: ThemeForm;
    site: ThemeSite;
    /** ما يُكتب في `<head>` حين لا يكتب شيئًا — يُحسب في الخادم لا هنا */
    fallback: { title: string; description: string };
    /** ما يُعرض في نتيجة غوغل — إرشادٌ لا شرطُ حفظ */
    limits: { title: number; desc: number };
}) {
    const t = useTranslate();

    /* وما سيُكتب فعلًا — الفراغُ يُبقي المحسوب، فالمعاينةُ تقول الحقّ */
    const title = form.data.store_seo_title.trim() || fallback.title;
    const desc = form.data.store_seo_desc.trim() || fallback.description;

    /** عدّادٌ يقول «كم بقي» — ويحمرّ بعد الحدّ ولا يمنع */
    const count = (value: string, max: number) => (
        <span className={value.length > max ? 'text-[#b91c1c]' : 'text-[#9ca3af]'}>
            {number(value.length)} / {number(max)}
        </span>
    );

    return (
        <section id="seo" className="scroll-mt-24">
            <SettingsSection
                icon={Search}
                title="الظهور في البحث"
                description="العنوان والوصف اللذان يظهران في نتائج غوغل — اتركهما فارغين فيبقى ما يُحسب من اسمك ونبذتك."
                divided
            >
                <SettingsGroup title="كما تظهر">
                    <div data-testid="seo-preview" className="rounded-[12px] border border-[#e5e7eb] bg-white p-4">
                        {site.url && (
                            <p dir="ltr" className="truncate text-[12px] text-[#4b5563]">
                                {site.url}
                            </p>
                        )}
                        <p className="mt-1 truncate text-[18px] leading-tight text-[#1a0dab]">{title}</p>
                        <p className="mt-1 line-clamp-2 text-[13px] leading-relaxed text-[#4d5156]">{desc}</p>
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
                        <p className="mt-1 text-[12px]" data-testid="count-title">
                            {count(form.data.store_seo_title, limits.title)}
                        </p>
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
                        <p className="mt-1 text-[12px]" data-testid="count-desc">
                            {count(form.data.store_seo_desc, limits.desc)}
                        </p>
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
