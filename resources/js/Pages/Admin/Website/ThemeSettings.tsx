import { useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    FileText,
    Globe,
    History,
    Rocket,
    RotateCcw,
    Search,
    ShoppingBag,
    Store,
} from 'lucide-react';

import { useConfirm } from '@/Components/ConfirmDialog';
import SmartLink from '@/Components/SmartLink';
import Toggle from '@/Components/Toggle';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import AdminLayout from '@/Layouts/AdminLayout';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import { domainHost, type DomainState } from './shell';
import ThemeHeader, { ThemeState, type ThemeShell } from './theme/Shell';

interface ThemeVersion {
    id: number;
    number: number;
    at: string | null;
    by: string | null;
    note: string | null;
    current: boolean;
}

interface Props extends ThemeShell {
    /** `null` لمن لم يُفتح له نظامُ المسوّدات — يُحفظ فيظهر */
    publishing: {
        changed: number;
        fields: string[];
        revision: number;
        published_at: string | null;
        saved_at: string | null;
        versions: ThemeVersion[];
    } | null;
    domain: DomainState;
    storeOn: boolean;
    productCount: number;
    cards: {
        pages: { shown: number; all: number };
        ways: string[];
        seoIndexed: boolean;
        gatewayReady: boolean;
    };
}

/**
 * «الموقع الإلكتروني ‹ عام» لصاحب الواجهة الخاصّة — حالُ متجره وأبوابُه.
 *
 * ═══ وهي شاشةُ جاره بعينها ═══
 *
 * الحالُ في بطاقةٍ أولى: الاسمُ والشارةُ والرابطُ وآخرُ نشرة، وزرُّ النشر
 * والنسخُ السابقة. ثمّ خمسةُ أبوابٍ في بطاقات. ثمّ مفتاحٌ أسفلَها.
 *
 * وما يختلف يختلف عن حقّ: مكانَ «وضع الصيانة» عند جاره يقف **«نشر
 * المتجر»** — فالواجهةُ الخاصّة لا صفَّ لها في `websites`، ومفتاحُ حياتها
 * `store_on` في إعداداتها.
 *
 * ═══ ولمَ عادت هذه الشاشة ═══
 *
 * جُمعت الستُّ مرّةً في صفحةٍ واحدةٍ بعمودٍ يقفز، لأنّ «عام» كانت حينها
 * **قائمةً ثانية** فوق شريطٍ يقول الشيءَ نفسَه. وعادت بقرارٍ من صاحب
 * المنتج: أن يكون لصاحب الواجهة ما لجاره حرفًا بحرف.
 *
 * والعطبُ المقيسُ لم يُهمَل: بطاقةُ «المتجر» تقود إلى شاشةٍ **بزرّ حفظٍ
 * واحد** لا زرّين متجاورين — انظر `ThemeStore`.
 */
