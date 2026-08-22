import { useForm, usePage } from '@inertiajs/react';
import { Save, Search } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import Toggle from '@/Components/Toggle';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface Props {
    settings: Record<string, string>;
    domain: string;
    siteEnabled: boolean;
    storeName: string | null;
}

/** حدود ما تعرضه محرّكات البحث قبل أن تقصّ */
const TITLE_MAX = 60;
const DESC_MAX = 160;

export default function Seo() {
    const { settings, domain, siteEnabled, storeName } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const form = useForm({
        seo_title: settings.seo_title ?? '',
        seo_description: settings.seo_description ?? '',
        seo_keywords: settings.seo_keywords ?? '',
        seo_index: settings.seo_index === '1',
        seo_ga_id: settings.seo_ga_id ?? '',
    });

    const title = form.data.seo_title || storeName || t('اسم متجرك');
    const description = form.data.seo_description || t('وصف مختصر لمتجرك يظهر تحت العنوان في نتائج البحث.');

    return (
        <AdminLayout title="تحسين محركات البحث">
            <PageHeader
                title="تحسين محركات البحث"
                subtitle={t('كيف يظهر متجرك لمن يبحث عنه')}
            />

            {/*
                إعداداتٌ لموقعٍ غير منشور لا أثر لها.
                يضبطها التاجر ويصبر أسابيع ينتظر ظهورًا لا يأتي — والسبب أنّ
                الموقع نفسه مغلق، وهو أمرٌ لا تقوله هذه الشاشة إلا هنا.
            */}
            {!siteEnabled && (
                <div className="mb-6 rounded-[12px] border border-[#fde68a] bg-[#fffbeb] px-4 py-3 text-[13px] text-[#b45309]">
                    {t('الموقع الإلكتروني غير منشور — هذه الإعدادات لا أثر لها حتى تنشره.')}{' '}
                    <SmartLink
                        routeName="admin.settings.index"
                        href={route('admin.settings.index', { section: 'domain' })}
                        className="underline"
                    >
                        {t('الذهاب إلى الموقع')}
                    </SmartLink>
                </div>
            )}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(route('admin.marketing.seo.save'), { preserveScroll: true });
                    }}
                >
                    <Card className="p-6">
                        <div className="space-y-4">
                            <Field
                                label="عنوان الصفحة"
                                hint={`${form.data.seo_title.length}/${TITLE_MAX}`}
                                error={form.errors.seo_title}
                            >
                                <Input
                                    value={form.data.seo_title}
                                    maxLength={TITLE_MAX}
                                    onChange={(e) => form.setData('seo_title', e.target.value)}
                                    placeholder={storeName ?? ''}
                                />
                            </Field>

                            <Field
                                label="وصف الصفحة"
                                hint={`${form.data.seo_description.length}/${DESC_MAX}`}
                                error={form.errors.seo_description}
                            >
                                <textarea
                                    rows={3}
                                    maxLength={DESC_MAX}
                                    value={form.data.seo_description}
                                    onChange={(e) => form.setData('seo_description', e.target.value)}
                                    className="w-full rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white px-3 py-2 text-sm transition-[border-color,box-shadow] focus:border-[#d1d5db] focus:shadow-[0_0_0_3px_rgba(0,0,0,0.05)] focus:outline-none"
                                />
                            </Field>

                            <Field
                                label="كلمات مفتاحية"
                                hint="افصل بينها بفواصل"
                                error={form.errors.seo_keywords}
                            >
                                <Input
                                    value={form.data.seo_keywords}
                                    onChange={(e) => form.setData('seo_keywords', e.target.value)}
                                    placeholder={t('عطور، هدايا، مسقط')}
                                />
                            </Field>

                            <Field
                                label="معرّف Google Analytics"
                                hint="اتركه فارغًا إن لم تستعمله"
                                error={form.errors.seo_ga_id}
                            >
                                <Input
                                    dir="ltr"
                                    value={form.data.seo_ga_id}
                                    onChange={(e) => form.setData('seo_ga_id', e.target.value)}
                                    placeholder="G-XXXXXXXXXX"
                                />
                            </Field>

                            <div className="border-t border-[var(--ui-border,#e8e8e8)] pt-2">
                                <Toggle
                                    label="السماح بالفهرسة"
                                    hint="بإطفائه تُطلَب محركات البحث ألّا تُدرج الموقع في نتائجها."
                                    on={form.data.seo_index}
                                    onChange={(v) => form.setData('seo_index', v)}
                                />
                            </div>
                        </div>

                        <div className="mt-5 flex justify-end">
                            <Button type="submit" loading={form.processing}>
                                <Save />
                                {t('حفظ')}
                            </Button>
                        </div>
                    </Card>
                </form>

                {/*
                    معاينةٌ بشكل نتيجة البحث.
                    العنوان والوصف يُكتبان في حقلين فارغين لا يقول شكلهما شيئًا،
                    فيُقصّان في النتيجة الفعلية ولا يُكتشف ذلك إلا بعد أسابيع.
                */}
                <Card className="h-fit p-6">
                    <p className="mb-4 flex items-center gap-1.5 text-[13px] font-medium text-[#374151]">
                        <Search className="size-4" />
                        {t('كما يظهر في نتائج البحث')}
                    </p>

                    <div className="rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-4">
                        <p dir="ltr" className="text-[12px] text-[#6b7280]">
                            {domain || 'mystore.om'}
                        </p>
                        <p className="mt-1 truncate text-[18px] text-[#1a0dab]">{title}</p>
                        <p className="mt-1 line-clamp-2 text-[13px] text-[#4d5156]">{description}</p>
                    </div>

                    <div className="mt-4 space-y-1 text-[12px]">
                        <p className={cn(form.data.seo_title.length > TITLE_MAX ? 'text-[#b91c1c]' : 'text-[#9ca3af]')}>
                            {t('العنوان يُقصّ بعد :n محرفًا', { n: TITLE_MAX })}
                        </p>
                        <p
                            className={cn(
                                form.data.seo_description.length > DESC_MAX ? 'text-[#b91c1c]' : 'text-[#9ca3af]',
                            )}
                        >
                            {t('الوصف يُقصّ بعد :n محرفًا', { n: DESC_MAX })}
                        </p>
                        {!form.data.seo_index && (
                            <p className="text-[#b45309]">{t('الفهرسة مطفأة — لن يظهر الموقع في النتائج.')}</p>
                        )}
                    </div>
                </Card>
            </div>
        </AdminLayout>
    );
}
