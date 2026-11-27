import { Star } from 'lucide-react';
import SmartLink from '@/Components/SmartLink';
import StatusPill from '@/Components/StatusPill';
import { SettingsSection } from '@/Components/Settings';
import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';

/** رحلةُ إدارة التقييمات — من الخادم وحده (`GoogleBusiness::state`) */
export interface ReviewsManagement {
    state: 'unavailable' | 'off' | 'reconnect' | 'api_blocked' | 'link_branches' | 'ready';
    /** فرعٌ مربوطٌ على الخرائط — يُعرض بجوار الحال ولا يُغني عن الإذن */
    maps: boolean;
    locations: number;
    branches: number;
}

/**
 * خطوةُ إدارة التقييمات — مستقلّةٌ عن ربط الخرائط.
 *
 * مفتاحُ الخرائط يقرأ الملفَّ العامّ، ولا يفتح ملفَّ أحد. فالقراءةُ الكاملة والردُّ
 * يحتاجان إذنًا صريحًا من حساب Google الذي يملك النشاط، ولا يُظنّ أنّهما أتيا مع
 * الخرائط. وكلُّ حالٍ تُقال بما يُفعل فيها — ومن لا شيء عليه لا يُطلب منه شيء.
 */
export default function GoogleReviewsStep({ data }: { data: ReviewsManagement }) {
    const t = useTranslate();

    const view = {
        unavailable: {
            pill: <StatusPill state="progress" label="غير متاحة بعد" />,
            line: t('تُتاح هنا متى اعتمدتها Google لأبعاد — لا شيء عليك أن تفعله الآن.'),
        },
        off: {
            pill: <StatusPill state="idle" label="غير مفعّلة" />,
            line: t('مفتاح الخرائط يعرض المعدّل فقط. لقراءة تقييماتك كلّها والرد عليها، امنح إذنك من حساب Google الذي يملك النشاط أو يديره.'),
        },
        reconnect: {
            pill: <StatusPill state="error" label="انتهى الإذن" />,
            line: t('انتهى إذن Google أو سُحب. أعد الربط بحسابك لتعود قراءة التقييمات والرد عليها.'),
        },
        api_blocked: {
            pill: <StatusPill state="progress" label="بانتظار اعتماد Google" />,
            line: t('حسابك مربوط، لكنّ Google لم تعتمد واجهات Business Profile لأبعاد بعد. ليس عليك شيء، ويعود العمل تلقائيًّا بعد اعتمادها.'),
        },
        link_branches: {
            pill: <StatusPill state="progress" label="بقي ربط الفروع" />,
            line: t('حسابك مربوط. اربط كلّ فرعٍ بموقعه في ملفّ أعمالك لتصلك تقييماته.'),
        },
        ready: {
            pill: <StatusPill state="ready" label="جاهز للعمل" />,
            line: t('تُسحب تقييماتك تلقائيًّا، وتردّ عليها من هنا باسم متجرك: :n من :t فروع مربوطة.', {
                n: data.locations,
                t: data.branches,
            }),
        },
    }[data.state];

    const grants = data.state === 'off' || data.state === 'reconnect';

    return (
        <SettingsSection
            title="إدارة تقييمات Google"
            description={view.line}
            icon={Star}
            status={view.pill}
            action={
                data.state === 'unavailable' ? undefined : (
                    <Button asChild variant={grants ? 'primary' : 'outline'} size="sm">
                        {grants ? (
                            /* رابطٌ لا زيارةُ Inertia: يخرج إلى صفحة الإذن عند Google */
                            <a href={route('admin.integrations.googleBusiness.connect')} data-testid="gbp-step-connect">
                                {t(data.state === 'reconnect' ? 'إعادة ربط حساب Google' : 'تفعيل إدارة التقييمات')}
                            </a>
                        ) : (
                            <SmartLink
                                routeName="admin.integrations.googleBusiness"
                                href={route('admin.integrations.googleBusiness')}
                            >
                                {t('إدارة التقييمات')}
                            </SmartLink>
                        )}
                    </Button>
                )
            }
        />
    );
}