export default function ThemeSettings() {
    const { site, publishing, domain, storeOn, productCount, cards } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const [ask, confirmDialog] = useConfirm();

    const [publishOpen, setPublishOpen] = useState(false);
    const [history, setHistory] = useState(false);

    const publishForm = useForm({ note: '' });

    const publish = (e: React.FormEvent) => {
        e.preventDefault();
        publishForm.post(route('admin.website.store.publish'), {
            preserveScroll: true,
            onSuccess: () => {
                setPublishOpen(false);
                publishForm.reset();
            },
        });
    };

    const doors = [
        {
            label: 'الصفحات والمحتوى',
            hint: 'صفحاتُ متجرك الأربع — ما يُفتح منها، وأين يُكتب ما فيها',
            icon: FileText,
            route: 'admin.website.pages',
            meta: t(':n من :all', { n: number(cards.pages.shown), all: number(cards.pages.all) }),
        },
        {
            /*
             * و«التصميم» يقود إلى محرّر صفحته لا إلى منتقي قوالب.
             *
             * `store_theme` ميّتٌ لواجهةٍ خاصّة (انظر `Store\PageEditor::DEAD`)
             * وتصميمُها مثبَّتٌ في قالبها. فتصميمُها ترتيبُ أقسامها وما فيها
             * — ولا تُخترع لها لوحةُ ألوانٍ لا يقرؤها قالبُها.
             */
            label: 'التصميم والمظهر',
            hint: 'رتّب أقسام صفحتك واكتب ما تحفظه فيها',
            icon: Store,
            route: 'admin.website.design',
            meta: t('واجهةٌ خاصّة'),
        },
        {
            label: 'المتجر والطلبات',
            hint: 'ما يقبضه متجرك من زبونه، وما يُسأل عنه قبل أن يؤكّد طلبه',
            icon: ShoppingBag,
            route: 'admin.website.shop',
            meta: cards.ways.length ? cards.ways.join(' · ') : t('لا طريقة دفع'),
        },
        {
            label: 'الظهور في البحث',
            hint: 'عنوان متجرك في غوغل ووصفه وصورة المشاركة',
            icon: Search,
            route: 'admin.website.seo',
            meta: cards.seoIndexed ? t('يظهر في غوغل') : t('لا يُفهرس'),
        },
        {
            label: 'الدومين',
            hint: 'عنوان متجرك على الإنترنت، وربط نطاقك الخاص',
            icon: Globe,
            route: 'admin.website.domain',
            meta: domain.custom
                ? `${domain.custom.host} · ${domain.custom.label}`
                : (domainHost(domain) ?? t('بلا عنوان بعد')),
        },
    ];

    return (
        <AdminLayout title="الموقع الإلكتروني">
            <ThemeHeader
                site={site}
                current="admin.website.site"
                subtitle={t('متجرك على الإنترنت — عدّله ثمّ انشره حين يجهز')}
            />

            {/* ===== الحال ===== */}
            <Card className="mb-6 p-5">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <h2 className="font-bold text-[#111]">{site.name}</h2>
                            <ThemeState published={site.published} />
                            {publishing && publishing.changed > 0 && (
                                <Badge variant="warning">{t('فيه تغييرات لم تُنشر')}</Badge>
                            )}
                        </div>

                        <p className="mt-2 text-[13px] text-[#6b7280]">
                            {site.url ? (
                                <a
                                    href={site.url}
                                    target="_blank"
                                    rel="noreferrer"
                                    dir="ltr"
                                    className="font-mono hover:underline"
                                >
                                    {site.url}
                                </a>
                            ) : (
                                <span className="inline-flex flex-wrap items-center gap-2">
                                    {t('متجرك لا يُفتح من أيّ رابط بعد')}
                                    <Button variant="link" size="sm" asChild>
                                        <Link href={route('admin.website.domain')}>
                                            <Globe />
                                            {t('اضبط العنوان')}
                                        </Link>
                                    </Button>
                                </span>
                            )}
                        </p>

                        {publishing && (
                            <p className="mt-1 flex flex-wrap items-center gap-x-2 text-[12px] text-[#9ca3af]">
                                <span>
                                    {publishing.published_at
                                        ? `${t('آخر نشرة')} ${publishing.published_at}`
                                        : t('لم يُنشر بعد')}
                                </span>
                                {/* وآخرُ حفظٍ إلى جانبها — فيُعرف أحُفظ بعد النشر أم لا */}
                                {publishing.saved_at && (
                                    <>
                                        <span aria-hidden>·</span>
                                        <span>{`${t('آخر حفظ')} ${publishing.saved_at}`}</span>
                                    </>
                                )}
                            </p>
                        )}
                    </div>

                    {/*
                        ولا يُعرض زرُّ نشرٍ لمن لم يُفتح له النظام: متجرٌ بلا
                        صفٍّ في `store_sites` يُحفظ فيظهر في الحال، ومقبضٌ
                        موصولٌ بلا شيءٍ أسوأ من غيابه.
                    */}
                    {publishing && (
                        <div className="flex flex-wrap items-center gap-2">
                            {publishing.versions.length > 0 && (
                                <Button variant="ghost" onClick={() => setHistory(true)}>
                                    <History />
                                    {t('النسخ السابقة')}
                                </Button>
                            )}
                            <Button onClick={() => setPublishOpen(true)} disabled={publishing.changed === 0}>
                                <Rocket />
                                {t(publishing.changed > 0 ? 'نشر التغييرات' : 'لا تغييرات للنشر')}
                            </Button>
                        </div>
                    )}
                </div>

                {publishing && publishing.changed > 0 && publishing.published_at && (
                    <p className="mt-4 flex items-center gap-2 rounded-[12px] bg-[#fffbeb] px-4 py-3 text-[13px] text-[#b45309]">
                        <AlertTriangle className="size-4 shrink-0" />
                        {t('عدّلت متجرك ولم تنشر التغييرات — زوّارك ما زالوا يرون النسخة السابقة.')}
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

            {/* ===== النشر ===== */}
            <Card className="p-5">
                {/*
                    ولا مفتاحَ نشرٍ بلا عنوان — ولا مفتاحٌ مُعطَّل.

                    الخادمُ يردّ النشرَ بلا عنوانٍ برسالةٍ عن حقلٍ ليس في هذه
                    الشاشة (انظر `saveStore`). ومفتاحٌ يُضغط فيُردّ يُعلِّم
                    صاحبَه ألّا يثق بالشاشة. فيُقال له ما ينقص ويُقاد إلى
                    موضعه — و`Toggle` مشتركٌ بلا `disabled`، ولا يُعدَّل من
                    أجل شاشةٍ واحدة.
                */}
                {site.slug ? (
                    <Toggle
                        on={storeOn}
                        label="نشر المتجر"
                        hint={
                            storeOn
                                ? 'متجرك مفتوحٌ لزبائنك الآن'
                                : 'حتى يُنشر لا يفتحه أحد — والعنوان يردّ «غير موجود» لا صفحةً فارغة'
                        }
                        onChange={(on) =>
                            router.post(
                                route('admin.marketing.store.save'),
                                { store_on: on },
                                { preserveScroll: true },
                            )
                        }
                    />
                ) : (
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="min-w-0">
                            <p className="font-semibold text-[#111]">{t('نشر المتجر')}</p>
                            <p className="mt-1 flex items-center gap-2 text-[12px] text-[#b45309]">
                                <AlertTriangle className="size-3.5 shrink-0" />
                                {t('اكتب عنوان متجرك قبل نشره — بلا عنوانٍ لا يُفتح من أيّ رابط.')}
                            </p>
                        </div>
                        <Button variant="outline" size="sm" asChild>
                            <Link href={route('admin.website.domain')}>
                                <Globe />
                                {t('اضبط العنوان')}
                            </Link>
                        </Button>
                    </div>
                )}

                {storeOn && productCount === 0 && (
                    <p className="mt-2 flex items-center gap-2 text-[12px] text-[#b45309]">
                        <AlertTriangle className="size-3.5 shrink-0" />
                        {t('لا منتجَ معروضًا — زائرُك يرى صفحةً خالية.')}
                    </p>
                )}
            </Card>

            {/* ===== نشر ===== */}
            {publishing && (
                <Dialog open={publishOpen} onOpenChange={setPublishOpen}>
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle>{t('نشر التغييرات')}</DialogTitle>
                        </DialogHeader>

                        <form onSubmit={publish} className="space-y-4 px-5 pb-5">
                            <p className="text-[13px] leading-7 text-[#6b7280]">
                                {t('سيصير ما في مسوّدتك هو ما يراه زوّارك. والنسخة الحالية تبقى محفوظة، فيمكنك الرجوع إليها.')}
                            </p>

                            {/*
                                وأسماءُ ما تغيّر لا عددُه وحدَه: «٣ تغييرات»
                                تُقلق ولا تُفيد، والأسماءُ تُراجَع في لحظة.
                            */}
                            {publishing.fields.length > 0 && (
                                <ul className="space-y-1.5 rounded-[12px] bg-[#fafafa] px-4 py-3 text-[13px] text-[#374151]">
                                    {publishing.fields.map((f) => (
                                        <li key={f}>{f}</li>
                                    ))}
                                </ul>
                            )}

                            <div className="flex justify-end gap-2">
                                <Button type="button" variant="ghost" onClick={() => setPublishOpen(false)}>
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
            )}

            {/* ===== النسخ السابقة ===== */}
            {publishing && (
                <Dialog open={history} onOpenChange={setHistory}>
                    <DialogContent className="sm:max-w-lg">
                        <DialogHeader>
                            <DialogTitle>{t('النسخ السابقة')}</DialogTitle>
                        </DialogHeader>

                        <div className="space-y-2 px-5 pb-5">
                            <p className="text-[13px] text-[#6b7280]">
                                {t('الاستعادة تُرجع النسخة إلى مسوّدتك — تعاينها ثمّ تنشرها إن رضيت.')}
                            </p>

                            {publishing.versions.map((v) => (
                                <div
                                    key={v.id}
                                    className={cn(
                                        'flex flex-wrap items-center justify-between gap-3 rounded-[12px] border px-4 py-3',
                                        v.current ? 'border-[#111] bg-[#fafafa]' : 'border-[var(--ui-border,#e8e8e8)]',
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
                                            onClick={async () => {
                                                if (!(await ask({ message: 'استعادة هذه النسخة إلى المسوّدة؟', action: 'استعادة' }))) return;
                                                router.post(
                                                    route('admin.website.store.restore', v.id),
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
            )}

            {confirmDialog}
        </AdminLayout>
    );
}
