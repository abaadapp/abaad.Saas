import { useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    BarChart3,
    Check,
    ExternalLink,
    Globe,
    Minus,
    RefreshCw,
    Search,
    X,
} from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import CopyButton from '@/Components/CopyButton';
import Field from '@/Components/Field';
import Gate from '@/Components/Gate';
import StatusPill, { type SetupState } from '@/Components/StatusPill';
import { Advanced, PageActions, SettingsPage, SettingsSection } from '@/Components/Settings';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface Link_ {
    domain: string;
    site_url: string | null;
    measurement_id: string | null;
    snippet: string | null;
    analytics_url: string;
}

interface CheckRow {
    key: string;
    label: string;
    /** pass | warn | fail | off */
    state: 'pass' | 'warn' | 'fail' | 'off';
    detail: string | null;
    fix: string | null;
}

interface Audit {
    /** off | nodomain | unreachable | ok */
    state: 'off' | 'nodomain' | 'unreachable' | 'ok';
    error: string | null;
    checked_at: string | null;
    site: {
        url: string;
        status: number;
        https: boolean;
        title: string | null;
        description: string | null;
        tagged: boolean;
    } | null;
    checks: CheckRow[];
}

interface Props {
    link: Link_;
    audit: Audit;
}

/** أيقونةُ الحالة — والرمادي لما لم يُفحص بعد، لا لما نجح */
function StateIcon({ state }: { state: CheckRow['state'] }) {
    if (state === 'pass') return <Check className="size-4 text-[#047857]" />;
    if (state === 'fail') return <X className="size-4 text-[#b91c1c]" />;
    if (state === 'warn') return <AlertTriangle className="size-4 text-[#b45309]" />;

    return <Minus className="size-4 text-[#9ca3af]" />;
}

/**
 * الظهور في البحث وربط Google Analytics.
 *
 * والحدُّ مكتوبٌ في الشاشة نفسها لأنّه يحكم كلَّ ما فيها: **موقعك ليس عندنا**.
 * فلا حقلَ هنا لعنوان صفحتك ولا وصفها ولا كلماتها المفتاحية — ما يُكتب عندنا
 * لا يصل صفحةً يقرؤها محرّك بحث، وحقلٌ يُملأ ولا يصل أسوأ من غيابه.
 *
 * وما تفعله الشاشة شيئان: تُعطيك **ما تلصقه** في موقعك، ثمّ **تفتح موقعك
 * وتقول ما رأت**. و«مربوط» تعني أنّ الوسم رُئي في صفحتك — لا أنّك لصقتَ
 * معرّفًا في حقل.
 */
