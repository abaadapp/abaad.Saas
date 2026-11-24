import { Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, Bot, Camera as InstagramIcon, LogIn, Unlink } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import BackLink from '@/Components/BackLink';
import StatusPill from '@/Components/StatusPill';
import { SettingsPage, SettingsSection } from '@/Components/Settings';
import { useConfirm } from '@/Components/ConfirmDialog';
import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

/** ما يعرضه الخادم — `InstagramLink::view`، ولا رمزَ فيه */
export interface InstagramLinkView {
    state: 'connected' | 'needs_relink' | 'revoked';
    username: string;
    name: string | null;
    account_type: string | null;
    picture: string | null;
    expires_at: string | null;
    last_webhook_at: string | null;
    connected_at: string | null;
    missing_permissions: string[];
    subscribed: boolean;
    /** أيصل ما يكتبه التاجرُ بيده من تطبيق إنستقرام؟ — فتصمت الأتمتةُ عن زبونٍ يحادثه */
    hears_phone: boolean;
    error: string | null;
}

export interface InstagramPageView {
    link: InstagramLinkView | null;
    may_manage: boolean;
    scopes: string[];
    /** أتمتتُه — `null` لمتجرٍ لم تُفتح له (`InstagramAutomations::available`) */
    automations?: { published: number; total: number } | null;
}

const PERMISSION_LABEL: Record<string, string> = {
    instagram_business_basic: 'قراءة الحساب',
    instagram_business_manage_messages: 'الرسائل الخاصة',
    instagram_business_manage_comments: 'التعليقات',
};

const when = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString() : '—');

/**
 * ‏‏إنستقرام عند التاجر — «تسجيل الدخول عبر Instagram» ثمّ الرجوعُ مربوطًا.
 *
 * قبل الربط: ثلاثُ خطواتٍ وزرٌّ واحد. وبعده: الحسابُ بصورته واسمه، ومتى يتجدّد إذنُه وحده، وآخرُ ما
 * وصل منه، وما نقص من الأذونات بكلماته — والفصل.
 */
