import { Link, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, MessageCircle, Save } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import Toggle from '@/Components/Toggle';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
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
                <Card className="mx-auto flex max-w-xl flex-col items-center px-6 py-16 text-center">
                    <span
                        className="flex size-20 items-center justify-center rounded-[24px]"
                        style={{ background: '#25d36614', color: '#25d366' }}
                    >
                        <MessageCircle className="size-9" />
                    </span>

                    <h2 className="mt-6 text-[20px] font-bold text-[#111]">{t('واتساب غير مربوط بعد')}</h2>
                    <p className="mt-2 max-w-sm text-[14px] leading-relaxed text-[#6b7280]">
                        {t('اختيارُ الأحداث يأتي بعد الربط — فلا معنى لإشعالِ رسالةٍ لا قناةَ تخرج منها.')}
                    </p>

                    <Button asChild size="lg" className="mt-8">
                        <Link href={route('admin.integrations.whatsapp')}>{t('اذهب إلى ربط واتساب')}</Link>
                    </Button>
                </Card>
            </AdminLayout>
        );
    }

    return (
        <AdminLayout title="إشعارات واتساب">
            <PageHeader
                title="إشعارات واتساب"
                subtitle={t('رسائل تُرسَل للعميل عند تغيّر حال طلبه')}
                actions={
                    <Button asChild variant="outline">
                        <Link href={route('admin.integrations.whatsapp')}>
                            <MessageCircle />
                            {t('الوصلة وإعدادات الربط')}
                        </Link>
                    </Button>
                }
            />

            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(route('admin.marketing.whatsapp.save'), { preserveScroll: true });
                }}
                className="max-w-3xl space-y-6"
            >
                <Card className="p-6">
                    <h3 className="mb-1 font-bold text-[#111]">{t('متى تُرسَل الرسالة')}</h3>
                    <p className="mb-5 text-[13px] text-[#6b7280]">
                        {t('نصّ الرسالة قالبٌ معتمَدٌ لدى واتساب ولا يُكتب هنا — وهذه الأحداث قرارُك.')}
                    </p>

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
                    <div className="space-y-5">
                        {automation.events.map((e) => (
                            <Toggle
                                key={e.key}
                                label={e.label}
                                on={form.data[e.setting]}
                                onChange={(v) => form.setData(e.setting, v)}
                            />
                        ))}
                    </div>
                </Card>

                <div className="flex justify-end">
                    <Button type="submit" loading={form.processing}>
                        <Save />
                        {t('حفظ')}
                    </Button>
                </div>
            </form>
        </AdminLayout>
    );
}
