import { Link, usePage } from '@inertiajs/react';
import { AlertTriangle, Info } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import BackLink from '@/Components/BackLink';
import { SettingsPage, SettingsSection } from '@/Components/Settings';
import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';
import { AutomationsScreen, type AutomationsView } from '@/Pages/Admin/Integrations/partials/AutomationsScreen';
import type { InstagramLinkView } from '@/Pages/Admin/Integrations/Instagram';
import type { PageProps } from '@/types';

/**
 * ‏‏حالُ الربط فوق الأتمتة — ما يمنع الردَّ الآن بكلماته، وزرٌّ إلى صفحة الربط.
 *
 * والأتمتةُ تُكتب قبل الربط وتُحفظ: لا يخرج منها ردٌّ حتى يُربط الحساب.
 */
export function InstagramAutomationsNotice({ link }: { link: InstagramLinkView | null }) {
    const t = useTranslate();

    const problem = link === null || link.state === 'revoked'
        ? 'حساب إنستقرام غير مربوط — اكتب أتمتتك الآن، ولا يخرج منها رد حتى تربطه.'
        : link.state === 'needs_relink'
          ? 'انقطع إذن إنستقرام — لا يخرج رد حتى تعيد الربط.'
          : ! link.subscribed || link.missing_permissions.length > 0
            ? 'الربط ناقص — بعض التعليقات أو الرسائل لا تصل. أعد الربط.'
            : ! link.hears_phone
              ? 'لا نرى ردك من تطبيق إنستقرام، فقد ترد الأتمتة على زبون تحادثه بنفسك.'
              : null;

    if (problem === null) {
        return null;
    }

    return (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-[12px] bg-[#fffbeb] px-4 py-3 text-[13px] text-[#b45309]" data-testid="ig-automations-notice">
            <p className="flex items-center gap-2"><AlertTriangle className="size-4 shrink-0" />{t(problem)}</p>
            <Button asChild variant="outline" size="sm">
                <Link href={route('admin.integrations.instagram')}>{t('صفحة الربط')}</Link>
            </Button>
        </div>
    );
}

export default function IntegrationsInstagramAutomations() {
    const { view, link } = usePage<PageProps<{ view: AutomationsView; link: InstagramLinkView | null }>>().props;
    const t = useTranslate();

    return (
        <AdminLayout title="أتمتة إنستقرام">
            <BackLink routeName="admin.integrations.instagram" href={route('admin.integrations.instagram')} label="إنستقرام" />
            <PageHeader title="أتمتة إنستقرام" subtitle={t('ردود خاصة على التعليقات، وردود على الرسائل — بكلمات تحددها')} />
            <SettingsPage width="wide">
                <InstagramAutomationsNotice link={link} />
                <AutomationsScreen view={view} channel="instagram" />
                <SettingsSection title="حدود إنستقرام" icon={Info}>
                    <ul className="list-disc space-y-1.5 ps-5 text-[13px] leading-relaxed text-[#6b7280]" data-testid="ig-limits">
                        <li>{t('رسالة خاصة واحدة لكل تعليق. وبعدها يكتب الزبون إليك لتكمل معه.')}</li>
                        <li>{t('من لا يتابعك تصله الرسالة في «طلبات المراسلة» — فالرد العلني ينبهه إليها.')}</li>
                        <li>{t('تصل التعليقات من حساب عام، لا من حساب خاص.')}</li>
                        <li>{t('إن رددت بنفسك على زبون من تطبيق إنستقرام، تصمت الأتمتة عنه ساعة.')}</li>
                    </ul>
                </SettingsSection>
            </SettingsPage>
        </AdminLayout>
    );
}