export function InstagramScreen({ view }: { view: InstagramPageView }) {
    const t = useTranslate();
    const [ask, dialog] = useConfirm();
    const errors = (usePage().props.errors ?? {}) as Record<string, string>;
    const link = view.link;
    const linked = link !== null && link.state !== 'revoked';

    const connect = () => router.post(route('admin.integrations.instagram.connect'));
    const disconnect = async () => {
        if (await ask({ message: 'فصل حساب إنستقرام @:name؟ لا يصل منه شيء بعد الفصل، وتعيد ربطه متى شئت.', values: { name: link?.username ?? '' }, danger: true, action: 'فصل' })) {
            router.post(route('admin.integrations.instagram.disconnect'), {}, { preserveScroll: true });
        }
    };

    return (
        <>
            {dialog}
            {errors.instagram && (
                <p className="rounded-[10px] bg-[#fef2f2] px-3 py-2 text-[13px] text-[#b91c1c]" data-testid="ig-error">{errors.instagram}</p>
            )}

            {! linked && (
                <SettingsSection title="اربط حساب إنستقرام" description="بضغطة واحدة — لا رمز يُنسخ ولا إعداد في Meta." icon={InstagramIcon}>
                    <ol className="space-y-3" data-testid="ig-steps">
                        {[
                            ['حساب احترافي', 'حوّل حساب متجرك إلى «أعمال» أو «صانع محتوى» من إعدادات إنستقرام، واجعله عامًا لتصل التعليقات.'],
                            ['سجّل الدخول عبر Instagram', 'تفتح صفحة إنستقرام، فتدخل بحساب متجرك.'],
                            ['اسمح بالأذونات الثلاثة', 'قراءة الحساب، والرسائل، والتعليقات — ثم ترجع إلى هنا مربوطًا.'],
                        ].map(([title, line], i) => (
                            <li key={title} className="flex gap-3">
                                <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-[#111] text-[12px] font-bold text-white tabular-nums">{i + 1}</span>
                                <div>
                                    <p className="font-bold text-[#111]">{t(title)}</p>
                                    <p className="mt-0.5 text-[13px] leading-relaxed text-[#6b7280]">{t(line)}</p>
                                </div>
                            </li>
                        ))}
                    </ol>
                    {link?.state === 'revoked' && (
                        <p className="mt-4 text-[12px] text-[#6b7280]" data-testid="ig-revoked">{t('كان مربوطًا بـ @:name ثم فُصل.', { name: link.username })}</p>
                    )}
                    <div className="mt-5">
                        {view.may_manage ? (
                            <Button type="button" onClick={connect} data-testid="ig-connect">
                                <LogIn />
                                {t('تسجيل الدخول عبر Instagram')}
                            </Button>
                        ) : (
                            <p className="text-[12px] text-[#9ca3af]">{t('ربط إنستقرام لصاحب الحساب.')}</p>
                        )}
                    </div>
                </SettingsSection>
            )}

            {linked && link && (
                <SettingsSection
                    title="حساب إنستقرام"
                    icon={InstagramIcon}
                    status={link.state === 'connected'
                        ? <StatusPill state="ready" label="مربوط" />
                        : <StatusPill state="action" label="أعد الربط" />}
                >
                    <div className="flex items-center gap-3" data-testid="ig-account">
                        {link.picture
                            ? <img src={link.picture} alt="" className="size-12 rounded-full object-cover" referrerPolicy="no-referrer" />
                            : <span className="flex size-12 items-center justify-center rounded-full bg-[#fdf2f8] text-[#e1306c]"><InstagramIcon className="size-5" /></span>}
                        <div className="min-w-0">
                            <p className="font-bold text-[#111]" dir="ltr">@{link.username}</p>
                            <p className="text-[12px] text-[#6b7280]">{[link.name, link.account_type].filter(Boolean).join(' · ')}</p>
                        </div>
                    </div>

                    {link.state === 'needs_relink' && (
                        <div className="mt-4 rounded-[10px] bg-[#fffbeb] px-3 py-2 text-[13px] text-[#b45309]" data-testid="ig-relink">
                            <p className="flex items-center gap-1.5 font-bold"><AlertTriangle className="size-4" />{t('انقطع إذن إنستقرام — أعد ربط الحساب.')}</p>
                            {link.error && <p className="mt-1 text-[12px]">{link.error}</p>}
                        </div>
                    )}

                    {link.missing_permissions.length > 0 && (
                        <p className="mt-4 rounded-[10px] bg-[#fffbeb] px-3 py-2 text-[12px] text-[#b45309]" data-testid="ig-missing">
                            {t('لم تُمنح: :list — أعد الربط واسمح بها لتعمل الأتمتة كلها.', { list: link.missing_permissions.map((p) => t(PERMISSION_LABEL[p] ?? p)).join('، ') })}
                        </p>
                    )}

                    {link.state === 'connected' && ! link.subscribed && (
                        <p className="mt-4 rounded-[10px] bg-[#fffbeb] px-3 py-2 text-[12px] text-[#b45309]" data-testid="ig-unsubscribed">
                            {link.error ?? t('لم يكتمل الاشتراك في الإشعارات — أعد الربط.')}
                        </p>
                    )}

                    <dl className="mt-5 grid grid-cols-1 gap-3 text-[13px] sm:grid-cols-3" data-testid="ig-facts">
                        <div>
                            <dt className="text-[#6b7280]">{t('رُبط في')}</dt>
                            <dd className="font-medium text-[#111]">{when(link.connected_at)}</dd>
                        </div>
                        <div>
                            <dt className="text-[#6b7280]">{t('يتجدد الإذن وحده قبل')}</dt>
                            <dd className="font-medium text-[#111]">{when(link.expires_at)}</dd>
                        </div>
                        <div>
                            <dt className="text-[#6b7280]">{t('آخر ما وصل منه')}</dt>
                            <dd className="font-medium text-[#111]" data-testid="ig-last-webhook">{link.last_webhook_at ? when(link.last_webhook_at) : t('لم يصل شيء بعد')}</dd>
                        </div>
                    </dl>

                    {view.may_manage && (
                        <div className="mt-6 flex flex-wrap gap-2 border-t border-[#f0f0f0] pt-4">
                            {link.state === 'needs_relink' || link.missing_permissions.length > 0 || ! link.subscribed ? (
                                <Button type="button" onClick={connect} data-testid="ig-relink-button">
                                    <LogIn />
                                    {t('أعد الربط')}
                                </Button>
                            ) : null}
                            <Button type="button" variant="outline" onClick={disconnect} data-testid="ig-disconnect">
                                <Unlink />
                                {t('فصل الحساب')}
                            </Button>
                        </div>
                    )}
                </SettingsSection>
            )}

            {view.automations ? (
                <SettingsSection
                    title="أتمتة إنستقرام"
                    description="يعلّق أحد «بكم؟» فيصله ردك على الخاص، أو يكتب رسالة فيصلها ردها — بقوالب جاهزة."
                    icon={Bot}
                    status={
                        <span className="text-[12px] text-[#6b7280] tabular-nums" data-testid="ig-automations-count">
                            {t(':n تعمل من :total', { n: view.automations.published, total: view.automations.total })}
                        </span>
                    }
                    action={
                        <Button asChild variant="outline">
                            <Link href={route('admin.integrations.instagram.automations')}>
                                <Bot />
                                {t('أتمتة إنستقرام')}
                            </Link>
                        </Button>
                    }
                />
            ) : (
                <SettingsSection title="ما الذي يأتي بعد الربط" description="الرد الآلي على التعليقات برسالة خاصة، وعلى الرسائل بالكلمات — تفتحه إدارة أبعاد لمتجرك." />
            )}
        </>
    );
}

export default function IntegrationsInstagram() {
    const { instagram } = usePage<PageProps<{ instagram: InstagramPageView }>>().props;
    const t = useTranslate();

    return (
        <AdminLayout title="إنستقرام">
            <BackLink routeName="admin.integrations.index" href={route('admin.integrations.index')} label="ربط مع أبعاد" />
            <PageHeader title="إنستقرام" subtitle={t('اربط حساب متجرك الاحترافي لتصل تعليقاته ورسائله أبعاد')} />
            <SettingsPage>
                <InstagramScreen view={instagram} />
            </SettingsPage>
        </AdminLayout>
    );
}
