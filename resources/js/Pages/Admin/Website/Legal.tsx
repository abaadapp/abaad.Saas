import { useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, ExternalLink, Eye, RotateCcw, Save, Send } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { THEME_TABS, WEBSITE_TABS } from '@/Components/SectionTabs';
import CopyButton from '@/Components/CopyButton';
import Field from '@/Components/Field';
import { SettingsPage, SettingsSection } from '@/Components/Settings';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

type Kind = 'privacy' | 'deletion' | 'terms';

interface LegalPage {
    kind: Kind;
    title: string;
    title_en: string;
    url: string | null;
    published: { version: number; at: string | null } | null;
    from: 'draft' | 'published' | 'template';
    body_ar: string;
    body_en: string;
    can_restore: boolean;
}

interface Props {
    themed: boolean;
    facts: { store_name: string; legal_name: string; email: string; phone: string; whatsapp: string; website: string };
    missing: { key: string; label: string }[];
    pages: LegalPage[];
    csrf?: string;
}

/**
 * الصفحاتُ القانونيّة — سياسةُ الخصوصيّة وتعليماتُ حذف البيانات والشروط.
 *
 * لكلّ متجرٍ بلا أن يُنشئها له أحد: يفتح الشاشة فيجد قالبًا مملوءًا ببياناته،
 * يراجعه بلغتيه، ويحفظه مسوّدةً أو ينشره. وما لم يُنشر لا يراه زبون.
 *
 * والبياناتُ الناقصة تُقال أوّلَ الشاشة لا عند الضغط على «نشر»: الاسمُ
 * القانونيّ ووسيلةُ التواصل يُملأ بهما القالبُ في كلّ صفحة.
 */
export default function Legal() {
    const { themed, facts, missing, pages, csrf } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const form = useForm({ store_legal_name: facts.legal_name, store_legal_email: facts.email });

    const saveFacts = (e: React.FormEvent) => {
        e.preventDefault();
        form.put(route('admin.website.legal.facts'), { preserveScroll: true });
    };

    return (
        <AdminLayout title="الصفحات القانونية">
            <PageHeader title="الصفحات القانونية" subtitle={t('سياسة الخصوصية وتعليمات حذف البيانات وشروط الاستخدام لموقعك')} />

            <SectionTabs tabs={themed ? THEME_TABS : WEBSITE_TABS} current="admin.website.legal" />

            <SettingsPage width="wide">
                {missing.length > 0 && (
                    <div data-testid="legal-missing" className="flex gap-3 rounded-[12px] border border-[#fde68a] bg-[#fffbeb] p-4 text-[13px] text-[#92400e]">
                        <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                        <div>
                            <p className="font-semibold">{t('أكمل هذه البيانات قبل النشر:')}</p>
                            <ul className="mt-1 list-disc ps-5">
                                {missing.map((m) => (
                                    <li key={m.key} data-testid={`legal-missing-${m.key}`}>{m.label}</li>
                                ))}
                            </ul>
                        </div>
                    </div>
                )}

                <SettingsSection title="بيانات النشاط" description="تُكتب في الصفحات القانونية الثلاث بدل الرموز {{legal_name}} و{{contact}}.">
                    <form onSubmit={saveFacts} className="grid gap-4 sm:grid-cols-2">
                        <Field label="الاسم القانوني للنشاط" hint="كما في السجل التجاري" htmlFor="legal-name" error={form.errors.store_legal_name}>
                            <Input id="legal-name" dir="auto" value={form.data.store_legal_name} onChange={(e) => form.setData('store_legal_name', e.target.value)} data-testid="legal-name" />
                        </Field>
                        <Field label="بريد التواصل" hint="لطلبات الخصوصية وحذف البيانات" htmlFor="legal-email" error={form.errors.store_legal_email}>
                            <Input id="legal-email" dir="ltr" type="email" value={form.data.store_legal_email} onChange={(e) => form.setData('store_legal_email', e.target.value)} data-testid="legal-email" />
                        </Field>
                        <dl className="grid gap-1 text-[13px] text-[#4b5563] sm:col-span-2">
                            <div><dt className="inline font-medium">{t('اسم المتجر')}: </dt><dd className="inline">{facts.store_name || '—'}</dd></div>
                            <div><dt className="inline font-medium">{t('عنوان الموقع')}: </dt><dd className="inline" dir="ltr">{facts.website || '—'}</dd></div>
                            <div><dt className="inline font-medium">{t('واتساب')}: </dt><dd className="inline" dir="ltr">{facts.whatsapp || '—'}</dd></div>
                            <div><dt className="inline font-medium">{t('الهاتف')}: </dt><dd className="inline" dir="ltr">{facts.phone || '—'}</dd></div>
                        </dl>
                        <div className="sm:col-span-2">
                            <Button type="submit" disabled={form.processing} data-testid="legal-facts-save">
                                <Save className="size-4" />
                                {t('حفظ البيانات')}
                            </Button>
                        </div>
                    </form>
                </SettingsSection>

                {pages.map((page) => (
                    <LegalPageCard key={page.kind} page={page} token={String(csrf ?? '')} />
                ))}
            </SettingsPage>
        </AdminLayout>
    );
}

