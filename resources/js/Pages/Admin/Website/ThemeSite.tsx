import { Link, usePage } from '@inertiajs/react';
import {
    FileText,
    Globe,
    LayoutTemplate,
    Package,
    ScanSearch,
    Search,
    ShoppingBag,
} from 'lucide-react';

import AdminLayout from '@/Layouts/AdminLayout';
import SmartLink from '@/Components/SmartLink';
import StoreReadinessList, { type ReadinessStep } from '@/Components/StoreReadinessList';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import ThemeHeader, { ThemeState, type ThemeShell } from './theme/Shell';

interface Props extends ThemeShell {
    readiness: ReadinessStep[];
    counts: {
        sections: number;
        allSections: number;
        shown: number;
        active: number;
        payments: number;
        /** ما يُفتح من صفحاته الأربع — لا ما أذِن به وحده */
        pages: number;
        allPages: number;
        /** أكتب عنوانَ البحث ووصفَه بيده أم بقيا محسوبين؟ */
        seo: boolean;
    };
}

/**
 * «الموقع الإلكتروني ‹ عام» لصاحب الواجهة الخاصّة.
 *
 * ═══ ولمَ بشكل شاشة جاره بالحرف ═══
 *
 * صاحبُ متجرٍ في أبعاد يفتح «الموقع الإلكتروني» فيجد ترويسةً وشريطَ تبويبات
 * وبطاقةَ حالٍ وأبوابًا. وصاحبُ الواجهة الخاصّة كان يُساق إلى بطاقةٍ في
 * «الإعدادات» فيها ثلاثون مقبضًا بلا شريطٍ ولا أبواب — لوحتان مختلفتان
 * للشيء نفسِه، فمن تعلّم إحداهما لا يعرف أين يبحث في الأخرى.
 *
 * ═══ وما سقط سقط عن حقّ ═══
 *
 * لا «نشر التغييرات» ولا «النسخ السابقة» ولا «وضع الصيانة»: واجهتُه تقرأ
 * إعداداته مباشرةً — ما يُحفظ يصل زبونَه في اللحظة نفسِها. ولا مسوّدةَ
 * تُنشر ولا لقطةَ تُستعاد، وزرٌّ يَعِد بتأجيلٍ لا يقع يُقرأ مرّةً ثمّ
 * يُترك.
 */
