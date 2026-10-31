import { useForm, usePage } from '@inertiajs/react';
import { Check, Globe, Search, ShieldCheck } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { WEBSITE_TABS } from '@/Components/SectionTabs';
import SmartLink from '@/Components/SmartLink';
import Toggle from '@/Components/Toggle';
import { PageActions, SettingsGroup, SettingsPage, SettingsSection } from '@/Components/Settings';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import Field from '@/Components/Field';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import { LengthNote, type Limits, PendingNotice, type Readiness, ReadinessList, SaveState, SerpPreview, useSaveFeedback } from './search/SearchParts';
import { type DomainState, domainHost, type SiteShell } from './shell';

interface Props extends SiteShell {
    /** ما يقرؤه غوغل الآن — انظر `SettingsController::seo` */
    seo: { title: string; description: string; image: string; index: boolean };
    limits: Limits;
    savedAt: string | null;
    /** ما حُفظ قبل أن يسري البحثُ بالحفظ ولم يُنشر — حقلًا حقلًا، والفهرسةُ «1» أو «0» */
    pending: Partial<Record<'title' | 'description' | 'image' | 'index', string>>;
    readiness: Readiness;
    pages: {
        id: number;
        title: string;
        slug: string;
        status: string;
        seo: { title: string; description: string; image: string };
    }[];
    domain: DomainState;
}

/**
 * الظهور في البحث — بلغةِ من يبيع لا بلغةِ من يبرمج.
 *
 * «عنوان موقعك في غوغل» لا «meta title»، و«صورة المشاركة» لا «og:image».
 * والكلمات المفتاحية ليست هنا: لا يقرؤها محرّك بحثٍ منذ سنين، وجعلُها محورَ
 * الشاشة يجعل التاجر ينفق وقته في أقلّ ما ينفع.
 *
 * والمعاينة هي الشرح: يرى سطرَه كما سيظهر في نتائج البحث، فيعرف أنّ عنوانه
 * طويلٌ قبل أن يُقصَّ هناك.
 *
 * ═══ ويسري بالحفظ ═══
 *
 * كان يُحفظ في الصفّ والزائرُ يقرأ آخرَ نشرة — فيقرأ صاحبُه «حُفظ» ويبقى
 * غوغل على القديم حتّى ينشر تصميمَه كلَّه. اليوم يُطبَّق بالحفظ وحده، ولا
 * يُعيده استرجاعُ نسخةٍ قديمة (`Published::forBusiness`، `Publisher::restore`).
 */
