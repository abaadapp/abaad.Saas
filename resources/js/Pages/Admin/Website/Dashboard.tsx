import { useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ExternalLink,
    Eye,
    FileText,
    Globe,
    History,
    Palette,
    Pencil,
    Rocket,
    RotateCcw,
    Search,
    ShoppingBag,
    Wrench,
} from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { WEBSITE_TABS } from '@/Components/SectionTabs';
import SmartLink from '@/Components/SmartLink';
import Toggle from '@/Components/Toggle';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import { STATE_TONE, STATE_LABEL, type SiteShell } from './shell';

interface Props extends SiteShell {
    pages: { id: number; title: string; slug: string; status: string; is_home: boolean; sections: number }[];
    summary: { pages: number; sections: number; hidden: number; versions: number };
    domain: { mode: string; domain: string; subdomain: string | null };
    template_label: string;
    versions: {
        id: number;
        number: number;
        at: string | null;
        by: string | null;
        note: string | null;
        current: boolean;
    }[];
}

/**
 * لوحة الموقع — حالُه في سطر، وأربعة أبواب.
 *
 * ومن يفتح موقعه لا يُقذف في الإعدادات: يريد أن يعرف أمنشورٌ هو، وأين رابطه،
 * وهل بقي فيه ما لم يُنشر. ثمّ يذهب إلى ما جاء له.
 *
 * والزرّ الأساسيّ واحدٌ ظاهر: «تعديل الموقع». وشاشةٌ بخمسة أزرارٍ متساوية
 * شاشةٌ بلا زرٍّ أساسيّ، فيقف من يفتحها لا يدري بأيّها يبدأ.
 */
