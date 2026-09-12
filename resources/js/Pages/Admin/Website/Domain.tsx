import { useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, Check, Globe, Link2, RefreshCw, Wrench } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { WEBSITE_TABS } from '@/Components/SectionTabs';
import CopyButton from '@/Components/CopyButton';
import Field from '@/Components/Field';
import StatusPill, { type SetupState } from '@/Components/StatusPill';
import { Advanced, PageActions, SettingsPage, SettingsSection } from '@/Components/Settings';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import { type DomainState, type SiteShell } from './shell';

interface Props extends SiteShell {
    domain: DomainState;
    connect: string;
    serves: boolean;
}

/**
 * الدومين — عنوانٌ يعمل من اليوم الأوّل، وآخرُ يُربط متى شاء.
 *
 * والترتيب في الشاشة هو الرسالة: أوّلُ ما يراه التاجر عنوانُه **العامل**،
 * لا حقلٌ فارغ يطلب منه نطاقًا. فمن لا نطاق له لا يشعر أنّ موقعه ناقص —
 * موقعُه يعمل، وهذا عنوانُه.
 *
 * ولا كلمةً من كلمات النظام: لا «DNS» ولا «SSL» ولا «propagation». والحالُ
 * أربعُ كلماتٍ تصف ما على التاجر أن يفعله: متصل، جارٍ الربط، بانتظار
 * التوجيه، يحتاج إجراء.
 *
 * والسجلُّ لا يُعرض إلّا لمن يحتاجه: من تمّ ربطُه لا يعنيه أيُّ سجلٍّ أُضيف،
 * وعرضُه له يجعل الشاشة تبدو ناقصةَ عملٍ وهي تامّة.
 *
 * ═══ ترتيبُ الشاشة ═══
 *
 * عمودٌ واحد بترتيب العمل: عنوانٌ يعمل، ثمّ نطاقٌ يُربط، ثمّ السجلُّ الذي
 * يُضاف. وكانت الحالُ في عمودٍ جانبيّ بعيدًا عن الحقل الذي تصفه — فيقرأ
 * التاجر «بانتظار التوجيه» في صندوقٍ ويبحث عن الحقل في آخر.
 */