export default function MarketingSeo() {
    const { link, audit } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const form = useForm({ ga_measurement_id: link.measurement_id ?? '' });
    const [checking, setChecking] = useState(false);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('admin.marketing.seo.save'), { preserveScroll: true });
    };

    const recheck = () => {
        setChecking(true);
        router.post(route('admin.marketing.seo.refresh'), {}, {
            preserveScroll: true,
            onFinish: () => setChecking(false),
        });
    };

    const tagged = audit.site?.tagged ?? false;

    /*
        حالُ الربط في كلمة — و«محفوظ» ليست «مربوط».

        المعرّفُ في الحقل لا يعني أنّ الوسم على الصفحة: يُلصق في موقعٍ
        خارج النظام، وقد لا يُلصق أبدًا. فمن حفظ ولم يُرَ وسمُه «يحتاج
        إجراء» لا «جاهز» — وإلّا انتظر أرقامًا لا تأتي.
    */
    const state: SetupState =
        audit.state === 'unreachable' ? 'error'
        : ! link.measurement_id ? 'idle'
        : tagged ? 'ready'
        : 'action';

    return (
        <AdminLayout title="الظهور في البحث">
            <PageHeader
                title="الظهور في البحث"
                subtitle={t('اربط موقعك بـGoogle Analytics، واقرأ ما يراه محرّك البحث في صفحتك')}
                actions={
                    audit.state !== 'nodomain' && audit.state !== 'off' && (
                        <>
                            <StatusPill state={state} connected />
                            <Button type="button" variant="outline" loading={checking} onClick={recheck}>
                                <RefreshCw />
                                {t('افحص الآن')}
                            </Button>
                        </>
                    )
                }
            />

            {/*
                بلا نطاقٍ لا فحص: لا يُعرف أيُّ موقعٍ يُفتح. والباب يُفتح من
                هنا بدل أن يُقال «أضف نطاقك» ويُترك صاحبُه يبحث عن الحقل.
            */}
            {audit.state === 'off' || audit.state === 'nodomain' ? (
                /*
                    و«مُطفأ» غير «بلا نطاق»: الأولى قرارٌ يُلغى بمفتاح،
                    والثانية نقصٌ يُكمَل بكتابة نطاق. وجمعُهما في رسالةٍ
                    واحدة يجعل من أطفأ موقعه يبحث عن نطاقٍ كتبه بالفعل.
                */
                <Gate
                    icon={Globe}
                    title={audit.state === 'off' ? t('الموقع الإلكتروني مُطفأ') : t('لم تُضف نطاق موقعك بعد')}
                    description={
                        audit.state === 'off'
                            ? t('فعّله من الإعدادات ليُفحص، ويظهر زرّه في الشريط العلوي.')
                            : t('بلا نطاقٍ لا يُعرف أيّ موقعٍ يُفحص — ولا أين يُلصق وسم القياس.')
                    }
                    action={
                        <Button asChild size="lg">
                            <Link href={route('admin.settings.index', { section: 'website' })}>
                                {audit.state === 'off' ? t('فعّل الموقع الإلكتروني') : t('أضف نطاق موقعك')}
                            </Link>
                        </Button>
                    }
                />
            ) : (
                /*
                    عمودٌ واحد بترتيب العمل: الصقْ المعرّف، خُذ الوسم، ثمّ
                    اقرأ ما رأته الشاشة في صفحتك. وكان عمودين — المعرّفُ
                    والوسمُ والفحصُ في الواسع، وفي الضيّق بطاقةُ رابطٍ
                    وبطاقةُ شرح — فيقرأ التاجر عمودين لا يتبع أحدُهما الآخر.
                */
                <SettingsPage>
                    {/* --------------------------- الربط --------------------------- */}
                    <form onSubmit={submit}>
                        <SettingsSection
                            title="معرّف القياس"
                            description="من «المشرف ← تدفّقات البيانات» في Google Analytics — يبدأ بـG-"
                            icon={BarChart3}
                            status={<StatusPill state={state} connected />}
                        >
                            <Field error={form.errors.ga_measurement_id}>
                                <Input
                                    dir="ltr"
                                    value={form.data.ga_measurement_id}
                                    onChange={(e) => form.setData('ga_measurement_id', e.target.value)}
                                    placeholder="G-XXXXXXXXXX"
                                    aria-label={t('معرّف القياس')}
                                />
                            </Field>

                            <PageActions
                                className="mt-5"
                                note={
                                    link.measurement_id && (
                                        <span
                                            className={cn(
                                                'flex items-center gap-1.5 text-[13px]',
                                                tagged ? 'text-[#047857]' : 'text-[#b45309]',
                                            )}
                                        >
                                            {tagged ? <Check className="size-4" /> : <AlertTriangle className="size-4" />}
                                            {t(tagged ? 'الوسم يعمل على موقعك' : 'محفوظ — ولم يُرَ في موقعك بعد')}
                                        </span>
                                    )
                                }
                            >
                                <Button asChild variant="outline">
                                    <a href={link.analytics_url} target="_blank" rel="noreferrer">
                                        <ExternalLink />
                                        {t('افتح Google Analytics')}
                                    </a>
                                </Button>
                                <Button type="submit" loading={form.processing}>
                                    {t('حفظ')}
                                </Button>
                            </PageActions>
                        </SettingsSection>
                    </form>

                    {/* ------------------------- ما يُلصق ------------------------- */}
                    {link.snippet && (
                        <SettingsSection
                            title="الوسم الذي تلصقه في موقعك"
                            /*
                                ولا نستطيع لصقه عنك: الموقع خارج النظام،
                                لا نستضيفه ولا نبني صفحاته.
                            */
                            description="داخل <head> في كل صفحة. موقعك خارج النظام فلا نستطيع وضعه فيه نيابةً عنك."
                            action={<CopyButton text={link.snippet ?? ''} label="نسخ" />}
                        >
                            <pre
                                dir="ltr"
                                className="overflow-x-auto rounded-[12px] bg-[#0f172a] p-4 text-start text-[12px] leading-relaxed text-[#e2e8f0]"
                            >
                                {link.snippet}
                            </pre>
                        </SettingsSection>
                    )}

                    {/* -------------------------- الفحص -------------------------- */}
                    <SettingsSection
                        title="ما يراه محرّك البحث في صفحتك"
                        icon={Search}
                        action={
                            link.site_url && (
                                <>
                                    <Button asChild size="sm" variant="outline">
                                        <a href={link.site_url} target="_blank" rel="noreferrer">
                                            <ExternalLink />
                                            {t('فتح موقعك')}
                                        </a>
                                    </Button>
                                    <Button asChild size="sm" variant="outline">
                                        <Link href={route('admin.settings.index', { section: 'website' })}>
                                            {t('تغيير النطاق')}
                                        </Link>
                                    </Button>
                                </>
                            )
                        }
                    >
                        {audit.state === 'ok' && audit.site && (
                            <p dir="ltr" className="mb-4 break-all text-start text-[12px] text-[#9ca3af]">
                                {audit.site.url}
                            </p>
                        )}

                        {audit.state === 'unreachable' ? (
                            <p className="rounded-[12px] bg-[#fef2f2] p-4 text-[13px] text-[#b91c1c]">{audit.error}</p>
                        ) : (
                            <ul className="divide-y divide-[var(--ui-border,#e8e8e8)]">
                                {audit.checks.map((c) => (
                                    <li key={c.key} className="flex items-start gap-3 py-3 first:pt-0 last:pb-0">
                                        <span className="mt-0.5 shrink-0">
                                            <StateIcon state={c.state} />
                                        </span>
                                        <div className="min-w-0">
                                            <p className="text-[13px] font-medium text-[#111]">{c.label}</p>
                                            {c.detail && (
                                                <p className="mt-0.5 break-words text-[12px] text-[#6b7280]">{c.detail}</p>
                                            )}
                                            {/* وما يُصلح يُقال، لا «ناقص» وحدها */}
                                            {c.fix && <p className="mt-1 text-[12px] text-[#b45309]">{c.fix}</p>}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </SettingsSection>

                    {/*
                        الأرقام تُقرأ عند Google لا هنا — ويُقال صراحةً.

                        قراءةُ تقارير Analytics تحتاج إذنًا على الحساب نفسه
                        (OAuth) لا معرّفًا يُلصق في حقل. ولوحةٌ هنا تقول
                        «الزوّار: ٠» إلى الأبد أسوأ من رابطٍ يقود إلى أرقامه.
                    */}
                    <Advanced title="أين تُقرأ الأرقام" icon={Search}>
                        <p className="text-[13px] leading-relaxed text-[#6b7280]">
                            {t('الزيارات والمصادر والصفحات تُقرأ في لوحة Google Analytics نفسها. وسحبُها إلى هنا يحتاج إذنًا على حسابك لا معرّفًا يُلصق — ولوحةٌ تقول «٠» إلى الأبد أسوأ من رابطٍ يفتح أرقامك.')}
                        </p>
                        <p className="mt-2 text-[13px] leading-relaxed text-[#6b7280]">
                            {t('وهذه الشاشة تضمن الشرط الذي بدونه لا رقمَ أصلًا: أن يكون الوسم على صفحتك، وأن تسمح صفحتُك بالفهرسة.')}
                        </p>
                    </Advanced>
                </SettingsPage>
            )}
        </AdminLayout>
    );
}
