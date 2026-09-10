import { useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, Check, Globe, Link2, RefreshCw, ShieldCheck } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { WEBSITE_TABS } from '@/Components/SectionTabs';
import CopyButton from '@/Components/CopyButton';
import Field from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
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

    return (
        <AdminLayout title="الدومين">
            <PageHeader title="الدومين" subtitle={t('عنوان موقعك على الإنترنت')} />

            <SectionTabs tabs={WEBSITE_TABS} current="admin.website.domain" />

            <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    {/* ===== عنوانُ أبعاد — يعمل بلا عملٍ من أحد ===== */}
                    <Card className="p-5">
                        <h3 className="flex items-center gap-2 font-bold text-[#111]">
                            <Globe className="size-4 text-[#9ca3af]" />
                            {t('عنوان موقعك')}
                        </h3>

                        {domain.platform ? (
                            <div className="mt-3 flex flex-wrap items-center gap-2">
                                <a
                                    href={domain.platform.url}
                                    target="_blank"
                                    rel="noreferrer"
                                    dir="ltr"
                                    className="font-mono text-[13px] text-[#111] underline decoration-[#d1d5db] underline-offset-4"
                                >
                                    {domain.platform.host}
                                </a>
                                <Badge variant="success">{t('يعمل')}</Badge>
                                <CopyButton text={domain.platform.url} name={t('انسخ العنوان')} size="sm" variant="ghost" />
                            </div>
                        ) : (
                            <p className="mt-3 text-[13px] leading-6 text-[#6b7280]">
                                {t('لم تحجز اسم متجرك بعد — احجزه من «الإعدادات ‹ المتجر» فيصير لموقعك عنوانٌ يعمل فورًا.')}
                            </p>
                        )}

                        <p className="mt-3 text-[12px] leading-6 text-[#9ca3af]">
                            {t('هذا العنوان لك ولا يحتاج منك شيئًا — لا سجلّات ولا إعدادات.')}
                        </p>
                    </Card>

                    {/* ===== نطاقُ التاجر ===== */}
                    <form onSubmit={submit}>
                        <Card className="p-5">
                            <h3 className="flex items-center gap-2 font-bold text-[#111]">
                                <Link2 className="size-4 text-[#9ca3af]" />
                                {t('استخدم دومينك الخاص')}
                            </h3>
                            <p className="mt-1 text-[13px] leading-6 text-[#6b7280]">
                                {t('إن كنت تملك نطاقًا — مثل mystore.om — اكتبه هنا ليفتح موقعك.')}
                            </p>

                            <div className="mt-4">
                                <Field label="نطاقك" error={form.errors.hostname} htmlFor="hostname">
                                    <Input
                                        id="hostname"
                                        dir="ltr"
                                        placeholder="mystore.om"
                                        value={form.data.hostname}
                                        onChange={(e) => form.setData('hostname', e.target.value)}
                                    />
                                </Field>
                            </div>

                            <div className="mt-4 flex flex-wrap items-center gap-2">
                                <Button type="submit" loading={form.processing}>
                                    <Check />
                                    {t('حفظ')}
                                </Button>

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
                            </div>
                        </Card>
                    </form>

                    {/* ===== ما على التاجر أن يفعله — إن كان عليه شيء ===== */}
                    {domain.custom && domain.custom.records.length > 0 && (
                        <Card className="p-5">
                            <h3 className="font-bold text-[#111]">{t('أضف هذا السجلّ عند مزوّد نطاقك')}</h3>
                            <p className="mt-1 text-[13px] leading-6 text-[#6b7280]">
                                {t('افتح لوحة المكان الذي اشتريت منه نطاقك، وأضف سجلًّا واحدًا بهذه القيم، ثمّ اضغط «تحقّق من الربط».')}
                            </p>

                            <div className="mt-4 overflow-x-auto">
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
                        </Card>
                    )}
                </div>

                <div className="space-y-4">
                    {/* ===== الحال ===== */}
                    <Card className="p-5">
                        <h3 className="flex items-center gap-2 font-bold text-[#111]">
                            <ShieldCheck className="size-4 text-[#9ca3af]" />
                            {t('الحالة')}
                        </h3>

                        {domain.custom ? (
                            <>
                                <div className="mt-3">
                                    <Badge
                                        variant={
                                            status === 'active'
                                                ? 'success'
                                                : status === 'failed'
                                                  ? 'danger'
                                                  : 'warning'
                                        }
                                    >
                                        {domain.custom.label}
                                    </Badge>
                                </div>

                                {domain.custom.reason && (
                                    <p className="mt-3 flex items-start gap-2 text-[13px] leading-6 text-[#b45309]">
                                        <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                                        {domain.custom.reason}
                                    </p>
                                )}

                                {domain.custom.checked_at && (
                                    <p className="mt-3 text-[12px] text-[#9ca3af]">
                                        {t('آخر تحقّق')}: {domain.custom.checked_at}
                                    </p>
                                )}
                            </>
                        ) : (
                            <p className="mt-3 text-[13px] leading-6 text-[#6b7280]">
                                {t('لم تربط نطاقًا خاصًّا — وموقعك يعمل على عنوان أبعاد.')}
                            </p>
                        )}
                    </Card>

                    {/*
                        وما لا يستطيعه الخادمُ بعد يُقال، ولا يُترك للتاجر يكتشفه.

                        ربطُ النطاق يُحفظ ويُتحقَّق منه، لكنّ **خدمة** الصفحة عليه
                        تلزمها كتلةُ nginx وشهادةٌ تُصدَر له. وإخفاءُ ذلك يجعل
                        التاجر يوجّه سجلَّه ثمّ يفتح نطاقه فلا يعمل، ويظنّ أنّه
                        أخطأ.
                    */}
                    {!serves && domain.custom && (
                        <Card className="p-5">
                            <p className="text-[13px] leading-6 text-[#6b7280]">
                                {t('ربطُ النطاقات الخاصّة قيد التجهيز عندنا — نحفظ نطاقك ونتحقّق من توجيهه، ونُعلمك حين يصير يفتح موقعك. وعنوان أبعاد يعمل الآن.')}
                            </p>
                        </Card>
                    )}

                    <Card className="p-5">
                        <h3 className="font-bold text-[#111]">{t('العنوان الذي يُفهرس')}</h3>
                        <p dir="ltr" className="mt-3 break-all font-mono text-[13px] text-[#374151]">
                            {domain.primary ?? '—'}
                        </p>
                        <p className="mt-2 text-[12px] leading-6 text-[#9ca3af]">
                            {t('حين يكون لموقعك أكثر من عنوان، نقول لمحرّكات البحث أيُّها الأصل — فلا تتنافس نسختان لصفحةٍ واحدة.')}
                        </p>
                    </Card>

                    <Card className="p-5">
                        <h3 className="font-bold text-[#111]">{t('عنوان الوصل')}</h3>
                        <p dir="ltr" className="mt-3 font-mono text-[13px] text-[#374151]">
                            {connect}
                        </p>
                        <p className="mt-2 text-[12px] leading-6 text-[#9ca3af]">
                            {t('هذا ما توجّه إليه نطاقك — واحدٌ لا يتغيّر.')}
                        </p>
                    </Card>
                </div>
            </div>
        </AdminLayout>
    );
}