export default function Domain() {
    const { domain, connect, serves } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const form = useForm({ hostname: domain.custom?.host ?? '' });
    const check = useForm({});

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.put(route('admin.website.domain.save'), { preserveScroll: true });
    };

    const status = domain.custom?.status;

    /*
        حالُ النطاق في كلمةٍ من المفردات الموحّدة — والاسمُ من الخادم.

        `domain.custom.label` يقول «جارٍ الربط» أو «بانتظار التوجيه»، وهما
        أدقُّ من «قيد الإعداد» العامّة. فتُؤخذ النغمةُ والأيقونة من هنا،
        والكلمةُ من هناك.
    */
    const state: SetupState =
        ! domain.custom ? 'idle'
        : status === 'active' ? 'ready'
        : status === 'failed' ? 'error'
        : 'progress';

    return (
        <AdminLayout title="الدومين">
            <PageHeader
                title="الدومين"
                subtitle={t('عنوان موقعك على الإنترنت')}
                actions={<StatusPill state={state} label={domain.custom?.label} connected />}
            />

            <SectionTabs tabs={WEBSITE_TABS} current="admin.website.domain" />

            <SettingsPage>
                {/* ===== عنوانُ أبعاد — يعمل بلا عملٍ من أحد ===== */}
                <SettingsSection
                    title="عنوان موقعك"
                    description="هذا العنوان لك ولا يحتاج منك شيئًا — لا سجلّات ولا إعدادات."
                    icon={Globe}
                    status={domain.platform ? <StatusPill state="ready" label="يعمل" /> : undefined}
                >
                    {domain.platform ? (
                        <div className="flex flex-wrap items-center gap-2">
                            <a
                                href={domain.platform.url}
                                target="_blank"
                                rel="noreferrer"
                                dir="ltr"
                                className="font-mono text-[13px] text-[#111] underline decoration-[#d1d5db] underline-offset-4"
                            >
                                {domain.platform.host}
                            </a>
                            <CopyButton text={domain.platform.url} name={t('انسخ العنوان')} size="sm" variant="ghost" />
                        </div>
                    ) : (
                        <p className="text-[13px] leading-6 text-[#6b7280]">
                            {t('لم تحجز اسم متجرك بعد — احجزه من «الإعدادات ‹ المتجر» فيصير لموقعك عنوانٌ يعمل فورًا.')}
                        </p>
                    )}
                </SettingsSection>

                {/* ===== نطاقُ التاجر — والحالُ عند الحقل لا في عمودٍ آخر ===== */}
                <form onSubmit={submit}>
                    <SettingsSection
                        title="استخدم دومينك الخاص"
                        description="إن كنت تملك نطاقًا — مثل mystore.om — اكتبه هنا ليفتح موقعك."
                        icon={Link2}
                        status={domain.custom ? <StatusPill state={state} label={domain.custom.label} connected /> : undefined}
                    >
                        <Field label="نطاقك" error={form.errors.hostname} htmlFor="hostname">
                            <Input
                                id="hostname"
                                dir="ltr"
                                placeholder="mystore.om"
                                value={form.data.hostname}
                                onChange={(e) => form.setData('hostname', e.target.value)}
                            />
                        </Field>

                        {/* وما يُصلح يُقال تحت الحقل الذي يُصلحه */}
                        {domain.custom?.reason && (
                            <p className="mt-3 flex items-start gap-2 text-[13px] leading-6 text-[#b45309]">
                                <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                                {domain.custom.reason}
                            </p>
                        )}

                        <PageActions
                            className="mt-5"
                            note={
                                domain.custom?.checked_at
                                    ? `${t('آخر تحقّق')}: ${domain.custom.checked_at}`
                                    : undefined
                            }
                        >
                            {domain.custom && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    loading={check.processing}
                                    onClick={() =>
                                        check.post(route('admin.website.domain.check'), { preserveScroll: true })
                                    }
                                >
                                    <RefreshCw />
                                    {t('تحقّق من الربط')}
                                </Button>
                            )}
                            <Button type="submit" loading={form.processing}>
                                <Check />
                                {t('حفظ')}
                            </Button>
                        </PageActions>
                    </SettingsSection>
                </form>

                {/*
                    ما على التاجر أن يفعله — إن كان عليه شيء.

                    وخارج النموذج لا داخله: فيه أزرارُ نسخٍ وجدول، ولا شأن
                    لزرّ «حفظ» بها.
                */}
                {domain.custom && domain.custom.records.length > 0 && (
                    <SettingsSection
                        title="أضف هذا السجلّ عند مزوّد نطاقك"
                        description="افتح لوحة المكان الذي اشتريت منه نطاقك، وأضف سجلًّا واحدًا بهذه القيم، ثمّ اضغط «تحقّق من الربط»."
                    >
                        {/* الجدولُ يُمرَّر داخل حاويته — والصفحة لا تتمدّد أفقيًّا */}
                        <div className="overflow-x-auto">
                            <table className="w-full text-start text-[13px]">
                                <thead className="text-[12px] text-[#9ca3af]">
                                    <tr>
                                        <th className="pb-2 text-start font-medium">{t('النوع')}</th>
                                        <th className="pb-2 text-start font-medium">{t('الاسم')}</th>
                                        <th className="pb-2 text-start font-medium">{t('القيمة')}</th>
                                        <th />
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-[var(--ui-border,#e8e8e8)]">
                                    {domain.custom.records.map((r) => (
                                        <tr key={r.type + r.name}>
                                            <td className="py-2 font-mono" dir="ltr">
                                                {r.type}
                                            </td>
                                            <td className="py-2 font-mono" dir="ltr">
                                                {r.name}
                                            </td>
                                            <td className="py-2 font-mono" dir="ltr">
                                                {r.value}
                                            </td>
                                            <td className="py-2 text-end">
                                                <CopyButton text={r.value} name={t('انسخ القيمة')} size="sm" variant="ghost" />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <p className="mt-3 text-[12px] leading-6 text-[#9ca3af]">
                            {t('قد يستغرق ظهورُ السجلّ ساعات — هذا طبيعيّ ولا يعني أنّك أخطأت.')}
                        </p>
                    </SettingsSection>
                )}

                {/*
                    وما لا يستطيعه الخادمُ بعد يُقال، ولا يُترك للتاجر يكتشفه.

                    ربطُ النطاق يُحفظ ويُتحقَّق منه، لكنّ **خدمة** الصفحة عليه
                    تلزمها كتلةُ nginx وشهادةٌ تُصدَر له. وإخفاءُ ذلك يجعل
                    التاجر يوجّه سجلَّه ثمّ يفتح نطاقه فلا يعمل، ويظنّ أنّه
                    أخطأ.
                */}
                {!serves && domain.custom && (
                    <SettingsSection
                        title="ربط النطاقات الخاصّة قيد التجهيز"
                        icon={Wrench}
                        status={<StatusPill state="progress" label="عند أبعاد" />}
                    >
                        <p className="text-[13px] leading-6 text-[#6b7280]">
                            {t('ربطُ النطاقات الخاصّة قيد التجهيز عندنا — نحفظ نطاقك ونتحقّق من توجيهه، ونُعلمك حين يصير يفتح موقعك. وعنوان أبعاد يعمل الآن.')}
                        </p>
                    </SettingsSection>
                )}

                {/* وما يُقرأ مرّةً عند العطب — مطويٌّ لا يزاحم الحقل */}
                <Advanced title="عناوين تقنيّة" description="ما تحتاجه إن سألك مزوّد نطاقك أو أردت أن تتحقّق بنفسك.">
                    <dl className="divide-y divide-[var(--ui-border,#e8e8e8)]">
                        <div className="pb-4">
                            <dt className="font-bold text-[#111]">{t('العنوان الذي يُفهرس')}</dt>
                            <dd dir="ltr" className="mt-2 break-all text-start font-mono text-[13px] text-[#374151]">
                                {domain.primary ?? '—'}
                            </dd>
                            <dd className="mt-2 text-[12px] leading-6 text-[#9ca3af]">
                                {t('حين يكون لموقعك أكثر من عنوان، نقول لمحرّكات البحث أيُّها الأصل — فلا تتنافس نسختان لصفحةٍ واحدة.')}
                            </dd>
                        </div>

                        <div className="pt-4">
                            <dt className="font-bold text-[#111]">{t('عنوان الوصل')}</dt>
                            <dd dir="ltr" className="mt-2 break-all text-start font-mono text-[13px] text-[#374151]">
                                {connect}
                            </dd>
                            <dd className="mt-2 text-[12px] leading-6 text-[#9ca3af]">
                                {t('هذا ما توجّه إليه نطاقك — واحدٌ لا يتغيّر.')}
                            </dd>
                        </div>
                    </dl>
                </Advanced>
            </SettingsPage>
        </AdminLayout>
    );
}
