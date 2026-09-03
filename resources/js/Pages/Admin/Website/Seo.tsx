import { useForm, usePage } from '@inertiajs/react';
import { Check, Globe, Search } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { WEBSITE_TABS } from '@/Components/SectionTabs';
import SmartLink from '@/Components/SmartLink';
import Toggle from '@/Components/Toggle';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import Field from '@/Components/Field';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { SiteShell } from './shell';

interface Props extends SiteShell {
    seo: { title: string; description: string; image: string; index: boolean };
    pages: {
        id: number;
        title: string;
        slug: string;
        status: string;
        seo: { title: string; description: string; image: string };
    }[];
    domain: { mode: string; domain: string; subdomain: string | null };
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
 */
export default function Seo() {
    const { site, seo, pages, domain } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const form = useForm({
        title: seo.title,
        description: seo.description,
        image: seo.image,
        index: seo.index,
    });

    const host = domain.domain || domain.subdomain || 'example.om';
    const missing = pages.filter((p) => p.status === 'published' && !p.seo.description);

    return (
        <AdminLayout title="الظهور في البحث">
            <PageHeader
                title="الظهور في البحث"
                subtitle={t('كيف يظهر موقعك في غوغل وحين يُشارَك رابطه')}
            />

            <SectionTabs tabs={WEBSITE_TABS} current="admin.website.seo" />

            <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.put(route('admin.website.seo.save'), { preserveScroll: true });
                    }}
                    className="space-y-4 lg:col-span-2"
                >
                    <Card className="space-y-4 p-5">
                        <h3 className="font-bold text-[#111]">{t('موقعك في نتائج البحث')}</h3>

                        {/* المعاينة قبل الحقول: يرى النتيجة ثمّ يعدّل لأجلها */}
                        <div className="rounded-[12px] border border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] p-4">
                            <p className="truncate text-[12px] text-[#15803d]" dir="ltr">
                                {host}
                            </p>
                            <p className="mt-1 truncate text-[17px] text-[#1a0dab]">
                                {form.data.title || site.name}
                            </p>
                            <p className="mt-1 line-clamp-2 text-[13px] leading-6 text-[#4d5156]">
                                {form.data.description || t('لا وصف بعد — اكتب سطرين يقنعان من يقرؤهما بأن يضغط.')}
                            </p>
                        </div>

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

                        <div className="border-t border-[var(--ui-border,#e8e8e8)] pt-2">
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

                        <div className="flex justify-end">
                            <Button type="submit" loading={form.processing}>
                                <Check />
                                {t('حفظ')}
                            </Button>
                        </div>
                    </Card>
                </form>

                <div className="space-y-4">
                    <Card className="p-5">
                        <h3 className="flex items-center gap-2 font-bold text-[#111]">
                            <Globe className="size-4 text-[#9ca3af]" />
                            {t('نطاق موقعك')}
                        </h3>

                        {domain.domain ? (
                            <p dir="ltr" className="mt-3 font-mono text-[13px] text-[#374151]">
                                {domain.domain}
                            </p>
                        ) : (
                            <p className="mt-3 text-[13px] leading-6 text-[#6b7280]">
                                {t('لا نطاق لموقعك بعد — بدونه لا يصل إليه أحد.')}
                            </p>
                        )}

                        <Button variant="outline" size="sm" className="mt-3" asChild>
                            <SmartLink
                                routeName="admin.settings.index"
                                href={route('admin.settings.index', { section: 'domain' })}
                            >
                                {t(domain.domain ? 'إعدادات النطاق' : 'اضبط النطاق')}
                            </SmartLink>
                        </Button>
                    </Card>

                    <Card className="p-5">
                        <h3 className="flex items-center gap-2 font-bold text-[#111]">
                            <Search className="size-4 text-[#9ca3af]" />
                            {t('وصف الصفحات')}
                        </h3>

                        {missing.length === 0 ? (
                            <p className="mt-3 text-[13px] leading-6 text-[#15803d]">
                                {t('كل صفحاتك المنشورة لها وصف — جيّد.')}
                            </p>
                        ) : (
                            <>
                                <p className="mt-3 text-[13px] leading-6 text-[#6b7280]">
                                    {t('هذه الصفحات بلا وصفٍ في نتائج البحث — غوغل يختار سطرًا من محتواها بدلًا عنك.')}
                                </p>
                                <ul className="mt-3 space-y-2">
                                    {missing.map((p) => (
                                        <li key={p.id} className="flex items-center justify-between gap-2 text-[13px]">
                                            <span className="truncate text-[#374151]">{p.title}</span>
                                            <Badge variant="warning">{t('بلا وصف')}</Badge>
                                        </li>
                                    ))}
                                </ul>
                            </>
                        )}

                        <Button variant="outline" size="sm" className="mt-3" asChild>
                            <SmartLink routeName="admin.website.pages" href={route('admin.website.pages')}>
                                {t('الصفحات')}
                            </SmartLink>
                        </Button>
                    </Card>
                </div>
            </div>
        </AdminLayout>
    );
}