export default function Seo() {
    const { site, seo, pages, domain, limits, savedAt, pending, readiness } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const form = useForm({
        title: seo.title,
        description: seo.description,
        image: seo.image,
        index: seo.index,
    });
    const feedback = useSaveFeedback();

    const url = readiness.canonical ?? domainHost(domain);
    const missing = pages.filter((p) => p.status === 'published' && !p.seo.description);

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

    const labels = {
        title: t('عنوان موقعك في غوغل'),
        description: t('الوصف تحت العنوان'),
        image: t('صورة المشاركة'),
        index: t('السماح لمحركات البحث بإظهار موقعك'),
    };
    const fields = (Object.keys(labels) as (keyof typeof labels)[]).filter((k) => pending?.[k] !== undefined);
    const pendingRows = fields.map((k) => ({
        label: labels[k],
        value: k === 'index' ? (pending[k] === '1' ? t('مسموح') : t('مخفيّ عن محرّكات البحث')) : (pending[k] ?? ''),
    }));

    const applyPending = () => {
        for (const k of fields) {
            if (k === 'index') form.setData('index', pending.index === '1');
            else form.setData(k, pending[k] ?? '');
        }
    };

    return (
        <AdminLayout title="الظهور في البحث">
            <PageHeader
                title="الظهور في البحث"
                subtitle={t('كيف يظهر موقعك في غوغل وحين يُشارَك رابطه')}
            />

            <SectionTabs tabs={WEBSITE_TABS} current="admin.website.seo" />

            {/*
                عمودٌ واحد: ما يُكتب أوّلًا، ثمّ ما يُقرأ ويُحال إلى موضعه.

                وكان النموذج في ثلثين وإلى جانبه بطاقتا «نطاق موقعك» و«وصف
                الصفحات» — وهما تقريران لا حقلان، فيزاحمان ما يُكتب فعلًا.
            */}
            <SettingsPage>
                <PendingNotice rows={pendingRows} onUse={applyPending} />

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        save();
                    }}
                >
                    <SettingsSection
                        title="موقعك في نتائج البحث"
                        description="اكتب لأجل ما تراه في المعاينة — هكذا يظهر سطرُك في غوغل."
                        icon={Search}
                    >
                        {/* المعاينة قبل الحقول: يرى النتيجة ثمّ يعدّل لأجلها */}
                        <SerpPreview url={url} title={form.data.title.trim() || site.name} description={form.data.description.trim()} />

                        <div className="mt-5 space-y-4">
                            <Field
                                label="عنوان موقعك في غوغل"
                                hint="اسم متجرك وما تبيعه — «ورود مسقط · باقات وهدايا»"
                                error={form.errors.title}
                            >
                                <Input
                                    maxLength={70}
                                    value={form.data.title}
                                    onChange={(e) => form.setData('title', e.target.value)}
                                    placeholder={site.name}
                                />
                                <LengthNote value={form.data.title} kind="title" min={limits.titleMin} max={limits.title} testId="count-title" />
                            </Field>

                            <Field
                                label="الوصف تحت العنوان"
                                hint="سطران — وما زاد عن ١٧٠ حرفًا يُقصّ في غوغل"
                                error={form.errors.description}
                            >
                                <textarea
                                    rows={3}
                                    maxLength={170}
                                    value={form.data.description}
                                    onChange={(e) => form.setData('description', e.target.value)}
                                    className="w-full rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white px-3 py-2.5 text-sm leading-7 outline-none focus:border-[#111]"
                                />
                                <LengthNote value={form.data.description} kind="desc" min={limits.descMin} max={limits.desc} testId="count-desc" />
                            </Field>

                            <Field
                                label="صورة المشاركة"
                                hint="تظهر حين يُشارَك رابط موقعك في واتساب أو غيره"
                                error={form.errors.image}
                            >
                                <Input
                                    dir="ltr"
                                    value={form.data.image}
                                    onChange={(e) => form.setData('image', e.target.value)}
                                    placeholder="https://…"
                                />
                            </Field>
                        </div>

                        <div className="mt-5 border-t border-[var(--ui-border,#e8e8e8)] pt-4">
                            <Toggle
                                on={form.data.index}
                                label="السماح لمحركات البحث بإظهار موقعك"
                                hint={
                                    form.data.index
                                        ? 'موقعك يظهر في نتائج غوغل — وهذا ما تريده غالبًا'
                                        : 'موقعك مخفيّ عن غوغل — لن يجدك أحدٌ بالبحث'
                                }
                                onChange={(v) => form.setData('index', v)}
                            />
                        </div>

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

                {/* وما يقرؤه غوغل الآن — بما حُفظ، لا بما في الحقول قبل الحفظ */}
                <SettingsSection
                    icon={ShieldCheck}
                    title="جاهزية الفهرسة"
                    description="ما تقرؤه محرّكات البحث من موقعك كما حُفظ الآن."
                >
                    <ReadinessList readiness={readiness} />
                </SettingsSection>

                {/* ما يُقرأ ويُضبط في موضعٍ آخر — ومن أين يُضبط مكتوبٌ معه */}
                <SettingsSection title="ما يحكم ظهورك ويُضبط في موضعٍ آخر" divided>
                    <SettingsGroup
                        title="نطاق موقعك"
                        action={
                            <Button variant="outline" size="sm" asChild>
                                <SmartLink routeName="admin.website.domain" href={route('admin.website.domain')}>
                                    <Globe />
                                    {t('الدومين')}
                                </SmartLink>
                            </Button>
                        }
                    >
                        {/* والعنوانُ يُعرض ولو لم يربط التاجر نطاقًا: عنوان أبعاد يعمل */}
                        <p dir="ltr" className="text-start font-mono text-[13px] text-[#374151]">
                            {domainHost(domain) ?? t('بلا عنوان بعد')}
                        </p>

                        {domain.custom && domain.custom.status !== 'active' && (
                            <p className="mt-2 text-[12px] leading-6 text-[#b45309]">{domain.custom.label}</p>
                        )}
                    </SettingsGroup>

                    <SettingsGroup
                        title="وصف الصفحات"
                        action={
                            <Button variant="outline" size="sm" asChild>
                                <SmartLink routeName="admin.website.pages" href={route('admin.website.pages')}>
                                    {t('الصفحات')}
                                </SmartLink>
                            </Button>
                        }
                    >
                        {missing.length === 0 ? (
                            <p className="flex items-center gap-2 text-[13px] leading-6 text-[#15803d]">
                                <Check className="size-4 shrink-0" />
                                {t('كل صفحاتك المنشورة لها وصف — جيّد.')}
                            </p>
                        ) : (
                            <>
                                <p className="text-[13px] leading-6 text-[#6b7280]">
                                    {t('هذه الصفحات بلا وصفٍ في نتائج البحث — غوغل يختار سطرًا من محتواها بدلًا عنك.')}
                                </p>
                                <ul className="mt-3 divide-y divide-[var(--ui-border,#e8e8e8)]">
                                    {missing.map((p) => (
                                        <li
                                            key={p.id}
                                            className="flex items-center justify-between gap-2 py-2.5 text-[13px] first:pt-0 last:pb-0"
                                        >
                                            <span className="truncate text-[#374151]">{p.title}</span>
                                            <Badge variant="warning">{t('بلا وصف')}</Badge>
                                        </li>
                                    ))}
                                </ul>
                            </>
                        )}
                    </SettingsGroup>
                </SettingsSection>
            </SettingsPage>

        </AdminLayout>
    );
}
