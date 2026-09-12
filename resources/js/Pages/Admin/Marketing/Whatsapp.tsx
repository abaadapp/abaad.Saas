import { Link, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, MessageCircle, Save } from 'lucide-react';
import { WhatsAppBusinessMark } from '@/Components/BrandMarks';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import Gate from '@/Components/Gate';
import StatusPill, { readinessState } from '@/Components/StatusPill';
import Toggle from '@/Components/Toggle';
import { PageActions, SettingsPage, SettingsSection } from '@/Components/Settings';
import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';
import type { Readiness } from '@/Components/Connect';
import type { PageProps } from '@/types';

/**
 * ما تقرؤه هذه الشاشة من حال الأتمتة — لا كلَّ ما يصلها.
 *
 * والحمولة أوسع (الوصلةُ والحصّةُ ووضعُ الإرسال) لأنّ شاشة الربط تقرؤها،
 * وهي تصل هنا كما هي. والمكتوبُ هنا ما يُقرأ في هذا الملفّ وحده: حقلٌ
 * يُعلَن ولا يُقرأ يصير عقدًا يظنّ من يعدّله أنّ أحدًا يعتمد عليه.
 */
interface Automation {
    readiness: Readiness;
    events: { key: string; setting: string; label: string }[];
}

interface Props {
    settings: Record<string, string>;
    automation: Automation;
}

/**
 * إشعارات واتساب — أيُّ رسالةٍ تخرج ومتى، ولا وصلةَ ولا رمز.
 *
 * والوصلةُ ووضعُ الإرسال في «التطبيقات التكاملية»: تلك تُفتح مرّةً عند
 * الربط ثمّ لا تُفتح، وهذه تُفتح كلّما بُدّلت خطّةُ المتجر في مخاطبة
 * زبائنه. وجمعُهما كان يجعل من يريد إطفاء رسالةٍ واحدة يمرّ على رمز
 * تفعيلٍ من ميتا لا شأن له به.
 */
export default function Whatsapp() {
    const { settings, automation } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    /*
     * أربعةُ مقابضَ لا أكثر — ولا مقبضَ لا يُدير شيئًا.
     *
     * كانت الشاشة تعرض معها مفتاح «تفعيل الإشعارات» وحقلَ «رقم المتجر»
     * وثلاثةَ نصوصِ رسائلَ بمعاينةٍ حيّة تحتها. يكتبها التاجر ويحفظها ولا
     * يقرأ منها أحدٌ حرفًا: الإطفاء في عمود `businesses.whatsapp_enabled`،
     * والرقم رقمُ الوصلة المعتمدة، والنصّ قالبٌ معتمَدٌ عند ميتا باسمه —
     * فميتا لا تقبل نصًّا حرًّا في رسالةٍ يبدؤها العمل.
     */
    const form = useForm<Record<string, boolean>>(
        Object.fromEntries(automation.events.map((e) => [e.setting, settings[e.setting] === '1'])),
    );

    /*
        ومن لم يربط بعدُ يُقاد إلى الربط، لا يُعرض له بابُه هنا.

        وكان «ربط مع أبعاد» يُضغط من شاشتين: هذه وشاشةُ الأداة. وبابان
        لفعلٍ واحد يعني أنّ من ضغط أحدهما لا يعرف أين يعود ليكمل.
    */
    if (! automation.readiness.connected) {
        return (
            <AdminLayout title="إشعارات واتساب">
                <PageHeader
                    title="إشعارات واتساب"
                    subtitle={t('رسائل تُرسَل للعميل عند تغيّر حال طلبه')}
                />
                <Gate
                    /* الشعارُ نفسه الذي في اللوحة وفي باب الأداة — لا ثالثَ له */
                    mark={<WhatsAppBusinessMark size={80} />}
                    title="واتساب غير مربوط بعد"
                    description="اختيارُ الأحداث يأتي بعد الربط — فلا معنى لإشعالِ رسالةٍ لا قناةَ تخرج منها."
                    action={
                        <Button asChild size="lg">
                            <Link href={route('admin.integrations.whatsapp')}>{t('اذهب إلى ربط واتساب')}</Link>
                        </Button>
                    }
                />
            </AdminLayout>
        );
    }

    return (
        <AdminLayout title="إشعارات واتساب">
            <PageHeader
                title="إشعارات واتساب"
                subtitle={t('رسائل تُرسَل للعميل عند تغيّر حال طلبه')}
                actions={
                    <>
                        <StatusPill state={readinessState(automation.readiness)} connected />
                        <Button asChild variant="outline">
                            <Link href={route('admin.integrations.whatsapp')}>
                                <MessageCircle />
                                {t('الوصلة وإعدادات الربط')}
                            </Link>
                        </Button>
                    </>
                }
            />

            <SettingsPage>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(route('admin.marketing.whatsapp.save'), { preserveScroll: true });
                    }}
                    className="space-y-6"
                >
                    <SettingsSection
                        title="متى تُرسَل الرسالة"
                        description="نصّ الرسالة قالبٌ معتمَدٌ لدى واتساب ولا يُكتب هنا — وهذه الأحداث قرارُك."
                    >
                        {/*
                            والمقابض تبقى تُحفظ وإن لم يكتمل الربط — قرارُ التاجر
                            يُحفظ ليعمل يوم يكتمل. وإنّما يُقال إنّها لا تُرسل
                            اليوم، لئلّا يُشعلها ويمضي ينتظر.
                        */}
                        {! automation.readiness.ready && (
                            <div className="mb-5 flex items-start gap-2 rounded-[10px] bg-[#fffbeb] p-3 text-[13px] text-[#92400e]">
                                <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                                <span>
                                    {t('اختيارُك هنا يُحفظ، ولا تخرج رسالةٌ حتى تكتمل مراحلُ الربط.')}
                                </span>
                            </div>
                        )}

                        {/* من مصدرٍ واحد: WhatsAppEvent — حدثٌ يُضاف يظهر هنا بلا سطر */}
                        <div className="divide-y divide-[var(--ui-border,#e8e8e8)]">
                            {automation.events.map((e) => (
                                <div key={e.key} className="py-2 first:pt-0 last:pb-0">
                                    <Toggle
                                        label={e.label}
                                        on={form.data[e.setting]}
                                        onChange={(v) => form.setData(e.setting, v)}
                                    />
                                </div>
                            ))}
                        </div>
                    </SettingsSection>

                    <PageActions>
                        <Button type="submit" loading={form.processing}>
                            <Save />
                            {t('حفظ')}
                        </Button>
                    </PageActions>
                </form>
            </SettingsPage>
        </AdminLayout>
    );
}