export default function Dashboard() {
    const { site, pages, summary, domain, template_label, versions } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const [publishing, setPublishing] = useState(false);
    const [history, setHistory] = useState(false);

    const publishForm = useForm({ note: '' });

    const publish = (e: React.FormEvent) => {
        e.preventDefault();
        publishForm.post(route('admin.website.publish'), {
            preserveScroll: true,
            onSuccess: () => {
                setPublishing(false);
                publishForm.reset();
            },
        });
    };

    const doors = [
        {
            label: 'الصفحات والمحتوى',
            hint: 'أضف صفحةً، رتّب الأقسام، غيّر النصوص والصور',
            icon: FileText,
            route: 'admin.website.pages',
            meta: `${number(summary.pages)} ${t('صفحات')} · ${number(summary.sections)} ${t('قسمًا')}`,
        },
        {
            label: 'التصميم والمظهر',
            hint: 'القالب والألوان والخط وشكل الأزرار',
            icon: Palette,
            route: 'admin.website.design',
            meta: template_label,
        },
        {
            label: 'المتجر',
            hint: 'ما يراه الزائر من الأسعار، وهل يستطيع الطلب',
            icon: ShoppingBag,
            route: 'admin.website.shop',
            meta: site.sells ? t('يبيع') : t('عرضٌ بلا طلب'),
        },
        {
            label: 'الظهور في البحث',
            hint: 'عنوان موقعك في غوغل ووصفه وصورة المشاركة',
            icon: Search,
            route: 'admin.website.seo',
            meta: domain.domain || domain.subdomain || t('بلا نطاق'),
        },
    ];

    return (
        <AdminLayout title="الموقع الإلكتروني">
            <PageHeader
                title={site.name}
                subtitle={t('موقعك على الإنترنت — عدّله ثمّ انشره حين يجهز')}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        {site.url && (
                            <Button variant="outline" asChild>
                                <a href={site.url} target="_blank" rel="noreferrer">
                                    <ExternalLink />
                                    {t('زيارة الموقع')}
                                </a>
                            </Button>
                        )}
                        <Button variant="outline" asChild>
                            <SmartLink routeName="admin.website.editor" href={route('admin.website.editor')}>
                                <Eye />
                                {t('معاينة')}
                            </SmartLink>
                        </Button>
                        <Button asChild>
                            <SmartLink routeName="admin.website.editor" href={route('admin.website.editor')}>
                                <Pencil />
                                {t('تعديل الموقع')}
                            </SmartLink>
                        </Button>
                    </div>
                }
            />

            <SectionTabs tabs={WEBSITE_TABS} current="admin.website.index" />

            {/* ===== الحال ===== */}
            <Card className="mb-6 p-5">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <h2 className="font-bold text-[#111]">{site.name}</h2>
                            <Badge variant={STATE_TONE[site.state]}>{t(STATE_LABEL[site.state])}</Badge>
                            <Badge variant="neutral">{site.goal_label}</Badge>
                        </div>

                        <p className="mt-2 text-[13px] text-[#6b7280]">
                            {site.url ? (
                                <a href={site.url} target="_blank" rel="noreferrer" dir="ltr" className="font-mono hover:underline">
                                    {site.url}
                                </a>
                            ) : (
                                <span className="inline-flex flex-wrap items-center gap-2">
                                    {t('لا نطاق لموقعك بعد — الزوّار لا يصلون إليه')}
                                    <Button variant="link" size="sm" asChild>
                                        <Link href={route('admin.settings.index', { section: 'domain' })}>
                                            <Globe />
                                            {t('اضبط النطاق')}
                                        </Link>
                                    </Button>
                                </span>
                            )}
                        </p>

                        <p className="mt-1 text-[12px] text-[#9ca3af]">
                            {site.published_at
                                ? `${t('آخر نشرة')} ${site.published_at}`
                                : t('لم يُنشر بعد — الزوّار لا يرون شيئًا حتى تنشره')}
                            {site.saved_at && ` · ${t('آخر حفظ')} ${site.saved_at}`}
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {summary.versions > 0 && (
                            <Button variant="ghost" onClick={() => setHistory(true)}>
                                <History />
                                {t('النسخ السابقة')}
                            </Button>
                        )}
                        <Button onClick={() => setPublishing(true)} disabled={!site.changes}>
                            <Rocket />
                            {t(site.changes ? 'نشر التغييرات' : 'لا تغييرات للنشر')}
                        </Button>
                    </div>
                </div>

                {/*
                    «فيه تغييرات لم تُنشر» يُقال هنا لا في تلميحٍ صغير.
                    التاجر يعدّل ثمّ يفتح موقعه فلا يجد تعديله، فيظنّ أنّ الحفظ
                    لم يعمل — وهو عمل، لكنّه لم ينشر.
                */}
                {site.changes && site.published_at && (
                    <p className="mt-4 flex items-center gap-2 rounded-[12px] bg-[#fffbeb] px-4 py-3 text-[13px] text-[#b45309]">
                        <AlertTriangle className="size-4 shrink-0" />
                        {t('عدّلت موقعك ولم تنشر التغييرات — زوّارك ما زالوا يرون النسخة السابقة.')}
                    </p>
                )}
            </Card>

            {/* ===== الأبواب ===== */}
            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                {doors.map((d) => (
                    <SmartLink
                        key={d.route}
                        routeName={d.route}
                        href={route(d.route)}
                        className="group rounded-[14px] border border-[var(--ui-border,#e8e8e8)] bg-white p-5 transition-colors hover:border-[#c9c9c9]"
                    >
                        <span className="flex items-start gap-4">
                            <span className="flex size-11 shrink-0 items-center justify-center rounded-[12px] bg-[#f5f5f5] text-[#374151] transition-colors group-hover:bg-[#111] group-hover:text-white">
                                <d.icon className="size-5" />
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="block font-bold text-[#111]">{t(d.label)}</span>
                                <span className="mt-1 block text-[13px] leading-6 text-[#6b7280]">{t(d.hint)}</span>
                                <span className="mt-2 block truncate text-[12px] text-[#9ca3af]">{d.meta}</span>
                            </span>
                        </span>
                    </SmartLink>
                ))}
            </div>

            {/* ===== الصيانة ===== */}
            <Card className="p-5">
                <Toggle
                    on={site.maintenance}
                    label="وضع الصيانة"
                    hint={
                        site.maintenance
                            ? 'الزوّار يرون صفحة صيانة الآن — ولوحتك تعمل كما هي'
                            : 'يعرض للزوّار صفحة صيانة محترمة بدل موقعك — ولوحتك تبقى تعمل'
                    }
                    onChange={(on) =>
                        router.post(
                            route('admin.website.maintenance'),
                            { maintenance: on },
                            { preserveScroll: true },
                        )
                    }
                />
                {site.maintenance && (
                    <p className="mt-2 flex items-center gap-2 text-[12px] text-[#b45309]">
                        <Wrench className="size-3.5 shrink-0" />
                        {t('لن يصل أحدٌ إلى موقعك حتى تُطفئ هذا المفتاح.')}
                    </p>
                )}
            </Card>

            {/* ===== نشر ===== */}
            <Dialog open={publishing} onOpenChange={setPublishing}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('نشر التغييرات')}</DialogTitle>
                    </DialogHeader>

                    <form onSubmit={publish} className="space-y-4 px-5 pb-5">
                        <p className="text-[13px] leading-7 text-[#6b7280]">
                            {t('سيصير ما في محرّرك هو ما يراه زوّارك. والنسخة الحالية تبقى محفوظة، فيمكنك الرجوع إليها.')}
                        </p>

                        <ul className="space-y-1.5 rounded-[12px] bg-[#fafafa] px-4 py-3 text-[13px] text-[#374151]">
                            <li>
                                {number(pages.filter((p) => p.status === 'published').length)} {t('صفحة منشورة')}
                            </li>
                            <li>
                                {number(summary.sections)} {t('قسمًا')}
                                {summary.hidden > 0 && ` · ${number(summary.hidden)} ${t('مخفيًا')}`}
                            </li>
                        </ul>

                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="ghost" onClick={() => setPublishing(false)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" loading={publishForm.processing}>
                                <Rocket />
                                {t('انشر')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            {/* ===== النسخ السابقة ===== */}
            <Dialog open={history} onOpenChange={setHistory}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t('النسخ السابقة')}</DialogTitle>
                    </DialogHeader>

                    <div className="space-y-2 px-5 pb-5">
                        <p className="text-[13px] text-[#6b7280]">
                            {t('الاستعادة تُرجع النسخة إلى محرّرك — تعاينها ثمّ تنشرها إن رضيت.')}
                        </p>

                        {versions.map((v) => (
                            <div
                                key={v.id}
                                className={cn(
                                    'flex flex-wrap items-center justify-between gap-3 rounded-[12px] border px-4 py-3',
                                    v.current
                                        ? 'border-[#111] bg-[#fafafa]'
                                        : 'border-[var(--ui-border,#e8e8e8)]',
                                )}
                            >
                                <div className="min-w-0">
                                    <p className="flex items-center gap-2 text-[13px] font-semibold text-[#111]">
                                        {t('نشرة')} {number(v.number)}
                                        {v.current && <Badge variant="success">{t('المنشورة الآن')}</Badge>}
                                    </p>
                                    <p className="mt-0.5 text-[12px] text-[#9ca3af]">
                                        {v.at} {v.by && `· ${v.by}`}
                                        {v.note && ` · ${v.note}`}
                                    </p>
                                </div>

                                {!v.current && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => {
                                            if (!confirm(t('استعادة هذه النسخة إلى المحرّر؟'))) return;
                                            router.post(
                                                route('admin.website.restore', v.id),
                                                {},
                                                { preserveScroll: true, onSuccess: () => setHistory(false) },
                                            );
                                        }}
                                    >
                                        <RotateCcw />
                                        {t('استعادة')}
                                    </Button>
                                )}
                            </div>
                        ))}
                    </div>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