export default function ThemeSite() {
    const { site, readiness, counts } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const doors = [
        {
            label: 'التصميم',
            hint: 'رتّب أقسام صفحتك واكتب فيها — وما تحفظه يظهر في الحال',
            icon: LayoutTemplate,
            route: 'admin.website.design',
            meta: `${number(counts.sections)} ${t('قسمًا ظاهرًا من')} ${number(counts.allSections)}`,
        },
        {
            label: 'الصفحات',
            hint: 'صفحاتُ متجرك الأربع — ما يُفتح منها، وأين يُكتب ما فيها',
            icon: FileText,
            route: 'admin.website.pages',
            meta: `${number(counts.pages)} ${t('صفحةً تُفتح من')} ${number(counts.allPages)}`,
        },
        {
            label: 'المتجر والطلبات',
            hint: 'كيف يدفع زبونك وكيف يستلم، وما يُسأل عنه في إتمام الطلب',
            icon: ShoppingBag,
            route: 'admin.website.shop',
            /*
             * وعددُ طرق الدفع يُقرأ من `WebCheckout::payments` لا من مفتاحين.
             *
             * بوّابةُ البطاقة طريقةٌ ثالثةٌ بلا مفتاحٍ في هذه المجموعة — فعدٌّ
             * يقرأ المفاتيح يقول «اثنتان» ومتجرُه يقبض بثلاث.
             */
            meta:
                counts.payments > 0
                    ? `${number(counts.payments)} ${t('طريقة دفع')}`
                    : t('لا طريقة دفع — لا يقبل طلبًا'),
        },
        {
            label: 'الدومين',
            hint: 'أين يُفتح متجرك، واسمُه على العنوان، ومفتاحُ نشره',
            icon: Globe,
            route: 'admin.website.domain',
            meta: site.slug ? `${site.slug}.${site.host}` : t('بلا عنوان بعد'),
        },
        {
            label: 'ما يظهر في متجرك',
            hint: 'الأصناف التي يراها زبونك — وما نفد منها فاختفى',
            icon: Package,
            route: 'admin.website.index',
            meta: `${number(counts.shown)} ${t('صنفًا معروضًا من')} ${number(counts.active)}`,
        },
        {
            label: 'الظهور في البحث',
            hint: 'عنوانُ متجرك ووصفُه في نتائج غوغل — كما يقرؤهما من يبحث',
            icon: Search,
            route: 'admin.website.seo',
            meta: counts.seo ? t('مكتوبٌ بيدك') : t('محسوبٌ من اسمك ونبذتك'),
        },
        /*
         * وفحصُ الظهور بابٌ آخر — لا اسمٌ ثانٍ للأوّل.
         *
         * ذاك يفتح متجرك ويقرأ ما فيه ويقول أين الخلل، وهذا يكتب ما يُعرض
         * في النتيجة. وبابان باسمٍ واحد يجعلان أحدهما لا يُفتح أبدًا.
         */
        {
            label: 'فحص متجرك في البحث',
            hint: 'يفتح متجرك ويقول ما يراه محرّك البحث فيه',
            icon: ScanSearch,
            route: 'admin.marketing.seo',
            meta: site.name,
        },
    ];

    return (
        <AdminLayout title="الموقع الإلكتروني">
            <ThemeHeader
                site={site}
                current="admin.website.site"
                subtitle={t('متجرك على الإنترنت — عدّله فيظهر لزبونك في الحال')}
            />

            {/* ═══ الحال ═══ */}
            <Card className="mb-6 p-5">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <h2 className="font-bold text-[#111]">{site.name}</h2>
                            <ThemeState published={site.published} />
                            <Badge variant="neutral">{t('واجهة خاصّة')}</Badge>
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
                                    {t('متجرك لا يفتحه أحدٌ بعد — زبائنك لا يصلون إليه')}
                                    <Button variant="link" size="sm" asChild>
                                        <Link href={route('admin.website.domain')}>
                                            <Globe />
                                            {t('اضبط عنوانه وانشره')}
                                        </Link>
                                    </Button>
                                </span>
                            )}
                        </p>

                        {/*
                            ولا «آخر نشرة» هنا.

                            تلك تصدق على موقعٍ يُنشر لقطةً. وهذا يقرأ إعداداته
                            كلَّما فُتح — فما يراه زبونُه هو حالُ اللحظة، وسطرٌ
                            يقول «آخر نشرة ١٥-٠٩» يُقرأ وعدًا بأنّ ما بعدها لم
                            يصل، وقد وصل كلُّه.
                        */}
                        <p className="mt-1 text-[12px] text-[#9ca3af]">
                            {t('ما تحفظه يصل زبونك فورًا — لا نشرَ بعده ولا مسوّدة تنتظر.')}
                        </p>
                    </div>

                    <Button asChild>
                        <Link href={route('admin.website.editor')}>
                            <LayoutTemplate />
                            {t('حرّر صفحتك')}
                        </Link>
                    </Button>
                </div>

                {/*
                    ودليلُ التجهيز في بطاقة الحال — فهو جوابُ «أين أنا؟».

                    واللازمُ يُفصل عن المستحسن: «بلا عنوانٍ لا يُفتح متجرك»
                    ليست كـ«بلا نبذةٍ يبدو أقلَّ ثقة».
                */}
                <div className="mt-5 border-t border-[var(--ui-border,#e8e8e8)] pt-5">
                    <StoreReadinessList steps={readiness} />
                </div>
            </Card>

            {/* ═══ الأبواب ═══ */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
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
        </AdminLayout>
    );
}