function LegalPageCard({ page, token }: { page: LegalPage; token: string }) {
    const t = useTranslate();
    const form = useForm({ body_ar: page.body_ar, body_en: page.body_en });
    const [restoring, setRestoring] = useState(false);
    const errors = form.errors as Record<string, string | undefined>;

    const status = page.published
        ? t('منشورة — الإصدار :n', { n: page.published.version })
        : page.from === 'draft'
            ? t('غير منشورة — مسودة محفوظة')
            : t('غير منشورة — قالب جاهز للمراجعة');

    const draft = () => form.put(route('admin.website.legal.draft', page.kind), { preserveScroll: true });
    const publish = () => form.post(route('admin.website.legal.publish', page.kind), { preserveScroll: true });
    const restore = () => form.post(route('admin.website.legal.restore', page.kind), {
        preserveScroll: true,
        onFinish: () => setRestoring(false),
    });

    return (
        <SettingsSection title={page.title} description={status}>
            <div className="space-y-4" data-testid={`legal-${page.kind}`}>
                {page.url && (
                    <div className="flex flex-wrap items-center gap-2 rounded-[10px] bg-[#f9fafb] px-3 py-2 text-[13px]">
                        <span dir="ltr" className="min-w-0 break-all" data-testid={`legal-url-${page.kind}`}>{page.url}</span>
                        <CopyButton text={page.url} label={t('نسخ الرابط')} size="sm" variant="outline" />
                        {page.published && (
                            <a href={page.url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-[#1d4ed8] underline">
                                <ExternalLink className="size-3.5" />
                                {t('فتح')}
                            </a>
                        )}
                    </div>
                )}

                <div className="grid gap-4 lg:grid-cols-2">
                    <Field label="النص العربي" htmlFor={`legal-${page.kind}-ar`} error={errors.body_ar}>
                        <textarea
                            id={`legal-${page.kind}-ar`}
                            dir="rtl"
                            rows={16}
                            value={form.data.body_ar}
                            onChange={(e) => form.setData('body_ar', e.target.value)}
                            className="w-full rounded-[10px] border border-[var(--ui-border,#e8e8e8)] p-3 text-[14px] leading-7"
                            data-testid={`legal-${page.kind}-ar`}
                        />
                    </Field>
                    <Field label="النص الإنجليزي" htmlFor={`legal-${page.kind}-en`} error={errors.body_en}>
                        <textarea
                            id={`legal-${page.kind}-en`}
                            dir="ltr"
                            rows={16}
                            value={form.data.body_en}
                            onChange={(e) => form.setData('body_en', e.target.value)}
                            className="w-full rounded-[10px] border border-[var(--ui-border,#e8e8e8)] p-3 text-[14px] leading-7"
                            data-testid={`legal-${page.kind}-en`}
                        />
                    </Field>
                </div>

                <p className="text-[12px] text-[#6b7280]">
                    {t('«## » في أول السطر عنوان، و«- » بند في قائمة. وما بين [[ ]] يجب أن تكتبه أنت قبل النشر.')}
                </p>

                {(errors.body || errors.facts) && (
                    <p className="text-[13px] text-[#b91c1c]" data-testid={`legal-error-${page.kind}`}>{errors.facts ?? errors.body}</p>
                )}

                {/* المعاينةُ تُرسل ما في الحقل الآن إلى نافذةٍ جديدة — بلا حفظ، ولكلّ لغةٍ نموذجُها */}
                {(['ar', 'en'] as const).map((lang) => (
                    <form key={lang} method="POST" action={route('admin.website.legal.preview', page.kind)} target="_blank" className="hidden" id={`legal-preview-${page.kind}-${lang}-form`}>
                        <input type="hidden" name="_token" value={token} />
                        <input type="hidden" name="lang" value={lang} />
                        <input type="hidden" name="body" value={lang === 'en' ? form.data.body_en : form.data.body_ar} />
                    </form>
                ))}

                <div className="flex flex-wrap gap-2">
                    <Button type="button" variant="outline" disabled={form.processing} onClick={draft} data-testid={`legal-draft-${page.kind}`}>
                        <Save className="size-4" />
                        {t('حفظ المسودة')}
                    </Button>
                    {(['ar', 'en'] as const).map((lang) => (
                        <Button
                            key={lang}
                            type="submit"
                            form={`legal-preview-${page.kind}-${lang}-form`}
                            variant="outline"
                            data-testid={`legal-preview-${page.kind}-${lang}`}
                        >
                            <Eye className="size-4" />
                            {lang === 'ar' ? t('معاينة بالعربية') : t('معاينة بالإنجليزية')}
                        </Button>
                    ))}
                    <Button type="button" disabled={form.processing} onClick={publish} data-testid={`legal-publish-${page.kind}`}>
                        <Send className="size-4" />
                        {page.published ? t('نشر التعديلات') : t('نشر الصفحة')}
                    </Button>
                    {page.can_restore && (restoring ? (
                        <Button type="button" variant="danger" disabled={form.processing} onClick={restore} data-testid={`legal-restore-confirm-${page.kind}`}>
                            <RotateCcw className="size-4" />
                            {t('تأكيد الرجوع للإصدار السابق')}
                        </Button>
                    ) : (
                        <Button type="button" variant="ghost" onClick={() => setRestoring(true)} data-testid={`legal-restore-${page.kind}`}>
                            <RotateCcw className="size-4" />
                            {t('الرجوع للإصدار السابق')}
                        </Button>
                    ))}
                </div>
            </div>
        </SettingsSection>
    );
}
